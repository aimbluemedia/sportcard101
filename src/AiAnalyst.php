<?php
declare(strict_types=1);

namespace SportCard101;

/**
 * AI Opportunity Engine — uses Claude to assess sports-card listings for
 * buy/sell opportunities that are hard to spot without AI:
 *
 *   - normalises messy eBay titles to a canonical card identity (so comps mean
 *     something),
 *   - judges whether a listing is a genuine deal vs. the computed market
 *     baseline,
 *   - flags "hidden gems" (mislabeled / miscategorised / missing key terms that
 *     keep a listing cheap), and
 *   - estimates flip margin after eBay fees, with a beginner-friendly reason.
 *
 * Calls the Anthropic Messages API directly via cURL (this project keeps zero
 * Composer dependencies — the eBay client uses cURL too). Uses structured
 * outputs so the response is guaranteed-parseable JSON. With no API key
 * configured it falls back to deterministic heuristic scoring (MOCK mode).
 *
 * Docs: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
 */
final class AiAnalyst
{
    private bool $mock;

    public function __construct(private array $cfg)
    {
        $this->mock = empty($cfg['api_key']);
    }

    public function isMock(): bool
    {
        return $this->mock;
    }

    /**
     * Assess a batch of deal-candidate listings.
     *
     * @param array<int,array<string,mixed>> $listings Each: ebay_item_id, title,
     *        price, currency, baseline_price, discount_pct, buying_option, bid_count.
     * @param array{keywords:string,grade:string} $context The saved search.
     * @return array<string,array<string,mixed>> Keyed by ebay_item_id.
     */
    public function analyze(array $listings, array $context): array
    {
        if (!$listings) {
            return [];
        }
        // Cost control — cap how many we send per scan.
        $cap = (int)($this->cfg['max_per_scan'] ?? 15);
        $listings = array_slice($listings, 0, $cap);

        $results = $this->mock
            ? $this->heuristic($listings)
            : $this->callClaude($listings, $context);

        // Key by item id for easy lookup by the caller.
        $byId = [];
        foreach ($results as $r) {
            if (!empty($r['ebay_item_id'])) {
                $byId[$r['ebay_item_id']] = $r;
            }
        }
        return $byId;
    }

    // ------------------------------------------------------------------ AI path

