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
