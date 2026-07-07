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

        self::email($to, $matches);

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
                    l.bid_count, l.end_time, l.item_url,
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

    /** Send a plain-text digest of matched deals. */
    private static function email(string $to, array $matches): void
    {
        $n = count($matches);
        $subject = "SportCard101: {$n} deal alert" . ($n === 1 ? '' : 's');

        $lines = ["{$n} auction" . ($n === 1 ? '' : 's') . " matched your triggers:\n"];
        foreach ($matches as $m) {
            $card  = $m['ai_card'] ?: $m['title'];
            $price = '$' . number_format((float)$m['price'], 2);
            $bids  = (int) $m['bid_count'];
            $hrs   = (int) max(0, round((float)$m['hours_left']));
            $url   = \function_exists('epn_link') ? \epn_link((string)$m['item_url']) : (string)$m['item_url'];

            $lines[] = "• {$card}";
            $lines[] = "  Current bid {$price} · {$bids} bids · ends in ~{$hrs}h";
            if (!empty($m['comp']) && $m['under_pct'] !== null) {
                $median = '$' . number_format((float)$m['comp']['median'], 2);
                $under  = (int) round((float)$m['under_pct']);
                $lines[] = "  {$under}% under comp median {$median} ({$m['comp']['count']} sales)";
            }
            $lines[] = "  Matched: " . implode(', ', $m['triggers']);
            $lines[] = "  {$url}";
            $lines[] = "";
        }
        $lines[] = "— SportCard101 deal agent";
        $body = implode("\n", $lines);

        Mailer::send($to, $subject, $body);
    }
}
