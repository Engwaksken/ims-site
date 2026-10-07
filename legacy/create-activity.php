<?php

$page_title = 'Create Activity Plan';
include 'includes/header.php';

// Get user's department
$user_dept_query = $conn->query("SELECT ed.department_id, d.department_name 
    FROM employee_directory ed 
    LEFT JOIN departments d ON ed.department_id = d.department_id 
    WHERE ed.user_id = {$_SESSION['user_id']}");
$user_dept = $user_dept_query->fetch_assoc();

if (!$user_dept || !$user_dept['department_id']) {
    send_notification($_SESSION['user_id'], 'Please complete your employee profile first', 'warning');
    header("Location: my-profile");
    exit();
}

$categories = [];
$result = $conn->query("SELECT * FROM kpi_categories ORDER BY category_name");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

$current_year = date('Y');
?>

<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --ink: #ff5722;
    --surface: #f5f4f0;
    --panel: #ffffff;
    --accent: #ff9800;
    --accent-soft: #e8effe;
    --success: #1a9e5c;
    --success-soft: #e3f7ed;
    --warning: #d97706;
    --warning-soft: #fef3c7;
    --danger: #dc2626;
    --danger-soft: #fee2e2;
    --border: #e2e0d8;
    --muted: #8a8880;
    --mono: 'DM Mono', monospace;
    --sans: 'Sora', sans-serif;
    --radius: 10px;
    --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 16px rgba(0,0,0,0.06);
}

* { box-sizing: border-box; }

body { font-family: var(--sans); background: var(--surface); color: var(--ink); }

/* -- Page header -- */
.page-hero {
    background: var(--ink);
    color: #fff;
    padding: 28px 32px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.page-hero h2 {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.4px;
}
.page-hero p { margin: 4px 0 0; font-size: 13px; color: #ffffff; }
.dept-badge {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.15);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
}
.dept-badge i { margin-right: 6px; color: var(--accent); }

/* -- Panels -- */
.panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 20px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.panel-head {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #fafaf8;
}
.panel-head h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--ink);
}
.panel-head h4 i { color: var(--accent); font-size: 14px; }
.panel-body { padding: 24px; }

/* -- Meta row -- */
.meta-grid {
    display: grid;
    grid-template-columns: 180px 1fr 1fr;
    gap: 16px;
}

/* -- Form controls -- */
.field { margin-bottom: 0; }
.field label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: var(--muted);
    margin-bottom: 6px;
}
.field label .req { color: var(--danger); margin-left: 2px; }
.field input, .field select, .field textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-family: var(--sans);
    font-size: 13.5px;
    color: var(--ink);
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.field input:focus, .field select:focus, .field textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(42,110,245,0.12);
}
.field textarea { resize: vertical; }
.field .hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; }

/* -- Weight indicator -- */
.weight-total-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--accent-soft);
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 13px;
}
.weight-total-bar .bar-wrap {
    flex: 1;
    height: 8px;
    background: #c8d8fd;
    border-radius: 99px;
    overflow: hidden;
}
.weight-total-bar .bar-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 99px;
    transition: width .3s;
    width: 0%;
}
.weight-total-bar .bar-fill.over { background: var(--danger); }
.weight-total-bar .label { font-weight: 600; font-family: var(--mono); min-width: 70px; text-align: right; }

/* -- Activity table -- */
.activity-table-wrap { overflow-x: auto; }
.activity-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
}
.activity-table thead th {
    padding: 10px 12px;
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--muted);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
    background: #fafaf8;
}
.activity-table tbody tr { transition: background .12s; }
.activity-table tbody tr:hover { background: #fafaf8; }
.activity-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0eeea;
    vertical-align: middle;
}
.activity-table td input,
.activity-table td select,
.activity-table td textarea {
    width: 100%;
    padding: 7px 9px;
    border: 1.5px solid var(--border);
    border-radius: 6px;
    font-family: var(--sans);
    font-size: 13px;
    color: var(--ink);
    background: #fff;
    outline: none;
    transition: border-color .15s;
}
.activity-table td input:focus,
.activity-table td select:focus,
.activity-table td textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(42,110,245,0.1);
}
.activity-table td.td-num { width: 36px; text-align: center; font-family: var(--mono); font-size: 12px; color: var(--muted); }
.activity-table td.td-del { width: 40px; text-align: center; }
.activity-table td.td-weight { width: 90px; }

