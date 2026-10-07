<?php
ob_start();
session_start();
require_once 'includes/config.php';

/* -----------------------------------------------
   AUTH GUARD
----------------------------------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$uid     = (int)$_SESSION['user_id'];
$plan_id = (int)($_GET['id'] ?? 0);

if ($plan_id <= 0) {
    $_SESSION['activity_error'] = 'No activity plan specified.';
    header('Location: my-activities.php');
    exit();
}

/* -----------------------------------------------
   HELPERS
----------------------------------------------- */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date(?string $d): string
{
    if (!$d || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') {
        return '-';
    }
    return date('d M Y', strtotime($d));
}

function progress_color(int $pct): string
{
    if ($pct >= 100) return '#16a34a';
    if ($pct >= 60)  return '#ff9800';
    if ($pct >= 30)  return '#f59e0b';
    return '#ef4444';
}

function quarter_label(string $q): string
{
    return strtoupper($q);
}

/* -----------------------------------------------
   LOAD PLAN
----------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        ap.*,
        kc.category_name,
        u.full_name AS owner_display_name,
        ed.supervisor_id,
        su.full_name AS supervisor_name
    FROM activity_plans ap
    LEFT JOIN kpi_categories kc
        ON kc.category_id = ap.category_id
    LEFT JOIN users u
        ON u.user_id = ap.user_id
    LEFT JOIN employee_directory ed
        ON ed.user_id = ap.user_id
    LEFT JOIN users su
        ON su.user_id = ed.supervisor_id
    WHERE ap.plan_id = ?
      AND (
            ap.user_id = ?
         OR ed.supervisor_id = ?
      )
    LIMIT 1
");
if (!$stmt) {
    die('Prepare failed: ' . h($conn->error));
}

$stmt->bind_param('iii', $plan_id, $uid, $uid);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$plan) {
    $_SESSION['activity_error'] = 'Activity plan not found or access denied.';
    header('Location: my-activities.php');
    exit();
}

$is_owner      = ((int)$plan['user_id'] === $uid);
$is_supervisor = ((int)($plan['supervisor_id'] ?? 0) === $uid);
$status        = (string)($plan['status'] ?? 'draft');

/* -----------------------------------------------
   FETCH ACTIVITIES
----------------------------------------------- */
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

/* -----------------------------------------------
   FETCH WEEKLY ENTRIES
----------------------------------------------- */
$entries_by_activity = [];
$all_entries = [];

if (!empty($activity_ids)) {
    $placeholders = implode(',', array_fill(0, count($activity_ids), '?'));
    $sql = "
        SELECT *
        FROM activity_weekly_entries
        WHERE plan_id = ?
          AND activity_id IN ($placeholders)
        ORDER BY activity_id ASC, quarter ASC, week_number ASC, entry_id ASC
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
    $all_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($all_entries as $entry) {
        $aid = (int)$entry['activity_id'];
        $entries_by_activity[$aid][] = $entry;
    }
}

/* -----------------------------------------------
   AGGREGATE STATS PER ACTIVITY
----------------------------------------------- */
foreach ($activities as &$act) {
    $aid = (int)$act['activity_id'];
    $entries = $entries_by_activity[$aid] ?? [];

    $act['entry_count'] = count($entries);
    $act['total_actual'] = 0;

    foreach ($entries as $entry) {
        $act['total_actual'] += (float)($entry['actual_value'] ?? 0);
    }

    $target = (float)($act['annual_target'] ?? 0);
    $actual = (float)$act['total_actual'];

    $act['progress_pct'] = $target > 0
        ? min(100, (int)round(($actual / $target) * 100))
        : 0;
}
unset($act);

/* -----------------------------------------------
   PLAN LEVEL STATS
----------------------------------------------- */
$total_activities = count($activities);
$total_entries    = count($all_entries);
$avg_progress     = $total_activities > 0
    ? (int)round(array_sum(array_column($activities, 'progress_pct')) / $total_activities)
    : 0;

$total_weight = 0.0;
foreach ($activities as $act) {
    $total_weight += (float)($act['weight'] ?? 0);
}

/* -----------------------------------------------
   STATUS META
----------------------------------------------- */
$status_map = [
    'draft'      => ['Draft', 'badge-draft'],
    'submitted'  => ['Submitted', 'badge-submitted'],
    'approved'   => ['Approved', 'badge-approved'],
    'completed'  => ['Completed', 'badge-approved'],
    'rejected'   => ['Rejected', 'badge-rejected'],
];
$status_meta = $status_map[$status] ?? [ucfirst($status), 'badge-draft'];

