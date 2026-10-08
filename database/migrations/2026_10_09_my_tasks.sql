-- Tasks mapped to employee KPIs / appraisal KPIs and KRAs.
CREATE TABLE IF NOT EXISTS employee_tasks (
    task_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(240) NOT NULL,
    details TEXT NULL,
    assigned_to INT NOT NULL,
    created_by INT NOT NULL,
    kpi_source ENUM('employee','appraisal') NULL,
    kpi_id INT NULL,
    kra_id INT NULL,
    task_frequency ENUM('Daily','Weekly') NOT NULL DEFAULT 'Daily',
    task_date DATE NOT NULL,
    status ENUM('Pending','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
    moved_from DATE NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id),
    KEY idx_employee_tasks_assignee_date (assigned_to, task_date, status),
    KEY idx_employee_tasks_kpi (kpi_source, kpi_id),
    KEY idx_employee_tasks_kra (kra_id),
    CONSTRAINT fk_employee_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_tasks_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Make the page available in the Operations menu to staff and administrators.
INSERT INTO menu_items (page, icon, label, category, sort_order, is_active)
SELECT 'my-tasks', 'fa-list-check', 'My Tasks', 'Operations', 15, 1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE page = 'my-tasks');

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, roles.role
FROM menu_items mi
CROSS JOIN (
    SELECT 'Administrator' AS role UNION ALL SELECT 'Programs Lead' UNION ALL
    SELECT 'MEAL Lead' UNION ALL SELECT 'Operations/Admin' UNION ALL
    SELECT 'Project Officer' UNION ALL SELECT 'Staff' UNION ALL
    SELECT 'Program Director' UNION ALL SELECT 'HR' UNION ALL
    SELECT 'Program Manager' UNION ALL SELECT 'Program Officer' UNION ALL
    SELECT 'IT Officer' UNION ALL SELECT 'Executive Director' UNION ALL
    SELECT 'Consultant' UNION ALL SELECT 'Reviewer' UNION ALL
    SELECT 'Accountant' UNION ALL SELECT 'Finance' UNION ALL
    SELECT 'Procurement Officer'
) roles
WHERE mi.page = 'my-tasks';