.btn-del-row {
    background: none;
    border: none;
    color: #ccc;
    cursor: pointer;
    font-size: 16px;
    padding: 4px 8px;
    border-radius: 5px;
    transition: color .15s, background .15s;
}
.btn-del-row:hover { color: var(--danger); background: var(--danger-soft); }

.btn-add-row {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 14px;
    padding: 9px 18px;
    background: var(--accent-soft);
    color: var(--accent);
    border: 1.5px dashed var(--accent);
    border-radius: 7px;
    font-family: var(--sans);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.btn-add-row:hover { background: #d8e7fe; }

/* -- Quarter tabs -- */
.quarter-tabs { display: flex; gap: 6px; margin-bottom: 0; }
.q-tab {
    padding: 8px 18px;
    border-radius: 8px 8px 0 0;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1.5px solid var(--border);
    border-bottom: none;
    background: #f0eeea;
    color: var(--muted);
    transition: all .15s;
    user-select: none;
}
.q-tab.active {
    background: var(--panel);
    color: var(--accent);
    border-color: var(--border);
    border-bottom-color: var(--panel);
    z-index: 1;
    position: relative;
}
.q-tab-content { display: none; }
.q-tab-content.active { display: block; }
.quarter-panel {
    border: 1.5px solid var(--border);
    border-radius: 0 var(--radius) var(--radius) var(--radius);
    background: var(--panel);
    overflow: hidden;
}

/* -- Weekly sub-table -- */
.week-table-wrap { overflow-x: auto; }
.week-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12.5px;
    min-width: 900px;
}
.week-table thead th {
    padding: 9px 10px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--muted);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
    background: #fafaf8;
}
.week-table thead th.th-activity { text-align: left; min-width: 180px; }
.week-table thead th.th-week { min-width: 110px; }
.week-table tbody tr:hover { background: #fafaf8; }
.week-table td {
    padding: 8px 8px;
    border-bottom: 1px solid #f0eeea;
    vertical-align: middle;
}
.week-table td.td-activity-name {
    font-weight: 500;
    color: var(--ink);
    padding-left: 16px;
    border-left: 3px solid var(--accent);
}
.week-table td.td-activity-name .key-result {
    font-size: 11px;
    color: var(--muted);
    font-weight: 400;
    margin-top: 2px;
    display: block;
}
.week-table td input,
.week-table td select {
    width: 100%;
    padding: 6px 8px;
    border: 1.5px solid var(--border);
    border-radius: 5px;
    font-family: var(--sans);
    font-size: 12px;
    color: var(--ink);
    background: #fff;
    outline: none;
    transition: border-color .15s;
}
.week-table td input:focus,
.week-table td select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(42,110,245,0.1);
}
.week-selector-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 12px 16px;
    background: #f7f6f2;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.week-selector-wrap label { font-size: 11.5px; font-weight: 600; color: var(--muted); margin-right: 4px; }
