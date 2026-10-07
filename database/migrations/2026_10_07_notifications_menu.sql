-- ============================================================================
-- IMS: sidebar entry for the Notifications page (legacy/notifications.php)
-- ============================================================================
-- Idempotent: safe to run more than once.
--   * menu_items.page is UNIQUE, so INSERT IGNORE never duplicates the item and
--     never overwrites an icon/label/order an admin has since changed.
--   * menu_role_permissions has UNIQUE (menu_id, role), so roles already granted
--     are skipped.
-- Every role gets the entry: the page only ever shows the signed-in user's own
-- notifications. Remove roles later from Menu Permissions if wanted.
-- ============================================================================

INSERT IGNORE INTO menu_items (page, icon, label, category, sort_order, is_active)
VALUES ('notifications.php', 'fa-bell', 'Notifications', 'General', 1, 1);

INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT mi.menu_id, r.role
FROM menu_items mi
CROSS JOIN (
    SELECT 'Administrator' AS role
    UNION ALL SELECT 'Programs Lead'
    UNION ALL SELECT 'MEAL Lead'
    UNION ALL SELECT 'Operations/Admin'
    UNION ALL SELECT 'Project Officer'
    UNION ALL SELECT 'Donor/Partner'
    UNION ALL SELECT 'Staff'
    UNION ALL SELECT 'Member'
    UNION ALL SELECT 'Applicant'
    UNION ALL SELECT 'Reviewer'
    UNION ALL SELECT 'Accountant'
    UNION ALL SELECT 'Finance'
    UNION ALL SELECT 'Program Director'
    UNION ALL SELECT 'Consultant'
    UNION ALL SELECT 'Executive Director'
    UNION ALL SELECT 'IT Officer'
    UNION ALL SELECT 'HR'
    UNION ALL SELECT 'Program Manager'
    UNION ALL SELECT 'Procurement Officer'
    UNION ALL SELECT 'Program Officer'
) AS r
WHERE mi.page = 'notifications.php';
