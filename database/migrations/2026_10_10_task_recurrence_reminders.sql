-- Recurring task rules, per-date instances, and scheduled reminders.
ALTER TABLE employee_tasks
    ADD COLUMN IF NOT EXISTS is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS recurrence_days VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS recurrence_end_date DATE NULL,
    ADD COLUMN IF NOT EXISTS recurrence_parent_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS reminder_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS reminder_time TIME NULL,
    ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_employee_tasks_recurrence_date
    ON employee_tasks (recurrence_parent_id, task_date);
CREATE INDEX IF NOT EXISTS idx_employee_tasks_reminder
    ON employee_tasks (status, reminder_at, reminder_sent_at);
CREATE INDEX IF NOT EXISTS idx_employee_tasks_recurring
    ON employee_tasks (is_recurring, task_date, recurrence_end_date);
