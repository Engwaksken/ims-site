<?php

$page_title = 'Behavioral Competency Assessment';
include 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit();
}

$uid = (int)$_SESSION['user_id'];


$profile_q = $conn->query("
    SELECT
        u.full_name,
        d.department_name,
        ed.job_title,
        CONCAT(su.full_name) AS supervisor_name,
        su.user_id AS supervisor_id
    FROM users u
    LEFT JOIN employee_directory ed ON ed.user_id = u.user_id
    LEFT JOIN departments d ON d.department_id = ed.department_id
    LEFT JOIN users su ON su.user_id = ed.supervisor_id
    WHERE u.user_id = $uid LIMIT 1
");
$profile = $profile_q ? $profile_q->fetch_assoc() : [];

if (!$profile || !$profile['job_title']) {
    send_notification($uid,'Please complete your employee profile first.','warning');
    header("Location: my-profile.php"); exit();
}

// -- Fetch active competency groups + indicators --
$groups = [];
$gr = $conn->query("SELECT * FROM bc_groups WHERE is_active=1 ORDER BY sort_order, group_id");
while ($g = $gr->fetch_assoc()) {
    $g['indicators'] = [];
    $ir = $conn->prepare("SELECT * FROM bc_indicators WHERE group_id=? AND is_active=1 ORDER BY sort_order, indicator_id");
    $ir->bind_param('i', $g['group_id']);
    $ir->execute();
    $res = $ir->get_result();
    while ($ind = $res->fetch_assoc()) {
        $g['indicators'][] = $ind;
    }
    $ir->close();
    if (!empty($g['indicators'])) $groups[] = $g;
}

if (empty($groups)) {
    echo '<div style="padding:40px;text-align:center;color:#94a3b8;">
        <i class="fas fa-exclamation-circle" style="font-size:32px;margin-bottom:12px;display:block;"></i>
        No behavioral competency groups have been configured yet. Please contact your administrator.
    </div>';
    include 'includes/footer.php';
    exit();
}

$current_year = date('Y');
?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
:root {
    --ink: #0d1117;
    --surface: #f0f2f5;
    --panel: #fff;
    --accent: #5b5ef4;
    --accent-lt: #ededfd;
    --success: #16a34a;
    --success-lt: #dcfce7;
    --danger: #dc2626;
    --danger-lt: #fee2e2;
    --warning: #d97706;
    --border: #e2e8f0;
    --muted: #94a3b8;
    --group-bg: #1e1b4b;
    --sans: 'Plus Jakarta Sans', sans-serif;
    --mono: 'JetBrains Mono', monospace;
    --radius: 10px;
    --shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 12px rgba(0,0,0,.05);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--sans); background: var(--surface); color: var(--ink); font-size: 14px; }