    private function callClaude(array $listings, array $context): array
    {
        $system =
            "You are a sports-card investing analyst for BEGINNERS. You are given active eBay " .
            "listings for a graded card search, each with a computed market baseline (the median " .
            "price of comparable active listings). For each listing decide whether it is a buy/sell " .
            "OPPORTUNITY.\n\n" .
            "For each listing:\n" .
            "- canonical_card: normalise the messy title to a clean identity " .
            "(year, set, player, card #, grade). This lets prices be compared meaningfully.\n" .
            "- verdict: BUY (clearly underpriced vs baseline, worth bidding), WATCH (borderline), " .
            "or PASS (fair/overpriced or too risky).\n" .
            "- confidence: 0-100.\n" .
            "- hidden_gem: true if it is likely underpriced because the listing is weak " .
            "(misspelled player/set, miscategorised, missing 'rookie'/grade in the title, poor " .
            "wording) — the kind of bargain normal keyword searches miss.\n" .
            "- est_flip_margin_pct: rough profit margin if bought at this price and resold at the " .
            "baseline, AFTER ~13% eBay fees and shipping. Can be negative.\n" .
            "- reason: ONE short, plain-English sentence a beginner understands.\n\n" .
            "Be skeptical: an auction with hours left and few bids may rise. Do not call something a " .
            "BUY just because it is cheap if the card looks off or the price is fair.";

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['analyses'],
            'properties' => [
                'analyses' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'ebay_item_id', 'canonical_card', 'verdict',
                            'confidence', 'hidden_gem', 'est_flip_margin_pct', 'reason',
                        ],
                        'properties' => [
                            'ebay_item_id'        => ['type' => 'string'],
                            'canonical_card'      => ['type' => 'string'],
                            'verdict'             => ['type' => 'string', 'enum' => ['BUY', 'WATCH', 'PASS']],
                            'confidence'          => ['type' => 'integer'],
                            'hidden_gem'          => ['type' => 'boolean'],
                            'est_flip_margin_pct' => ['type' => 'number'],
                            'reason'              => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $payload = [
            'search'   => $context,
            'listings' => array_map(fn ($l) => [
                'ebay_item_id'   => $l['ebay_item_id'],
                'title'          => $l['title'],
                'price'          => (float)$l['price'],
                'baseline_price' => (float)($l['baseline_price'] ?? 0),
                'discount_pct'   => (float)($l['discount_pct'] ?? 0),
                'buying_option'  => $l['buying_option'] ?? null,
                'bid_count'      => $l['bid_count'] ?? null,
            ], $listings),
        ];

        $body = [
            'model'      => $this->cfg['model'] ?? 'claude-opus-4-8',
            'max_tokens' => 4000,
            'system'     => $system,
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'messages' => [[
                'role'    => 'user',
                'content' => "Assess these listings:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]],
        ];

        try {
            $resp = $this->httpPost('https://api.anthropic.com/v1/messages', $body);
        } catch (\Throwable $e) {
            // Never let an AI hiccup break a scan — degrade to heuristics.
            return $this->heuristic($listings);
        }

        $data = json_decode($resp, true);
        // Structured output lands in the first text block as JSON.
        $text = null;
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = $block['text'];
                break;
            }
        }
        $parsed = $text !== null ? json_decode($text, true) : null;
        if (!is_array($parsed) || !isset($parsed['analyses'])) {
            return $this->heuristic($listings);
        }
        return $parsed['analyses'];
    }

    /**
     * Assess bulk-lot auctions from their TITLES: how many cards, rough total
     * value, and whether the current bid makes it worth a look. Returns a map
     * keyed by ebay_item_id: verdict, est_card_count, est_value_low/high, reason.
     *
     * @param array<int,array<string,mixed>> $lots Each: ebay_item_id, title, price, bid_count, est_cards.
     */
    public function analyzeLots(array $lots): array
    {
        if (!$lots) {
            return [];
        }
        $lots = array_slice($lots, 0, (int)($this->cfg['max_per_scan'] ?? 15));

        if ($this->mock) {
            return $this->heuristicLots($lots);
        }

        $system =
            "You are a sports-card expert valuing eBay AUCTION LOTS of graded (mostly PSA) cards for a " .
            "flipper, from the listing TITLE ONLY. Be conservative — titles oversell.\n\n" .
            "For each lot:\n" .
            "- est_card_count: cards in the lot (0 if the title doesn't say).\n" .
            "- est_value_low / est_value_high: conservative USD resale range for the WHOLE lot if broken " .
            "up and sold individually. Recognisable stars/rookies/low pop raise it; vague titles " .
            "('mystery', 'random', unnamed players) mean assume near-floor commons (graded commons " .
            "often resell for only \$10-20 each).\n" .
            "- verdict vs the current bid: BUY (bid clearly below est_value_low, real margin after " .
            "~13% fees and shipping on EVERY card when reselling), WATCH (borderline or needs the " .
            "photos checked), PASS (fair, rich, or unknowable).\n" .
            "- reason: ONE beginner-friendly sentence, name the key cards if any.\n" .
            "Mystery/repack lots are gambling, not investing: verdict PASS.";

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['lots'],
            'properties' => [
                'lots' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['ebay_item_id', 'verdict', 'est_card_count', 'est_value_low', 'est_value_high', 'reason'],
                        'properties' => [
                            'ebay_item_id'   => ['type' => 'string'],
                            'verdict'        => ['type' => 'string', 'enum' => ['BUY', 'WATCH', 'PASS']],
                            'est_card_count' => ['type' => 'integer'],
                            'est_value_low'  => ['type' => 'number'],
                            'est_value_high' => ['type' => 'number'],
                            'reason'         => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $payload = array_map(fn ($l) => [
            'ebay_item_id' => (string)$l['ebay_item_id'],
            'title'        => (string)$l['title'],
            'current_bid'  => (float)$l['price'],
            'bid_count'    => $l['bid_count'],
            'parsed_count' => $l['est_cards'],
        ], $lots);

        $body = [
            'model'      => $this->cfg['model'] ?? 'claude-opus-4-8',
            'max_tokens' => 3000,
            'system'     => $system,
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'messages' => [[
                'role'    => 'user',
                'content' => "Value these lot auctions:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]],
        ];

        try {
            $resp = $this->httpPost('https://api.anthropic.com/v1/messages', $body);
        } catch (\Throwable $e) {
            return $this->heuristicLots($lots);
        }
        $data = json_decode($resp, true);
        $text = null;
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = $block['text'];
                break;
            }
        }
        $parsed = $text !== null ? json_decode($text, true) : null;
        if (!is_array($parsed) || !isset($parsed['lots'])) {
            return $this->heuristicLots($lots);
        }
        $byId = [];
        foreach ($parsed['lots'] as $r) {
            if (!empty($r['ebay_item_id'])) {
                $byId[(string)$r['ebay_item_id']] = $r;
            }
        }
        return $byId;
    }

    /** No-API fallback: floor-value lots at ~$12/card when the count is known. */
    private function heuristicLots(array $lots): array
    {
        $out = [];
        foreach ($lots as $l) {
            $n     = (int) ($l['est_cards'] ?? 0);
            $price = (float) $l['price'];
            $low   = $n > 0 ? $n * 8.0 : 0.0;
            $high  = $n > 0 ? $n * 18.0 : 0.0;
            $verdict = 'PASS';
            $reason  = 'Card count unclear from the title — open the photos to judge.';
            if ($n > 0) {
                $perCard = $price / max(1, $n);
                $verdict = $perCard < 6.0 ? 'WATCH' : 'PASS';
                $reason  = sprintf('~%d graded cards at $%.2f/card vs a rough $8–18/card floor — %s',
                    $n, $perCard, $verdict === 'WATCH' ? 'cheap enough to inspect the photos.' : 'no obvious edge at this bid.');
            }
            $out[(string)$l['ebay_item_id']] = [
                'verdict' => $verdict, 'est_card_count' => $n,
                'est_value_low' => round($low, 2), 'est_value_high' => round($high, 2),
                'reason' => $reason,
            ];
        }
        return $out;
    }

    /**
     * Write the Morning Playbook narrative: a short market summary and an
     * optional coaching note per buy target. The plan's numbers (max bids,
     * margins) are computed deterministically by Playbook — the AI only adds
     * context, it never changes the math. Returns null in mock mode or on any
     * API failure so the playbook degrades gracefully.
     *
     * @param array $payload date, budget, targets[], watch[], heat[]
     * @return array{summary: string, notes: array<string,string>}|null
     */
    public function planNarrative(array $payload): ?array
    {
        if ($this->mock) {
            return null;
        }

        $system =
            "You are a veteran sports-card flipper writing a SHORT morning briefing for a beginner " .
            "with a small bankroll. You get today's buy targets (each with a pre-computed max bid " .
            "derived from real sold comps), a watchlist, and a list of auctions drawing heavy bidding " .
            "(market heat).\n\n" .
            "- summary: 2-3 plain-English sentences. What kind of day is it? Where is demand hot? " .
            "Remind them of the one discipline that matters most today. Never promise profit.\n" .
            "- notes: for each buy target, AT MOST one short sentence of genuinely useful coaching " .
            "(e.g. why this comp is trustworthy, what to double-check in the photos, when to place " .
            "the bid). Skip a target if you have nothing beyond the numbers it already shows.\n" .
            "Do not invent prices or change any max bid.";

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'notes'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'notes'   => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['ebay_item_id', 'note'],
                        'properties' => [
                            'ebay_item_id' => ['type' => 'string'],
                            'note'         => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $body = [
            'model'      => $this->cfg['model'] ?? 'claude-opus-4-8',
            'max_tokens' => 1500,
            'system'     => $system,
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'messages' => [[
                'role'    => 'user',
                'content' => "Today's plan data:\n" . json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]],
        ];

        try {
            $resp = $this->httpPost('https://api.anthropic.com/v1/messages', $body);
        } catch (\Throwable $e) {
            return null;
        }
        $data = json_decode($resp, true);
        $text = null;
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = $block['text'];
                break;
            }
        }
        $parsed = $text !== null ? json_decode($text, true) : null;
        if (!is_array($parsed) || !isset($parsed['summary'])) {
            return null;
        }
        $notes = [];
        foreach (($parsed['notes'] ?? []) as $n) {
            if (!empty($n['ebay_item_id']) && !empty($n['note'])) {
                $notes[(string)$n['ebay_item_id']] = (string) $n['note'];
            }
        }
        return ['summary' => (string) $parsed['summary'], 'notes' => $notes];
    }

    private function httpPost(string $url, array $body): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->cfg['api_key'],
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 90,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('Anthropic request failed: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException('Anthropic API error (HTTP ' . $code . '): ' . $resp);
        }
        return $resp;
    }

    // ----------------------------------------------------------- Heuristic path

    /**
     * Deterministic fallback so the engine works with no API key. Uses the
     * discount vs. baseline; tags likely "hidden gems" when the title is missing
     * obvious value terms (a real signal that a listing is underexposed).
     */
    private function heuristic(array $listings): array
    {
        $out = [];
        foreach ($listings as $l) {
            $discount = (float)($l['discount_pct'] ?? 0);
            $title    = strtolower((string)($l['title'] ?? ''));

            $verdict = $discount >= 35 ? 'BUY' : ($discount >= 20 ? 'WATCH' : 'PASS');
            $confidence = (int)max(40, min(95, 45 + $discount));

            // A listing missing "rookie"/"rc" or the grade in its title tends to
            // attract fewer bidders — a classic overlooked-bargain signal.
            $missingTerms = !str_contains($title, 'rookie') && !str_contains($title, ' rc');
            $hiddenGem    = $discount >= 30 && $missingTerms;

            $flip = round($discount - 13.0, 1); // ~13% eBay fees

            $reason = match ($verdict) {
                'BUY'   => sprintf('About %d%% below the going rate — strong value if the card checks out.', (int)$discount),
                'WATCH' => sprintf('Roughly %d%% under market; worth watching as the auction closes.', (int)$discount),
                default => 'Priced close to market — little room to flip for profit.',
            };

            $out[] = [
                'ebay_item_id'        => $l['ebay_item_id'],
                'canonical_card'      => trim((string)($l['title'] ?? '')),
                'verdict'             => $verdict,
                'confidence'          => $confidence,
                'hidden_gem'          => $hiddenGem,
                'est_flip_margin_pct' => $flip,
                'reason'              => $reason,
            ];
        }
        return $out;
    }
}
