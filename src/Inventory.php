<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Member card inventory ("My Collection"). Every member owns their rows;
 * cards are keyed the same way as the comps engine so the collection can be
 * marked to market against real tracked sales. Phase 1: CRUD + lifecycle +
 * on-page valuation. Phase 2 adds nightly revaluation, snapshots (table
 * created now), and sell-signal emails.
 */
final class Inventory
{
    public const STATUSES = ['RAW' => 'Raw', 'AT_GRADER' => 'At grader', 'GRADED' => 'Graded', 'LISTED' => 'Listed', 'SOLD' => 'Sold'];
    public const COMPANIES = ['PSA', 'BGS', 'SGC', 'CGC', 'RAW'];

    /** Create the inventory tables when missing (idempotent, self-migrating). */
    public static function ensureTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id       INT UNSIGNED NOT NULL,
                card_name     VARCHAR(255) NOT NULL,
                sport         VARCHAR(32)  DEFAULT NULL,
                year          VARCHAR(10)  DEFAULT NULL,
                set_name      VARCHAR(120) DEFAULT NULL,
                player        VARCHAR(120) DEFAULT NULL,
                card_number   VARCHAR(40)  DEFAULT NULL,
                parallel      VARCHAR(120) DEFAULT NULL,
                grade_company VARCHAR(8)   NOT NULL DEFAULT "PSA",
                grade         VARCHAR(8)   DEFAULT NULL,
                cert_number   VARCHAR(40)  DEFAULT NULL,
                card_cost     DECIMAL(10,2) NOT NULL DEFAULT 0,
                ship_cost     DECIMAL(10,2) NOT NULL DEFAULT 0,
                purchase_source VARCHAR(120) DEFAULT NULL,
                purchased_at  DATE DEFAULT NULL,
                status        ENUM("RAW","AT_GRADER","GRADED","LISTED","SOLD") NOT NULL DEFAULT "GRADED",
                list_price    DECIMAL(10,2) DEFAULT NULL,
                listed_at     DATE DEFAULT NULL,
                sold_price    DECIMAL(10,2) DEFAULT NULL,
                sold_fees     DECIMAL(10,2) DEFAULT NULL,
                sold_ship     DECIMAL(10,2) DEFAULT NULL,
                sold_at       DATE DEFAULT NULL,
                location      VARCHAR(120) DEFAULT NULL,
                image_url     VARCHAR(1024) DEFAULT NULL,
                notes         VARCHAR(1000) DEFAULT NULL,
                card_key      VARCHAR(255) DEFAULT NULL,
                base_key      VARCHAR(255) DEFAULT NULL,
                est_value     DECIMAL(10,2) DEFAULT NULL,
                est_at        DATETIME DEFAULT NULL,
                sell_signal_at DATETIME DEFAULT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (user_id, status),
                CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_snapshots (
                id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id   INT UNSIGNED NOT NULL,
                snap_date DATE NOT NULL,
                cards     INT NOT NULL DEFAULT 0,
                cost      DECIMAL(12,2) NOT NULL DEFAULT 0,
                est_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_day (user_id, snap_date),
                CONSTRAINT fk_snap_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Comp-matching keys for a card name. */
    public static function keysFor(string $cardName): array
    {
        return [Comps::cardKey($cardName), Playbook::baseCardKey($cardName)];
    }

    /**
     * Attach a live comp valuation to each row (PSA-graded cards only — that's
     * what the comps engine tracks). Adds 'live_value' and 'live_comp_count'
     * keys; null when no trustworthy comp exists yet.
     */
    public static function value(PDO $pdo, array $rows): array
    {
        $cards = [];
        foreach ($rows as $r) {
            if ($r['grade_company'] === 'PSA' && $r['grade'] !== null && $r['grade'] !== '' && $r['card_key']) {
                $cards[] = ['sport' => $r['sport'], 'grade' => 'PSA ' . $r['grade'], 'key' => (string)$r['card_key']];
            }
        }
        $stats = $cards ? Comps::statsForCards($pdo, $cards, 90) : [];

        foreach ($rows as &$r) {
            $r['live_value'] = null;
            $r['live_comp_count'] = null;
            if ($r['grade_company'] === 'PSA' && $r['grade'] !== null && $r['grade'] !== '' && $r['card_key']) {
                $s = $stats[$r['sport'] . '|PSA ' . $r['grade'] . '|' . $r['card_key']] ?? null;
                if ($s && $s['count'] >= Comps::MIN_FOR_BASELINE) {
                    $r['live_value']      = (float) $s['median'];
                    $r['live_comp_count'] = (int) $s['count'];
                }
            }
        }
        unset($r);
        return $rows;
    }

    /** All-in acquisition cost for a row. */
    public static function allIn(array $r): float
    {
        return (float)$r['card_cost'] + (float)$r['ship_cost'];
    }

    /** Realized net for a SOLD row (fees/shipping default to 0 when unrecorded). */
    public static function realized(array $r): ?float
    {
        if ($r['status'] !== 'SOLD' || $r['sold_price'] === null) {
            return null;
        }
        return (float)$r['sold_price'] - (float)($r['sold_fees'] ?? 0) - (float)($r['sold_ship'] ?? 0) - self::allIn($r);
    }

    /**
     * Portfolio totals over a member's (already valued) rows:
     * active count, invested, valued est + count, unrealized, realized, sold count.
     */
    public static function portfolio(array $rows): array
    {
        $out = ['active' => 0, 'invested' => 0.0, 'valued' => 0, 'est' => 0.0,
                'unrealized' => 0.0, 'sold' => 0, 'realized' => 0.0];
        foreach ($rows as $r) {
            if ($r['status'] === 'SOLD') {
                $out['sold']++;
                $out['realized'] += self::realized($r) ?? 0.0;
                continue;
            }
            $out['active']++;
            $out['invested'] += self::allIn($r);
            if (($r['live_value'] ?? null) !== null) {
                $out['valued']++;
                $out['est'] += (float)$r['live_value'];
                $out['unrealized'] += (float)$r['live_value'] - self::allIn($r);
            }
        }
        return $out;
    }
}
