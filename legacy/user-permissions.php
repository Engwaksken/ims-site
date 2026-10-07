<?php
ob_start();

$page_title = 'User Permissions';
include 'includes/header.php';

check_role(['Administrator']);

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// -- Role list -------------------------------------------------
$all_roles = [
    'Administrator', 'Programs Lead', 'MEAL Lead', 'Operations/Admin',
    'Project Officer', 'Donor/Partner', 'Staff', 'Member', 'Applicant',
    'Reviewer', 'Accountant', 'Finance', 'Program Director', 'Consultant',
    'Executive Director', 'IT Officer', 'HR', 'Program Manager',
    'Procurement Officer', 'Program Officer',
];

// -- Filter / pagination params --------------------------------
$filter_role   = trim((string)($_GET['role']   ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$search        = trim((string)($_GET['search'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 20;
$offset        = ($page - 1) * $limit;

// Validate role filter against known list
if ($filter_role !== '' && !in_array($filter_role, $all_roles, true)) {
    $filter_role = '';
}
if ($filter_status !== '' && !in_array($filter_status, ['1', '0'], true)) {
    $filter_status = '';
}

// -- Build WHERE -----------------------------------------------
$where  = [];
$params = [];
$types  = '';

if ($filter_role !== '') {
    $where[]  = "u.role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}
if ($filter_status !== '') {
    $where[]  = "u.is_active = ?";
    $params[] = (int)$filter_status;
    $types   .= 'i';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    array_push($params, $like, $like, $like, $like);
    $types   .= 'ssss';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// -- Count -----------------------------------------------------
$cs = $conn->prepare("SELECT COUNT(*) AS total FROM users u $whereSql");
if ($types !== '') { $cs->bind_param($types, ...$params); }
$cs->execute();
$totalRows = (int)($cs->get_result()->fetch_assoc()['total'] ?? 0);
$cs->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $limit;

// -- Fetch page ------------------------------------------------
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$stmt = $conn->prepare("
    SELECT
        u.user_id, u.username, u.full_name, u.email, u.phone,
        u.role, u.is_active, u.last_login, u.created_at,
        (SELECT COUNT(*) FROM activity_log al WHERE al.user_id = u.user_id) AS total_actions
    FROM users u
    $whereSql
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Edit user -------------------------------------------------
$edit_user = null;
if (!empty($_GET['edit'])) {
    $edit_id  = (int)$_GET['edit'];
    $es = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $es->bind_param("i", $edit_id);
    $es->execute();
    $edit_user = $es->get_result()->fetch_assoc();
    $es->close();
}

// -- Global stats (always unfiltered) -------------------------
$sr = $conn->query("
    SELECT
        COUNT(*)                            AS total,
        SUM(role = 'Administrator')         AS administrators,
        SUM(role = 'Procurement Officer')   AS procurement_officers,
        SUM(role = 'Program Officer')       AS program_officers,
        SUM(is_active = 1)                  AS active,
        SUM(is_active = 0)                  AS inactive
    FROM users
")->fetch_assoc();

// -- URL helper (preserve filters across pagination/edit) ------
function perm_url(array $overrides = []): string
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
}

// -- Pagination page range -------------------------------------
function perm_page_range(int $cur, int $total): array
{
    $range = [];
    for ($i = max(1, $cur - 2); $i <= min($total, $cur + 2); $i++) {
        $range[] = $i;
    }
    if (($range[0] ?? 1) > 2)  array_unshift($range, '…');
    if (($range[0] ?? 1) > 1)  array_unshift($range, 1);
    $last = end($range);
    if ($last < $total - 1) $range[] = '…';
    if ($last < $total)     $range[] = $total;
    return $range;
}

$isFiltered = ($search !== '' || $filter_role !== '' || $filter_status !== '');
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/users.css">

<div class="assets-wrap">

    <!-- -- Page Hero -------------------------------------------- -->
    <div class="page-hero">
        <div>
            <h1><i class="fas fa-user-lock"></i> User Permissions</h1>
            <p>Manage roles, access levels, and account status across all users</p>
        </div>
        <div class="hero-actions">
            <button class="btn btn-primary" onclick="openModal('rolesInfoModal')">
                <i class="fas fa-info-circle"></i> Role Descriptions
            </button>
            <a href="users" class="btn btn-dark">
                <i class="fas fa-users-cog"></i> Manage Users
            </a>
        </div>
    </div>

    <!-- -- Stats ------------------------------------------------ -->
    <div class="stats-grid" style="grid-template-columns: repeat(6, 1fr);">
        <div class="stat-card">
            <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <span class="stat-label">Total</span>
                <span class="stat-value"><?= number_format((int)$sr['total']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-red"><i class="fas fa-user-shield"></i></div>
            <div class="stat-info">
                <span class="stat-label">Admins</span>
                <span class="stat-value"><?= number_format((int)$sr['administrators']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-amber"><i class="fas fa-cart-shopping"></i></div>
            <div class="stat-info">
                <span class="stat-label">Procurement</span>
                <span class="stat-value"><?= number_format((int)$sr['procurement_officers']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-purple"><i class="fas fa-user-tie"></i></div>
            <div class="stat-info">
                <span class="stat-label">Program Officers</span>
                <span class="stat-value"><?= number_format((int)$sr['program_officers']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-green"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
                <span class="stat-label">Active</span>
                <span class="stat-value"><?= number_format((int)$sr['active']) ?></span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--ink-50);color:var(--ink-300);">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="stat-info">
                <span class="stat-label">Inactive</span>
                <span class="stat-value"><?= number_format((int)$sr['inactive']) ?></span>
            </div>
        </div>
    </div>

    <!-- -- Users Panel ------------------------------------------ -->
    <div class="panel">

        <!-- Toolbar -->
        <div class="users-panel-head">
            <h3 class="panel-title"><i class="fas fa-user-lock"></i> Permissions</h3>

            <form method="GET" class="users-toolbar filters-bar" id="filterForm" role="search">
                <input type="hidden" name="page" value="1">

                <!-- Search -->
                <div class="search-box filters-grow">
                    <i class="fas fa-search search-icon"></i>
                    <input
                        type="search"
                        name="search"
                        class="search-input"
                        placeholder="Search name, email, username…"
                        value="<?= e($search) ?>"
                        autocomplete="off"
                        id="searchInput"
                    >
                    <?php if ($search !== ''): ?>
                        <a href="<?= e(perm_url(['search' => '', 'page' => 1])) ?>" class="search-clear" title="Clear">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Role filter -->
                <select name="role" class="filter-select" onchange="this.form.submit()">
                    <option value="">All roles</option>
                    <?php foreach ($all_roles as $r): ?>
                        <option value="<?= e($r) ?>" <?= $filter_role === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Status filter -->
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value=""  <?= $filter_status === ''  ? 'selected' : '' ?>>All status</option>
                    <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>

                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="fas fa-search"></i><span class="btn-label"> Search</span>
                </button>

                <?php if ($isFiltered): ?>
                    <a href="user-permissions" class="btn btn-sm btn-gray">
                        <i class="fas fa-times"></i><span class="btn-label"> Clear</span>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Results meta -->
        <div class="results-meta">
            <span class="results-count">
                <?php if ($isFiltered): ?>
                    <strong><?= number_format($totalRows) ?></strong> result<?= $totalRows !== 1 ? 's' : '' ?>
                    <?php if ($search !== ''): ?> for <em>"<?= e($search) ?>"</em><?php endif; ?>
                    <span class="note">-<?= number_format((int)$sr['total']) ?> total</span>
                <?php else: ?>
                    <strong><?= number_format($totalRows) ?></strong> user<?= $totalRows !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </span>
            <span class="results-page-info">
                Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                &nbsp;.&nbsp;
                <?= number_format(($page - 1) * $limit + 1) ?>-<?= number_format(min($page * $limit, $totalRows)) ?>
            </span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Activity</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>

                <?php if (empty($users)): ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            <i class="fas fa-user-slash"></i>
                            No users match your search.
                            <?php if ($isFiltered): ?>
                                <a href="user-permissions" style="color:var(--brand-500);margin-left:6px;">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $row):
                    $isSelf   = (int)$row['user_id'] === (int)$_SESSION['user_id'];
                    $isActive = (int)$row['is_active'] === 1;
                    $initials = strtoupper(mb_substr(trim($row['full_name']), 0, 1));
                ?>
                    <tr>
                        <!-- User cell -->
                        <td>
                            <div class="u-cell">
                                <div class="u-avatar" data-letter="<?= e($initials) ?>"><?= e($initials) ?></div>
                                <div class="u-cell-body">
                                    <span class="u-name">
                                        <?= e($row['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="self-tag">You</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="u-handle">@<?= e($row['username']) ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- Contact -->
                        <td>
                            <span class="note" style="display:block;"><?= e($row['email'] ?: '—') ?></span>
                            <?php if ($row['phone']): ?>
                                <span class="note"><?= e($row['phone']) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Role -->
                        <td>
                            <span class="badge badge-role" data-role="<?= e($row['role']) ?>">
                                <?= e($row['role']) ?>
                            </span>
                        </td>

                        <!-- Status -->
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-issued">Active</span>
                            <?php else: ?>
                                <span class="badge badge-rejected">Inactive</span>
                            <?php endif; ?>
                        </td>

                        <!-- Last login -->
                        <td>
                            <?php if (!empty($row['last_login'])): ?>
                                <span class="login-time">
                                    <?= function_exists('time_ago')
                                        ? e(time_ago($row['last_login']))
                                        : date('d M Y H:i', strtotime($row['last_login'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="login-never">Never</span>
                            <?php endif; ?>
                        </td>

                        <!-- Activity -->
                        <td>
                            <span class="activity-count">
                                <?= number_format((int)$row['total_actions']) ?>
                                <span class="note"> actions</span>
                            </span>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div class="tbl-actions">
                                <!-- View details -->
                                <button
                                    class="btn btn-sm btn-soft"
                                    title="View details"
                                    onclick="viewUserDetails(<?= (int)$row['user_id'] ?>)"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>

                                <!-- Edit permissions -->
                                <a
                                    href="<?= e(perm_url(['edit' => (int)$row['user_id']])) ?>"
                                    class="btn btn-sm btn-dark"
                                    title="Edit permissions"
                                >
                                    <i class="fas fa-user-edit"></i>
                                </a>

                                <?php if (!$isSelf): ?>
                                    <!-- Toggle active/inactive -->
                                    <?php if ($isActive): ?>
                                        <button
                                            class="btn btn-sm btn-gray"
                                            title="Deactivate user"
                                            onclick="toggleUserStatus(<?= (int)$row['user_id'] ?>, '0', '<?= e(addslashes($row['full_name'])) ?>')"
                                        ><i class="fas fa-user-times"></i></button>
                                    <?php else: ?>
                                        <button
                                            class="btn btn-sm btn-green"
                                            title="Activate user"
                                            onclick="toggleUserStatus(<?= (int)$row['user_id'] ?>, '1', '<?= e(addslashes($row['full_name'])) ?>')"
                                        ><i class="fas fa-user-check"></i></button>
                                    <?php endif; ?>

                                    <!-- Reset password -->
                                    <button
                                        class="btn btn-sm btn-soft"
                                        title="Reset password"
                                        onclick="resetPassword(<?= (int)$row['user_id'] ?>, '<?= e(addslashes($row['username'])) ?>')"
                                    ><i class="fas fa-key"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>
        </div>

        <!-- -- Pagination --------------------------------------- -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <?php if ($page > 1): ?>
                <a href="<?= e(perm_url(['page' => $page - 1])) ?>" class="pg-btn" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php foreach (perm_page_range($page, $totalPages) as $pn): ?>
                <?php if ($pn === '…'): ?>
                    <span class="pg-ellipsis">…</span>
                <?php elseif ($pn === $page): ?>
                    <span class="pg-btn pg-active"><?= $pn ?></span>
                <?php else: ?>
                    <a href="<?= e(perm_url(['page' => (int)$pn])) ?>" class="pg-btn"><?= $pn ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e(perm_url(['page' => $page + 1])) ?>" class="pg-btn" title="Next">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="pg-btn pg-disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.panel -->

</div><!-- /.assets-wrap -->

<!-- Hidden forms for JS actions -->
<form id="statusForm" method="POST" action="user-permissions-process" style="display:none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="user_id" id="statusUserId">
    <input type="hidden" name="new_status" id="statusNewStatus">
</form>

<?php include 'includes/user-permissions-modals.php'; ?>

<script>
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

window.addEventListener('click', function (e) {
    document.querySelectorAll('.modal.show').forEach(function (m) {
        if (e.target === m) m.classList.remove('show');
    });
});

function viewUserDetails(userId) {
    fetch('user-permissions-process?action=get_user&user_id=' + encodeURIComponent(userId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            var u = data.user;
            document.getElementById('detailsUserName').textContent  = u.full_name    || '';
            document.getElementById('detailsUsername').textContent   = u.username     || '';
            document.getElementById('detailsEmail').textContent      = u.email        || 'N/A';
            document.getElementById('detailsPhone').textContent      = u.phone        || 'N/A';
            document.getElementById('detailsRole').textContent       = u.role         || '';
            document.getElementById('detailsStatus').textContent     = u.is_active == 1 ? 'Active' : 'Inactive';
            document.getElementById('detailsCreated').textContent    = u.created_at   || '';
            document.getElementById('detailsLastLogin').textContent  = u.last_login   || 'Never';
            document.getElementById('detailsActions').textContent    = u.total_actions || 0;
            openModal('userDetailsModal');
        });
}

function toggleUserStatus(userId, newStatus, userName) {
    var verb = newStatus === '1' ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + verb + ' ' + userName + '?')) return;
    document.getElementById('statusUserId').value    = userId;
    document.getElementById('statusNewStatus').value = newStatus;
    document.getElementById('statusForm').submit();
}

function resetPassword(userId, username) {
    document.getElementById('resetUserId').value          = userId;
    document.getElementById('resetUsername').textContent  = username;
    openModal('resetPasswordModal');
}

// Debounce search ? submit after 450 ms idle
(function () {
    var input = document.getElementById('searchInput');
    if (!input) return;
    var timer;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            document.getElementById('filterForm').submit();
        }, 450);
    });
})();

<?php if ($edit_user): ?>
window.addEventListener('DOMContentLoaded', function () { openModal('editPermissionsModal'); });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>