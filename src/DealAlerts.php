<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Multi-trigger deal alerts. Loads the active rows from alert_triggers, checks
 * each active PSA auction against every trigger, and emails the ones that match
 * at least one — noting which trigger(s) fired. De-duplicates via
 * listings.notified so each auction only alerts once.
 */
final class DealAlerts
{
    /**
     * Evaluate active auctions against all active triggers and email matches.
     * Returns the matched rows (with matched trigger labels + comp context).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function run(PDO $pdo): array
    {
        if ((string) \setting('notify_enabled', '0') !== '1') {
            return [];
        }
        $to = trim((string) \setting('notify_email', ''));
        if ($to === '') {
            return [];
        }

        $matches = self::evaluate($pdo, false)['matches'];
        if (!$matches) {
            return [];
        }

        // Only mark auctions as notified when the email ACTUALLY sent —
        // otherwise leave them unmarked so the next scan retries, and record
        // the error where the Alerts page can show it.
        if (!self::email($to, $matches)) {
            \set_setting('alerts_last_error', date('M j, g:ia') . ' — ' . (Mailer::$lastError ?: 'unknown send error'));
            return [];
        }
        \set_setting('alerts_last_error', '');
        \set_setting('alerts_last_sent', date('Y-m-d H:i:s'));

        $mark = $pdo->prepare('UPDATE listings SET notified = 1 WHERE id = ?');
        foreach ($matches as $m) {
            $mark->execute([(int)$m['id']]);
        }

        return $matches;
    }

    /**
     * Read-only evaluation used by run() and the dry-run preview. Returns
     * ['triggers'=>int active, 'candidates'=>int auctions checked, 'matches'=>[]].
     * When $includeNotified is true, auctions already alerted are still checked
     * (so a preview shows what would match regardless of de-dupe).
     */
    public static function evaluate(PDO $pdo, bool $includeNotified = false): array
    {
        $out = ['triggers' => 0, 'candidates' => 0, 'matches' => []];

        try {
            $triggers = $pdo->query('SELECT * FROM alert_triggers WHERE active = 1')->fetchAll();
        } catch (\Throwable $e) {
            return $out; // table not migrated yet
        }
        $out['triggers'] = count($triggers);
        if (!$triggers) {
            return $out;
        }

        $notCond = $includeNotified ? '' : ' AND l.notified = 0';
        $rows = $pdo->query(
            "SELECT l.id, l.ebay_item_id, l.title, l.ai_card, l.price, l.currency,
                    l.bid_count, l.end_time, l.item_url, l.notified,
                    s.keywords AS sport, s.grade AS grade
             FROM listings l
             JOIN searches s ON s.id = l.search_id
             WHERE l.buying_option = 'AUCTION'
               AND l.end_time IS NOT NULL
               AND l.end_time > UTC_TIMESTAMP()
               AND s.grade LIKE 'PSA %'" . $notCond
        )->fetchAll();
        $out['candidates'] = count($rows);
        if (!$rows) {
            return $out;
        }

        $cards = array_map(fn ($r) => [
            'sport' => $r['sport'],
            'grade' => $r['grade'],
            'key'   => Comps::cardKey((string)$r['title']),
        ], $rows);
        $stats = Comps::statsForCards($pdo, $cards);

        $matches = [];
        foreach ($rows as $r) {
            $key   = Comps::cardKey((string)$r['title']);
            $comp  = $stats[$r['sport'] . '|' . $r['grade'] . '|' . $key] ?? null;
            $price = (float) $r['price'];
            $under = ($comp && $comp['median'] > 0)
                ? round((($comp['median'] - $price) / $comp['median']) * 100, 1)
                : null;
            $hoursLeft = \hours_until((string)$r['end_time']) ?? 0.0;
            $titleLc   = strtolower((string)$r['title']);
            $isSigned  = (bool) preg_match('/\b(auto|autograph|signed|signature)/i', $titleLc);

            $fired = [];
            foreach ($triggers as $t) {
                if (self::matches($t, $r, $price, $under, $hoursLeft, $titleLc, $isSigned)) {
                    $fired[] = $t['label'];
                }
            }
            if ($fired) {
                $r['comp']       = $comp;
                $r['under_pct']  = $under;
                $r['hours_left'] = $hoursLeft;
                $r['triggers']   = $fired;
                $r['notified']   = (int)($r['notified'] ?? 0);
                $matches[] = $r;
            }
        }

        usort($matches, function ($a, $b) {
            $ua = $a['under_pct'] ?? -999;
            $ub = $b['under_pct'] ?? -999;
            return ($ub <=> $ua) ?: ($a['price'] <=> $b['price']);
        });

        $out['matches'] = $matches;
        return $out;
    }

