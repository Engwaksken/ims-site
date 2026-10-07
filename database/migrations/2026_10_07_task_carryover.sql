-- ============================================================================
-- IMS: daily task carry-over (pending daily activities roll to the next day)
-- ============================================================================
-- Idempotent: safe to run more than once (MariaDB 10.0.2+ "IF NOT EXISTS").
-- Back up the database before running.
--
-- Tasks = workplan_deliverables (shown in Activities Calendar / Workplan).
-- A task whose calendar_frequency = 'Daily' and whose status is still
-- Pending / In Progress / Delayed after its end_date is moved to the next
-- day. The original dates are preserved and every move is logged.
-- ============================================================================

ALTER TABLE workplan_deliverables
    ADD COLUMN IF NOT EXISTS calendar_frequency VARCHAR(20) NOT NULL DEFAULT 'Monthly',
    ADD COLUMN IF NOT EXISTS original_start_date DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS original_end_date DATE NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS carried_over TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS carried_over_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_carried_over_at DATETIME NULL DEFAULT NULL;

ALTER TABLE workplan_milestones
    ADD COLUMN IF NOT EXISTS calendar_frequency VARCHAR(20) NOT NULL DEFAULT 'Annual';

-- Speeds up the nightly scan.
CREATE INDEX IF NOT EXISTS idx_wd_carryover
    ON workplan_deliverables (calendar_frequency, status, end_date);

-- Full history of every carry-over move.
CREATE TABLE IF NOT EXISTS task_carryover_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deliverable_id  INT UNSIGNED NOT NULL,
    from_start_date DATE NULL,
    from_end_date   DATE NULL,
    to_start_date   DATE NULL,
    to_end_date     DATE NOT NULL,
    status_at_move  VARCHAR(30) NOT NULL,
    carried_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    trigger_source  VARCHAR(20) NOT NULL DEFAULT 'cron',
    PRIMARY KEY (id),
    KEY idx_tcl_deliverable (deliverable_id),
    KEY idx_tcl_carried_at (carried_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Marker used by the once-per-day lazy fallback (no cron needed).
INSERT INTO system_settings (setting_key, setting_value, description)
SELECT 'task_carryover_last_run', '', 'Last date the daily task carry-over ran'
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings WHERE setting_key = 'task_carryover_last_run'
);
