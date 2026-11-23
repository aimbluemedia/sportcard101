-- Deal-alert history for the members area.
-- Optional: src/DealAlerts.php creates this table itself on the first send.
-- Sent deal-alert emails, shown in the members area (src/DealAlerts.php
-- creates this on the fly too, so an existing install needs no import).
CREATE TABLE IF NOT EXISTS alert_emails (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sent_at     DATETIME NOT NULL,
    match_count INT UNSIGNED NOT NULL DEFAULT 0,
    payload     MEDIUMTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
