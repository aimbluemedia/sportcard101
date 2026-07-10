<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Morning Playbook — turns the three data sections (live auctions, sold comps,
 * bid heat) into ONE actionable plan for the day: a handful of BUY targets,
 * each with a pre-computed MAX BID (walk-away price), plus a watchlist and
 * sell actions on open positions.
 *
 * Discipline rules (why the numbers are the way they are):
 *   - A card only becomes a BUY target with >= MIN_COMPS sales in the last 90
 *     days and a sane price spread — thin or wild comps go to the watchlist.
 *   - max_bid is DERIVED from the comp median, never from the current price:
 *         resale_net = median * (1 - fees) - shipping
 *         max_bid    = resale_net / (1 + margin)
 *     so winning at max_bid nets ~margin% after costs. Current price only
 *     decides whether the target is still reachable.
 *   - Total exposure (sum of max bids) stays under the daily budget, and no
 *     single card exceeds the per-card cap. Both are settings.
 *
 * Every target is stored as a prediction so the closing tracker can grade it
 * against the real final price (Phase 2 of the playbook).
 */
final class Playbook
{
    public const FEE_RATE  = 0.1325; // eBay final value fee ~13.25%
    public const SHIP_COST = 5.00;   // typical PSA-slab shipping
    public const MIN_COMPS = 4;      // sales needed to trust a median
    public const MAX_BUYS  = 5;
    public const MAX_WATCH = 8;

    /** Settings (managed on the Daily Plan page), with defaults. */
    public static function config(): array
    {
        return [
            'budget'     => (float) (\setting('plan_daily_budget', '150') ?: 150),
            'per_card'   => (float) (\setting('plan_max_per_card', '75') ?: 75),
            'margin_pct' => (float) (\setting('plan_margin_pct', '20') ?: 20),
        ];
    }

    /**
     * Pure economics for one card: what we'd net selling at the comp median,
     * and the most we can pay while keeping the required margin.
     *
     * @return array{resale_net: float, max_bid: float, est_net: float}
     */
    public static function pricing(float $median, float $marginPct, float $perCardCap): array
    {
        $resaleNet = $median * (1 - self::FEE_RATE) - self::SHIP_COST;
        $maxBid    = min($resaleNet / (1 + $marginPct / 100), $perCardCap);
        $maxBid    = floor($maxBid * 100) / 100; // round DOWN — never overshoot
        return [
            'resale_net' => round($resaleNet, 2),
            'max_bid'    => $maxBid,
            'est_net'    => round($resaleNet - $maxBid, 2),
        ];
    }

