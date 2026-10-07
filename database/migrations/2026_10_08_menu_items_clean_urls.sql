-- ============================================================================
-- IMS: store sidebar menu links without ".php"  (dashboard.php -> dashboard)
-- ============================================================================
-- Pages are served extensionless (see .htaccess rules 4 and 9-10), so a menu
-- link to "page.php" costs a 301 redirect and shows ".php" in the browser.
-- legacy/includes/sidebar.php already strips ".php" when it renders, and
-- menu-permissions.php saves new links without it. This migration cleans the
-- rows that are already stored.
--
-- Only local links are changed: anything starting with http://, https:// or //
-- or holding a scheme (":") is left as it is. A query string is kept
-- ("reports.php?tab=x" -> "reports?tab=x").
--
-- Idempotent: safe to run more than once. Once no local row ends in ".php"
-- (before any "?"), every statement matches nothing.
--
-- menu_items.page is UNIQUE. If both "x.php" and "x" exist (for example, the
-- 2026_10_07 notifications migration ran again after this one), the role
-- grants of "x.php" are copied onto "x". Then the "x.php" row is deleted, and
-- its menu_role_permissions rows go with it (ON DELETE CASCADE).
-- ============================================================================

-- 1. Duplicates: copy the role grants of "x.php" onto the existing "x" row.
INSERT IGNORE INTO menu_role_permissions (menu_id, role)
SELECT clean.menu_id, p.role
FROM menu_items dirty
JOIN menu_items clean
  ON clean.page = CONCAT(
         LEFT(SUBSTRING_INDEX(dirty.page, '?', 1), CHAR_LENGTH(SUBSTRING_INDEX(dirty.page, '?', 1)) - 4),
         SUBSTRING(dirty.page, CHAR_LENGTH(SUBSTRING_INDEX(dirty.page, '?', 1)) + 1)
     )
JOIN menu_role_permissions p ON p.menu_id = dirty.menu_id
WHERE SUBSTRING_INDEX(dirty.page, '?', 1) LIKE '%.php'
  AND dirty.page NOT LIKE '%:%'
  AND dirty.page NOT LIKE '//%';

-- 2. Duplicates: remove the "x.php" row; its grants cascade.
DELETE dirty
FROM menu_items dirty
JOIN menu_items clean
  ON clean.page = CONCAT(
         LEFT(SUBSTRING_INDEX(dirty.page, '?', 1), CHAR_LENGTH(SUBSTRING_INDEX(dirty.page, '?', 1)) - 4),
         SUBSTRING(dirty.page, CHAR_LENGTH(SUBSTRING_INDEX(dirty.page, '?', 1)) + 1)
     )
WHERE SUBSTRING_INDEX(dirty.page, '?', 1) LIKE '%.php'
  AND dirty.page NOT LIKE '%:%'
  AND dirty.page NOT LIKE '//%';

-- 3. Strip ".php" from every remaining local link.
UPDATE menu_items
SET page = CONCAT(
        LEFT(SUBSTRING_INDEX(page, '?', 1), CHAR_LENGTH(SUBSTRING_INDEX(page, '?', 1)) - 4),
        SUBSTRING(page, CHAR_LENGTH(SUBSTRING_INDEX(page, '?', 1)) + 1)
    )
WHERE SUBSTRING_INDEX(page, '?', 1) LIKE '%.php'
  AND page NOT LIKE '%:%'
  AND page NOT LIKE '//%';