    /** Does one auction satisfy every condition set on one trigger? */
    private static function matches(array $t, array $r, float $price, ?float $under, float $hoursLeft, string $titleLc, bool $isSigned): bool
    {
        // Sport.
        if (($t['sport'] ?? 'all') !== 'all' && $t['sport'] !== $r['sport']) {
            return false;
        }
        // Grade (trigger stores the number, e.g. "10"; auction grade is "PSA 10").
        if (($t['grade'] ?? 'any') !== 'any' && strcasecmp((string)$r['grade'], 'PSA ' . $t['grade']) !== 0) {
            return false;
        }
        // Signed / autograph.
        if (!empty($t['signed']) && !$isSigned) {
            return false;
        }
        // Keyword in title.
        $kw = trim((string)($t['keywords'] ?? ''));
        if ($kw !== '' && !str_contains($titleLc, strtolower($kw))) {
            return false;
        }
        // Max price.
        if ($t['max_price'] !== null && $t['max_price'] !== '' && $price > (float)$t['max_price']) {
            return false;
        }
        // Ending within N hours.
        if ($t['within_hours'] !== null && $t['within_hours'] !== '' && $hoursLeft > (float)$t['within_hours']) {
            return false;
        }
        // Comp conditions.
        $needComp = !empty($t['require_comp'])
            || ($t['min_under_comp'] !== null && $t['min_under_comp'] !== '');
        if ($needComp) {
            if ($under === null) {
                return false; // no comp to compare against
            }
            $minUnder = ($t['min_under_comp'] === null || $t['min_under_comp'] === '')
                ? 0.0 : (float)$t['min_under_comp'];
            // require_comp with 0% threshold means "priced below comp at all".
            if (!empty($t['require_comp']) && $minUnder <= 0) {
                if ($under <= 0) {
                    return false;
                }
            } elseif ($under < $minUnder) {
                return false;
            }
        }
        return true;
    }

    /** Send the deal digest (HTML with a plain-text fallback). */
    private static function email(string $to, array $matches): bool
    {
        $n = count($matches);
        $subject = "SportCard101: {$n} deal alert" . ($n === 1 ? '' : 's');
        return Mailer::send($to, $subject, self::emailText($matches), self::emailHtml($matches));
    }

    /** Normalise one match into the display fields both email formats use. */
    private static function displayFields(array $m): array
    {
        $hrs = (int) max(0, round((float)$m['hours_left']));
        $comp = null;
        if (!empty($m['comp']) && $m['under_pct'] !== null) {
            $comp = [
                'median' => '$' . number_format((float)$m['comp']['median'], 2),
                'count'  => (int) $m['comp']['count'],
                'under'  => (int) round((float)$m['under_pct']),
            ];
        }
        return [
            'card'  => (string) ($m['ai_card'] ?: $m['title']),
            'price' => '$' . number_format((float)$m['price'], 2),
            'bids'  => (int) $m['bid_count'],
            'ends'  => $hrs >= 48 ? '~' . (int) round($hrs / 24) . ' days' : "~{$hrs}h",
            'url'   => \function_exists('epn_link') ? \epn_link((string)$m['item_url']) : (string)$m['item_url'],
            'comp'  => $comp,
            'trig'  => implode(', ', $m['triggers']),
        ];
    }

