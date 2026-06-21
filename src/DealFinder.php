<?php
declare(strict_types=1);

namespace Sportscard101;

use PDO;

/**
 * Scans saved searches against eBay, computes a market baseline per search,
 * flags under-priced listings as deals, and persists everything.
 */
final class DealFinder
{
    public function __construct(
        private PDO $pdo,
        private EbayClient $ebay,
        private int $scanLimit = 100,
        private ?AiAnalyst $ai = null
    ) {}

    /**
     * Scan a single saved search. Returns the list of NEW deals found
     * (deals not previously flagged) so callers can notify on them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function scanSearch(array $search): array
    {
        $listings = $this->ebay->search(
            $search['keywords'],
            $search['grade'],
            $search['buying_option'],
            $this->scanLimit
        );

        $listings = array_values(array_filter(
            $listings,
            fn ($l) => !empty($l['ebay_item_id']) && $l['price'] > 0
        ));

        if (!$listings) {
            $this->markScanned((int)$search['id']);
            return [];
        }

        $baseline  = $this->baseline(array_column($listings, 'price'));
        $threshold = (int)$search['threshold_pct'];
        $maxPrice  = $search['max_price'] !== null ? (float)$search['max_price'] : null;

        $newDeals = [];
        $dealCandidates = [];

        foreach ($listings as $l) {
            $discount = $baseline > 0 ? round((($baseline - $l['price']) / $baseline) * 100, 2) : 0.0;
            $isDeal   = $discount >= $threshold && ($maxPrice === null || $l['price'] <= $maxPrice);

            $wasDealBefore = $this->existingDealFlag((int)$search['id'], $l['ebay_item_id']);
            $this->upsertListing((int)$search['id'], $l, $baseline, $discount, $isDeal);

            if ($isDeal) {
                $row = $l;
                $row['baseline_price'] = $baseline;
                $row['discount_pct']   = $discount;
                $dealCandidates[] = $row;

                // A "new deal" is one that is a deal now but wasn't flagged before.
                if ($wasDealBefore !== 1) {
                    $newDeals[] = $row;
                }
            }
        }

        // AI Opportunity Engine: assess the deal candidates and persist verdicts.
        if ($this->ai && $dealCandidates) {
            $analyses = $this->ai->analyze($dealCandidates, [
                'keywords' => $search['keywords'],
                'grade'    => $search['grade'],
            ]);
            foreach ($analyses as $itemId => $a) {
                $this->saveAi((int)$search['id'], (string)$itemId, $a);
            }
            // Attach AI verdicts to new deals so notifications can include them.
            foreach ($newDeals as &$nd) {
                if (isset($analyses[$nd['ebay_item_id']])) {
                    $nd['ai'] = $analyses[$nd['ebay_item_id']];
                }
            }
            unset($nd);
        }

        $this->markScanned((int)$search['id']);
        return $newDeals;
    }

    /** Scan every active search for a user. Returns all new deals. */
    public function scanAll(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM searches WHERE user_id = ? AND active = 1');
        $stmt->execute([$userId]);
        $all = [];
        foreach ($stmt->fetchAll() as $search) {
            foreach ($this->scanSearch($search) as $deal) {
                $deal['search_label'] = $search['label'];
                $all[] = $deal;
            }
        }
        return $all;
    }

    /**
     * Market baseline = median price. The median resists being dragged down by
     * the very bargains we are trying to detect (unlike the mean).
     */
    private function baseline(array $prices): float
    {
        $prices = array_values(array_filter($prices, fn ($p) => $p > 0));
        if (!$prices) {
            return 0.0;
        }
        sort($prices);
        $n   = count($prices);
        $mid = intdiv($n, 2);
        return $n % 2 === 0
            ? ($prices[$mid - 1] + $prices[$mid]) / 2
            : $prices[$mid];
    }

    private function existingDealFlag(int $searchId, string $itemId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_deal FROM listings WHERE search_id = ? AND ebay_item_id = ? LIMIT 1'
        );
        $stmt->execute([$searchId, $itemId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (int)$val;
    }

    private function upsertListing(int $searchId, array $l, float $baseline, float $discount, bool $isDeal): void
    {
        $sql = 'INSERT INTO listings
                (search_id, ebay_item_id, title, price, currency, bid_count, buying_option,
                 end_time, image_url, item_url, item_condition, seller,
                 baseline_price, discount_pct, is_deal, last_seen_at)
                VALUES
                (:search_id, :item_id, :title, :price, :currency, :bids, :buying,
                 :end_time, :image, :url, :cond, :seller,
                 :baseline, :discount, :is_deal, NOW())
                ON DUPLICATE KEY UPDATE
                 title = VALUES(title),
                 price = VALUES(price),
                 currency = VALUES(currency),
                 bid_count = VALUES(bid_count),
                 buying_option = VALUES(buying_option),
                 end_time = VALUES(end_time),
                 image_url = VALUES(image_url),
                 item_url = VALUES(item_url),
                 item_condition = VALUES(item_condition),
                 seller = VALUES(seller),
                 baseline_price = VALUES(baseline_price),
                 discount_pct = VALUES(discount_pct),
                 is_deal = VALUES(is_deal),
                 last_seen_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':search_id' => $searchId,
            ':item_id'   => $l['ebay_item_id'],
            ':title'     => mb_substr($l['title'], 0, 500),
            ':price'     => $l['price'],
            ':currency'  => $l['currency'],
            ':bids'      => $l['bid_count'],
            ':buying'    => $l['buying_option'],
            ':end_time'  => $l['end_time'],
            ':image'     => $l['image_url'],
            ':url'       => $l['item_url'],
            ':cond'      => $l['item_condition'],
            ':seller'    => $l['seller'],
            ':baseline'  => $baseline,
            ':discount'  => $discount,
            ':is_deal'   => $isDeal ? 1 : 0,
        ]);
    }

    /** Persist the AI Opportunity Engine's assessment for one listing. */
    private function saveAi(int $searchId, string $itemId, array $a): void
    {
        $sql = 'UPDATE listings SET
                    ai_verdict = :verdict, ai_confidence = :confidence, ai_card = :card,
                    ai_reason = :reason, ai_flip_pct = :flip, ai_hidden_gem = :gem
                WHERE search_id = :sid AND ebay_item_id = :item';
        $this->pdo->prepare($sql)->execute([
            ':verdict'    => in_array($a['verdict'] ?? '', ['BUY', 'WATCH', 'PASS'], true) ? $a['verdict'] : null,
            ':confidence' => isset($a['confidence']) ? (int)$a['confidence'] : null,
            ':card'       => isset($a['canonical_card']) ? mb_substr((string)$a['canonical_card'], 0, 250) : null,
            ':reason'     => isset($a['reason']) ? mb_substr((string)$a['reason'], 0, 500) : null,
            ':flip'       => isset($a['est_flip_margin_pct']) ? (float)$a['est_flip_margin_pct'] : null,
            ':gem'        => !empty($a['hidden_gem']) ? 1 : 0,
            ':sid'        => $searchId,
            ':item'       => $itemId,
        ]);
    }

    private function markScanned(int $searchId): void
    {
        $this->pdo->prepare('UPDATE searches SET last_scanned_at = NOW() WHERE id = ?')
            ->execute([$searchId]);
    }
}
