<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once __DIR__ . '/config.php';

if (function_exists('check_login')) {
    check_login();
}

$current_page = basename($_SERVER['PHP_SELF'] ?? '');
// Page name without ".php", for matching extensionless menu links.
$current_page_name = (string)preg_replace('/\.php$/i', '', $current_page);
$user_role    = (string)($_SESSION['role'] ?? '');
$user_name    = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');

$menu_items = [];

try {
    if (isset($conn) && $conn instanceof mysqli && $user_role !== '') {
        $stmt = $conn->prepare("
            SELECT  mi.page,
                    mi.icon,
                    mi.label,
                    mi.sort_order
            FROM    menu_items mi
            INNER JOIN menu_role_permissions mrp
                    ON mrp.menu_id = mi.menu_id
                   AND mrp.role = ?
            WHERE   mi.is_active = 1
            ORDER BY mi.sort_order ASC, mi.label ASC
        ");

        if ($stmt) {
            $stmt->bind_param('s', $user_role);
            $stmt->execute();

            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $menu_items[] = $row;
            }

            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $menu_items = [];
}
?>

<aside class="sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <h1><?= htmlspecialchars((string)(defined('SITE_NAME') ? SITE_NAME : 'Dashboard'), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>

        <a href="user-profile" class="sidebar-user" title="My profile">
            <div class="user-avatar" aria-hidden="true">
                <i class="fas fa-user-circle"></i>
            </div>

            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="user-role"><?= htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Main menu">
        <ul class="sidebar-menu">
            <?php if (empty($menu_items)): ?>
                <li class="sidebar-empty">
                    No menu items available.
                </li>
            <?php else: ?>
                <?php foreach ($menu_items as $item): ?>
                    <?php
                    $page  = trim((string)($item['page'] ?? ''));
                    $icon  = (string)($item['icon'] ?? 'fa-circle');
                    $label = (string)($item['label'] ?? 'Menu');
                    // menu_items is editable from Menu Permissions: never render a
                    // javascript:/data:/protocol-relative link. Unsafe rows are skipped.
                    $page_ok = function_exists('ims_is_safe_link')
                        ? ims_is_safe_link($page)
                        : (bool)preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $page);
                    if (!$page_ok) {
                        continue;
                    }
                    // Local pages are linked extensionless ("dashboard.php?x=1" -> "dashboard?x=1");
                    // absolute http(s) URLs are left untouched.
                    $is_local = !preg_match('#^https?://#i', $page);
                    $href = $is_local ? (string)preg_replace('#\.php(?=[?\#]|$)#i', '', $page) : $page;
                    $is_active = $is_local
                        && $current_page_name !== ''
                        && $current_page_name === basename((string)strtok($href, '?#'));
                    ?>
                    <li class="<?= $is_active ? 'active' : '' ?>">
                        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
                            <i class="fas <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($label) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout" class="logout-btn">
            <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>