.week-chip {
    padding: 4px 11px;
    border-radius: 20px;
    border: 1.5px solid var(--border);
    background: #fff;
    font-size: 11.5px;
    font-weight: 600;
    font-family: var(--mono);
    cursor: pointer;
    color: var(--muted);
    transition: all .12s;
    user-select: none;
}
.week-chip.selected {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.week-entry-area { padding: 0; }
.no-weeks-msg {
    padding: 32px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
}
.no-weeks-msg i { font-size: 24px; margin-bottom: 8px; display: block; }

/* column widths */
.week-table td.td-actual { min-width: 100px; }
.week-table td.td-comment { min-width: 180px; }

/* -- Actions bar -- */
.actions-bar {
    display: flex;
    gap: 12px;
    justify-content: center;
    padding: 24px;
    background: #fafaf8;
    border-top: 1px solid var(--border);
    border-radius: 0 0 var(--radius) var(--radius);
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 24px;
    border-radius: 8px;
    font-family: var(--sans);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    border: 2px solid transparent;
    transition: all .15s;
    text-decoration: none;
}
.btn-primary { background: var(--accent); color: #fff; border-color: var(--accent); }
.btn-primary:hover { background: #1a5ce0; }
.btn-outline { background: #fff; color: var(--ink); border-color: var(--border); }
.btn-outline:hover { border-color: #aaa; background: #f5f4f0; }
.btn-success { background: var(--success); color: #fff; border-color: var(--success); }
.btn-success:hover { background: #148049; }
.btn-danger { background: #fff; color: var(--danger); border-color: var(--border); }
.btn-danger:hover { background: var(--danger-soft); border-color: var(--danger); }

/* -- Misc -- */
.info-strip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: var(--accent-soft);
    border-radius: 8px;
    font-size: 13px;
    color: #ff9800;
    margin-bottom: 20px;
}
.info-strip i { font-size: 15px; flex-shrink: 0; }
.section-divider {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    padding: 0 8px;
    margin: 20px 0 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-divider::before, .section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* responsive */
@media(max-width: 768px) {
    .meta-grid { grid-template-columns: 1fr 1fr; }
    .page-hero { flex-direction: column; align-items: flex-start; }
    .actions-bar { flex-wrap: wrap; }
}
</style>

<div class="page-hero">
    <div>
        <h2><i class="fas fa-tasks"></i> &nbsp;Create Activity Plan</h2>
        <p>Define activities, key results, weightings, and weekly tracking targets.</p>
    </div>
    <div class="dept-badge">
        <i class="fas fa-building"></i>
        <?php echo htmlspecialchars($user_dept['department_name']); ?>
    </div>
</div>

<form method="POST" action="activity-process" id="activityForm">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="department_id" value="<?php echo $user_dept['department_id']; ?>">

    <!-- -- Meta -- -->
    <div class="panel">
        <div class="panel-head">
            <h4><i class="fas fa-sliders-h"></i> Plan Details</h4>
        </div>
        <div class="panel-body">
            <div class="meta-grid">
                <div class="field">
                    <label>Fiscal Year <span class="req">*</span></label>
                    <select name="fiscal_year" required>
                        <option value="<?php echo $current_year; ?>"><?php echo $current_year; ?></option>
                        <option value="<?php echo $current_year+1; ?>"><?php echo $current_year+1; ?></option>
                    </select>
                </div>
                <div class="field">
                    <label>Category <span class="req">*</span></label>
                    <select name="category_id" required>
                        <option value="">Select category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Plan Title <span class="req">*</span></label>
                    <input type="text" name="plan_title" placeholder="e.g., Q1 Operational Goals" required maxlength="200">
                </div>
            </div>
        </div>
    </div>

    <!-- -- Activities -- -->
    <div class="panel">
        <div class="panel-head">
            <h4><i class="fas fa-list-check"></i> Activities</h4>
            <div id="weightDisplay" style="font-size:13px;font-weight:600;color:var(--muted);">
                Total Weight: <span id="totalWeightVal" style="font-family:var(--mono);">0%</span>
            </div>
        </div>
        <div class="panel-body">
            <div class="info-strip">
                <i class="fas fa-info-circle"></i>
                Add each activity with its key result and relative weight. Weights should sum to 100%.
            </div>

            <!-- Weight progress bar -->
            <div class="weight-total-bar">
                <span style="font-size:12px;color:var(--accent);font-weight:600;">Weight Total</span>
                <div class="bar-wrap"><div class="bar-fill" id="weightBar"></div></div>
                <span class="label"><span id="weightPct">0</span> / 100%</span>
            </div>

            <div class="activity-table-wrap">
                <table class="activity-table" id="activityTable">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th style="min-width:200px">Activity Description <span style="color:var(--danger)">*</span></th>
                            <th style="min-width:200px">Key Result <span style="color:var(--danger)">*</span></th>
                            <th style="width:90px">Weight (%) <span style="color:var(--danger)">*</span></th>
                            <th style="min-width:120px">Unit of Measure</th>
                            <th style="min-width:100px">Annual Target</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="activityRows">
                        <!-- rows injected by JS -->
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn-add-row" id="btnAddActivity">
                <i class="fas fa-plus"></i> Add Activity
            </button>
        </div>
    </div>

    <!-- -- Weekly Tracking -- -->
    <div class="panel">
        <div class="panel-head">
            <h4><i class="fas fa-calendar-week"></i> Weekly Tracking by Quarter</h4>
        </div>
        <div class="panel-body" style="padding: 0;">

            <!-- Quarter tabs -->
            <div style="padding: 20px 24px 0;">
                <div class="quarter-tabs">
                    <div class="q-tab active" data-q="q1">Q1 <span style="font-size:10px;font-weight:400;opacity:.7">Jan-Mar</span></div>
                    <div class="q-tab" data-q="q2">Q2 <span style="font-size:10px;font-weight:400;opacity:.7">Apr-Jun</span></div>
                    <div class="q-tab" data-q="q3">Q3 <span style="font-size:10px;font-weight:400;opacity:.7">Jul-Sep</span></div>
                    <div class="q-tab" data-q="q4">Q4 <span style="font-size:10px;font-weight:400;opacity:.7">Oct-Dec</span></div>
                </div>
            </div>

            <!-- Q panels -->
            <?php
            $quarters = [
                'q1' => ['label'=>'Q1','months'=>'Jan - Mar','weeks'=>12],
                'q2' => ['label'=>'Q2','months'=>'Apr - Jun','weeks'=>12],
                'q3' => ['label'=>'Q3','months'=>'Jul - Sep','weeks'=>12],
                'q4' => ['label'=>'Q4','months'=>'Oct - Dec','weeks'=>12],
            ];
            foreach ($quarters as $qk => $qv): ?>
            <div class="q-tab-content <?php echo $qk==='q1'?'active':''; ?>" id="tab-<?php echo $qk; ?>" style="padding: 0 24px 24px;">
                <div class="quarter-panel">
                    <!-- Week selector -->
                    <div class="week-selector-wrap" id="chipWrap-<?php echo $qk; ?>">
                        <label>Select weeks to track:</label>
                        <?php for ($w = 1; $w <= $qv['weeks']; $w++): ?>
                        <div class="week-chip" data-q="<?php echo $qk; ?>" data-week="<?php echo $w; ?>" onclick="toggleWeek(this)">W<?php echo $w; ?></div>
                        <?php endfor; ?>
                        <button type="button" style="margin-left:auto;font-size:11px;padding:4px 10px;border:1.5px solid var(--border);border-radius:5px;background:#fff;cursor:pointer;font-family:var(--sans);" onclick="selectAllWeeks('<?php echo $qk; ?>')">All</button>
                        <button type="button" style="font-size:11px;padding:4px 10px;border:1.5px solid var(--border);border-radius:5px;background:#fff;cursor:pointer;font-family:var(--sans);" onclick="clearAllWeeks('<?php echo $qk; ?>')">Clear</button>
                    </div>

                    <!-- Week entry table -->
                    <div class="week-entry-area" id="weekArea-<?php echo $qk; ?>">
                        <div class="no-weeks-msg" id="noWeeksMsg-<?php echo $qk; ?>">
                            <i class="fas fa-mouse-pointer"></i>
                            Select weeks above to begin entering tracking data.
                        </div>
                        <div class="week-table-wrap" id="weekTableWrap-<?php echo $qk; ?>" style="display:none;">
                            <table class="week-table">
                                <thead>
                                    <tr id="weekTableHead-<?php echo $qk; ?>">
                                        <th class="th-activity">Activity</th>
                                        <!-- week columns injected -->
                                    </tr>
                                </thead>
                                <tbody id="weekTableBody-<?php echo $qk; ?>">
                                    <!-- rows injected -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- -- Form Actions -- -->
    <div class="panel">
        <div class="actions-bar">
            <button type="submit" name="save_draft" class="btn btn-outline">
                <i class="fas fa-save"></i> Save Draft
            </button>
            <button type="submit" name="submit_plan" class="btn btn-success">
                <i class="fas fa-paper-plane"></i> Submit for Review
            </button>
            <a href="my-kpis" class="btn btn-danger">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </div>

</form>

<?php include 'includes/footer.php'; ?>

<script>
// -- State --
let activities = [];
let selectedWeeks = { q1: new Set(), q2: new Set(), q3: new Set(), q4: new Set() };

// -- Quarter tab switching --
document.querySelectorAll('.q-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.q-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.q-tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.q).classList.add('active');
    });
});

// -- Activity row management --
let rowCounter = 0;

function addActivityRow() {
    rowCounter++;
    const id = rowCounter;
    const tbody = document.getElementById('activityRows');
    const tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.innerHTML = `
        <td class="td-num">${id}</td>
        <td><input type="text" name="activities[${id}][description]" placeholder="e.g., Complete monthly report" required></td>
        <td><input type="text" name="activities[${id}][key_result]" placeholder="e.g., 12 reports submitted on time" required></td>
        <td class="td-weight"><input type="number" name="activities[${id}][weight]" min="0" max="100" step="0.1" placeholder="20" oninput="updateWeightBar()" required></td>
        <td><input type="text" name="activities[${id}][unit]" placeholder="%, count, hrs..."></td>
        <td><input type="number" name="activities[${id}][annual_target]" step="0.01" placeholder="100"></td>
        <td class="td-del"><button type="button" class="btn-del-row" onclick="removeActivityRow(this, ${id})"><i class="fas fa-trash-alt"></i></button></td>
    `;
    tbody.appendChild(tr);
    activities.push({ id, description: '', key_result: '' });

    // listen for label changes to sync to week tables
    tr.querySelector('input[name*="[description]"]').addEventListener('input', function() {
        syncActivityLabel(id, this.value, tr.querySelector('input[name*="[key_result]"]').value);
    });
    tr.querySelector('input[name*="[key_result]"]').addEventListener('input', function() {
        syncActivityLabel(id, tr.querySelector('input[name*="[description]"]').value, this.value);
    });

    refreshWeekTables();
    updateWeightBar();
    renumberRows();
}

function removeActivityRow(btn, id) {
    if (document.querySelectorAll('#activityRows tr').length <= 1) {
        alert('You must have at least one activity.');
        return;
    }
    btn.closest('tr').remove();
    activities = activities.filter(a => a.id !== id);
    refreshWeekTables();
    updateWeightBar();
    renumberRows();
}

function renumberRows() {
    document.querySelectorAll('#activityRows tr').forEach((tr, i) => {
        tr.querySelector('.td-num').textContent = i + 1;
    });
}

function syncActivityLabel(id, desc, kr) {
    const act = activities.find(a => a.id === id);
    if (act) { act.description = desc; act.key_result = kr; }
    refreshWeekTables();
}

// -- Weight bar --
function updateWeightBar() {
    let total = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    total = Math.min(total, 999);
    const pct = Math.min(total, 100);
    const bar = document.getElementById('weightBar');
    bar.style.width = pct + '%';
    bar.className = 'bar-fill' + (total > 100 ? ' over' : '');
    document.getElementById('weightPct').textContent = total.toFixed(1);
    document.getElementById('totalWeightVal').textContent = total.toFixed(1) + '%';
}

// -- Week chip toggling --
function toggleWeek(chip) {
    const q = chip.dataset.q;
    const w = parseInt(chip.dataset.week);
    if (selectedWeeks[q].has(w)) {
        selectedWeeks[q].delete(w);
        chip.classList.remove('selected');
    } else {
        selectedWeeks[q].add(w);
        chip.classList.add('selected');
    }
    refreshWeekTables();
}

function selectAllWeeks(q) {
    document.querySelectorAll(`.week-chip[data-q="${q}"]`).forEach(chip => {
        selectedWeeks[q].add(parseInt(chip.dataset.week));
        chip.classList.add('selected');
    });
    refreshWeekTables();
}

function clearAllWeeks(q) {
    document.querySelectorAll(`.week-chip[data-q="${q}"]`).forEach(chip => {
        chip.classList.remove('selected');
    });
    selectedWeeks[q].clear();
    refreshWeekTables();
}

// -- Build week tables --
function refreshWeekTables() {
    ['q1', 'q2', 'q3', 'q4'].forEach(q => buildWeekTable(q));
}

function buildWeekTable(q) {
    const weeks = Array.from(selectedWeeks[q]).sort((a,b) => a-b);
    const noMsg = document.getElementById('noWeeksMsg-' + q);
    const wrap = document.getElementById('weekTableWrap-' + q);
    const head = document.getElementById('weekTableHead-' + q);
    const body = document.getElementById('weekTableBody-' + q);
    const rows = document.querySelectorAll('#activityRows tr');

    if (weeks.length === 0 || rows.length === 0) {
        noMsg.style.display = 'block';
        wrap.style.display = 'none';
        return;
    }

    noMsg.style.display = 'none';
    wrap.style.display = 'block';

    // Build header
    // Preserve existing input values before rebuild
    const savedValues = {};
    body.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.name) savedValues[el.name] = el.value;
    });

    // Header
    head.innerHTML = `<th class="th-activity">Activity / Key Result</th>`;
    weeks.forEach(w => {
        head.innerHTML += `
            <th class="th-week" colspan="2">
                Week ${w}
            </th>
        `;
    });

    // Sub-header row
    let subRow = `<tr style="background:#f7f6f2;">
        <td style="border-bottom:1px solid var(--border);padding:4px 16px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);"></td>`;
    weeks.forEach(() => {
        subRow += `
            <td style="border-bottom:1px solid var(--border);padding:4px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);text-align:center;">Actual (Target)</td>
            <td style="border-bottom:1px solid var(--border);padding:4px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);text-align:center;">Comments</td>
        `;
    });
    subRow += '</tr>';

    // Activity rows
    let bodyHtml = subRow;
    rows.forEach((tr, idx) => {
        const rowId = tr.dataset.id;
        const desc = tr.querySelector('input[name*="[description]"]').value || `Activity ${idx+1}`;
        const kr = tr.querySelector('input[name*="[key_result]"]').value || '';

        bodyHtml += `<tr>
            <td class="td-activity-name">
                ${escHtml(desc)}
                ${kr ? `<span class="key-result"> ${escHtml(kr)}</span>` : ''}
            </td>`;

        weeks.forEach(w => {
            const nameActual = `weekly[${q}][${rowId}][w${w}][actual]`;
            const nameComment = `weekly[${q}][${rowId}][w${w}][comment]`;
            const valActual = savedValues[nameActual] || '';
            const valComment = savedValues[nameComment] || '';

            bodyHtml += `
                <td class="td-actual">
                    <input type="number" step="0.01" name="${nameActual}" placeholder="-" value="${escHtml(valActual)}">
                </td>
                <td class="td-comment">
                    <input type="text" name="${nameComment}" placeholder="Add comment..." value="${escHtml(valComment)}" maxlength="200">
                </td>
            `;
        });

        bodyHtml += '</tr>';
    });

    body.innerHTML = bodyHtml;
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// -- Form validation --
document.getElementById('activityForm').addEventListener('submit', function(e) {
    const rows = document.querySelectorAll('#activityRows tr');
    if (rows.length === 0) {
        e.preventDefault();
        alert('Please add at least one activity.');
        return false;
    }

    let totalWeight = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(inp => {
        totalWeight += parseFloat(inp.value) || 0;
    });

    if (Math.abs(totalWeight - 100) > 0.1) {
        if (!confirm(`Warning: Total weight is ${totalWeight.toFixed(1)}% (should be 100%). Continue anyway?`)) {
            e.preventDefault();
            return false;
        }
    }
});

// Add button
document.getElementById('btnAddActivity').addEventListener('click', addActivityRow);

// Initialise with one empty row
addActivityRow();
</script>