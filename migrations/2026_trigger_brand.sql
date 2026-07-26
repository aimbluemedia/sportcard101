-- SportCard101 — add the card-company (brand) filter to alert triggers.
-- NOTE: the Alerts page applies this automatically on first load after
-- deploying; run manually only if that self-migration is blocked.

ALTER TABLE alert_triggers ADD COLUMN brand VARCHAR(32) NOT NULL DEFAULT 'all' AFTER grade;