    /** Plain-text fallback shown by clients that don't render HTML. */
    public static function emailText(array $matches): string
    {
        $n = count($matches);
        $lines = ["{$n} auction" . ($n === 1 ? '' : 's') . " matched your triggers:\n"];
        foreach ($matches as $m) {
            $d = self::displayFields($m);
            $lines[] = "• {$d['card']}";
            $lines[] = "  Current bid {$d['price']} · {$d['bids']} bid" . ($d['bids'] === 1 ? '' : 's') . " · ends in {$d['ends']}";
            if ($d['comp']) {
                $lines[] = $d['comp']['under'] > 0
                    ? "  {$d['comp']['under']}% under comp median {$d['comp']['median']} ({$d['comp']['count']} sales)"
                    : "  Comp median {$d['comp']['median']} ({$d['comp']['count']} sales)";
            }
            $lines[] = "  Matched: {$d['trig']}";
            $lines[] = "  View on eBay: {$d['url']}";
            $lines[] = "";
        }
        $lines[] = "— SportCard101 deal agent";
        return implode("\n", $lines);
    }

    /** Rich HTML digest — inline styles and table layout for email clients. */
    public static function emailHtml(array $matches): string
    {
        $n    = count($matches);
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $intro = $n === 1
            ? '1 auction matched your alert triggers.'
            : "{$n} auctions matched your alert triggers, best value first.";
        $preheader = \e($intro . ($matches ? ' Top match: ' . (string)($matches[0]['ai_card'] ?: $matches[0]['title']) : ''));

        $rows = '';
        foreach ($matches as $m) {
            $d = self::displayFields($m);
            $compLine = '';
            if ($d['comp']) {
                $compLine = $d['comp']['under'] > 0
                    ? '<p style="margin:8px 0 0;font-size:13px;line-height:1.4;font-weight:600;color:#1d7d46;' . $font . '">'
                        . \e("{$d['comp']['under']}% under comp") . ' <span style="font-weight:400;color:#6e6e73">&middot; median '
                        . \e($d['comp']['median']) . ' &middot; ' . $d['comp']['count'] . ' sales</span></p>'
                    : '<p style="margin:8px 0 0;font-size:13px;line-height:1.4;color:#6e6e73;' . $font . '">Comp median '
                        . \e($d['comp']['median']) . ' &middot; ' . $d['comp']['count'] . ' sales</p>';
            }
            $rows .=
                '<tr><td style="padding:22px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:16px;line-height:1.4;font-weight:600;color:#1d1d1f;' . $font . '">' . \e($d['card']) . '</p>'
                . '<p style="margin:6px 0 0;font-size:13px;line-height:1.4;color:#6e6e73;' . $font . '">Current bid '
                . '<span style="font-weight:600;color:#1d1d1f">' . \e($d['price']) . '</span>'
                . ' &middot; ' . $d['bids'] . ' bid' . ($d['bids'] === 1 ? '' : 's')
                . ' &middot; ends in ' . \e($d['ends']) . '</p>'
                . $compLine
                . '<p style="margin:8px 0 0;font-size:12px;line-height:1.4;color:#86868b;' . $font . '">Matched: ' . \e($d['trig']) . '</p>'
                . '<p style="margin:16px 0 0"><a href="' . \e($d['url']) . '" '
                . 'style="display:inline-block;background:#0071e3;color:#ffffff;font-size:13px;font-weight:600;line-height:1;'
                . 'padding:10px 20px;border-radius:980px;text-decoration:none;' . $font . '">View on eBay</a></p>'
                . '</td></tr>';
        }

        return
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . $preheader . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7">'
            . '<tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:600px;max-width:100%;background:#ffffff;border-radius:18px">'
            . '<tr><td style="padding:30px 28px 6px">'
            . '<p style="margin:0;font-size:21px;font-weight:700;letter-spacing:-0.3px;color:#1d1d1f;' . $font . '">SportCard101</p>'
            . '<p style="margin:3px 0 0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">Deal Alerts</p>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 28px 22px">'
            . '<p style="margin:0;font-size:14px;line-height:1.5;color:#1d1d1f;' . $font . '">' . \e($intro) . '</p>'
            . '</td></tr>'
            . $rows
            . '</table>'
            . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#86868b;' . $font . '">'
            . 'You&rsquo;re receiving this because deal alerts are enabled in your SportCard101 dashboard.<br>'
            . '&copy; ' . date('Y') . ' SportCard101 &middot; Deal Agent</p>'
            . '</td></tr></table>';
    }
}
