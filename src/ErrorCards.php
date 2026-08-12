<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Error-card catalog. Error cards are the purest "hidden gem": the value is
 * invisible in the listing title, so a seller who doesn't know their card is
 * the variant prices it like a common.
 *
 * Entries are drafted (AI or mined from scan history), reviewed by an admin,
 * and only then PUBLISHED — nothing unverified ever reaches a public page.
 * Published entries double as a detector: their search terms are matched
 * against live listing titles to flag "this might be the error variant".
 */
final class ErrorCards
{
    /** Error taxonomy: key => human label. */
    public const TYPES = [
        'photo'      => 'Wrong / flipped photo',
        'name'       => 'Name or spelling error',
        'stats'      => 'Wrong stats or bio',
        'missing'    => 'Missing element (name, logo, position)',
        'color'      => 'Missing or shifted color plate',
        'print'      => 'Print / ink flaw',
        'cut'        => 'Miscut or wrong cropping',
        'foil'       => 'Foil or finish error',
        'serial'     => 'Serial numbering error',
        'auto_relic' => 'Wrong autograph or relic',
        'back'       => 'Wrong or upside-down back',
        'other'      => 'Other variation',
    ];

    /** Which version is scarcer — the value driver for corrected errors. */
    public const SCARCER = [
        'ERROR'     => 'The error is scarcer (corrected mid-run)',
        'CORRECTED' => 'The correction is scarcer',
        'UNKNOWN'   => 'Not established',
    ];

    public const STATUSES = ['DRAFT' => 'Needs review', 'PUBLISHED' => 'Published', 'REJECTED' => 'Rejected'];

