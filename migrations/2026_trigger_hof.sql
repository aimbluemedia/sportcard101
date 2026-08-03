-- SportCard101 — add the Hall of Fame flag to alert triggers.
-- NOTE: the Alerts page applies this automatically on first load after
-- deploying; run manually only if that self-migration is blocked.

ALTER TABLE alert_triggers ADD COLUMN hof TINYINT(1) NOT NULL DEFAULT 0 AFTER rookie;
