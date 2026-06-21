-- sportscard101 database schema (MySQL / MariaDB)
--
--   mysql -u root sportscard101 < schema.sql
--
-- All tables use utf8mb4 so card titles with any characters store cleanly.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email         VARCHAR(190) DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saved searches define what the scanner looks for on eBay.
CREATE TABLE IF NOT EXISTS searches (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    label          VARCHAR(190) NOT NULL,
    keywords       VARCHAR(255) NOT NULL,
    grade          VARCHAR(32)  NOT NULL DEFAULT 'PSA 10',
    -- Only consider listings at or below this price (NULL = no cap).
    max_price      DECIMAL(10,2) DEFAULT NULL,
    -- Flag a deal when price is this %% (or more) below the market baseline.
    threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 25,
    -- Restrict to auctions, fixed price, or both.
    buying_option  ENUM('AUCTION','FIXED_PRICE','ANY') NOT NULL DEFAULT 'AUCTION',
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    last_scanned_at DATETIME    DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    CONSTRAINT fk_search_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Snapshot of every listing seen for a search, with computed deal metrics.
CREATE TABLE IF NOT EXISTS listings (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    search_id      INT UNSIGNED NOT NULL,
    ebay_item_id   VARCHAR(64)  NOT NULL,
    title          VARCHAR(512) NOT NULL,
    price          DECIMAL(10,2) NOT NULL,
    currency       VARCHAR(8)   NOT NULL DEFAULT 'USD',
    bid_count      INT          DEFAULT NULL,
    buying_option  VARCHAR(32)  DEFAULT NULL,
    end_time       DATETIME     DEFAULT NULL,
    image_url      VARCHAR(1024) DEFAULT NULL,
    item_url       VARCHAR(1024) NOT NULL,
    item_condition VARCHAR(128) DEFAULT NULL,
    seller         VARCHAR(190) DEFAULT NULL,
    -- Computed at scan time:
    baseline_price DECIMAL(10,2) DEFAULT NULL,
    discount_pct   DECIMAL(6,2)  DEFAULT NULL,
    is_deal        TINYINT(1)   NOT NULL DEFAULT 0,
    -- AI Opportunity Engine output:
    ai_verdict     VARCHAR(8)   DEFAULT NULL, -- BUY | WATCH | PASS
    ai_confidence  TINYINT UNSIGNED DEFAULT NULL, -- 0-100
    ai_card        VARCHAR(255) DEFAULT NULL, -- canonical card identity
    ai_reason      VARCHAR(512) DEFAULT NULL, -- beginner-friendly rationale
    ai_flip_pct    DECIMAL(6,2) DEFAULT NULL, -- estimated flip margin after fees
    ai_hidden_gem  TINYINT(1)   NOT NULL DEFAULT 0, -- mislabeled / overlooked find
    -- Has the user been notified about this deal already?
    notified       TINYINT(1)   NOT NULL DEFAULT 0,
    first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_search_item (search_id, ebay_item_id),
    KEY idx_deal (search_id, is_deal),
    CONSTRAINT fk_listing_search FOREIGN KEY (search_id) REFERENCES searches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