    /** Create the catalog table when missing (idempotent). */
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS error_cards (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug             VARCHAR(190) NOT NULL,
                error_name       VARCHAR(190) NOT NULL,
                sport            VARCHAR(32)  DEFAULT NULL,
                year             VARCHAR(10)  DEFAULT NULL,
                set_name         VARCHAR(120) DEFAULT NULL,
                card_number      VARCHAR(40)  DEFAULT NULL,
                player           VARCHAR(120) DEFAULT NULL,
                error_type       VARCHAR(20)  NOT NULL DEFAULT "other",
                description      TEXT DEFAULT NULL,
                what_to_look_for TEXT DEFAULT NULL,
                corrected_exists TINYINT(1) NOT NULL DEFAULT 0,
                scarcer          VARCHAR(12) NOT NULL DEFAULT "UNKNOWN",
                slab_label       VARCHAR(190) DEFAULT NULL,
                premium_note     VARCHAR(255) DEFAULT NULL,
                rarity_note      VARCHAR(255) DEFAULT NULL,
                image_url        VARCHAR(1024) DEFAULT NULL,
                search_terms     VARCHAR(500) DEFAULT NULL,
                confidence       TINYINT UNSIGNED DEFAULT NULL,
                source           VARCHAR(10) NOT NULL DEFAULT "MANUAL",
                status           ENUM("DRAFT","PUBLISHED","REJECTED") NOT NULL DEFAULT "DRAFT",
                views            INT UNSIGNED NOT NULL DEFAULT 0,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_slug (slug),
                KEY idx_status (status, sport)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** URL-safe slug, uniqueness handled by the caller's retry. */
    public static function slugify(string $s): string
    {
        $k = strtolower(trim($s));
        $k = preg_replace('/[^a-z0-9]+/', '-', $k) ?? '';
        return trim(mb_substr($k, 0, 180), '-') ?: 'error-card';
    }

    /** Insert an entry, making the slug unique. Returns the new id. */
    public static function insert(PDO $pdo, array $d): int
    {
        $base = self::slugify(trim(($d['year'] ?? '') . ' ' . ($d['player'] ?? '') . ' ' . ($d['error_name'] ?? '')));
        $slug = $base;
        for ($i = 2; $i < 50; $i++) {
            $chk = $pdo->prepare('SELECT 1 FROM error_cards WHERE slug = ?');
            $chk->execute([$slug]);
            if (!$chk->fetchColumn()) {
                break;
            }
            $slug = $base . '-' . $i;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO error_cards
                (slug, error_name, sport, year, set_name, card_number, player, error_type, description,
                 what_to_look_for, corrected_exists, scarcer, slab_label, premium_note, rarity_note,
                 image_url, search_terms, confidence, source, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $slug,
            mb_substr((string)($d['error_name'] ?? 'Untitled error'), 0, 180),
            $d['sport'] ?? null, $d['year'] ?? null, $d['set_name'] ?? null,
            $d['card_number'] ?? null, $d['player'] ?? null,
            isset(self::TYPES[$d['error_type'] ?? '']) ? $d['error_type'] : 'other',
            $d['description'] ?? null, $d['what_to_look_for'] ?? null,
            !empty($d['corrected_exists']) ? 1 : 0,
            isset(self::SCARCER[$d['scarcer'] ?? '']) ? $d['scarcer'] : 'UNKNOWN',
            $d['slab_label'] ?? null, $d['premium_note'] ?? null, $d['rarity_note'] ?? null,
            $d['image_url'] ?? null,
            mb_substr((string)($d['search_terms'] ?? ''), 0, 490) ?: null,
            isset($d['confidence']) ? max(0, min(100, (int)$d['confidence'])) : null,
            in_array($d['source'] ?? 'MANUAL', ['AI', 'MANUAL', 'MINED', 'MEMBER'], true) ? $d['source'] : 'MANUAL',
            isset(self::STATUSES[$d['status'] ?? '']) ? $d['status'] : 'DRAFT',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Is this player+year already catalogued? Keeps AI drafts from repeating. */
    public static function existingSummaries(PDO $pdo, int $limit = 400): array
    {
        try {
            $rows = $pdo->query(
                'SELECT year, player, error_name FROM error_cards ORDER BY id DESC LIMIT ' . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(fn ($r) => trim(($r['year'] ?? '') . ' ' . ($r['player'] ?? '') . ' — ' . $r['error_name']), $rows);
    }

    /** Published entries, newest first, optionally filtered. */
    public static function published(PDO $pdo, ?string $sport = null, ?string $type = null, ?string $q = null, int $limit = 200): array
    {
        $where = ["status = 'PUBLISHED'"];
        $args  = [];
        if ($sport !== null && $sport !== 'all') {
            $where[] = 'sport = ?';
            $args[] = $sport;
        }
        if ($type !== null && $type !== 'all') {
            $where[] = 'error_type = ?';
            $args[] = $type;
        }
        if ($q !== null && $q !== '') {
            $where[] = '(player LIKE ? OR set_name LIKE ? OR error_name LIKE ? OR description LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM error_cards WHERE ' . implode(' AND ', $where)
                . ' ORDER BY year DESC, player ASC LIMIT ' . (int)$limit
            );
            $stmt->execute($args);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** One published entry by slug (for the public detail page). */
    public static function bySlug(PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM error_cards WHERE slug = ? AND status = 'PUBLISHED'");
            $stmt->execute([$slug]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Match terms for one entry: explicit search_terms plus the player name.
     * All are lowercase; a listing matches when its title contains the player
     * AND at least one distinguishing term.
     */
    private static function terms(array $e): array
    {
        $terms = array_filter(array_map('trim', explode(',', strtolower((string)($e['search_terms'] ?? '')))));
        return array_values($terms);
    }

    /**
     * Scan listing titles for possible error variants. $listings need
     * 'title' (and anything else you want echoed back).
     * Returns [['listing'=>..., 'error'=>entry], ...].
     */
    public static function matchListings(PDO $pdo, array $listings, ?array $entries = null): array
    {
        $entries = $entries ?? self::published($pdo, null, null, null, 500);
        if (!$entries || !$listings) {
            return [];
        }
        $hits = [];
        foreach ($listings as $l) {
            $t = strtolower((string)($l['title'] ?? ''));
            if ($t === '') {
                continue;
            }
            foreach ($entries as $e) {
                $player = strtolower((string)($e['player'] ?? ''));
                // Player must appear — keeps "error" keywords from matching everything.
                if ($player !== '' && !str_contains($t, $player)) {
                    continue;
                }
                $year = (string)($e['year'] ?? '');
                if ($year !== '' && !str_contains($t, $year)) {
                    continue;
                }
                $terms = self::terms($e);
                $hasTerm = !$terms; // no terms configured => player+year is enough
                foreach ($terms as $term) {
                    if ($term !== '' && str_contains($t, $term)) {
                        $hasTerm = true;
                        break;
                    }
                }
                if ($hasTerm) {
                    $hits[] = ['listing' => $l, 'error' => $e];
                    break; // one flag per listing
                }
            }
        }
        return $hits;
    }

    /**
     * Live tracked auctions that match a published catalog entry. Shared by
     * Snap Shot, the member library pages, and the alert email.
     *
     * @param bool $unalertedOnly only auctions not yet emailed about
     */
    public static function liveMatches(PDO $pdo, int $limit = 600, bool $unalertedOnly = false): array
    {
        $entries = self::published($pdo, null, null, null, 500);
        if (!$entries) {
            return [];
        }
        self::ensureListingFlag($pdo);
        try {
            $listings = $pdo->query(
                'SELECT l.id, l.title, l.price, l.bid_count, l.end_time, l.item_url
                 FROM listings l
                 WHERE l.end_time > UTC_TIMESTAMP()'
                . ($unalertedOnly ? ' AND l.error_notified = 0' : '')
                . ' ORDER BY l.end_time ASC LIMIT ' . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        return self::matchListings($pdo, $listings, $entries);
    }

    /** Add the error-alert de-dupe flag to listings (idempotent). */
    public static function ensureListingFlag(PDO $pdo): void
    {
        try {
            $pdo->query('SELECT error_notified FROM listings LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE listings ADD COLUMN error_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER notified');
            } catch (\Throwable $e2) {
                // If ALTER is blocked, alerts simply re-send; nothing breaks.
            }
        }
    }

    /**
     * Email newly-spotted possible error cards. Same discipline as the deal
     * alerts: listings are only marked notified after a successful send, so a
     * mail failure retries on the next scan instead of losing the alert.
     * Returns the number of matches emailed.
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
        $hits = array_slice(self::liveMatches($pdo, 600, true), 0, 10);
        if (!$hits) {
            return 0;
        }
        $n = count($hits);
        $subject = "SportCard101: {$n} possible error card" . ($n === 1 ? '' : 's');
        if (!Mailer::send($to, $subject, self::emailText($hits), self::emailHtml($hits))) {
            return 0; // next scan retries
        }
        $mark = $pdo->prepare('UPDATE listings SET error_notified = 1 WHERE id = ?');
        foreach ($hits as $h) {
            if (isset($h['listing']['id'])) {
                $mark->execute([(int)$h['listing']['id']]);
            }
        }
        return $n;
    }

    /** Alert email — house style, with the identification check front and centre. */
    public static function emailHtml(array $hits): string
    {
        $font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $n = count($hits);
        $intro = $n . ' live auction' . ($n === 1 ? '' : 's') . ' may be a known error card. '
               . 'A title match is a lead, not proof — run the check below against the photos before bidding.';

        $rows = '';
        foreach ($hits as $h) {
            $l = $h['listing'];
            $e = $h['error'];
            $hrs = \hours_until((string)($l['end_time'] ?? ''));
            $ends = $hrs === null ? '' : ' &middot; ends in ~' . ($hrs >= 48 ? round($hrs / 24) . ' days' : round($hrs) . 'h');
            $rows .=
                '<tr><td style="padding:22px 28px;border-top:1px solid #e8e8ed">'
                . '<p style="margin:0;font-size:13px;font-weight:700;color:#b8860b;' . $font . '">' . \e((string)$e['error_name']) . '</p>'
                . '<p style="margin:6px 0 0;font-size:15px;line-height:1.4;font-weight:600;color:#1d1d1f;' . $font . '">' . \e((string)$l['title']) . '</p>'
                . '<p style="margin:6px 0 0;font-size:13px;line-height:1.4;color:#6e6e73;' . $font . '">Now '
                . '<span style="font-weight:600;color:#1d1d1f">$' . number_format((float)$l['price'], 2) . '</span>'
                . ' &middot; ' . (int)($l['bid_count'] ?? 0) . ' bids' . $ends . '</p>'
                . (trim((string)($e['what_to_look_for'] ?? '')) !== ''
                    ? '<p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:#86868b;' . $font . '">'
                      . '<strong style="color:#1d1d1f">Check:</strong> ' . \e((string)$e['what_to_look_for']) . '</p>'
                    : '')
                . '<p style="margin:14px 0 0"><a href="' . \e(\epn_link((string)$l['item_url'])) . '" '
                . 'style="display:inline-block;background:#0071e3;color:#ffffff;font-size:13px;font-weight:600;line-height:1;'
                . 'padding:10px 20px;border-radius:980px;text-decoration:none;' . $font . '">View on eBay</a>'
                . ' &nbsp; <a href="https://sportcard101.com/errors.php?card=' . \e((string)$e['slug']) . '" '
                . 'style="font-size:12px;color:#0071e3;text-decoration:none;' . $font . '">full identification guide &rsaquo;</a></p>'
                . '</td></tr>';
        }

        return
            '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . \e($intro) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7">'
            . '<tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:18px">'
            . '<tr><td style="padding:30px 28px 6px">'
            . '<p style="margin:0;font-size:21px;font-weight:700;letter-spacing:-0.3px;color:#1d1d1f;' . $font . '">SportCard101</p>'
            . '<p style="margin:3px 0 0;font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:#86868b;' . $font . '">Possible Error Cards</p>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 28px 22px">'
            . '<p style="margin:0;font-size:14px;line-height:1.6;color:#1d1d1f;' . $font . '">' . \e($intro) . '</p>'
            . '</td></tr>'
            . $rows
            . '</table>'
            . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#86868b;' . $font . '">'
            . 'Full library on your Error Cards dashboard.<br>&copy; ' . date('Y') . ' SportCard101</p>'
            . '</td></tr></table>';
    }

    /** Plain-text fallback for the error alert email. */
    public static function emailText(array $hits): string
    {
        $n = count($hits);
        $lines = ["{$n} possible error card" . ($n === 1 ? '' : 's') . ' spotted (verify against the photos before bidding):', ''];
        foreach ($hits as $h) {
            $l = $h['listing'];
            $e = $h['error'];
            $lines[] = "• {$e['error_name']}";
            $lines[] = "  {$l['title']}";
            $lines[] = '  Now $' . number_format((float)$l['price'], 2) . ' · ' . (int)($l['bid_count'] ?? 0) . ' bids';
            if (trim((string)($e['what_to_look_for'] ?? '')) !== '') {
                $lines[] = "  Check: {$e['what_to_look_for']}";
            }
            $lines[] = '  View on eBay: ' . \epn_link((string)$l['item_url']);
            $lines[] = '  Guide: https://sportcard101.com/errors.php?card=' . $e['slug'];
            $lines[] = '';
        }
        $lines[] = '— SportCard101 error card alerts';
        return implode("\n", $lines);
    }

    /**
     * Candidate errors hiding in scan history: listing titles that advertise
     * an error/variation. Free discovery from data already collected.
     */
    public static function mineCandidates(PDO $pdo, int $limit = 40): array
    {
        $like = ['%error%', '%misprint%', '%no name on front%', '%upside down%', '%wrong back%', '%variation%', '%missing name%'];
        $sql  = implode(' OR ', array_fill(0, count($like), 'l.title LIKE ?'));
        try {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT l.title, l.price, l.item_url, s.keywords AS sport
                 FROM listings l JOIN searches s ON s.id = l.search_id
                 WHERE ($sql)
                 ORDER BY l.last_seen_at DESC LIMIT " . (int)$limit
            );
            $stmt->execute($like);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
