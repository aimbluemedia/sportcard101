-- SportCard101 — sold comps (our own "what it actually sold for" database).
-- Run once in phpMyAdmin (or: mysql -u USER DBNAME < migrations/2026_sold_comps.sql)
--
-- Each row is the final result of a tracked auction once it closed: the last
-- bid we recorded before close, treated as the sale price. Built up over time
-- by the closing tracker (cron), this becomes a proprietary comp database for
-- pricing buys/sells without eBay's Marketplace Insights API.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sold_comps (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ebay_item_id   VARCHAR(64)  NOT NULL,
    search_id      INT UNSIGNED DEFAULT NULL,
    sport          VARCHAR(32)  DEFAULT NULL,
    grade          VARCHAR(32)  DEFAULT NULL,
    canonical_card VARCHAR(255) DEFAULT NULL,   -- display name (AI-normalised or title)
    card_key       VARCHAR(255) DEFAULT NULL,   -- normalised key for grouping/matching
    title          VARCHAR(512) DEFAULT NULL,
    final_price    DECIMAL(10,2) NOT NULL,
    final_bids     INT NOT NULL DEFAULT 0,
    currency       VARCHAR(8)   NOT NULL DEFAULT 'USD',
    image_url      VARCHAR(1024) DEFAULT NULL,
    item_url       VARCHAR(1024) DEFAULT NULL,
    closed_at      DATETIME     NOT NULL,
    recorded_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_item (ebay_item_id),
    KEY idx_card (sport, grade, card_key),
    KEY idx_closed (closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
