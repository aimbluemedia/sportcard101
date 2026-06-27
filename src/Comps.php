<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Sold-comps engine. Turns closed tracked auctions into a proprietary
 * "what it actually sold for" database, and reads it back as pricing stats
 * (median / range / demand / trend) to power smarter buying decisions.
 *
 * We don't have eBay's Marketplace Insights API, so a "comp" here is the last
 * bid we recorded for an auction before it closed — an approximation of the
 * sale that gets sharper the more often the scanner runs.
 */
final class Comps
{
    /** Minimum comps for a card before we trust its median as a baseline. */
    public const MIN_FOR_BASELINE = 3;

    /**
     * Normalise a card name into a grouping/matching key. Lower-cases, strips
     * punctuation and collapses whitespace so minor title variations align.
     */
    public static function cardKey(string $name): string
    {
        $k = strtolower($name);
        $k = preg_replace('/[^a-z0-9]+/', ' ', $k) ?? '';
        $k = trim(preg_replace('/\s+/', ' ', $k) ?? '');
        return mb_substr($k, 0, 200);
    }

    /**
     * Record every tracked auction that has closed (end_time in the past) with
     * at least one bid as a sold comp. Idempotent — a unique key on the eBay
     * item id means re-runs skip already-recorded sales. Returns the number of
     * NEW comps recorded.
     */
    public static function recordClosed(PDO $pdo): int
    {
        $rows = $pdo->query(
            "SELECT l.ebay_item_id, l.search_id, l.title, l.ai_card, l.price, l.bid_count,
                    l.currency, l.image_url, l.item_url, l.end_time,
                    s.keywords AS sport, s.grade AS grade
             FROM listings l
             JOIN searches s ON s.id = l.search_id
             WHERE l.buying_option = 'AUCTION'
               AND l.end_time IS NOT NULL
               AND l.end_time < UTC_TIMESTAMP()
               AND l.bid_count >= 1"
        )->fetchAll();

        if (!$rows) {
            return 0;
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO sold_comps
                (ebay_item_id, search_id, sport, grade, canonical_card, card_key,
                 title, final_price, final_bids, currency, image_url, item_url, closed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $new = 0;
        foreach ($rows as $r) {
            $canonical = $r['ai_card'] ?: $r['title'];
            $ins->execute([
                $r['ebay_item_id'],
                $r['search_id'] !== null ? (int)$r['search_id'] : null,
                $r['sport'],
                $r['grade'],
                mb_substr((string)$canonical, 0, 250),
                self::cardKey((string)$r['title']), // key off title so scan-time lookups (no AI yet) match
                mb_substr((string)$r['title'], 0, 500),
                (float)$r['price'],
                (int)$r['bid_count'],
                $r['currency'] ?: 'USD',
                $r['image_url'],
                $r['item_url'],
                $r['end_time'],
            ]);
            $new += $ins->rowCount(); // 1 when inserted, 0 when ignored (dupe)
        }
        return $new;
    }

    /** Median of a numeric list. */
    private static function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
    }

    /** Build stats from a set of comp rows (each: final_price, final_bids, closed_at). */
    private static function statsFromRows(array $rows): ?array
    {
        if (!$rows) {
            return null;
        }
        $prices = array_map(fn ($r) => (float)$r['final_price'], $rows);
        $bids   = array_map(fn ($r) => (int)$r['final_bids'], $rows);
        $median = self::median($prices);

        // Trend: median of the older half vs the newer half (rows are time-ordered).
        $trend = 0.0;
        $n = count($rows);
        if ($n >= 4) {
            $half  = intdiv($n, 2);
            $older = self::median(array_slice($prices, 0, $half));
            $newer = self::median(array_slice($prices, $half));
            if ($older > 0) {
                $trend = round((($newer - $older) / $older) * 100, 1);
            }
        }

        return [
            'count'    => $n,
            'median'   => round($median, 2),
            'low'      => round(min($prices), 2),
            'high'     => round(max($prices), 2),
            'avg_bids' => $bids ? round(array_sum($bids) / count($bids), 1) : 0.0,
            'last'     => $rows[$n - 1]['closed_at'] ?? null,
            'trend'    => $trend, // % change older→newer
        ];
    }

    /** Stats for one card (sport + grade + card_key) over the last $days. */
    public static function statsFor(PDO $pdo, ?string $sport, ?string $grade, string $cardKey, int $days = 180): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT final_price, final_bids, closed_at FROM sold_comps
             WHERE sport <=> ? AND grade <=> ? AND card_key = ?
               AND closed_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
             ORDER BY closed_at ASC'
        );
        $stmt->execute([$sport, $grade, $cardKey, $days]);
        return self::statsFromRows($stmt->fetchAll());
    }

    /**
     * Batch stats for many cards at once. $cards is a list of
     * ['sport'=>, 'grade'=>, 'key'=>]. Returns a map keyed by "sport|grade|key".
     */
    public static function statsForCards(PDO $pdo, array $cards, int $days = 180): array
    {
        $cards = array_values(array_filter($cards, fn ($c) => ($c['key'] ?? '') !== ''));
        if (!$cards) {
            return [];
        }
        // Deduplicate the (sport,grade,key) tuples.
        $uniq = [];
        foreach ($cards as $c) {
            $uniq[$c['sport'] . '|' . $c['grade'] . '|' . $c['key']] = $c;
        }

        $keys = array_column($uniq, 'key');
        $in   = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            "SELECT sport, grade, card_key, final_price, final_bids, closed_at
             FROM sold_comps
             WHERE card_key IN ($in)
               AND closed_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
             ORDER BY closed_at ASC"
        );
        $stmt->execute([...$keys, $days]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $r) {
            $grouped[$r['sport'] . '|' . $r['grade'] . '|' . $r['card_key']][] = $r;
        }

        $out = [];
        foreach ($uniq as $mapKey => $c) {
            if (isset($grouped[$mapKey])) {
                $out[$mapKey] = self::statsFromRows($grouped[$mapKey]);
            }
        }
        return $out;
    }
}
