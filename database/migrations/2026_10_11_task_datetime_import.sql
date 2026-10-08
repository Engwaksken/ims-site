-- Preserve task due times separately from task_date (used by daily/weekly views).
-- Portable and idempotent across MySQL/MariaDB versions.
SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND COLUMN_NAME='task_due_at'),
    'SELECT 1',
    'ALTER TABLE employee_tasks ADD COLUMN task_due_at DATETIME NULL AFTER task_date'
);
PREPARE task_datetime_stmt FROM @task_ddl;
EXECUTE task_datetime_stmt;
DEALLOCATE PREPARE task_datetime_stmt;

SET @task_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_tasks' AND INDEX_NAME='idx_employee_tasks_due_datetime'),
    'SELECT 1',
    'CREATE INDEX idx_employee_tasks_due_datetime ON employee_tasks (task_due_at)'
);
PREPARE task_datetime_stmt FROM @task_ddl;
EXECUTE task_datetime_stmt;
DEALLOCATE PREPARE task_datetime_stmt;
