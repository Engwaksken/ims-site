<?php
ob_start();
session_start();
require_once 'includes/config.php';

/* ----------------------------------------------------------
   AUTH GUARD
---------------------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$uid     = (int)$_SESSION['user_id'];
$plan_id = (int)($_GET['id'] ?? 0);

if ($plan_id <= 0) {
    $_SESSION['activity_error'] = 'No activity plan specified.';
    header('Location: my-activities.php');
    exit();
}

/* ----------------------------------------------------------
   HELPERS
---------------------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ----------------------------------------------------------
   LOAD PLAN
---------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT ap.*, kc.category_name
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc ON kc.category_id = ap.category_id
    WHERE ap.plan_id = ?
      AND ap.user_id = ?
    LIMIT 1
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('ii', $plan_id, $uid);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$plan) {
    $_SESSION['activity_error'] = 'Activity plan not found or access denied.';
    header('Location: my-activities.php');
    exit();
}

if (!in_array(($plan['status'] ?? 'draft'), ['draft', 'rejected'], true)) {
    $_SESSION['activity_error'] = 'Only draft or rejected activity plans can be edited.';
    header('Location: view-activity.php?id=' . $plan_id);
    exit();
}

/* ----------------------------------------------------------
   LOAD CATEGORIES
---------------------------------------------------------- */
$categories = [];
$res = $conn->query("
    SELECT category_id, category_name
    FROM kpi_categories
    ORDER BY category_name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }
}

/* ----------------------------------------------------------
   LOAD ACTIVITIES
---------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT *
    FROM plan_activities
    WHERE plan_id = ?
    ORDER BY sort_order ASC, activity_id ASC
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('i', $plan_id);
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activity_ids = array_map('intval', array_column($activities, 'activity_id'));

/* ----------------------------------------------------------
   LOAD WEEKLY ENTRIES
---------------------------------------------------------- */
$weekly_map = []; // [activity_id][quarter][w1..w12] = ['actual'=>..., 'comment'=>...]

if (!empty($activity_ids)) {
    $placeholders = implode(',', array_fill(0, count($activity_ids), '?'));
    $sql = "
        SELECT *
        FROM activity_weekly_entries
        WHERE plan_id = ?
          AND activity_id IN ($placeholders)
        ORDER BY activity_id ASC, quarter ASC, week_number ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Prepare failed: ' . h($conn->error));
    }

    $types = 'i' . str_repeat('i', count($activity_ids));
    $params = array_merge([$types, $plan_id], $activity_ids);

    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($entries as $entry) {
        $aid = (int)$entry['activity_id'];
        $quarter = (string)$entry['quarter'];
        $weekKey = 'w' . (int)$entry['week_number'];

        $weekly_map[$aid][$quarter][$weekKey] = [
            'actual'  => $entry['actual_value'],
            'comment' => $entry['comment'],
        ];
    }
}

