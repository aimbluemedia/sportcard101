-- SportCard101 — Morning Playbook (daily buy/sell plan) + trade log.
-- Run once in phpMyAdmin (or: mysql -u USER DBNAME < migrations/2026_daily_plans.sql)
--
-- daily_plans:  one row per day — the generated plan (summary, budget, exposure).
-- plan_targets: the plan's picks. Each BUY target stores the pre-computed MAX BID
--               (walk-away price) and predicted economics, so the closing tracker
--               can later grade every recommendation against the real final price.
-- trades:       the manual trade log — cards actually bought, listed, and sold,
--               so the report shows real P&L next to the paper record.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS daily_plans (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_date   DATE NOT NULL,
    summary     TEXT DEFAULT NULL,                 -- AI market notes
    budget_day  DECIMAL(10,2) NOT NULL DEFAULT 0,  -- max exposure allowed today
    exposure    DECIMAL(10,2) NOT NULL DEFAULT 0,  -- sum of target max bids
    ai_mode     VARCHAR(16) NOT NULL DEFAULT 'mock',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_date (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plan_targets (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id        INT UNSIGNED NOT NULL,
    kind           ENUM('BUY','WATCH') NOT NULL DEFAULT 'BUY',
    ebay_item_id   VARCHAR(64) NOT NULL,
    card           VARCHAR(255) DEFAULT NULL,
    sport          VARCHAR(32) DEFAULT NULL,
    grade          VARCHAR(32) DEFAULT NULL,
    current_price  DECIMAL(10,2) DEFAULT NULL,     -- at plan time
    bid_count      INT DEFAULT NULL,
    end_time       DATETIME DEFAULT NULL,
    item_url       VARCHAR(1024) DEFAULT NULL,
    comp_median    DECIMAL(10,2) DEFAULT NULL,
    comp_count     INT DEFAULT NULL,
    comp_low       DECIMAL(10,2) DEFAULT NULL,
    comp_high      DECIMAL(10,2) DEFAULT NULL,
    max_bid        DECIMAL(10,2) DEFAULT NULL,     -- walk-away price
    est_resale     DECIMAL(10,2) DEFAULT NULL,     -- expected sale (comp median)
    est_net        DECIMAL(10,2) DEFAULT NULL,     -- profit at max_bid after fees+ship
    reason         VARCHAR(512) DEFAULT NULL,
    -- Grading (filled by the closing tracker in Phase 2):
    final_price    DECIMAL(10,2) DEFAULT NULL,
    would_have_won TINYINT(1) DEFAULT NULL,
    graded_at      DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_plan (plan_id),
    KEY idx_item (ebay_item_id),
    CONSTRAINT fk_pt_plan FOREIGN KEY (plan_id) REFERENCES daily_plans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trades (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    card         VARCHAR(255) NOT NULL,
    ebay_item_id VARCHAR(64) DEFAULT NULL,
    sport        VARCHAR(32) DEFAULT NULL,
    grade        VARCHAR(32) DEFAULT NULL,
    buy_price    DECIMAL(10,2) NOT NULL,
    bought_at    DATE NOT NULL,
    status       ENUM('BOUGHT','LISTED','SOLD') NOT NULL DEFAULT 'BOUGHT',
    listed_at    DATE DEFAULT NULL,
    sell_price   DECIMAL(10,2) DEFAULT NULL,
    sold_at      DATE DEFAULT NULL,
    fees         DECIMAL(10,2) DEFAULT NULL,       -- actual fees+shipping; NULL = estimate
    notes        VARCHAR(512) DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