.hero {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    color: #fff; padding: 26px 32px; border-radius: var(--radius);
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; gap: 16px;
}
.hero h2 { font-size: 20px; font-weight: 700; letter-spacing: -.3px; }
.hero p  { font-size: 12px; color: #a5b4fc; margin-top: 3px; }
.hero-btns { display: flex; gap: 10px; flex-shrink: 0; }

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 20px; border-radius: 7px;
    font-family: var(--sans); font-size: 13px; font-weight: 600;
    cursor: pointer; border: 2px solid transparent; transition: all .15s;
    text-decoration: none;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #4f46e5; }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover { background: #15803d; }
.btn-outline { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.3); }
.btn-outline:hover { background: rgba(255,255,255,.25); }
.btn-outline-dark { background: #fff; color: var(--ink); border-color: var(--border); }
.btn-outline-dark:hover { border-color: #94a3b8; }
.btn-danger { background: #fff; color: var(--danger); border-color: var(--border); }
.btn-danger:hover { background: var(--danger-lt); border-color: var(--danger); }
.btn-print { background: #374151; color: #fff; }
.btn-print:hover { background: #1f2937; }

.panel { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 20px; overflow: hidden; }
.panel-head { padding: 14px 20px; border-bottom: 1px solid var(--border); background: #fafbfc; display: flex; align-items: center; justify-content: space-between; }
.panel-head h4 { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.panel-head h4 i { color: var(--accent); }
.panel-body { padding: 20px; }

.field-grid { display: grid; gap: 14px; }
.col-2 { grid-template-columns: 1fr 1fr; }
.col-3 { grid-template-columns: 1fr 1fr 1fr; }
.field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin-bottom: 5px; }
.field input, .field select, .field textarea {
    width: 100%; padding: 8px 11px; border: 1.5px solid var(--border); border-radius: 7px;
    font-family: var(--sans); font-size: 13px; color: var(--ink);
    background: #fff; outline: none; transition: border-color .15s;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(91,94,244,.1); }
.field input[readonly] { background: #f8fafc; color: var(--muted); cursor: not-allowed; }

/* -- Competency Table -- */
.bc-section { margin-bottom: 20px; border: 1.5px solid var(--border); border-radius: var(--radius); overflow: hidden; }

.bc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.bc-table thead th {
    padding: 9px 10px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: var(--muted);
    border-bottom: 2px solid var(--border); background: #f8fafc;
    white-space: nowrap; text-align: center;
}
.bc-table thead th.th-indicator { text-align: left; min-width: 220px; }
.bc-table thead th.th-group {
    background: var(--group-bg); color: #fff; font-size: 13px;
    font-weight: 700; text-align: left; padding: 11px 14px;
    text-transform: none; letter-spacing: 0;
}
.bc-table thead th.th-rating-block {
    background: #f0f4ff; color: var(--accent); font-size: 10px;
    font-weight: 700; padding: 6px 8px;
}
.bc-table thead th.th-mgr-block {
    background: #f0fdf4; color: var(--success);
}
.bc-table thead th.th-agr-block {
    background: #fff7ed; color: var(--warning);
}

.bc-table tbody tr:hover { background: #fafbfc; }
.bc-table td {
    padding: 9px 10px; border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.bc-table tbody tr:last-child td { border-bottom: none; }
.bc-table td.td-indicator { padding-left: 16px; font-size: 13px; line-height: 1.5; }
.bc-table td.td-indicator::before {
    content: '›';
    color: var(--muted); margin-right: 8px; font-size: 16px;
}
.bc-table td.td-rating { text-align: center; width: 52px; }

/* Star rating widget */
.star-rating {
    display: inline-flex; gap: 2px; justify-content: center;
}
.star-rating input[type="radio"] { display: none; }
.star-rating label {
    font-size: 18px; cursor: pointer; color: #d1d5db;
    transition: color .1s; padding: 2px;
    user-select: none;
}
.star-rating input[type="radio"]:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label { color: #fbbf24; }
.star-rating.emp-stars input[type="radio"]:checked ~ label,
.star-rating.emp-stars label:hover  { color: #6366f1; }
.star-rating.mgr-stars input[type="radio"]:checked ~ label,
.star-rating.mgr-stars label:hover  { color: #16a34a; }
.star-rating.agr-stars input[type="radio"]:checked ~ label,
.star-rating.agr-stars label:hover  { color: #d97706; }

/* Also a simple select fallback for compact display */
.rating-select {
    width: 54px; padding: 5px 6px;
    border: 1.5px solid var(--border); border-radius: 6px;
    font-family: var(--mono); font-size: 13px; text-align: center;
    cursor: pointer; outline: none;
}
.rating-select:focus { border-color: var(--accent); }
.rating-select.mgr-sel { border-color: #bbf7d0; background: #f0fdf4; }
.rating-select.agr-sel { border-color: #fed7aa; background: #fff7ed; }
.rating-select[disabled] { background: #f8fafc; color: var(--muted); cursor: not-allowed; }

/* Progress bar per group */
.group-progress {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px; background: #f8fafc;
    border-top: 1px solid var(--border);
    font-size: 12px; color: var(--muted);
}
.gp-bar { flex: 1; height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
.gp-fill { height: 100%; background: var(--accent); border-radius: 99px; transition: width .4s; width: 0; }
.gp-label { font-family: var(--mono); font-size: 11px; min-width: 60px; text-align: right; }

/* Overall comments */
.comments-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    padding: 16px 20px; border-top: 1px solid var(--border);
}
.comment-block label {
    display: block; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin-bottom: 6px;
}
.comment-block textarea {
    width: 100%; padding: 9px 11px; border: 1.5px solid var(--border); border-radius: 7px;
    font-family: var(--sans); font-size: 13px; resize: vertical; outline: none;
}
.comment-block textarea:focus { border-color: var(--accent); }

/* Score summary bar */
.score-bar {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 20px; background: var(--accent-lt);
    border-bottom: 1px solid #c7d2fe; flex-wrap: wrap;
}
.score-box {
    text-align: center; padding: 10px 18px;
    background: #fff; border-radius: 8px;
    border: 1.5px solid #c7d2fe;
    min-width: 100px;
}
.score-box .score-val { font-size: 22px; font-weight: 700; font-family: var(--mono); color: var(--accent); }
.score-box .score-lbl { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }

/* Actions bar */
.actions-bar {
    display: flex; gap: 12px; justify-content: flex-end;
    padding: 18px 20px; background: #fafbfc; border-top: 1px solid var(--border);
    flex-wrap: wrap;
}

/* Print modal */
.modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9000; align-items: flex-start; justify-content: center; padding: 30px 20px; overflow-y: auto; }
.modal-backdrop.open { display: flex; }
.modal-box { background: #fff; border-radius: var(--radius); width: 100%; max-width: 960px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.modal-head { padding: 15px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.modal-head h3 { font-size: 15px; font-weight: 700; }
.modal-body { padding: 20px; overflow-x: auto; }
.modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }
.btn-sm { padding: 6px 14px; font-size: 12px; }

/* Print table styles */
.pt { width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11px; }
.pt td, .pt th { border: 1px solid #999; padding: 4px 7px; vertical-align: top; }
.pt .pt-group { background: #1e1b4b; color: #fff; font-weight: 700; font-size: 12px; }
.pt .pt-subhead { background: #ebebfc; font-weight: 700; font-size: 10px; text-align: center; }
.pt .pt-ind { padding-left: 12px; }
.pt .pt-rating { text-align: center; width: 36px; font-weight: 700; }
.pt .pt-comment-label { font-weight: 700; background: #f2f2f2; }

@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { display: block !important; position: fixed; top: 0; left: 0; width: 100%; }
}
#printArea { display: none; }

@media(max-width:768px) {
    .col-2, .col-3 { grid-template-columns: 1fr; }
    .comments-grid { grid-template-columns: 1fr; }
    .hero { flex-direction: column; }
    .score-bar { flex-direction: column; align-items: flex-start; }
}
</style>

<!-- Hero -->
<div class="hero">
    <div>
        <h2><i class="fas fa-brain"></i> &nbsp;Behavioral Competency Assessment</h2>
        <p>Rate yourself on each behavioral indicator. Your supervisor will complete the Manager Rating.</p>
    </div>
    <div class="hero-btns">
        <button type="button" class="btn btn-print" onclick="generatePreview()">
            <i class="fas fa-eye"></i> Preview & Print
        </button>
        <a href="my-appraisals.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<form method="POST" action="bc-process.php" id="bcForm">
<input type="hidden" name="action" value="create_bc">

<!-- Personal Info -->
<div class="panel">
    <div class="panel-head">
        <h4><i class="fas fa-user"></i> Personal Information</h4>
    </div>
    <div class="panel-body">
        <div class="field-grid col-3" style="margin-bottom:14px">
            <div class="field">
                <label>Name</label>
                <input type="text" name="full_name" value="<?php echo htmlspecialchars($profile['full_name']); ?>" readonly>
            </div>
            <div class="field">
                <label>Department</label>
                <input type="text" name="department" value="<?php echo htmlspecialchars($profile['department_name'] ?? ''); ?>" readonly>
            </div>
            <div class="field">
                <label>Job Title</label>
                <input type="text" name="job_title" value="<?php echo htmlspecialchars($profile['job_title'] ?? ''); ?>" readonly>
            </div>
        </div>
        <div class="field-grid col-3">
            <div class="field">
                <label>Supervisor</label>
                <input type="text" value="<?php echo htmlspecialchars($profile['supervisor_name'] ?? 'Not assigned'); ?>" readonly>
                <input type="hidden" name="supervisor_id" value="<?php echo $profile['supervisor_id'] ?? ''; ?>">
            </div>
            <div class="field">
                <label>Appraisal Period <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="date" name="period_from" value="<?php echo $current_year; ?>-01-01" required style="flex:1">
                    <span style="color:var(--muted);font-size:12px">to</span>
                    <input type="date" name="period_to"   value="<?php echo $current_year; ?>-12-31" required style="flex:1">
                </div>
            </div>
            <div class="field">
                <label>Discussion Date</label>
                <input type="date" name="discussion_date">
            </div>
        </div>
    </div>
</div>

<!-- Score summary (live) -->
<div class="panel">
    <div class="score-bar" id="scoreSummary">
        <div style="font-weight:700;font-size:13px;color:#4338ca;flex-shrink:0;">
            <i class="fas fa-chart-bar"></i> &nbsp;Live Score Summary
        </div>
        <div class="score-box">
            <div class="score-val" id="scoreEmp">-</div>
            <div class="score-lbl">My Avg Rating</div>
        </div>
        <div class="score-box" style="border-color:#bbf7d0">
            <div class="score-val" style="color:var(--success)" id="scoreMgr">-</div>
            <div class="score-lbl">Manager Avg</div>
        </div>
        <div class="score-box" style="border-color:#fed7aa">
            <div class="score-val" style="color:var(--warning)" id="scoreAgr">-</div>
            <div class="score-lbl">Agreed Avg</div>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-left:auto">
            Ratings are out of 5
        </div>
    </div>

    <!-- Competency sections -->
    <?php foreach ($groups as $gi => $g): ?>
    <div class="bc-section" id="section-<?php echo $g['group_id']; ?>">
        <table class="bc-table">
            <thead>
                <tr>
                    <th class="th-group" colspan="10">
                        <?php echo htmlspecialchars($g['group_name']); ?>
                        <?php if ($g['description']): ?>
                            <span style="font-size:11px;font-weight:400;opacity:.7;margin-left:10px">
                               - <?php echo htmlspecialchars($g['description']); ?>
                            </span>
                        <?php endif; ?>
                    </th>
                </tr>
                <tr>
                    <th class="th-indicator">Behavioral Indicator</th>
                    <th class="th-rating-block" colspan="5">Employee Rating (1-5)</th>
                    <th class="th-rating-block th-mgr-block" colspan="5">Manager Rating (1-5)</th>
                    <th class="th-rating-block th-agr-block" colspan="5">Agreed Rating (1-5)</th>
                </tr>
                <tr>
                    <th class="th-indicator" style="background:#f8fafc"></th>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block"><?php echo $r; ?></th><?php endfor; ?>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block th-mgr-block"><?php echo $r; ?></th><?php endfor; ?>
                    <?php for ($r=1;$r<=5;$r++): ?><th class="th-rating-block th-agr-block"><?php echo $r; ?></th><?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($g['indicators'] as $ind): ?>
            <tr>
                <td class="td-indicator">
                    <?php echo htmlspecialchars($ind['indicator_text']); ?>
                </td>
                <!-- Employee rating (radio buttons shown as cells) -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating" style="background:#f5f5ff">
                    <label style="cursor:pointer;display:flex;align-items:center;justify-content:center;width:100%;height:100%">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][emp_rating]"
                               value="<?php echo $r; ?>"
                               onchange="updateScores()"
                               style="cursor:pointer;accent-color:#6366f1;width:16px;height:16px;">
                    </label>
                </td>
                <?php endfor; ?>
                <!-- Manager rating (disabled for employee, supervisor fills in) -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating" style="background:#f0fdf4">
                    <label style="cursor:not-allowed;display:flex;align-items:center;justify-content:center;width:100%;height:100%;opacity:.4">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][mgr_rating]"
                               value="<?php echo $r; ?>"
                               disabled
                               style="width:16px;height:16px;accent-color:#16a34a;">
                    </label>
                </td>
                <?php endfor; ?>
                <!-- Agreed rating -->
                <?php for ($r=1;$r<=5;$r++): ?>
                <td class="td-rating" style="background:#fff7ed">
                    <label style="cursor:pointer;display:flex;align-items:center;justify-content:center;width:100%;height:100%">
                        <input type="radio"
                               name="bc[<?php echo $ind['indicator_id']; ?>][agr_rating]"
                               value="<?php echo $r; ?>"
                               onchange="updateScores()"
                               style="cursor:pointer;accent-color:#d97706;width:16px;height:16px;">
                    </label>
                </td>
                <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Group progress -->
        <div class="group-progress">
            <i class="fas fa-user" style="color:var(--accent);font-size:11px"></i>
            <span>My completion:</span>
            <div class="gp-bar"><div class="gp-fill" id="gpFill-<?php echo $g['group_id']; ?>"></div></div>
            <span class="gp-label" id="gpLabel-<?php echo $g['group_id']; ?>">0 / <?php echo count($g['indicators']); ?></span>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Overall Comments -->
    <div class="comments-grid">
        <div class="comment-block">
            <label><i class="fas fa-user" style="color:var(--accent)"></i> &nbsp;Employee Overall Comments</label>
            <textarea name="emp_overall_comment" rows="4"
                      placeholder="Provide an overall self-assessment and specific examples…"></textarea>
        </div>
        <div class="comment-block">
            <label><i class="fas fa-user-tie" style="color:var(--success)"></i> &nbsp;Manager Overall Comments</label>
            <textarea name="mgr_overall_comment" rows="4"
                      placeholder="Supervisor completes this section during review…"
                      style="background:#f0fdf4;border-color:#bbf7d0;color:var(--muted)"
                      readonly></textarea>
        </div>
    </div>

    <!-- Actions -->
    <div class="actions-bar">
        <button type="submit" name="save_draft" class="btn btn-outline-dark">
            <i class="fas fa-save"></i> Save Draft
        </button>
        <button type="submit" name="submit_bc" class="btn btn-success">
            <i class="fas fa-paper-plane"></i> Submit for Review
        </button>
        <a href="my-appraisals.php" class="btn btn-danger"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

</form>

<!-- Print Preview Modal -->
<div class="modal-backdrop" id="previewModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-print"></i> &nbsp;Behavioral Competency Preview</h3>
            <button type="button" class="btn btn-outline-dark btn-sm" onclick="closePreview()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot">
            <button type="button" class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print / Save PDF
            </button>
            <button type="button" class="btn btn-outline-dark" onclick="closePreview()">Close</button>
        </div>
    </div>
</div>
<div id="printArea"></div>

<?php include 'includes/footer.php'; ?>

<script>
// -- Competency data from PHP --
const groups = <?php
    $jsGroups = array_map(function($g) {
        return [
            'group_id'   => $g['group_id'],
            'group_name' => $g['group_name'],
            'indicators' => array_map(function($i) {
                return ['indicator_id' => $i['indicator_id'], 'indicator_text' => $i['indicator_text']];
            }, $g['indicators'])
        ];
    }, $groups);
    echo json_encode($jsGroups);
?>;

const userInfo = {
    full_name:   <?php echo json_encode($profile['full_name'] ?? ''); ?>,
    department:  <?php echo json_encode($profile['department_name'] ?? ''); ?>,
    job_title:   <?php echo json_encode($profile['job_title'] ?? ''); ?>,
    supervisor:  <?php echo json_encode($profile['supervisor_name'] ?? ''); ?>
};

// -- Live score + progress --
function updateScores() {
    let empTotal = 0, empCount = 0;
    let agrTotal = 0, agrCount = 0;

    groups.forEach(g => {
        let gFilled = 0;
        g.indicators.forEach(ind => {
            const id = ind.indicator_id;
            const empEl = document.querySelector(`input[name="bc[${id}][emp_rating]"]:checked`);
            const agrEl = document.querySelector(`input[name="bc[${id}][agr_rating]"]:checked`);
            if (empEl) { empTotal += parseInt(empEl.value); empCount++; gFilled++; }
            if (agrEl) { agrTotal += parseInt(agrEl.value); agrCount++; }
        });

        // Group progress bar
        const fill  = document.getElementById('gpFill-' + g.group_id);
        const label = document.getElementById('gpLabel-' + g.group_id);
        const pct   = g.indicators.length > 0 ? (gFilled / g.indicators.length * 100) : 0;
        if (fill)  fill.style.width  = pct + '%';
        if (label) label.textContent = gFilled + ' / ' + g.indicators.length;
    });

    const eAvg = empCount > 0 ? (empTotal / empCount).toFixed(2) : '-';
    const aAvg = agrCount > 0 ? (agrTotal / agrCount).toFixed(2) : '-';
    document.getElementById('scoreEmp').textContent = eAvg;
    document.getElementById('scoreAgr').textContent = aAvg;
}

// -- Preview / Print --
function generatePreview() {
    const pdFrom = document.querySelector('input[name="period_from"]').value;
    const pdTo   = document.querySelector('input[name="period_to"]').value;
    const disc   = document.querySelector('input[name="discussion_date"]').value;
    const empCmt = document.querySelector('textarea[name="emp_overall_comment"]').value;

    function fmtDate(d) {
        if (!d) return '-';
        return new Date(d + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
    }

    let html = `
    <table class="pt" width="100%">
      <tr>
        <td style="border:none;padding:4px 6px;width:50%">
          <b>Name:</b> ${esc(userInfo.full_name)}<br>
          <b>Department:</b> ${esc(userInfo.department)}<br>
          <b>Job Title:</b> ${esc(userInfo.job_title)}
        </td>
        <td style="border:none;padding:4px 6px;width:50%">
          <b>Supervisor:</b> ${esc(userInfo.supervisor)}<br>
          <b>Period:</b> ${fmtDate(pdFrom)} - ${fmtDate(pdTo)}<br>
          <b>Discussion Date:</b> ${fmtDate(disc)}
        </td>
      </tr>
    </table>
    <br>
    <table class="pt" width="100%">
      <tr>
        <td colspan="16" style="background:#1e1b4b;color:#fff;font-size:14px;font-weight:700;padding:8px 10px;">
          Behavioral Competencies
        </td>
      </tr>
      <!-- Column headers -->
      <tr>
        <th class="pt-subhead" style="text-align:left;width:30%">Indicator</th>
        <th class="pt-subhead" colspan="5" style="background:#ebebfc;color:#4338ca">Employee Rating</th>
        <th class="pt-subhead" colspan="5" style="background:#dcfce7;color:#166534">Manager Rating</th>
        <th class="pt-subhead" colspan="5" style="background:#fff7ed;color:#d97706">Agreed Rating</th>
      </tr>
      <tr>
        <th style="background:#f8fafc;border:1px solid #ccc"></th>
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#ebebfc">${n}</th>`).join('')}
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#dcfce7">${n}</th>`).join('')}
        ${[1,2,3,4,5].map(n=>`<th class="pt-rating" style="background:#fff7ed">${n}</th>`).join('')}
      </tr>
    `;

    groups.forEach(g => {
        html += `
      <tr>
        <td class="pt-group" colspan="16">${esc(g.group_name)}</td>
      </tr>`;

        g.indicators.forEach(ind => {
            const id  = ind.indicator_id;
            const emp = document.querySelector(`input[name="bc[${id}][emp_rating]"]:checked`);
            const mgr = document.querySelector(`input[name="bc[${id}][mgr_rating]"]:checked`);
            const agr = document.querySelector(`input[name="bc[${id}][agr_rating]"]:checked`);
            const ev  = emp ? parseInt(emp.value) : 0;
            const mv  = mgr ? parseInt(mgr.value) : 0;
            const av  = agr ? parseInt(agr.value) : 0;

            html += `<tr>
        <td class="pt-ind">${esc(ind.indicator_text)}</td>
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#f5f5ff">${ev===n?'?':''}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#f0fdf4">${mv===n?'?':''}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="pt-rating" style="background:#fffbeb">${av===n?'?':''}</td>`).join('')}
      </tr>`;
        });
    });

    html += `
      <tr>
        <td class="pt-comment-label">Manager Overall Comments</td>
        <td colspan="15" style="padding:6px 8px;font-style:italic;color:#94a3b8">- Supervisor completes after review -</td>
      </tr>
      <tr>
        <td class="pt-comment-label">Employee Overall Comments</td>
        <td colspan="15" style="padding:6px 8px;line-height:1.6">${esc(empCmt).replace(/\n/g,'<br>') || '-'}</td>
      </tr>
    </table>
    <table class="pt" width="100%" style="margin-top:14px">
      <tr>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:33%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Employee Signature &amp; Date</div>
        </td>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:33%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Supervisor Signature &amp; Date</div>
        </td>
        <td style="padding:22px 10px 6px;border:1px solid #ccc;width:34%">
          <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">HR Signature &amp; Date</div>
        </td>
      </tr>
    </table>`;

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('printArea').innerHTML = html;
    document.getElementById('previewModal').classList.add('open');
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
}
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Form guard
document.getElementById('bcForm').addEventListener('submit', function(e) {
    const total = document.querySelectorAll('input[name*="[emp_rating]"]').length;
    const filled = document.querySelectorAll('input[name*="[emp_rating]"]:checked').length;
    if (filled === 0) {
        e.preventDefault();
        alert('Please rate at least one behavioral indicator before saving.');
        return false;
    }
    if (filled < total && e.submitter?.name === 'submit_bc') {
        if (!confirm(`You have rated ${filled} of ${total} indicators. Submit anyway?`)) {
            e.preventDefault(); return false;
        }
    }
});

// Init
updateScores();
</script>