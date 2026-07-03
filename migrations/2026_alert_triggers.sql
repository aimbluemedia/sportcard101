-- SportCard101 — multiple, named deal-alert triggers.
-- Run once in phpMyAdmin (or: mysql -u USER DBNAME < migrations/2026_alert_triggers.sql)
--
-- Each row is one rule. An auction alerts if it matches ANY active trigger.
-- Examples:
--   "Signed PSA 10 under $25"  -> signed=1, grade='10', max_price=25.00
--   "Any PSA 10 under comp"    -> grade='10', require_comp=1, min_under_comp=0

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS alert_triggers (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label          VARCHAR(120) NOT NULL,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    sport          VARCHAR(32)  NOT NULL DEFAULT 'all',  -- 'all' or a sport key
    grade          VARCHAR(16)  NOT NULL DEFAULT 'any',  -- 'any' or a number e.g. '10','9.5'
    signed         TINYINT(1)   NOT NULL DEFAULT 0,      -- require autograph (title match)
    keywords       VARCHAR(190) DEFAULT NULL,            -- optional title contains
    max_price      DECIMAL(10,2) DEFAULT NULL,           -- optional price cap
    min_under_comp DECIMAL(6,2) DEFAULT NULL,            -- optional % under comp median
    require_comp   TINYINT(1)   NOT NULL DEFAULT 0,      -- only when a comp exists (under it)
    within_hours   DECIMAL(6,1) DEFAULT NULL,            -- optional ending-within-hours
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
