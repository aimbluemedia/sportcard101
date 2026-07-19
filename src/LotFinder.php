<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Bulk-lot deal finder. Searches eBay for AUCTION lots of graded cards,
 * stores them in their own `lots` table (deliberately separate from the
 * singles pipeline — lots would poison comps and the playbook), and runs an
 * AI pass that reads each lot title to estimate how many cards it holds and
 * what the lot is roughly worth. The page then surfaces price-per-card and
 * the lots where the current bid sits well under the estimate.
 */
final class LotFinder
{
    /** eBay queries used to sweep for graded-card lots. */
    private const QUERIES = [
        'graded card lot',
        'psa card lot',
        'psa graded lot',
        'sports card lot psa slab',
    ];

    /** Create the lots table when missing (idempotent). */
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lots (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ebay_item_id  VARCHAR(64)  NOT NULL,
                title         VARCHAR(512) NOT NULL,
                price         DECIMAL(10,2) NOT NULL DEFAULT 0,
                currency      VARCHAR(8)   NOT NULL DEFAULT "USD",
                bid_count     INT DEFAULT NULL,
                end_time      DATETIME DEFAULT NULL,
                image_url     VARCHAR(1024) DEFAULT NULL,
                item_url      VARCHAR(1024) NOT NULL,
                est_cards     INT DEFAULT NULL,
                ai_verdict    VARCHAR(8)  DEFAULT NULL,
                ai_est_low    DECIMAL(10,2) DEFAULT NULL,
                ai_est_high   DECIMAL(10,2) DEFAULT NULL,
                ai_reason     VARCHAR(512) DEFAULT NULL,
                analyzed_at   DATETIME DEFAULT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_item (ebay_item_id),
                KEY idx_end (end_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Best-effort card count parsed from a lot title. Null when unclear. */
    public static function parseCardCount(string $title): ?int
    {
        $t = strtolower($title);
        // "lot of 12", "12 card lot", "12x", "(12) cards", "12 psa"
        foreach ([
            '/lot\s*of\s*(\d{1,3})/',
            '/(\d{1,3})\s*(?:card|cards|graded|slab|slabs|psa)\b/',
            '/\((\d{1,3})\)/',
            '/\b(\d{1,3})\s*x\b/',
        ] as $re) {
            if (preg_match($re, $t, $m)) {
                $n = (int)$m[1];
                if ($n >= 2 && $n <= 500) {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * Sweep eBay for lot auctions, upsert them, and AI-analyze the new ones.
     * Returns ['found'=>int, 'new'=>int, 'analyzed'=>int].
     */
    public static function scan(PDO $pdo, EbayClient $ebay, AiAnalyst $ai, int $analyzeCap = 12): array
    {
        self::ensureTable($pdo);

        $seen = [];
        foreach (self::QUERIES as $q) {
            foreach ($ebay->search($q, 'PSA', 'AUCTION', 50) as $item) {
                $id    = (string) $item['ebay_item_id'];
                $title = (string) $item['title'];
                // Must actually read like a multi-card lot.
                if ($id === '' || stripos($title, 'lot') === false || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = $item;
            }
        }

        $new = 0;
        if ($seen) {
            $ins = $pdo->prepare(
                'INSERT INTO lots (ebay_item_id, title, price, currency, bid_count, end_time, image_url, item_url, est_cards)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    price = VALUES(price), bid_count = VALUES(bid_count), end_time = VALUES(end_time),
                    last_seen_at = CURRENT_TIMESTAMP'
            );
            foreach ($seen as $item) {
                $ins->execute([
                    $item['ebay_item_id'],
                    mb_substr((string)$item['title'], 0, 500),
                    (float) $item['price'],
                    $item['currency'] ?: 'USD',
                    $item['bid_count'],
                    $item['end_time'],
                    $item['image_url'],
                    $item['item_url'],
                    self::parseCardCount((string)$item['title']),
                ]);
                $new += $ins->rowCount() === 1 ? 1 : 0; // 1 = inserted, 2 = updated
            }
        }

        // AI pass over live, not-yet-analyzed lots (cost-capped per scan).
        $todo = $pdo->query(
            'SELECT id, ebay_item_id, title, price, bid_count, est_cards
             FROM lots
             WHERE analyzed_at IS NULL AND end_time > UTC_TIMESTAMP()
             ORDER BY end_time ASC LIMIT ' . (int)$analyzeCap
        )->fetchAll();

        $analyzed = 0;
        if ($todo) {
            $results = $ai->analyzeLots($todo);
            $upd = $pdo->prepare(
                'UPDATE lots SET ai_verdict=?, ai_est_low=?, ai_est_high=?, ai_reason=?, est_cards=COALESCE(?, est_cards),
                    analyzed_at=UTC_TIMESTAMP() WHERE id=?'
            );
            foreach ($todo as $lot) {
                $r = $results[$lot['ebay_item_id']] ?? null;
                if (!$r) {
                    continue;
                }
                $upd->execute([
                    $r['verdict'], $r['est_value_low'], $r['est_value_high'],
                    mb_substr((string)$r['reason'], 0, 500),
                    ($r['est_card_count'] ?? 0) > 0 ? (int)$r['est_card_count'] : null,
                    (int)$lot['id'],
                ]);
                $analyzed++;
            }
        }

        return ['found' => count($seen), 'new' => $new, 'analyzed' => $analyzed];
    }
}
