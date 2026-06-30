<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Comp-aware deal alerts. Scans active PSA auctions, compares each to our sold
 * comps, and emails the ones that beat the configured thresholds (so many %
 * under the comp median, optionally ending soon and/or under a price cap).
 *
 * De-duplicates via listings.notified so each auction only alerts once.
 * Settings come from the admin Settings page (key/value `settings` table).
 */
final class DealAlerts
{
    /**
     * Evaluate active auctions and email matches. Returns the matched rows
     * (each with comp context attached) so callers can report a count.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function run(PDO $pdo): array
    {
        $enabled = (string) \setting('notify_enabled', '0');
        $to      = trim((string) \setting('notify_email', ''));
        if ($enabled !== '1' || $to === '') {
            return [];
        }

        $minUnder = (float) (\setting('notify_min_under_comp', '20') ?: 20);
        $withinH  = \setting('notify_within_hours', '');
        $withinH  = ($withinH === '' || $withinH === null) ? null : (float) $withinH;
        $maxPrice = \setting('notify_max_price', '');
        $maxPrice = ($maxPrice === '' || $maxPrice === null) ? null : (float) $maxPrice;

        // Candidate auctions: active PSA auctions we haven't alerted on yet.
        $rows = $pdo->query(
            "SELECT l.id, l.ebay_item_id, l.title, l.ai_card, l.price, l.currency,
                    l.bid_count, l.end_time, l.item_url,
                    s.keywords AS sport, s.grade AS grade
             FROM listings l
             JOIN searches s ON s.id = l.search_id
             WHERE l.buying_option = 'AUCTION'
               AND l.end_time IS NOT NULL
               AND l.end_time > UTC_TIMESTAMP()
               AND l.notified = 0
               AND s.grade LIKE 'PSA %'"
        )->fetchAll();

        if (!$rows) {
            return [];
        }

        // Batch comp stats for all candidate cards.
        $cards = array_map(fn ($r) => [
            'sport' => $r['sport'],
            'grade' => $r['grade'],
            'key'   => Comps::cardKey((string)$r['title']),
        ], $rows);
        $stats = Comps::statsForCards($pdo, $cards);

        $now = time();
        $matches = [];
        foreach ($rows as $r) {
            $key  = Comps::cardKey((string)$r['title']);
            $comp = $stats[$r['sport'] . '|' . $r['grade'] . '|' . $key] ?? null;
            if (!$comp || $comp['median'] <= 0) {
                continue; // only alert when we have a comp to judge against
            }
            $price    = (float) $r['price'];
            $under    = round((($comp['median'] - $price) / $comp['median']) * 100, 1);
            $hoursLeft = (strtotime((string)$r['end_time']) - $now) / 3600;

            if ($under < $minUnder) {
                continue;
            }
            if ($withinH !== null && $hoursLeft > $withinH) {
                continue;
            }
            if ($maxPrice !== null && $price > $maxPrice) {
                continue;
            }

            $r['comp']      = $comp;
            $r['under_pct'] = $under;
            $r['hours_left'] = $hoursLeft;
            $matches[] = $r;
        }

        if (!$matches) {
            return [];
        }

        // Strongest deal first.
        usort($matches, fn ($a, $b) => $b['under_pct'] <=> $a['under_pct']);

        self::email($to, $matches);

        // Mark as alerted so we don't email them again.
        $mark = $pdo->prepare('UPDATE listings SET notified = 1 WHERE id = ?');
        foreach ($matches as $m) {
            $mark->execute([(int)$m['id']]);
        }

        return $matches;
    }

    /** Send a plain-text digest of matched deals. */
    private static function email(string $to, array $matches): void
    {
        $n = count($matches);
        $subject = "SportCard101: {$n} deal alert" . ($n === 1 ? '' : 's') . " — PSA auctions under comp";

        $lines = ["{$n} PSA auction" . ($n === 1 ? '' : 's') . " beat your alert thresholds:\n"];
        foreach ($matches as $m) {
            $card   = $m['ai_card'] ?: $m['title'];
            $price  = '$' . number_format((float)$m['price'], 2);
            $median = '$' . number_format((float)$m['comp']['median'], 2);
            $under  = (int) round((float)$m['under_pct']);
            $bids   = (int) $m['bid_count'];
            $hrs    = (int) max(0, round((float)$m['hours_left']));
            $url    = \function_exists('epn_link') ? \epn_link((string)$m['item_url']) : (string)$m['item_url'];

            $lines[] = "• {$card}";
            $lines[] = "  Current bid {$price} — {$under}% under comp median {$median} ({$m['comp']['count']} sales)";
            $lines[] = "  {$bids} bids · ends in ~{$hrs}h";
            $lines[] = "  {$url}";
            $lines[] = "";
        }
        $lines[] = "— SportCard101 deal agent";
        $body = implode("\n", $lines);

        $from = (string) \setting('notify_from', '') ?: 'alerts@sportcard101.com';
        $headers = 'From: ' . $from . "\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";

        @\mail($to, $subject, $body, $headers);
    }
}
