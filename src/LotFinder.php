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
                notified      TINYINT(1) NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_item (ebay_item_id),
                KEY idx_end (end_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Self-migrate: alert de-dupe flag for installs created before it existed.
        try {
            $pdo->query('SELECT notified FROM lots LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE lots ADD COLUMN notified TINYINT(1) NOT NULL DEFAULT 0 AFTER analyzed_at');
            } catch (\Throwable $e2) {
            }
        }
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

    /**
     * Email un-notified BUY-verdict lots (same gates and retry semantics as
     * the deal alerts: only marked notified after a successful send).
     * Returns the number of lots alerted.
     */
    public static function alert(PDO $pdo): int
    {
        if ((string) \setting('notify_enabled', '0') !== '1') {
            return 0;
        }
        $to = trim((string) \setting('notify_email', ''));
        if ($to === '') {
            return 0;
        }
        try {
            $lots = $pdo->query(
                "SELECT * FROM lots
                 WHERE ai_verdict = 'BUY' AND notified = 0 AND end_time > UTC_TIMESTAMP()
                 ORDER BY end_time ASC LIMIT 10"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0;
        }
        if (!$lots) {
            return 0;
        }
        $n = count($lots);
        $subject = "SportCard101: {$n} bulk lot deal" . ($n === 1 ? '' : 's');
        if (!Mailer::send($to, $subject, self::emailText($lots), self::emailHtml($lots))) {
            return 0; // next scan retries
        }
        $mark = $pdo->prepare('UPDATE lots SET notified = 1 WHERE id = ?');
        foreach ($lots as $l) {
            $mark->execute([(int)$l['id']]);
        }
        return $n;
    }

    /** Live lots worth surfacing (BUY first, then WATCH, ending soonest). */
    public static function worthALook(PDO $pdo, int $limit = 8): array
    {
        try {
            return $pdo->query(
                "SELECT * FROM lots
                 WHERE ai_verdict IN ('BUY','WATCH') AND end_time > UTC_TIMESTAMP()
                 ORDER BY FIELD(ai_verdict,'BUY','WATCH'), end_time ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Plain-English buy recommendation for a lot. The suggested max is 55% of
     * the LOW estimate — the haircut covers ~13% fees on every card, shipping
     * each one, and the fact that title-based estimates oversell.
     *
     * @return array{max: ?float, label: string, tone: string} tone: buy|maybe|skip
     */
    public static function recommendation(array $l): array
    {
        $estLow = $l['ai_est_low'] !== null ? (float)$l['ai_est_low'] : 0.0;
        $price  = (float) $l['price'];
        if (($l['ai_verdict'] ?? null) === null || $estLow <= 0) {
            return ['max' => null, 'label' => 'Not valued yet — wait for the next sweep.', 'tone' => 'skip'];
        }
        $max = floor($estLow * 0.55 * 100) / 100;
        if ($l['ai_verdict'] === 'PASS' || $max < 10) {
            return ['max' => null, 'label' => 'Skip — no edge at any realistic bid.', 'tone' => 'skip'];
        }
        if ($price > $max) {
            return ['max' => $max, 'label' => sprintf('Already past our number — worth it only under $%s.', number_format($max, 2)), 'tone' => 'skip'];
        }
        if ($l['ai_verdict'] === 'BUY') {
            return ['max' => $max, 'label' => sprintf('✅ Worth buying at ≤ $%s if the photos match the title.', number_format($max, 2)), 'tone' => 'buy'];
        }
        return ['max' => $max, 'label' => sprintf('🔍 Maybe — inspect the photos first; only bid at ≤ $%s.', number_format($max, 2)), 'tone' => 'maybe'];
    }

    /** Fields both email formats share for one lot row. */
    public static function displayFields(array $l): array
    {
        $cards = $l['est_cards'] !== null ? (int)$l['est_cards'] : null;
        $hrs   = \hours_until((string)$l['end_time']);
        return [
            'title'    => (string) $l['title'],
            'price'    => '$' . number_format((float)$l['price'], 2),
            'bids'     => $l['bid_count'] !== null ? (int)$l['bid_count'] : 0,
            'ends'     => $hrs === null ? '' : ($hrs >= 48 ? '~' . round($hrs / 24) . ' days' : '~' . round($hrs) . 'h'),
            'cards'    => $cards,
            'per_card' => ($cards && $cards > 0) ? '$' . number_format((float)$l['price'] / $cards, 2) : null,
            'est'      => ($l['ai_est_low'] !== null && $l['ai_est_high'] !== null && (float)$l['ai_est_high'] > 0)
                ? '$' . number_format((float)$l['ai_est_low'], 0) . '–$' . number_format((float)$l['ai_est_high'], 0)
                : null,
            'reason'   => (string) ($l['ai_reason'] ?? ''),
            'url'      => \epn_link((string)$l['item_url']),
        ];
    }

    /** One HTML block per lot — used by the alert email and the playbook email. */
    public static function emailRows(array $lots, string $font): string
    {
        $rows = '';
        foreach ($lots as $l) {
            $d = self::displayFields($l);
            $meta = 'Now <span style="font-weight:600;color:#1d1d1f">' . \e($d['price']) . '</span>'
                . ' &middot; ' . $d['bids'] . ' bid' . ($d['bids'] === 1 ? '' : 's')
                . ($d['ends'] !== '' ? ' &middot; ends in ' . \e($d['ends']) : '')
                . ($d['cards'] !== null ? ' &middot; ~' . $d['cards'] . ' cards' : '')
                . ($d['per_card'] !== null ? ' &middot; <span style="font-weight:600;color:#1d1d1f">' . \e($d['per_card']) . '/card</span>' : '');
            $rows .=
                '<tr><td style="padding:22px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:15px;line-height:1.4;font-weight:600;color:#1d1d1f;' . $font . '">' . \e($d['title']) . '</p>'
                . '<p style="margin:6px 0 0;font-size:13px;line-height:1.4;color:#6e6e73;' . $font . '">' . $meta . '</p>'
                . ($d['est'] !== null
                    ? '<p style="margin:8px 0 0;font-size:13px;font-weight:700;color:#1d7d46;' . $font . '">Est. break-up value: ' . \e($d['est']) . '</p>'
                    : '')
                . ($d['reason'] !== ''
                    ? '<p style="margin:8px 0 0;font-size:12px;line-height:1.5;color:#86868b;' . $font . '">' . \e($d['reason']) . '</p>'
                    : '')
                . (function () use ($l, $font): string {
                    $rec = self::recommendation($l);
                    $color = match ($rec['tone']) { 'buy' => '#1d7d46', 'maybe' => '#b8860b', default => '#6e6e73' };
                    return '<p style="margin:8px 0 0;font-size:13px;font-weight:700;color:' . $color . ';' . $font . '">' . \e($rec['label']) . '</p>';
                })()
                . '<p style="margin:14px 0 0"><a href="' . \e($d['url']) . '" '
                . 'style="display:inline-block;background:#0071e3;color:#ffffff;font-size:13px;font-weight:600;line-height:1;'
                . 'padding:10px 20px;border-radius:980px;text-decoration:none;' . $font . '">View on eBay</a></p>'
                . '</td></tr>';
        }
        return $rows;
    }

    /** Standalone lot email (30-min BUY alerts and the daily digest). */
    public static function emailHtml(array $lots, string $heading = 'Bulk Lot Alerts', ?string $intro = null): string
    {
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $n = count($lots);
        $intro = $intro ?? ($n . ' bulk lot' . ($n === 1 ? '' : 's') . ' where the current bid sits under the AI\'s break-up estimate. '
               . 'Estimates come from titles only — inspect the photos before bidding.');
        return
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . \e($intro) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7">'
            . '<tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:18px">'
            . '<tr><td style="padding:30px 28px 6px">'
            . '<p style="margin:0;font-size:21px;font-weight:700;letter-spacing:-0.3px;color:#1d1d1f;' . $font . '">SportCard101</p>'
            . '<p style="margin:3px 0 0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">' . \e($heading) . '</p>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 28px 22px">'
            . '<p style="margin:0;font-size:14px;line-height:1.6;color:#1d1d1f;' . $font . '">' . \e($intro) . '</p>'
            . '</td></tr>'
            . self::emailRows($lots, $font)
            . '</table>'
            . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#86868b;' . $font . '">'
            . 'Full lot list is on your Lots dashboard.<br>&copy; ' . date('Y') . ' SportCard101 &middot; Bulk Lots</p>'
            . '</td></tr></table>';
    }

    /** Plain-text fallback for the lot alert email. */
    public static function emailText(array $lots): string
    {
        $n = count($lots);
        $lines = ["{$n} bulk lot" . ($n === 1 ? '' : 's') . ' worth a look (verify photos before bidding):', ''];
        foreach ($lots as $l) {
            $d = self::displayFields($l);
            $lines[] = "• {$d['title']}";
            $lines[] = "  Now {$d['price']} · {$d['bids']} bids"
                . ($d['ends'] !== '' ? " · ends in {$d['ends']}" : '')
                . ($d['cards'] !== null ? " · ~{$d['cards']} cards" : '')
                . ($d['per_card'] !== null ? " · {$d['per_card']}/card" : '');
            if ($d['est'] !== null) {
                $lines[] = "  Est. break-up value: {$d['est']}";
            }
            if ($d['reason'] !== '') {
                $lines[] = "  {$d['reason']}";
            }
            $lines[] = '  ' . self::recommendation($l)['label'];
            $lines[] = "  View on eBay: {$d['url']}";
            $lines[] = '';
        }
        $lines[] = '— SportCard101 bulk lot alerts';
        return implode("\n", $lines);
    }

    /**
     * The daily Bulk Auctions digest — its own email, separate from the
     * Morning Playbook: every live BUY/WATCH lot with a buy recommendation.
     * Returns the number of lots emailed (0 = nothing to send / not sent).
     */
    public static function dailyDigest(PDO $pdo): int
    {
        $to = trim((string) \setting('notify_email', ''));
        if ($to === '') {
            return 0;
        }
        $lots = self::worthALook($pdo, 8);
        if (!$lots) {
            return 0;
        }
        $n = count($lots);
        $buys = count(array_filter($lots, fn ($l) => $l['ai_verdict'] === 'BUY'));
        $subject = 'Bulk Auctions — ' . date('D, M j') . ': '
                 . ($buys > 0 ? "{$buys} worth buying, " . ($n - $buys) . ' to inspect' : "{$n} to inspect");
        $intro = 'Today\'s bulk-lot picture: ' . $n . ' live lot' . ($n === 1 ? '' : 's')
               . ' worth your attention. Each has a hard number — bid at or under it after checking the photos, or walk.';
        $ok = Mailer::send($to, $subject, self::emailText($lots), self::emailHtml($lots, 'Bulk Auctions · Daily Digest', $intro));
        return $ok ? $n : 0;
    }
}
