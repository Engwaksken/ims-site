-- Recurring task rules, per-date instances, and scheduled reminders.
-- Uses conditional prepared DDL so this is safe to rerun on MySQL/MariaDB
-- versions that do not support ADD COLUMN / CREATE INDEX IF NOT EXISTS.

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='is_recurring'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN is_recurring TINYINT(1) NOT NULL DEFAULT 0'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='recurrence_days'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN recurrence_days VARCHAR(20) NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='recurrence_end_date'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN recurrence_end_date DATE NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='recurrence_parent_id'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN recurrence_parent_id INT UNSIGNED NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='reminder_at'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN reminder_at DATETIME NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='reminder_time'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN reminder_time TIME NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='reminder_sent_at'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN reminder_sent_at DATETIME NULL'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND INDEX_NAME='uq_employee_tasks_recurrence_date'),
    'SELECT 1',
    'CREATE UNIQUE INDEX uq_employee_tasks_recurrence_date ON employee_tasks (recurrence_parent_id, task_date)'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND INDEX_NAME='idx_employee_tasks_reminder'),
    'SELECT 1',
    'CREATE INDEX idx_employee_tasks_reminder ON employee_tasks (status, reminder_at, reminder_sent_at)'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND INDEX_NAME='idx_employee_tasks_recurring'),
    'SELECT 1',
    'CREATE INDEX idx_employee_tasks_recurring ON employee_tasks (is_recurring, task_date, recurrence_end_date)'
);
PREPARE task_stmt FROM @task_ddl; EXECUTE task_stmt; DEALLOCATE PREPARE task_stmt;
