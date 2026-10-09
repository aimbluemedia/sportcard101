<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Demand analytics over the sold-comp archive.
 *
 * Bid counts are the signal nobody else publishes: price guides tell you what
 * a card is worth, but only this archive knows how hard people FOUGHT for it,
 * recorded every 30 minutes since the scanner started. A single 60-bid sale is
 * noise; a card that has drawn 40+ bids a dozen times is reliably liquid — and
 * that distinction only appears once you aggregate across the whole history.
 *
 * Nothing is ever deleted from sold_comps, so these are true all-time figures.
 */
final class Demand
{
    /** Sort options for the closed-sales browser: key => [label, SQL]. */
    public const SORTS = [
        'recent' => ['Newest first',   'closed_at DESC'],
        'bids'   => ['Most bids',      'final_bids DESC, closed_at DESC'],
        'price'  => ['Highest price',  'final_price DESC, closed_at DESC'],
        'oldest' => ['Oldest first',   'closed_at ASC'],
    ];

    /** Build the shared WHERE for closed-sale queries. */
    private static function filter(int $minBids, ?string $sport): array
    {
        $where  = ['final_bids >= ?'];
        $params = [$minBids];
        if ($sport !== null && $sport !== 'all') {
            $where[] = 'sport = ?';
            $params[] = $sport;
        }
        return [implode(' AND ', $where), $params];
    }