$page_title = 'View Activity Plan';
include 'includes/header.php';
?>
<style>
:root{
    --ink:#0d1117;
    --surface:#f0f2f5;
    --panel:#fff;
    --border:#e2e8f0;
    --muted:#94a3b8;
    --text:#334155;
    --green:#16a34a;
    --green-lt:#dcfce7;
    --orange:#ea580c;
    --orange-lt:#fff7ed;
    --amber:#d97706;
    --amber-lt:#fef3c7;
    --red:#dc2626;
    --red-lt:#fee2e2;
    --blue:#ff9800;
    --blue-lt:#dbeafe;
    --radius:10px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.05),0 1px 2px rgba(0,0,0,.04);
    --shadow:0 4px 16px rgba(0,0,0,.07);
}
body{background:var(--surface)}
.page-wrap{max-width:1120px;margin:0 auto;padding:28px 20px 80px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:22px}
.back-link{text-decoration:none;color:#64748b;font-weight:600}
.back-link:hover{color:var(--green)}
.topbar-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn-green{background:var(--green);border-color:var(--green);color:#fff}
.btn-outline{background:#fff;color:#111827}
.hero-banner{background:linear-gradient(135deg,#ff5722 0%, #ff9800 100%);border-radius:12px;padding:24px 28px;color:#fff;margin-bottom:20px}
.hero-inner{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}
.hero-title{font-size:24px;font-weight:800;margin-bottom:6px}
.hero-sub{font-size:13px;opacity:.9;display:flex;flex-wrap:wrap;gap:14px}
.badge{display:inline-flex;align-items:center;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase}
.badge-draft{background:#f1f5f9;color:#64748b}
.badge-submitted{background:var(--blue-lt);color:var(--blue)}
.badge-approved{background:var(--green-lt);color:var(--green)}
.badge-rejected{background:var(--red-lt);color:var(--red)}
.stats-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-tile{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px 20px;box-shadow:var(--shadow-sm)}
.stat-tile-label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:5px}
.stat-tile-val{font-size:28px;font-weight:700}
.stat-tile-sub{font-size:11px;color:var(--muted)}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-sm);margin-bottom:18px;overflow:hidden}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfc;font-weight:700}
.card-body{padding:20px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.info-label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
.info-value{font-size:14px;font-weight:600}
.activity-list{display:flex;flex-direction:column;gap:12px;padding:16px}
.activity-card{border:1px solid var(--border);border-radius:8px;overflow:hidden}
.activity-head{display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fafbfc;border-bottom:1px solid var(--border);cursor:pointer}
.act-num{width:28px;height:28px;border-radius:7px;background:var(--green-lt);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800}
.act-title-block{flex:1}
.act-title{font-weight:700}
.act-meta{font-size:11px;color:var(--muted);display:flex;flex-wrap:wrap;gap:10px;margin-top:3px}
.progress-bar-wrap{width:110px;height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.progress-bar-fill{height:100%;border-radius:99px;transition:width .4s ease}
.progress-label{font-size:12px;font-weight:700;min-width:38px;text-align:right}
.activity-detail{display:none}
.activity-detail.open{display:block}
.detail-section{padding:16px;border-bottom:1px solid var(--border)}
.detail-section:last-child{border-bottom:none}
.detail-label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.detail-text{font-size:13px;color:var(--text);line-height:1.65}
.entries-table{width:100%;border-collapse:collapse;font-size:13px}
.entries-table th{padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafc;border-bottom:2px solid var(--border)}
.entries-table td{padding:9px 12px;border-bottom:1px solid #f1f5f9}
.no-entries{text-align:center;padding:20px;color:var(--muted);font-style:italic}
.act-chevron{transition:transform .2s}
.act-chevron.open{transform:rotate(180deg)}
@media (max-width:860px){.stats-strip{grid-template-columns:repeat(2,1fr)}}
@media (max-width:560px){.stats-strip{grid-template-columns:1fr 1fr}.progress-bar-wrap{width:70px}}
</style>

<div class="page-wrap">

    <div class="topbar">
        <a href="my-activities.php" class="back-link"><i class="fa fa-arrow-left"></i> Activity Plans</a>

        <div class="topbar-actions">
            <?php if ($is_owner && $status === 'draft'): ?>
                <a href="edit-activity.php?id=<?= $plan_id ?>" class="btn btn-outline">Edit Plan</a>

                <form method="POST" action="includes/activity-process.php" style="display:inline">
                    <input type="hidden" name="action" value="submit_activity_plan">
                    <input type="hidden" name="plan_id" value="<?= $plan_id ?>">
                    <button type="submit" class="btn btn-green" onclick="return confirm('Submit this activity plan for supervisor review?')">
                        Submit for Review
                    </button>
                </form>
            <?php endif; ?>

            <button onclick="window.print()" class="btn btn-outline">Print</button>
        </div>
    </div>

    <div class="hero-banner">
        <div class="hero-inner">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;opacity:.8;margin-bottom:4px;">
                    Activity Plan - #<?= $plan_id ?>
                </div>
                <div class="hero-title"><?= h($plan['plan_title']) ?></div>
                <div class="hero-sub">
                    <?php if (!empty($plan['category_name'])): ?>
                        <span><?= h($plan['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($plan['fiscal_year'])): ?>
                        <span>FY <?= h($plan['fiscal_year']) ?></span>
                    <?php endif; ?>
                    <span><?= number_format($total_weight, 1) ?>% total weight</span>
                </div>
            </div>

            <div>
                <span class="badge <?= h($status_meta[1]) ?>"><?= h($status_meta[0]) ?></span>
            </div>
        </div>
    </div>

    <div class="stats-strip">
        <div class="stat-tile">
            <div class="stat-tile-label">Activities</div>
            <div class="stat-tile-val"><?= $total_activities ?></div>
            <div class="stat-tile-sub">defined in plan</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-label">Weekly Entries</div>
            <div class="stat-tile-val"><?= $total_entries ?></div>
            <div class="stat-tile-sub">recorded updates</div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-label">Total Weight</div>
            <div class="stat-tile-val" style="color:<?= abs($total_weight - 100) < 1 ? 'var(--green)' : 'var(--amber)' ?>">
                <?= number_format($total_weight, 1) ?>%
            </div>
            <div class="stat-tile-sub"><?= abs($total_weight - 100) < 1 ? 'balanced' : 'target 100%' ?></div>
        </div>
        <div class="stat-tile">
            <div class="stat-tile-label">Avg Progress</div>
            <div class="stat-tile-val" style="color:<?= progress_color($avg_progress) ?>">
                <?= $avg_progress ?>%
            </div>
            <div class="stat-tile-sub">overall completion</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Plan Details</div>
        <div class="card-body">
            <div class="info-grid">
                <div>
                    <div class="info-label">Plan Owner</div>
                    <div class="info-value"><?= h($plan['owner_display_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="info-label">Department</div>
                    <div class="info-value"><?= h($plan['department_id'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="info-label">Category</div>
                    <div class="info-value"><?= h($plan['category_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="info-label">Fiscal Year</div>
                    <div class="info-value"><?= h($plan['fiscal_year'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="info-label">Supervisor</div>
                    <div class="info-value"><?= h($plan['supervisor_name'] ?? '-') ?></div>
                </div>
                <div>
                    <div class="info-label">Created</div>
                    <div class="info-value"><?= fmt_date($plan['created_at'] ?? null) ?></div>
                </div>
                <?php if (!empty($plan['submitted_at'])): ?>
                    <div>
                        <div class="info-label">Submitted</div>
                        <div class="info-value"><?= fmt_date($plan['submitted_at']) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($plan['updated_at'])): ?>
                    <div>
                        <div class="info-label">Updated</div>
                        <div class="info-value"><?= fmt_date($plan['updated_at']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($plan['mgr_overall_comment'])): ?>
                <div style="margin-top:18px;padding:14px 16px;background:var(--amber-lt);border:1px solid #fcd34d;border-left:4px solid var(--amber);border-radius:8px">
                    <div class="info-label" style="color:var(--amber)">Manager Overall Comment</div>
                    <div style="font-size:13px;color:var(--text);line-height:1.65;margin-top:4px">
                        <?= nl2br(h($plan['mgr_overall_comment'])) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            Activities &amp; Weekly Progress
        </div>

        <?php if (empty($activities)): ?>
            <div class="card-body" style="text-align:center;color:var(--muted)">
                No activities recorded for this plan.
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($activities as $i => $act): ?>
                    <?php
                    $pct = (int)$act['progress_pct'];
                    $pcolor = progress_color($pct);
                    $entries = $entries_by_activity[(int)$act['activity_id']] ?? [];
                    ?>
                    <div class="activity-card">
                        <div class="activity-head" onclick="toggleActivity(this)">
                            <span class="act-num"><?= $i + 1 ?></span>

                            <div class="act-title-block">
                                <div class="act-title"><?= h($act['description']) ?></div>
                                <div class="act-meta">
                                    <?php if (!empty($act['unit_of_measure'])): ?>
                                        <span>Unit: <?= h($act['unit_of_measure']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((float)($act['annual_target'] ?? 0) > 0): ?>
                                        <span>Target: <strong><?= number_format((float)$act['annual_target'], 1) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ((float)$act['total_actual'] > 0): ?>
                                        <span style="color:var(--green)">Actual: <strong><?= number_format((float)$act['total_actual'], 1) ?></strong></span>
                                    <?php endif; ?>
                                    <span><?= (int)$act['entry_count'] ?> entr<?= (int)$act['entry_count'] !== 1 ? 'ies' : 'y' ?></span>
                                </div>
                            </div>

                            <?php if ((float)($act['weight'] ?? 0) > 0): ?>
                                <span style="padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;background:var(--orange-lt);color:var(--orange)">
                                    <?= number_format((float)$act['weight'], 1) ?>%
                                </span>
                            <?php endif; ?>

                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $pcolor ?>"></div>
                                </div>
                                <span class="progress-label" style="color:<?= $pcolor ?>"><?= $pct ?>%</span>
                            </div>

                            <span class="act-chevron"><i class="fa fa-arrow-down"></i></span>
                        </div>

                        <div class="activity-detail">
                            <?php if (!empty($act['key_result'])): ?>
                                <div class="detail-section">
                                    <div class="detail-label">Key Result</div>
                                    <div class="detail-text"><?= nl2br(h($act['key_result'])) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($act['mgr_comment'])): ?>
                                <div class="detail-section">
                                    <div class="detail-label">Manager Comment</div>
                                    <div class="detail-text"><?= nl2br(h($act['mgr_comment'])) ?></div>
                                </div>
                            <?php endif; ?>

                            <div class="detail-section">
                                <div class="detail-label">Weekly Entries (<?= count($entries) ?>)</div>

                                <?php if (empty($entries)): ?>
                                    <div class="no-entries">No weekly entries recorded yet.</div>
                                <?php else: ?>
                                    <div style="overflow-x:auto">
                                        <table class="entries-table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Quarter</th>
                                                    <th>Week</th>
                                                    <th>Actual Value</th>
                                                    <th>Comment</th>
                                                    <th>Logged</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($entries as $ei => $entry): ?>
                                                <tr>
                                                    <td><?= $ei + 1 ?></td>
                                                    <td><?= h(quarter_label((string)$entry['quarter'])) ?></td>
                                                    <td>Week <?= (int)$entry['week_number'] ?></td>
                                                    <td>
                                                        <?= number_format((float)$entry['actual_value'], 1) ?>
                                                        <?php if (!empty($act['unit_of_measure'])): ?>
                                                            <?= h($act['unit_of_measure']) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($entry['comment'])): ?>
                                                            <?= h($entry['comment']) ?>
                                                        <?php else: ?>
                                                            <span style="color:var(--muted);font-style:italic">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= fmt_date($entry['created_at'] ?? null) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_supervisor && $status === 'submitted'): ?>
        <div class="card">
            <div class="card-header">Supervisor Review</div>
            <div class="card-body">
                <form method="POST" action="includes/activity-review-process.php">
                    <input type="hidden" name="action" value="review_activity_plan">
                    <input type="hidden" name="plan_id" value="<?= $plan_id ?>">

                    <?php if (!empty($activities)): ?>
                        <div style="overflow-x:auto;margin-bottom:20px">
                            <table class="entries-table">
                                <thead>
                                    <tr>
                                        <th>Activity</th>
                                        <th>Target</th>
                                        <th>Actual</th>
                                        <th>Progress</th>
                                        <th>Manager Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td><?= h($act['description']) ?></td>
                                        <td><?= (float)($act['annual_target'] ?? 0) > 0 ? number_format((float)$act['annual_target'], 1) : '-' ?></td>
                                        <td><?= number_format((float)$act['total_actual'], 1) ?></td>
                                        <td><?= (int)$act['progress_pct'] ?>%</td>
                                        <td>
                                            <textarea name="activity[<?= (int)$act['activity_id'] ?>][mgr_comment]" class="form-control" rows="2"><?= h($act['mgr_comment'] ?? '') ?></textarea>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div style="margin-bottom:16px">
                        <label class="info-label">Overall Manager Comment</label>
                        <textarea name="mgr_overall_comment" class="form-control" rows="4"><?= h($plan['mgr_overall_comment'] ?? '') ?></textarea>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
                        <button type="submit" name="save_review" class="btn btn-outline">Save Draft Review</button>
                        <button type="submit" name="request_changes" class="btn">Request Changes</button>
                        <button type="submit" name="approve_plan" class="btn btn-green" onclick="return confirm('Approve this activity plan?')">Approve Plan</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>

<script>
function toggleActivity(headerEl) {
    const card = headerEl.closest('.activity-card');
    const detail = card.querySelector('.activity-detail');
    const chev = card.querySelector('.act-chevron');
    const isOpen = detail.classList.contains('open');

    detail.classList.toggle('open', !isOpen);
    chev.classList.toggle('open', !isOpen);
}

document.addEventListener('DOMContentLoaded', function () {
    const cards = document.querySelectorAll('.activity-card');
    if (cards.length === 1) {
        const head = cards[0].querySelector('.activity-head');
        if (head) toggleActivity(head);
    }

    document.querySelectorAll('.progress-bar-fill').forEach(bar => {
        const target = bar.style.width;
        bar.style.width = '0';
        requestAnimationFrame(() => {
            setTimeout(() => {
                bar.style.width = target;
            }, 80);
        });
    });
});
</script>
<?php ob_end_flush(); ?>