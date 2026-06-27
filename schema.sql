-- sportcard101 database schema (MySQL / MariaDB)
--
--   mysql -u root sportcard101 < schema.sql
--
-- utf8mb4 throughout so card titles / lesson content store cleanly.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------- Accounts
-- One table, role-based. superadmin logs in at /superadmin, members at /.
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('superadmin','member') NOT NULL DEFAULT 'member',
    status        ENUM('active','suspended')  NOT NULL DEFAULT 'active',
    -- Subscription (kept in sync with Stripe via webhook):
    plan_id            INT UNSIGNED DEFAULT NULL,
    sub_status         ENUM('none','trialing','active','past_due','canceled') NOT NULL DEFAULT 'none',
    trial_ends_at      DATETIME DEFAULT NULL,
    stripe_customer_id VARCHAR(64) DEFAULT NULL,
    stripe_sub_id      VARCHAR(64) DEFAULT NULL,
    -- Affiliate program:
    affiliate_code VARCHAR(16) DEFAULT NULL,  -- this member's own referral code
    referred_by    VARCHAR(16) DEFAULT NULL,  -- affiliate_code that referred them
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username),
    UNIQUE KEY uniq_email (email),
    UNIQUE KEY uniq_affiliate (affiliate_code),
    KEY idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Plans / pricing
CREATE TABLE IF NOT EXISTS plans (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(80)  NOT NULL,
    slug           VARCHAR(80)  NOT NULL,
    price_cents    INT UNSIGNED NOT NULL DEFAULT 0,
    bill_interval  ENUM('month','year') NOT NULL DEFAULT 'month',
    stripe_price_id VARCHAR(64) DEFAULT NULL,
    blurb          VARCHAR(255) DEFAULT NULL,
    features       TEXT DEFAULT NULL,          -- one feature per line
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort           INT NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- School content (CMS)
CREATE TABLE IF NOT EXISTS content_modules (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title        VARCHAR(190) NOT NULL,
    slug         VARCHAR(190) NOT NULL,
    summary      VARCHAR(512) DEFAULT NULL,
    sort         INT NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_lessons (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id    INT UNSIGNED NOT NULL,
    title        VARCHAR(190) NOT NULL,
    body         MEDIUMTEXT DEFAULT NULL,
    video_url    VARCHAR(512) DEFAULT NULL,
    is_free      TINYINT(1) NOT NULL DEFAULT 0,  -- visible to free tier as a preview
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    sort         INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_module (module_id),
    CONSTRAINT fk_lesson_module FOREIGN KEY (module_id) REFERENCES content_modules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Site settings (key/value)
CREATE TABLE IF NOT EXISTS settings (
    skey  VARCHAR(64) NOT NULL,
    sval  TEXT DEFAULT NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- Affiliate referrals
CREATE TABLE IF NOT EXISTS referrals (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    affiliate_user_id INT UNSIGNED NOT NULL,
    referred_user_id  INT UNSIGNED NOT NULL,
    reward_cents      INT NOT NULL DEFAULT 0,
    status            ENUM('pending','credited','void') NOT NULL DEFAULT 'pending',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_affiliate (affiliate_user_id),
    CONSTRAINT fk_ref_aff FOREIGN KEY (affiliate_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_user FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- AI scanner (admin-curated)
-- Saved searches define what the deal scanner hunts on eBay. Owned by the
-- superadmin; members view the resulting deals.
CREATE TABLE IF NOT EXISTS searches (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    label          VARCHAR(190) NOT NULL,
    keywords       VARCHAR(255) NOT NULL,
    grade          VARCHAR(32)  NOT NULL DEFAULT 'PSA 10',
    max_price      DECIMAL(10,2) DEFAULT NULL,
    threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 25,
    buying_option  ENUM('AUCTION','FIXED_PRICE','ANY') NOT NULL DEFAULT 'AUCTION',
    active          TINYINT(1)  NOT NULL DEFAULT 1,
    last_scanned_at DATETIME    DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    CONSTRAINT fk_search_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    baseline_price DECIMAL(10,2) DEFAULT NULL,
    discount_pct   DECIMAL(6,2)  DEFAULT NULL,
    is_deal        TINYINT(1)   NOT NULL DEFAULT 0,
    ai_verdict     VARCHAR(8)   DEFAULT NULL,
    ai_confidence  TINYINT UNSIGNED DEFAULT NULL,
    ai_card        VARCHAR(255) DEFAULT NULL,
    ai_reason      VARCHAR(512) DEFAULT NULL,
    ai_flip_pct    DECIMAL(6,2) DEFAULT NULL,
    ai_hidden_gem  TINYINT(1)   NOT NULL DEFAULT 0,
    notified       TINYINT(1)   NOT NULL DEFAULT 0,
    first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_search_item (search_id, ebay_item_id),
    KEY idx_deal (search_id, is_deal),
    CONSTRAINT fk_listing_search FOREIGN KEY (search_id) REFERENCES searches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-scan bid snapshots for auctions — lets us show how interest (bid count)
-- grew over time and compute bid velocity.
CREATE TABLE IF NOT EXISTS bid_snapshots (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id BIGINT UNSIGNED NOT NULL,
    bid_count  INT NOT NULL DEFAULT 0,
    price      DECIMAL(10,2) NOT NULL DEFAULT 0,
    snapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_listing_time (listing_id, snapped_at),
    CONSTRAINT fk_snap_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sold comps — the final result of each tracked auction once it closed (the
-- last bid we recorded ≈ the sale price). Our own "what it sold for" database,
-- built over time by the closing tracker, used to price buys/sells.
CREATE TABLE IF NOT EXISTS sold_comps (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ebay_item_id   VARCHAR(64)  NOT NULL,
    search_id      INT UNSIGNED DEFAULT NULL,
    sport          VARCHAR(32)  DEFAULT NULL,
    grade          VARCHAR(32)  DEFAULT NULL,
    canonical_card VARCHAR(255) DEFAULT NULL,
    card_key       VARCHAR(255) DEFAULT NULL,
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
