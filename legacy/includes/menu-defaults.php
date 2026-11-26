<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Default sidebar entries for staff modules
|--------------------------------------------------------------------------
| Modules added after the original menu seed (Appointments, IT Support, HR) used
| to appear only once their migration SQL had been run. sidebar.php calls
| ims_ensure_default_menu_items() so a missing entry is created on the fly
| and granted to every staff role, including admin-added roles based on a
| staff role. Entries that already exist are left as configured in Menu
| Permissions; one with no role at all is granted to staff again.
| Costs one indexed SELECT per page view once the entries exist.
*/

if (!function_exists('ims_default_menu_items')) {
    function ims_default_menu_items(): array
    {
        return [
            'appointments' => ['icon' => 'fa-handshake', 'label' => 'Appointments', 'category' => 'Operations', 'sort_order' => 16],
            'it-support' => ['icon' => 'fa-screwdriver-wrench', 'label' => 'IT Support', 'category' => 'Operations', 'sort_order' => 17],
            'my-hr' => ['icon' => 'fa-id-card-clip', 'label' => 'My HR', 'category' => 'People', 'sort_order' => 32],
            // 'roles': granted only to these roles instead of every staff role.
            'hr-operations' => ['icon' => 'fa-people-roof', 'label' => 'HR Operations', 'category' => 'People', 'sort_order' => 30, 'roles' => ['Administrator', 'HR']],
            'recruitment' => ['icon' => 'fa-user-check', 'label' => 'Recruitment', 'category' => 'People', 'sort_order' => 31, 'roles' => ['Administrator', 'HR']],
        ];
    }
}

if (!function_exists('ims_ensure_default_menu_items')) {
    function ims_ensure_default_menu_items(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $defaults = ims_default_menu_items();
        $pages = [];
        foreach (array_keys($defaults) as $page) {
            $pages[] = $page;
            $pages[] = $page . '.php';
        }

        try {
            $marks = implode(',', array_fill(0, count($pages), '?'));
            $stmt = $conn->prepare(
                "SELECT mi.menu_id, mi.page, COUNT(mrp.menu_id) AS grants
                 FROM menu_items mi
                 LEFT JOIN menu_role_permissions mrp ON mrp.menu_id = mi.menu_id
                 WHERE mi.page IN ($marks)
                 GROUP BY mi.menu_id, mi.page"
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param(str_repeat('s', count($pages)), ...$pages);
            $stmt->execute();
            $existing = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $existing[(string)preg_replace('/\.php$/', '', (string)$row['page'])] = $row;
            }
            $stmt->close();

            $grantTo = [];   // menu_id => page
            foreach ($defaults as $page => $item) {
                if (isset($existing[$page])) {
                    if ((int)$existing[$page]['grants'] === 0) {
                        $grantTo[(int)$existing[$page]['menu_id']] = $page;
                    }
                    continue;
                }
                $insert = $conn->prepare('INSERT INTO menu_items (page, icon, label, category, sort_order, is_active) VALUES (?, ?, ?, ?, ?, 1)');
                if (!$insert) {
                    continue;
                }
                $insert->bind_param('ssssi', $page, $item['icon'], $item['label'], $item['category'], $item['sort_order']);
                $insert->execute();
                $grantTo[(int)$insert->insert_id] = $page;
                $insert->close();
            }

            if (!$grantTo) {
                return;
            }

            $roles = ims_default_menu_roles($conn);
            $grant = $conn->prepare('INSERT IGNORE INTO menu_role_permissions (menu_id, role) VALUES (?, ?)');
            if (!$grant) {
                return;
            }
            foreach ($grantTo as $menuId => $page) {
                foreach ($defaults[$page]['roles'] ?? $roles as $role) {
                    $grant->bind_param('is', $menuId, $role);
                    $grant->execute();
                }
            }
            $grant->close();
        } catch (Throwable $e) {
            error_log('ims_ensure_default_menu_items: ' . $e->getMessage());
        }
    }
}

/*
|--------------------------------------------------------------------------
| Sidebar relocations
|--------------------------------------------------------------------------
| page => new category for existing entries. Each move runs once: it is
| recorded in menu_relocations_applied, so Menu Permissions stays in control
| afterwards (moving an entry elsewhere there sticks). To move an entry
| again later, add it under a new key ("page@2" style is fine: the part
| before "@" is the page).
*/
if (!function_exists('ims_menu_relocations')) {
    function ims_menu_relocations(): array
    {
        return [
            'payment-voucher' => 'Finance',
            'finance-reports' => 'Finance',
        ];
    }
}

if (!function_exists('ims_apply_menu_relocations')) {
    function ims_apply_menu_relocations(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $relocations = ims_menu_relocations();
        if (!$relocations) {
            return;
        }

        try {
            $applied = [];
            try {
                $result = $conn->query('SELECT relocation_key FROM menu_relocations_applied');
            } catch (Throwable $e) {
                $result = false;
            }

            if ($result === false) {
                if (!$conn->query(
                    'CREATE TABLE IF NOT EXISTS menu_relocations_applied (
                        relocation_key VARCHAR(191) NOT NULL PRIMARY KEY,
                        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                )) {
                    return;
                }
            } else {
                foreach ($result->fetch_all(MYSQLI_NUM) as [$key]) {
                    $applied[(string)$key] = true;
                }
            }

            $pending = array_diff_key($relocations, $applied);
            if (!$pending) {
                return;   // the usual case: one small SELECT per page view
            }

            $move = $conn->prepare('UPDATE menu_items SET category = ? WHERE page IN (?, ?)');
            $mark = $conn->prepare('INSERT IGNORE INTO menu_relocations_applied (relocation_key) VALUES (?)');
            if (!$move || !$mark) {
                return;
            }

            foreach ($pending as $key => $category) {
                $page = (string)strtok((string)$key, '@');
                $withExtension = $page . '.php';
                $move->bind_param('sss', $category, $page, $withExtension);
                $move->execute();
                $mark->bind_param('s', $key);
                $mark->execute();
            }

            $move->close();
            $mark->close();
        } catch (Throwable $e) {
            error_log('ims_apply_menu_relocations: ' . $e->getMessage());
        }
    }
}

/** Built-in staff roles plus admin-added roles whose base role is a staff role. */
if (!function_exists('ims_default_menu_roles')) {
    function ims_default_menu_roles(mysqli $conn): array
    {
        $staff = defined('IMS_STAFF_ROLES') ? IMS_STAFF_ROLES : ['Administrator', 'Staff'];
        $roles = $staff;

        if (function_exists('ims_effective_role')) {
            $result = $conn->query('SELECT DISTINCT role FROM menu_role_permissions');
            if ($result) {
                foreach ($result->fetch_all(MYSQLI_NUM) as [$role]) {
                    $role = (string)$role;
                    if (!in_array($role, $roles, true) && in_array(ims_effective_role($conn, $role), $staff, true)) {
                        $roles[] = $role;
                    }
                }
            }
        }

        return $roles;
    }
}