/* ----------------------------------------------------------
   PAGE
---------------------------------------------------------- */
$page_title = 'Edit Activity Plan';
include 'includes/header.php';
?>
<style>
:root{
    --ink:#0f172a;
    --muted:#64748b;
    --line:#e2e8f0;
    --bg:#f8fafc;
    --card:#ffffff;
    --green:#16a34a;
    --green-soft:#dcfce7;
    --orange:#ea580c;
    --orange-soft:#fff7ed;
    --red:#dc2626;
    --blue:#2563eb;
    --radius:12px;
    --shadow:0 8px 24px rgba(15,23,42,.06);
}
body{background:var(--bg)}
.page-wrap{max-width:1200px;margin:0 auto;padding:24px 16px 80px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.topbar a{text-decoration:none;color:var(--muted);font-weight:600}
.hero{background:linear-gradient(135deg,#15803d 0%,#16a34a 100%);color:#fff;border-radius:16px;padding:24px 28px;margin-bottom:20px}
.hero h1{margin:0 0 6px;font-size:26px}
.hero p{margin:0;opacity:.9}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden}
.card-header{padding:14px 18px;border-bottom:1px solid var(--line);font-weight:700;background:#fafafa}
.card-body{padding:18px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.form-control{width:100%;padding:10px 12px;border:1.5px solid var(--line);border-radius:10px;background:#fff;font-size:14px;outline:none}
.form-control:focus{border-color:var(--green)}
textarea.form-control{min-height:90px;resize:vertical}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;border:1px solid transparent;text-decoration:none;font-weight:700;font-size:14px;cursor:pointer}
.btn-green{background:var(--green);color:#fff}
.btn-outline{background:#fff;color:var(--ink);border-color:var(--line)}
.btn-orange{background:var(--orange);color:#fff}
.activities-wrap{display:flex;flex-direction:column;gap:16px}
.activity-card{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}
.activity-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#f8fafc;border-bottom:1px solid var(--line)}
.activity-title{font-weight:700}
.activity-body{padding:16px}
.row-actions{display:flex;gap:8px;flex-wrap:wrap}
.small-btn{padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:#fff;cursor:pointer;font-size:13px;font-weight:600}
.small-btn.remove{color:var(--red);border-color:#fecaca;background:#fff5f5}
.weekly-block{margin-top:16px;border-top:1px dashed var(--line);padding-top:16px}
.quarter-box{margin-bottom:18px;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.quarter-head{background:#f8fafc;padding:10px 12px;font-weight:700;border-bottom:1px solid var(--line)}
.week-grid{overflow-x:auto}
.week-table{width:100%;border-collapse:collapse;font-size:13px}
.week-table th,.week-table td{border-bottom:1px solid #eef2f7;padding:8px 10px;text-align:left;vertical-align:top}
.week-table th{background:#fcfcfd;font-size:11px;text-transform:uppercase;color:var(--muted)}
.week-input{width:100%;padding:8px 9px;border:1px solid var(--line);border-radius:8px}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px}
.alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
@media (max-width:900px){
    .grid,.grid-2{grid-template-columns:1fr}
}
</style>

<div class="page-wrap">

    <div class="topbar">
        <a href="view-activity.php?id=<?= $plan_id ?>">? Back to Activity Plan</a>
    </div>

    <div class="hero">
        <h1>Edit Activity Plan</h1>
        <p>Update your draft plan, activities, weights, and weekly targets before submitting for review.</p>
    </div>

    <?php if (!empty($_SESSION['activity_error'])): ?>
        <div class="alert alert-danger">
            <?= h($_SESSION['activity_error']) ?>
        </div>
        <?php unset($_SESSION['activity_error']); ?>
    <?php endif; ?>

    <form method="POST" action="includes/activity-update-process.php" id="activityForm">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="plan_id" value="<?= $plan_id ?>">
        <input type="hidden" name="department_id" value="<?= (int)$plan['department_id'] ?>">

        <div class="card">
            <div class="card-header">Plan Details</div>
            <div class="card-body">
                <div class="grid">
                    <div class="form-group">
                        <label class="form-label">Plan Title</label>
                        <input type="text" name="plan_title" class="form-control" value="<?= h($plan['plan_title']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>" <?= (int)$plan['category_id'] === (int)$cat['category_id'] ? 'selected' : '' ?>>
                                    <?= h($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fiscal Year</label>
                        <input type="number" name="fiscal_year" class="form-control" min="2000" max="2100" value="<?= (int)$plan['fiscal_year'] ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <span>Activities</span>
                <button type="button" class="btn btn-outline" onclick="addActivity()">+ Add Activity</button>
            </div>
            <div class="card-body">
                <div id="activitiesWrap" class="activities-wrap"></div>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">
            <a href="view-activity.php?id=<?= $plan_id ?>" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-outline">Save Draft</button>
            <button type="submit" name="submit_plan" value="1" class="btn btn-green" onclick="return confirm('Submit this activity plan for review?')">Save & Submit</button>
        </div>
    </form>
</div>

<script>
const activitiesWrap = document.getElementById('activitiesWrap');
let activityIndex = 0;

const existingActivities = <?= json_encode($activities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const existingWeekly = <?= json_encode($weekly_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function weekRowHtml(idx, quarter, weekNo, weeklyData = {}) {
    const weekKey = 'w' + weekNo;
    const item = weeklyData[weekKey] || {};
    const actual = item.actual ?? '';
    const comment = item.comment ?? '';

    return `
        <tr>
            <td>Week ${weekNo}</td>
            <td>
                <input type="number" step="0.01"
                       name="weekly[${quarter}][${idx}][${weekKey}][actual]"
                       class="week-input"
                       value="${escapeHtml(String(actual))}">
            </td>
            <td>
                <input type="text"
                       name="weekly[${quarter}][${idx}][${weekKey}][comment]"
                       class="week-input"
                       value="${escapeHtml(String(comment))}">
            </td>
        </tr>
    `;
}

function quarterHtml(idx, quarter, weeklyData = {}) {
    let rows = '';
    for (let w = 1; w <= 12; w++) {
        rows += weekRowHtml(idx, quarter, w, weeklyData);
    }

    return `
        <div class="quarter-box">
            <div class="quarter-head">${quarter.toUpperCase()}</div>
            <div class="week-grid">
                <table class="week-table">
                    <thead>
                        <tr>
                            <th>Week</th>
                            <th>Actual</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </div>
    `;
}

function activityCardHtml(idx, data = {}, weeklyData = {}) {
    const description = data.description ?? '';
    const keyResult = data.key_result ?? '';
    const weight = data.weight ?? '';
    const unit = data.unit_of_measure ?? '';
    const annualTarget = data.annual_target ?? '';

    return `
        <div class="activity-card" data-idx="${idx}">
            <div class="activity-head">
                <div class="activity-title">Activity #${idx + 1}</div>
                <div class="row-actions">
                    <button type="button" class="small-btn remove" onclick="removeActivity(${idx})">Remove</button>
                </div>
            </div>
            <div class="activity-body">
                <div class="form-group">
                    <label class="form-label">Activity Description</label>
                    <textarea name="activities[${idx}][description]" class="form-control" required>${escapeHtml(String(description))}</textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Key Result</label>
                    <textarea name="activities[${idx}][key_result]" class="form-control" required>${escapeHtml(String(keyResult))}</textarea>
                </div>

                <div class="grid">
                    <div class="form-group">
                        <label class="form-label">Weight (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="activities[${idx}][weight]" class="form-control" value="${escapeHtml(String(weight))}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Unit of Measure</label>
                        <input type="text" name="activities[${idx}][unit]" class="form-control" value="${escapeHtml(String(unit))}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Annual Target</label>
                        <input type="number" step="0.01" min="0" name="activities[${idx}][annual_target]" class="form-control" value="${escapeHtml(String(annualTarget))}">
                    </div>
                </div>

                <div class="weekly-block">
                    <div class="form-label" style="margin-bottom:10px;">Weekly Entries / Targets</div>
                    ${quarterHtml(idx, 'q1', weeklyData.q1 || {})}
                    ${quarterHtml(idx, 'q2', weeklyData.q2 || {})}
                    ${quarterHtml(idx, 'q3', weeklyData.q3 || {})}
                    ${quarterHtml(idx, 'q4', weeklyData.q4 || {})}
                </div>
            </div>
        </div>
    `;
}

function renderActivities() {
    activitiesWrap.innerHTML = '';
    document.querySelectorAll('.activity-card').forEach((card, i) => {
        const title = card.querySelector('.activity-title');
        if (title) title.textContent = 'Activity #' + (i + 1);
    });
}

function addActivity(data = {}, weeklyData = {}) {
    const idx = activityIndex++;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = activityCardHtml(idx, data, weeklyData);
    activitiesWrap.appendChild(wrapper.firstElementChild);
    refreshActivityTitles();
}

function removeActivity(idx) {
    const card = activitiesWrap.querySelector(`.activity-card[data-idx="${idx}"]`);
    if (card) {
        card.remove();
        refreshActivityTitles();
    }
}

function refreshActivityTitles() {
    const cards = activitiesWrap.querySelectorAll('.activity-card');
    cards.forEach((card, i) => {
        const title = card.querySelector('.activity-title');
        if (title) title.textContent = 'Activity #' + (i + 1);
    });
}

function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function () {
    if (existingActivities.length > 0) {
        existingActivities.forEach(act => {
            const aid = String(act.activity_id);
            addActivity(act, existingWeekly[aid] || {});
        });
    } else {
        addActivity();
    }
});
</script>

<?php
include 'includes/footer.php';
ob_end_flush();
?>