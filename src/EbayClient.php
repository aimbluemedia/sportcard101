<?php
declare(strict_types=1);

namespace Sportscard101;

/**
 * Minimal client for the eBay Browse API (item_summary/search).
 *
 * Uses the OAuth2 client-credentials grant to obtain an application token,
 * then queries active listings. If no credentials are configured, it falls
 * back to deterministic MOCK data so the app is fully usable offline.
 *
 * Docs: https://developer.ebay.com/api-docs/buy/browse/resources/item_summary/methods/search
 */
final class EbayClient
{
    private string $apiBase;
    private string $oauthBase;
    private bool $mock;

    public function __construct(private array $cfg)
    {
        $sandbox = ($cfg['environment'] ?? 'production') === 'sandbox';
        $this->apiBase   = $sandbox ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
        $this->oauthBase = $this->apiBase;
        $this->mock      = empty($cfg['client_id']) || empty($cfg['client_secret']);
    }

    public function isMock(): bool
    {
        return $this->mock;
    }

    /**
     * Search active eBay listings.
     *
     * @param string $query        Free-text query (keywords).
     * @param string $grade        e.g. "PSA 10" — appended to the query.
     * @param string $buyingOption AUCTION | FIXED_PRICE | ANY
     * @param int    $limit        Max results.
     * @return array<int,array<string,mixed>> Normalised listing rows.
     */
    public function search(string $query, string $grade, string $buyingOption = 'AUCTION', int $limit = 100): array
    {
        $fullQuery = trim($grade . ' ' . $query);

        if ($this->mock) {
            return $this->mockListings($fullQuery, $buyingOption, $limit);
        }

        $token = $this->token();
        $limit = max(1, min($limit, 200));

        $params = [
            'q'             => $fullQuery,
            'limit'         => (string)$limit,
            // Sports trading cards category for tighter results.
            'category_ids'  => '212',
        ];

        $filters = [];
        if ($buyingOption !== 'ANY') {
            $filters[] = 'buyingOptions:{' . $buyingOption . '}';
        }
        if ($filters) {
            $params['filter'] = implode(',', $filters);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'X-EBAY-C-MARKETPLACE-ID: ' . ($this->cfg['marketplace'] ?? 'EBAY_US'),
            'Content-Type: application/json',
        ];
        if (!empty($this->cfg['campaign_id'])) {
            $headers[] = 'X-EBAY-C-ENDUSERCTX: affiliateCampaignId=' . $this->cfg['campaign_id'];
        }

        $url  = $this->apiBase . '/buy/browse/v1/item_summary/search?' . http_build_query($params);
        $resp = $this->httpGet($url, $headers);
        $data = json_decode($resp, true);

        if (!is_array($data) || !isset($data['itemSummaries'])) {
            return [];
        }

        return array_map([$this, 'normalize'], $data['itemSummaries']);
    }

    /** Obtain (and cache for the request) an application OAuth token. */
    private function token(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $url  = $this->oauthBase . '/identity/v1/oauth2/token';
        $auth = base64_encode($this->cfg['client_id'] . ':' . $this->cfg['client_secret']);
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'https://api.ebay.com/oauth/api_scope',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('eBay OAuth request failed: ' . $err);
        }
        $data = json_decode($resp, true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('eBay OAuth error (HTTP ' . $code . '): ' . $resp);
        }

        return $cached = $data['access_token'];
    }

    private function httpGet(string $url, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('eBay API request failed: ' . $err);
        }
        return $resp;
    }

    /** Map an eBay itemSummary into our normalised listing shape. */
    private function normalize(array $item): array
    {
        $price    = $item['price']['value'] ?? null;
        $currency = $item['price']['currency'] ?? 'USD';

        $buying = $item['buyingOptions'][0] ?? null;
        $bids   = null;
        $end    = null;
        if (isset($item['currentBidPrice']['value'])) {
            $price    = $item['currentBidPrice']['value'];
            $currency = $item['currentBidPrice']['currency'] ?? $currency;
        }
        if (isset($item['bidCount'])) {
            $bids = (int)$item['bidCount'];
        }
        if (isset($item['itemEndDate'])) {
            $end = $this->toMysqlDate($item['itemEndDate']);
        }

        return [
            'ebay_item_id'   => (string)($item['itemId'] ?? $item['legacyItemId'] ?? ''),
            'title'          => (string)($item['title'] ?? ''),
            'price'          => $price !== null ? (float)$price : 0.0,
            'currency'       => $currency,
            'bid_count'      => $bids,
            'buying_option'  => $buying,
            'end_time'       => $end,
            'image_url'      => $item['image']['imageUrl'] ?? ($item['thumbnailImages'][0]['imageUrl'] ?? null),
            'item_url'       => (string)($item['itemWebUrl'] ?? $item['itemAffiliateWebUrl'] ?? ''),
            'item_condition' => $item['condition'] ?? null,
            'seller'         => $item['seller']['username'] ?? null,
        ];
    }

    private function toMysqlDate(string $iso): ?string
    {
        try {
            return (new \DateTime($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Deterministic sample data for MOCK mode. Produces a realistic spread of
     * prices around a baseline so deal-detection has something to chew on.
     */
    private function mockListings(string $query, string $buyingOption, int $limit): array
    {
        $seed = crc32($query);
        mt_srand($seed);

        $base = 180 + ($seed % 320); // baseline market price for this "card"
        $n    = min($limit, 12);
        $out  = [];

        for ($i = 0; $i < $n; $i++) {
            // Most listings cluster near the baseline; a couple are bargains.
            $factor = match (true) {
                $i === 1 => 0.55,             // strong deal
                $i === 4 => 0.68,             // moderate deal
                default  => 0.9 + (mt_rand(0, 40) / 100), // 0.90–1.30x
            };
            $price = round($base * $factor, 2);
            $end   = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->modify('+' . mt_rand(2, 96) . ' hours')
                ->format('Y-m-d H:i:s');

            $out[] = [
                'ebay_item_id'   => 'MOCK-' . $seed . '-' . $i,
                'title'          => $query . ' #' . (mt_rand(1, 350)) . ' Gem Mint',
                'price'          => $price,
                'currency'       => 'USD',
                'bid_count'      => $buyingOption === 'FIXED_PRICE' ? null : mt_rand(0, 24),
                'buying_option'  => $buyingOption === 'ANY' ? 'AUCTION' : $buyingOption,
                'end_time'       => $end,
                'image_url'      => 'https://placehold.co/120x160?text=PSA+10',
                'item_url'       => 'https://www.ebay.com/itm/' . abs($seed) . $i,
                'item_condition' => 'Graded',
                'seller'         => 'sample_seller_' . ($i % 4),
            ];
        }
        mt_srand(); // restore randomness
        return $out;
    }
}
