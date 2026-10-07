<?php
ob_start();
// config.php starts the session with hardened cookie settings.
require_once __DIR__ . '/includes/config.php';

/* ----------------------------------------------------------
   AUTH GUARD
---------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$uid = (int)$_SESSION['user_id'];

/* ----------------------------------------------------------
   HELPERS
---------------------------------------------------------- */
if (!function_exists('h')) {
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

function fmt_date(?string $date): string
{
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

function status_meta(string $status): array
{
    $map = [
        'draft'     => ['Draft', '#64748b', '#f1f5f9'],
        'submitted' => ['Submitted', '#ff9800', '#dbeafe'],
        'under_review' => ['Under Review', '#ff9800', '#fef3c7'],
        'approved'  => ['Approved', '#ff9800', '#dcfce7'],
        'completed' => ['Completed', '#ff5722', '#dcfce7'],
        'rejected'  => ['Rejected', '#dc2626', '#fee2e2'],
    ];

    return $map[$status] ?? [ucfirst($status), '#64748b', '#f1f5f9'];
}

/* ----------------------------------------------------------
   FILTERS
---------------------------------------------------------- */
$status_filter = trim((string)($_GET['status'] ?? ''));
if (!in_array($status_filter, ['draft', 'submitted', 'under_review', 'approved', 'rejected'], true)) {
    $status_filter = '';
}
$year_filter   = (int)($_GET['fiscal_year'] ?? 0);
$q             = trim((string)($_GET['q'] ?? ''));

/* ----------------------------------------------------------
   LOAD AVAILABLE YEARS
---------------------------------------------------------- */
$years = [];
$stmt = $conn->prepare("
    SELECT DISTINCT fiscal_year
    FROM activity_plans
    WHERE user_id = ?
      AND fiscal_year IS NOT NULL
    ORDER BY fiscal_year DESC
");
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $years[] = (int)$row['fiscal_year'];
    }
    $stmt->close();
}

/* ----------------------------------------------------------
   BUILD QUERY
---------------------------------------------------------- */
$where  = ["ap.user_id = ?"];
$params = [$uid];
$types  = "i";

if ($status_filter !== '') {
    $where[] = "ap.status = ?";
    $types  .= "s";
    $params[] = $status_filter;
}

if ($year_filter > 0) {
    $where[] = "ap.fiscal_year = ?";
    $types  .= "i";
    $params[] = $year_filter;
}

if ($q !== '') {
    $where[] = "(ap.plan_title LIKE ? OR COALESCE(kc.category_name,'') LIKE ?)";
    $types  .= "ss";
    $like = '%' . addcslashes($q, '%_') . '%';
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

/* ----------------------------------------------------------
   LOAD PLANS
---------------------------------------------------------- */
$sql = "
    SELECT
        ap.plan_id,
        ap.plan_title,
        ap.department_id,
        ap.category_id,
        ap.fiscal_year,
        ap.total_weight,
        ap.status,
        ap.created_at,
        ap.updated_at,
        ap.submitted_at,
        ap.reviewed_at,
        ap.reviewer_notes,
        kc.category_name,
        (
            SELECT COUNT(*)
            FROM plan_activities pa
            WHERE pa.plan_id = ap.plan_id
        ) AS activity_count,
        (
            SELECT COUNT(*)
            FROM activity_weekly_entries awe
            WHERE awe.plan_id = ap.plan_id
        ) AS weekly_count
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc
        ON kc.category_id = ap.category_id
    WHERE {$where_sql}
    ORDER BY ap.created_at DESC, ap.plan_id DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('my-activities prepare failed: ' . $conn->error);
    die('Unable to load activity plans.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ----------------------------------------------------------
   SUMMARY STATS
---------------------------------------------------------- */
$total_plans = count($plans);
$draft_count = 0;
$submitted_count = 0;
$approved_count = 0;

foreach ($plans as $plan) {
    $status = (string)($plan['status'] ?? '');
    if ($status === 'draft') {
        $draft_count++;
    } elseif ($status === 'submitted') {
        $submitted_count++;
    } elseif ($status === 'approved') {
        $approved_count++;
    }
}

$page_title = 'My Activities';
include 'includes/header.php';
?>
<style>
:root{
    --ink:#0f172a;
    --muted:#64748b;
    --line:#e2e8f0;
    --bg:#f8fafc;
    --card:#ffffff;
    --green:#ff9800;
    --blue:#ff9800;
    --orange:#ea580c;
    --red:#dc2626;
    --radius:14px;
    --shadow:0 10px 30px rgba(15,23,42,.06);
}
body{background:var(--bg)}
.page-wrap{max-width:1180px;margin:0 auto;padding:24px 16px 80px}
.hero{
    background:linear-gradient(135deg,#ff5722 0%,#ff9800 100%);
    color:#fff;border-radius:18px;padding:28px 30px;margin-bottom:20px;
    display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap
}
.hero h1{margin:0 0 8px;font-size:28px;font-weight:800}
.hero p{margin:0;opacity:.92;font-size:14px}
.btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:10px 16px;border-radius:10px;border:1px solid transparent;
    text-decoration:none;font-weight:700;font-size:14px;cursor:pointer
}
.btn-white{background:#fff;color:#166534}
.btn-outline{background:#fff;color:#0f172a;border-color:var(--line)}
.stats{
    display:grid;grid-template-columns:repeat(4,1fr);
    gap:14px;margin-bottom:20px
}
.stat{
    background:var(--card);border:1px solid var(--line);border-radius:14px;
    padding:18px;box-shadow:var(--shadow)
}
.stat-label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.stat-val{font-size:28px;font-weight:800;color:var(--ink)}
.toolbar{
    background:#fff;border:1px solid var(--line);border-radius:14px;
    padding:14px;box-shadow:var(--shadow);margin-bottom:18px;
    display:flex;gap:10px;flex-wrap:wrap;align-items:center
}
.toolbar select,.toolbar input[type="text"]{
    padding:10px 12px;border:1px solid var(--line);border-radius:10px;
    font-size:14px;background:#fff;min-width:160px
}
.toolbar .spacer{flex:1}
.card{
    background:#fff;border:1px solid var(--line);border-radius:14px;
    box-shadow:var(--shadow);overflow:hidden
}
.table-wrap{overflow-x:auto}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:14px 16px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:middle}
.table th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;letter-spacing:.04em}
.table tr:hover td{background:#fcfcfd}
.badge{
    display:inline-flex;align-items:center;padding:5px 12px;border-radius:999px;
    font-size:11px;font-weight:700;text-transform:uppercase
}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.link-btn{
    display:inline-flex;align-items:center;gap:6px;padding:8px 12px;
    border:1px solid var(--line);border-radius:10px;background:#fff;
    text-decoration:none;font-size:13px;font-weight:600;color:#0f172a
}
.link-btn.green{color:#166534;border-color:#bbf7d0;background:#f0fdf4}
.link-btn.orange{color:#9a3412;border-color:#fed7aa;background:#fff7ed}
.empty{
    text-align:center;padding:56px 20px;background:#fff;border:1px dashed #cbd5e1;
    border-radius:18px;color:var(--muted)
}
.flash{
    padding:12px 14px;border-radius:12px;margin-bottom:16px;font-size:14px
}
.flash.success{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.flash.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
@media (max-width:900px){
    .stats{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:640px){
    .stats{grid-template-columns:1fr 1fr}
    .page-wrap{padding:8px 0 48px}
    .hero{padding:20px;border-radius:14px}
    .hero h1{font-size:22px}
    .toolbar select,.toolbar input[type="text"]{flex:1 1 100%;min-width:0}
    .toolbar .btn{flex:1 1 auto;justify-content:center}
    .toolbar .spacer{display:none}
}
.table .status-pill{text-transform:none}
</style>

<div class="page-wrap">

    <div class="hero">
        <div>
            <h1>My Activity Plans</h1>
            <p>Create, track, edit drafts, and view submitted or approved activity plans.</p>
        </div>
        <div>
            <a href="create-activity" class="btn btn-white">+ New Activity Plan</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['activity_success'])): ?>
        <div class="flash success"><?= h($_SESSION['activity_success']) ?></div>
        <?php unset($_SESSION['activity_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['activity_error'])): ?>
        <div class="flash error"><?= h($_SESSION['activity_error']) ?></div>
        <?php unset($_SESSION['activity_error']); ?>
    <?php endif; ?>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">Total Plans</div>
            <div class="stat-val"><?= $total_plans ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Drafts</div>
            <div class="stat-val"><?= $draft_count ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Submitted</div>
            <div class="stat-val"><?= $submitted_count ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Approved</div>
            <div class="stat-val"><?= $approved_count ?></div>
        </div>
    </div>

    <form method="GET" class="toolbar" aria-label="Filter activity plans">
        <select name="status" onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="under_review" <?= $status_filter === 'under_review' ? 'selected' : '' ?>>Under Review</option>
            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>

        <select name="fiscal_year" onchange="this.form.submit()" aria-label="Filter by fiscal year">
            <option value="">All Years</option>
            <?php foreach ($years as $year): ?>
                <option value="<?= $year ?>" <?= $year_filter === $year ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search plan title or category" aria-label="Search plan title or category">

        <button type="submit" class="btn btn-outline">Filter</button>

        <div class="spacer"></div>

        <?php if ($status_filter !== '' || $year_filter > 0 || $q !== ''): ?>
            <a href="my-activities" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (empty($plans)): ?>
        <div class="empty">
            <div style="font-size:36px;margin-bottom:10px;color:#94a3b8;"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div>
            <div style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:6px;">No activity plans found</div>
            <div>Start by creating a new activity plan.</div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Category</th>
                            <th>FY</th>
                            <th>Activities</th>
                            <th>Weekly Entries</th>
                            <th>Total Weight</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th style="width:260px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        [$label, $fg, $bg] = status_meta((string)$plan['status']);
                        $can_edit = in_array((string)$plan['status'], ['draft', 'rejected'], true);
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= h($plan['plan_title']) ?></div>
                                <div style="font-size:12px;color:#64748b;">
                                    Created: <?= fmt_date($plan['created_at']) ?>
                                </div>
                            </td>
                            <td><?= h($plan['category_name'] ?? '-') ?></td>
                            <td><?= h($plan['fiscal_year'] ?? '-') ?></td>
                            <td><?= (int)$plan['activity_count'] ?></td>
                            <td><?= (int)$plan['weekly_count'] ?></td>
                            <td><?= number_format((float)($plan['total_weight'] ?? 0), 1) ?>%</td>
                            <td>
                                <span class="status-pill status-<?= h(strtolower((string)$plan['status'])) ?>">
                                    <?= h($label) ?>
                                </span>
                            </td>
                            <td><?= fmt_date($plan['updated_at']) ?></td>
                            <td>
                                <div class="actions">
                                    <a href="view-activity?id=<?= (int)$plan['plan_id'] ?>" class="link-btn">View</a>

                                    <?php if ($can_edit): ?>
                                        <a href="edit-activity?id=<?= (int)$plan['plan_id'] ?>" class="link-btn orange">Edit</a>
                                    <?php endif; ?>

                                    <?php if ((string)$plan['status'] === 'approved'): ?>
                                        <a href="view-activity?id=<?= (int)$plan['plan_id'] ?>#weekly" class="link-btn green">Weekly Progress</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
include 'includes/footer.php';
ob_end_flush();
?>