    /**
     * Build (or rebuild) today's plan and store it. Returns a summary:
     * ['plan_id'=>int, 'buys'=>int, 'watch'=>int, 'exposure'=>float, 'ai'=>'live'|'mock'].
     */
    public static function build(PDO $pdo, AiAnalyst $ai): array
    {
        $cfg = self::config();

        // ---- Candidates: tracked PSA auctions ending within 24h ------------
        $rows = $pdo->query(
            "SELECT l.ebay_item_id, l.title, l.ai_card, l.ai_verdict, l.ai_hidden_gem,
                    l.price, l.bid_count, l.end_time, l.item_url,
                    s.keywords AS sport, s.grade AS grade
             FROM listings l
             JOIN searches s ON s.id = l.search_id
             WHERE l.buying_option = 'AUCTION'
               AND l.end_time > UTC_TIMESTAMP()
               AND l.end_time < (UTC_TIMESTAMP() + INTERVAL 24 HOUR)
               AND s.grade LIKE 'PSA %'
             ORDER BY l.end_time ASC
             LIMIT 400"
        )->fetchAll();

        // Fresh comps only — 90 days, not the default 180.
        $stats = [];
        if ($rows) {
            $cards = array_map(fn ($r) => [
                'sport' => $r['sport'], 'grade' => $r['grade'],
                'key'   => Comps::cardKey((string)$r['title']),
            ], $rows);
            $stats = Comps::statsForCards($pdo, $cards, 90);
        }

        // ---- Score every candidate -----------------------------------------
        $buys = $watch = [];
        foreach ($rows as $r) {
            $key  = $r['sport'] . '|' . $r['grade'] . '|' . Comps::cardKey((string)$r['title']);
            $comp = $stats[$key] ?? null;
            $card = (string) ($r['ai_card'] ?: $r['title']);
            $base = [
                'ebay_item_id'  => (string) $r['ebay_item_id'],
                'card'          => $card,
                'sport'         => $r['sport'],
                'grade'         => $r['grade'],
                'current_price' => (float) $r['price'],
                'bid_count'     => (int) $r['bid_count'],
                'end_time'      => $r['end_time'],
                'item_url'      => (string) $r['item_url'],
                'comp_median'   => $comp['median'] ?? null,
                'comp_count'    => $comp['count'] ?? null,
                'comp_low'      => $comp['low'] ?? null,
                'comp_high'     => $comp['high'] ?? null,
            ];

            // No trustworthy comp → AI-flagged cards go to the watchlist only.
            if (!$comp || $comp['count'] < self::MIN_COMPS) {
                if (($r['ai_verdict'] === 'BUY' || (int)$r['ai_hidden_gem'] === 1) && count($watch) < 50) {
                    $watch[] = $base + ['reason' =>
                        'Comp too thin (' . (int)($comp['count'] ?? 0) . ' sales in 90d) — AI likes it, verify value manually before bidding.'];
                }
                continue;
            }
            // Wild price spread → the median is not a safe anchor.
            if ($comp['median'] > 0 && ($comp['high'] - $comp['low']) / $comp['median'] > 1.2) {
                $watch[] = $base + ['reason' =>
                    'Comps vary too much ($' . number_format((float)$comp['low'], 0) . '–$' . number_format((float)$comp['high'], 0)
                    . ') — likely mixed variations. Check the exact card before bidding.'];
                continue;
            }

            $p = self::pricing((float)$comp['median'], $cfg['margin_pct'], $cfg['per_card']);
            if ($p['max_bid'] < 5 || $p['est_net'] < 3) {
                continue; // not worth the shipping tape
            }
            if ((float)$r['price'] > $p['max_bid']) {
                continue; // already past our walk-away price
            }

            // Confidence-weighted expected profit: more comps = more trust.
            $score = $p['est_net'] * min(1.0, $comp['count'] / 6);
            $buys[] = $base + [
                'max_bid'    => $p['max_bid'],
                'est_resale' => (float) $comp['median'],
                'est_net'    => $p['est_net'],
                'score'      => $score,
                'reason'     => sprintf(
                    'Comp median $%s (%d sales, 90d). Bid up to $%s — walk away above it. Nets ~$%s after ~13%% fees + shipping.',
                    number_format((float)$comp['median'], 2), (int)$comp['count'],
                    number_format($p['max_bid'], 2), number_format($p['est_net'], 2)
                ),
            ];
        }

        // ---- Pick top BUYs under the daily budget --------------------------
        usort($buys, fn ($a, $b) => $b['score'] <=> $a['score']);
        $picked = [];
        $exposure = 0.0;
        foreach ($buys as $b) {
            if (count($picked) >= self::MAX_BUYS) {
                break;
            }
            if ($exposure + $b['max_bid'] > $cfg['budget']) {
                // Over budget but still a good card — watch it instead.
                $b['reason'] = 'Good target, but today\'s budget is already committed. ' . $b['reason'];
                $watch[] = $b;
                continue;
            }
            $exposure += $b['max_bid'];
            $picked[] = $b;
        }
        $watch = array_slice($watch, 0, self::MAX_WATCH);

        // ---- Bid heat (demand context for the narrative) --------------------
        $heat = $pdo->query(
            "SELECT l.title, l.bid_count, l.price
             FROM listings l JOIN searches s ON s.id = l.search_id
             WHERE l.buying_option = 'AUCTION' AND l.end_time > UTC_TIMESTAMP() AND l.bid_count >= 20
             ORDER BY l.bid_count DESC LIMIT 10"
        )->fetchAll();

        // ---- AI narrative (optional — plan works without it) -----------------
        $summary = $picked
            ? count($picked) . ' qualified target' . (count($picked) === 1 ? '' : 's') . ' today with $'
              . number_format($exposure, 2) . ' max exposure of your $' . number_format($cfg['budget'], 2) . ' budget.'
            : 'No auctions met the comp-confidence and margin bar today. Holding the bankroll IS the plan — a forced buy is how money gets lost.';
        $narrative = $ai->planNarrative([
            'date'    => date('Y-m-d'),
            'budget'  => $cfg['budget'],
            'targets' => array_map(fn ($t) => array_diff_key($t, array_flip(['score', 'item_url'])), $picked),
            'watch'   => array_map(fn ($t) => ['card' => $t['card'], 'reason' => $t['reason']], $watch),
            'heat'    => $heat,
        ]);
        if ($narrative !== null) {
            $summary = $narrative['summary'] ?: $summary;
            foreach ($picked as &$t) {
                if (!empty($narrative['notes'][$t['ebay_item_id']])) {
                    $t['reason'] = $narrative['notes'][$t['ebay_item_id']] . ' ' . $t['reason'];
                }
            }
            unset($t);
        }

        // ---- Store (rebuild replaces today's plan) ---------------------------
        $today = date('Y-m-d');
        $pdo->prepare('DELETE FROM daily_plans WHERE plan_date = ?')->execute([$today]);
        $pdo->prepare('INSERT INTO daily_plans (plan_date, summary, budget_day, exposure, ai_mode) VALUES (?,?,?,?,?)')
            ->execute([$today, $summary, $cfg['budget'], round($exposure, 2), $ai->isMock() ? 'mock' : 'live']);
        $planId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare(
            'INSERT INTO plan_targets
                (plan_id, kind, ebay_item_id, card, sport, grade, current_price, bid_count, end_time,
                 item_url, comp_median, comp_count, comp_low, comp_high, max_bid, est_resale, est_net, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ([['BUY', $picked], ['WATCH', $watch]] as [$kind, $list]) {
            foreach ($list as $t) {
                $ins->execute([
                    $planId, $kind, $t['ebay_item_id'], mb_substr($t['card'], 0, 250), $t['sport'], $t['grade'],
                    $t['current_price'], $t['bid_count'], $t['end_time'], $t['item_url'],
                    $t['comp_median'], $t['comp_count'], $t['comp_low'], $t['comp_high'],
                    $t['max_bid'] ?? null, $t['est_resale'] ?? null, $t['est_net'] ?? null,
                    mb_substr((string)$t['reason'], 0, 500),
                ]);
            }
        }

        return [
            'plan_id'  => $planId,
            'buys'     => count($picked),
            'watch'    => count($watch),
            'exposure' => round($exposure, 2),
            'ai'       => $ai->isMock() ? 'mock' : 'live',
        ];
    }

    /** Load a plan + its targets for display/email. Latest plan when $date is null. */
    public static function load(PDO $pdo, ?string $date = null): ?array
    {
        try {
            if ($date !== null) {
                $stmt = $pdo->prepare('SELECT * FROM daily_plans WHERE plan_date = ?');
                $stmt->execute([$date]);
            } else {
                $stmt = $pdo->query('SELECT * FROM daily_plans ORDER BY plan_date DESC LIMIT 1');
            }
            $plan = $stmt->fetch();
        } catch (\Throwable $e) {
            return null; // tables not migrated yet
        }
        if (!$plan) {
            return null;
        }
        $t = $pdo->prepare("SELECT * FROM plan_targets WHERE plan_id = ? ORDER BY kind = 'WATCH', est_net DESC, id ASC");
        $t->execute([(int)$plan['id']]);
        $plan['targets'] = $t->fetchAll();
        return $plan;
    }

    /** Open positions needing action: unlisted buys and stale listings. */
    public static function sellActions(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT * FROM trades WHERE status IN ('BOUGHT','LISTED') ORDER BY bought_at ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            if ($r['status'] === 'BOUGHT') {
                $days = (int) floor((time() - strtotime((string)$r['bought_at'])) / 86400);
                $r['action'] = $days >= 3
                    ? "Bought {$days} days ago and still not listed — list it today. Idle cards are parked bankroll."
                    : 'List it near the comp median as soon as it arrives.';
            } else {
                $days = (int) floor((time() - strtotime((string)($r['listed_at'] ?: $r['bought_at']))) / 86400);
                $r['action'] = $days >= 7
                    ? "Listed {$days} days ago with no sale — consider a price drop or Best Offer."
                    : 'Listed — hold at price for now.';
            }
        }
        return $rows;
    }

    // ------------------------------------------------------------------ Email

    /** Morning email, same visual system as the deal alerts. */
    public static function emailHtml(array $plan, array $sells): string
    {
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $buys  = array_values(array_filter($plan['targets'], fn ($t) => $t['kind'] === 'BUY'));
        $watch = array_values(array_filter($plan['targets'], fn ($t) => $t['kind'] === 'WATCH'));
        $dateNice = date('l, M j', strtotime((string)$plan['plan_date']));

        $rows = '';
        foreach ($buys as $t) {
            $hrs  = \hours_until((string)$t['end_time']);
            $ends = $hrs === null ? '' : ' &middot; ends in ~' . ($hrs >= 48 ? round($hrs / 24) . ' days' : round($hrs) . 'h');
            $rows .=
                '<tr><td style="padding:22px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:16px;line-height:1.4;font-weight:600;color:#1d1d1f;' . $font . '">' . \e((string)$t['card']) . '</p>'
                . '<p style="margin:6px 0 0;font-size:13px;line-height:1.4;color:#6e6e73;' . $font . '">Now '
                . '<span style="font-weight:600;color:#1d1d1f">$' . number_format((float)$t['current_price'], 2) . '</span>'
                . ' &middot; ' . (int)$t['bid_count'] . ' bids' . $ends . '</p>'
                . '<p style="margin:8px 0 0;font-size:14px;line-height:1.4;font-weight:700;color:#0071e3;' . $font . '">Max bid: $'
                . number_format((float)$t['max_bid'], 2)
                . ' <span style="font-weight:400;color:#1d7d46">(nets ~$' . number_format((float)$t['est_net'], 2) . ')</span></p>'
                . '<p style="margin:8px 0 0;font-size:12px;line-height:1.5;color:#86868b;' . $font . '">' . \e((string)$t['reason']) . '</p>'
                . '<p style="margin:14px 0 0"><a href="' . \e(\epn_link((string)$t['item_url'])) . '" '
                . 'style="display:inline-block;background:#0071e3;color:#ffffff;font-size:13px;font-weight:600;line-height:1;'
                . 'padding:10px 20px;border-radius:980px;text-decoration:none;' . $font . '">View on eBay</a></p>'
                . '</td></tr>';
        }
        if (!$buys) {
            $rows = '<tr><td style="padding:22px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:14px;line-height:1.5;color:#1d1d1f;' . $font . '">No buy targets today. '
                . 'Nothing met the comp-confidence and margin bar — sitting out is the profitable move.</p></td></tr>';
        }

        $watchHtml = '';
        foreach (array_slice($watch, 0, 5) as $t) {
            $watchHtml .= '<p style="margin:8px 0 0;font-size:12px;line-height:1.5;color:#6e6e73;' . $font . '">&bull; '
                . '<a href="' . \e(\epn_link((string)$t['item_url'])) . '" style="color:#0071e3;text-decoration:none;font-weight:600">'
                . \e((string)$t['card']) . '</a> — ' . \e((string)$t['reason']) . '</p>';
        }
        if ($watchHtml !== '') {
            $watchHtml = '<tr><td style="padding:20px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">Watchlist</p>'
                . $watchHtml . '</td></tr>';
        }

        $sellHtml = '';
        foreach ($sells as $s) {
            $sellHtml .= '<p style="margin:8px 0 0;font-size:12px;line-height:1.5;color:#6e6e73;' . $font . '">&bull; '
                . '<span style="font-weight:600;color:#1d1d1f">' . \e((string)$s['card']) . '</span> — ' . \e((string)$s['action']) . '</p>';
        }
        if ($sellHtml !== '') {
            $sellHtml = '<tr><td style="padding:20px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">Sell actions</p>'
                . $sellHtml . '</td></tr>';
        }

        return
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . \e((string)$plan['summary']) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7">'
            . '<tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:18px">'
            . '<tr><td style="padding:30px 28px 6px">'
            . '<p style="margin:0;font-size:21px;font-weight:700;letter-spacing:-0.3px;color:#1d1d1f;' . $font . '">SportCard101</p>'
            . '<p style="margin:3px 0 0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">Morning Playbook &middot; ' . \e($dateNice) . '</p>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 28px 22px">'
            . '<p style="margin:0;font-size:14px;line-height:1.6;color:#1d1d1f;' . $font . '">' . \e((string)$plan['summary']) . '</p>'
            . '<p style="margin:10px 0 0;font-size:12px;color:#86868b;' . $font . '">Budget $' . number_format((float)$plan['budget_day'], 2)
            . ' &middot; planned exposure $' . number_format((float)$plan['exposure'], 2)
            . ' &middot; the max bid is a promise to yourself, not a suggestion.</p>'
            . '</td></tr>'
            . $rows . $watchHtml . $sellHtml
            . '</table>'
            . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#86868b;' . $font . '">'
            . 'Full detail and the trade log are on your Daily Plan dashboard.<br>'
            . '&copy; ' . date('Y') . ' SportCard101 &middot; Morning Playbook</p>'
            . '</td></tr></table>';
    }

    /** Plain-text fallback for the morning email. */
    public static function emailText(array $plan, array $sells): string
    {
        $lines = ['MORNING PLAYBOOK — ' . date('l, M j', strtotime((string)$plan['plan_date'])), ''];
        $lines[] = (string) $plan['summary'];
        $lines[] = 'Budget $' . number_format((float)$plan['budget_day'], 2)
            . ' · planned exposure $' . number_format((float)$plan['exposure'], 2);
        $lines[] = '';
        $buys = array_filter($plan['targets'], fn ($t) => $t['kind'] === 'BUY');
        $lines[] = $buys ? 'BUY TARGETS:' : 'No buy targets today — holding the bankroll is the plan.';
        foreach ($buys as $t) {
            $lines[] = "• {$t['card']}";
            $lines[] = '  Now $' . number_format((float)$t['current_price'], 2)
                . " · {$t['bid_count']} bids · MAX BID $" . number_format((float)$t['max_bid'], 2)
                . ' (nets ~$' . number_format((float)$t['est_net'], 2) . ')';
            $lines[] = "  {$t['reason']}";
            $lines[] = '  View on eBay: ' . \epn_link((string)$t['item_url']);
            $lines[] = '';
        }
        $watch = array_slice(array_filter($plan['targets'], fn ($t) => $t['kind'] === 'WATCH'), 0, 5);
        if ($watch) {
            $lines[] = 'WATCHLIST:';
            foreach ($watch as $t) {
                $lines[] = "• {$t['card']} — {$t['reason']}";
            }
            $lines[] = '';
        }
        if ($sells) {
            $lines[] = 'SELL ACTIONS:';
            foreach ($sells as $s) {
                $lines[] = "• {$s['card']} — {$s['action']}";
            }
            $lines[] = '';
        }
        $lines[] = '— SportCard101 Morning Playbook';
        return implode("\n", $lines);
    }
}