    /** How many archived sales match the current filter. */
    public static function closedCount(PDO $pdo, int $minBids, ?string $sport): int
    {
        [$where, $params] = self::filter($minBids, $sport);
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sold_comps WHERE {$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * One page of archived sales. Replaces the old hardcoded 60-row cap.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function closedPage(PDO $pdo, int $minBids, ?string $sport, string $sort, int $page, int $perPage = 48): array
    {
        [$where, $params] = self::filter($minBids, $sport);
        $order   = self::SORTS[$sort][1] ?? self::SORTS['recent'][1];
        $perPage = max(1, min(200, $perPage));
        $offset  = max(0, ($page - 1)) * $perPage;
        try {
            // LIMIT/OFFSET are cast ints, not bound — real prepares reject
            // string-bound limits when emulation is off.
            $stmt = $pdo->prepare(
                "SELECT canonical_card, title, sport, grade, card_key, final_price, final_bids,
                        currency, image_url, item_url, closed_at
                 FROM sold_comps WHERE {$where}
                 ORDER BY {$order}
                 LIMIT {$perPage} OFFSET {$offset}"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * THE leaderboard: cards that repeatedly draw heavy bidding. Ranked by how
     * many high-bid sales each card has produced, not by a single hot result —
     * so it surfaces reliable demand rather than one-off spikes.
     *
     * Medians are filled in afterwards from the comps engine (all-time window).
     */
    public static function mostContested(PDO $pdo, int $minBids, ?string $sport, int $limit = 40, int $minSales = 2): array
    {
        [$where, $params] = self::filter($minBids, $sport);
        $limit    = max(1, min(200, $limit));
        $minSales = max(1, $minSales);
        try {
            // MAX(id) gives the most recently recorded row per card, joined
            // back for display fields — avoids GROUP_CONCAT length limits.
            $stmt = $pdo->prepare(
                "SELECT g.sport, g.grade, g.card_key, g.hot_sales, g.avg_bids, g.max_bids,
                        g.avg_price, g.last_sold,
                        sc.canonical_card, sc.title, sc.currency, sc.image_url, sc.item_url
                 FROM (
                    SELECT sport, grade, card_key,
                           COUNT(*)         AS hot_sales,
                           AVG(final_bids)  AS avg_bids,
                           MAX(final_bids)  AS max_bids,
                           AVG(final_price) AS avg_price,
                           MAX(closed_at)   AS last_sold,
                           MAX(id)          AS last_id
                    FROM sold_comps
                    WHERE {$where} AND card_key IS NOT NULL AND card_key <> ''
                    GROUP BY sport, grade, card_key
                    HAVING hot_sales >= {$minSales}
                    ORDER BY hot_sales DESC, avg_bids DESC
                    LIMIT {$limit}
                 ) g
                 JOIN sold_comps sc ON sc.id = g.last_id"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        if (!$rows) {
            return [];
        }
        // Always present, even if the median lookup below fails.
        foreach ($rows as &$r0) {
            $r0['median_price'] = null;
            $r0['total_sales']  = null;
        }
        unset($r0);

        // Attach the all-time median price per card (resists the outliers an
        // average would absorb).
        try {
            $cards = array_map(fn ($r) => [
                'sport' => $r['sport'], 'grade' => $r['grade'], 'key' => $r['card_key'],
            ], $rows);
            $stats = Comps::statsForCards($pdo, $cards, 3650);
            foreach ($rows as &$r) {
                $s = $stats[$r['sport'] . '|' . $r['grade'] . '|' . $r['card_key']] ?? null;
                $r['median_price'] = $s['median'] ?? null;
                $r['total_sales']  = $s['count'] ?? null; // sales at ANY bid count
            }
            unset($r);
        } catch (\Throwable $e) {
        }
        return $rows;
    }

    /**
     * Record book: single sales with the highest bid counts, or the highest
     * prices, ever recorded.
     *
     * @param string $by 'bids' or 'price'
     */
    public static function recordHolders(PDO $pdo, string $by, ?string $sport, int $limit = 15): array
    {
        $order = $by === 'price' ? 'final_price DESC' : 'final_bids DESC';
        $where  = ['1=1'];
        $params = [];
        if ($sport !== null && $sport !== 'all') {
            $where[] = 'sport = ?';
            $params[] = $sport;
        }
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $pdo->prepare(
                'SELECT canonical_card, title, sport, grade, card_key, final_price, final_bids,
                        currency, image_url, item_url, closed_at
                 FROM sold_comps WHERE ' . implode(' AND ', $where) . "
                 ORDER BY {$order}, closed_at DESC
                 LIMIT {$limit}"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Every recorded sale of one card, oldest first, plus summary figures.
     * This is what turns a leaderboard row into a judgement: a card averaging
     * 45 bids across 12 sales is liquid money; the same average from one sale
     * is a coincidence.
     *
     * @return array{sales:array,summary:array}|null
     */
    public static function cardProfile(PDO $pdo, ?string $sport, ?string $grade, string $cardKey): ?array
    {
        if ($cardKey === '') {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT canonical_card, title, sport, grade, final_price, final_bids, currency,
                        image_url, item_url, closed_at
                 FROM sold_comps
                 WHERE sport <=> ? AND grade <=> ? AND card_key = ?
                 ORDER BY closed_at ASC
                 LIMIT 500'
            );
            $stmt->execute([$sport, $grade, $cardKey]);
            $sales = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$sales) {
            return null;
        }

        $prices = array_map(fn ($s) => (float)$s['final_price'], $sales);
        $bids   = array_map(fn ($s) => (int)$s['final_bids'], $sales);
        sort($prices);
        $n   = count($prices);
        $mid = intdiv($n, 2);

        // Trend: median of the older half vs the newer half, in time order.
        $trend = null;
        if ($n >= 4) {
            $chrono = array_map(fn ($s) => (float)$s['final_price'], $sales);
            $half   = intdiv($n, 2);
            $med = function (array $v): float {
                sort($v);
                $c = count($v);
                $m = intdiv($c, 2);
                return $c % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
            };
            $older = $med(array_slice($chrono, 0, $half));
            $newer = $med(array_slice($chrono, $half));
            if ($older > 0) {
                $trend = round((($newer - $older) / $older) * 100, 1);
            }
        }

        // Typical gap between sales — how often one actually comes up.
        $gapDays = null;
        if ($n >= 2) {
            $first = strtotime((string)$sales[0]['closed_at']);
            $last  = strtotime((string)$sales[$n - 1]['closed_at']);
            if ($first && $last && $last > $first) {
                $gapDays = round((($last - $first) / 86400) / ($n - 1), 1);
            }
        }

        return [
            'sales'   => $sales,
            'summary' => [
                'count'      => $n,
                'median'     => $n % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2,
                'low'        => $prices[0],
                'high'       => $prices[$n - 1],
                'avg_bids'   => round(array_sum($bids) / $n, 1),
                'max_bids'   => max($bids),
                'trend'      => $trend,
                'gap_days'   => $gapDays,
                'first_seen' => $sales[0]['closed_at'],
                'last_seen'  => $sales[$n - 1]['closed_at'],
                'card'       => $sales[$n - 1]['canonical_card'] ?: $sales[$n - 1]['title'],
                'title'      => $sales[$n - 1]['title'],
                'image_url'  => $sales[$n - 1]['image_url'],
                'currency'   => $sales[$n - 1]['currency'] ?: 'USD',
            ],
        ];
    }

    /**
     * Size and reach of the archive, for the "what have I actually collected"
     * header: total sales, how many were heavily contested, and the span.
     */
    public static function archiveStats(PDO $pdo, int $minBids): array
    {
        $out = ['total' => 0, 'hot' => 0, 'first' => null, 'last' => null, 'cards' => 0];
        try {
            $row = $pdo->query(
                'SELECT COUNT(*) AS total, MIN(closed_at) AS first, MAX(closed_at) AS last,
                        COUNT(DISTINCT card_key) AS cards
                 FROM sold_comps'
            )->fetch();
            if ($row) {
                $out['total'] = (int) $row['total'];
                $out['first'] = $row['first'];
                $out['last']  = $row['last'];
                $out['cards'] = (int) $row['cards'];
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM sold_comps WHERE final_bids >= ?');
            $stmt->execute([$minBids]);
            $out['hot'] = (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
        }
        return $out;
    }
}
