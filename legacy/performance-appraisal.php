<?php

$page_title = 'Performance Appraisal';
include 'includes/header.php';

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
    WHERE u.user_id = $uid
    LIMIT 1
");
$profile = $profile_q ? $profile_q->fetch_assoc() : [];

if (!$profile || !$profile['job_title']) {
    send_notification($uid, 'Please complete your employee profile before creating an appraisal.', 'warning');
    header("Location: my-profile");
    exit();
}

$current_year = date('Y');
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* -----------------------------------------------
   VARIABLES & RESET
----------------------------------------------- */
.page-appraisal {
    --ink:       #ff5722;
    --surface:   #f3f4f6;
    --panel:     #ffffff;
    --accent:    #ff9800;
    --accent-lt: #dbeafe;
    --gray-hd:   #6b7280;
    --border:    #d1d5db;
    --danger:    #dc2626;
    --success:   #15803d;
    --kra-bg:    #1e3a5f;
    --kra-fg:    #ffffff;
    --mono:      'JetBrains Mono', monospace;
    --sans:      'Inter', sans-serif;
}
.page-appraisal *, .page-appraisal *::before, .page-appraisal *::after { box-sizing: border-box; margin: 0; padding: 0; }
.page-appraisal { font-family: var(--sans); background: var(--surface); color: var(--ink); font-size: 14px; }

/* -- Page hero -- */
.page-appraisal .hero {
    background: var(--ink);
    color: #fff;
    padding: 24px 32px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    gap: 16px;
}
.page-appraisal .hero h2 { font-size: 20px; font-weight: 700; letter-spacing: -.4px; }
.page-appraisal .hero p  { font-size: 12px; color: #ffffff; margin-top: 4px; }
.page-appraisal .hero-btns { display: flex; gap: 10px; flex-shrink: 0; }

/* -- Buttons -- */
.page-appraisal .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 20px; border-radius: 7px;
    font-family: var(--sans); font-size: 13px; font-weight: 600;
    cursor: pointer; border: 2px solid transparent;
    transition: all .15s; text-decoration: none;
}
.page-appraisal .btn-primary  { background: var(--accent); color: #fff; }
.page-appraisal .btn-primary:hover { background: #ff9800; }
.page-appraisal .btn-success  { background: var(--success); color: #fff; }
.page-appraisal .btn-success:hover { background: #166534; }
.page-appraisal .btn-outline  { background: #fff; color: var(--ink); border-color: var(--border); }
.page-appraisal .btn-outline:hover { border-color: #9ca3af; }
.page-appraisal .btn-danger   { background: #fff; color: var(--danger); border-color: var(--border); }
.page-appraisal .btn-danger:hover { background: #fee2e2; border-color: var(--danger); }
.page-appraisal .btn-print    { background: #ff9800; color: #fff; }
.page-appraisal .btn-print:hover { background: #1f2937; }
.page-appraisal .btn-sm { padding: 5px 12px; font-size: 12px; }

/* -- Cards -- */
.page-appraisal .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07), 0 4px 12px rgba(0,0,0,.05);
    margin-bottom: 20px;
    overflow: hidden;
}
.page-appraisal .card-head {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    background: #f9fafb;
    display: flex; align-items: center; justify-content: space-between;
}
.page-appraisal .card-head h4 {
    font-size: 14px; font-weight: 600;
    display: flex; align-items: center; gap: 8px; color: var(--ink);
}
.page-appraisal .card-head h4 i { color: var(--accent); }
.page-appraisal .card-body { padding: 20px; }

/* -- Field groups -- */
.page-appraisal .field-grid { display: grid; gap: 14px; }
.page-appraisal .col-2 { grid-template-columns: 1fr 1fr; }
.page-appraisal .col-3 { grid-template-columns: 1fr 1fr 1fr; }
.page-appraisal .col-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
.page-appraisal .field label {
    display: block; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .7px;
    color: var(--gray-hd); margin-bottom: 5px;
}
.page-appraisal .field label .req { color: var(--danger); }
.page-appraisal .field input, .page-appraisal .field select, .page-appraisal .field textarea {
    width: 100%; padding: 8px 11px;
    border: 1.5px solid var(--border); border-radius: 7px;
    font-family: var(--sans); font-size: 13px; color: var(--ink);
    background: #fff; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.page-appraisal .field input:focus, .page-appraisal .field select:focus, .page-appraisal .field textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(29,78,216,.1);
}
.page-appraisal .field input[readonly], .page-appraisal .field input[disabled] {
    background: #f9fafb; color: var(--gray-hd); cursor: not-allowed;
}
.page-appraisal .field textarea { resize: vertical; }
.page-appraisal .field .hint { font-size: 11px; color: #9ca3af; margin-top: 4px; }

/* -- Period row -- */
.page-appraisal .period-row { display: flex; align-items: center; gap: 10px; }
.page-appraisal .period-row span { font-size: 13px; color: var(--gray-hd); }

/* -- KRA builder -- */
.page-appraisal .kra-block {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    margin-bottom: 20px;
    overflow: hidden;
}
.page-appraisal .kra-header {
    background: var(--kra-bg);
    color: var(--kra-fg);
    padding: 10px 16px;
    display: flex; align-items: center; gap: 10px;
}
.page-appraisal .kra-header input {
    background: rgba(255,255,255,.12);
    border: 1.5px solid rgba(255,255,255,.25);
    border-radius: 6px;
    color: #fff;
    font-family: var(--sans); font-size: 13px; font-weight: 600;
    padding: 6px 11px; flex: 1; outline: none;
}
.page-appraisal .kra-header input::placeholder { color: rgba(255,255,255,.5); }
.page-appraisal .kra-header input:focus { border-color: rgba(255,255,255,.6); }
.page-appraisal .kra-num {
    background: rgba(255,255,255,.2);
    border-radius: 5px;
    padding: 4px 10px;
    font-size: 12px; font-weight: 700;
    white-space: nowrap;
}
.page-appraisal .kra-meta {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 12px; padding: 14px 16px;
    background: #f0f4ff; border-bottom: 1px solid var(--border);
}
.page-appraisal .kra-meta .field label { color: #3b4a6b; }

/* -- KPI rows table -- */
.page-appraisal .kpi-table-wrap { padding: 0 16px 14px; }
.page-appraisal .kpi-table {
    width: 100%; border-collapse: separate; border-spacing: 0;
    font-size: 12.5px; margin-top: 10px;
}
.page-appraisal .kpi-table thead th {
    padding: 8px 10px; text-align: left;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .7px;
    color: var(--gray-hd); border-bottom: 2px solid var(--border);
    background: #f9fafb; white-space: nowrap;
}
.page-appraisal .kpi-table thead th.th-center { text-align: center; }
.page-appraisal .kpi-table tbody tr:hover { background: #f9fafb; }
.page-appraisal .kpi-table td {
    padding: 7px 6px; border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.page-appraisal .kpi-table td input, .page-appraisal .kpi-table td select {
    width: 100%; padding: 6px 8px;
    border: 1.5px solid var(--border); border-radius: 5px;
    font-family: var(--sans); font-size: 12.5px; color: var(--ink);
    background: #fff; outline: none; transition: border-color .15s;
}
.page-appraisal .kpi-table td input:focus, .page-appraisal .kpi-table td select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(29,78,216,.09);
}
.page-appraisal .kpi-table td.td-rating { width: 60px; text-align: center; }
.page-appraisal .kpi-table td.td-del  { width: 36px; text-align: center; }
.page-appraisal .td-seq { width: 36px; text-align: center; font-family: var(--mono); font-size: 11px; color: #9ca3af; }

/* rating stars visual */
.page-appraisal .rating-select {
    text-align: center;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
}

/* comment blocks */
.page-appraisal .comment-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; padding: 14px 16px;
    border-top: 1px solid var(--border); background: #fefefe;
}
.page-appraisal .comment-block label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px;
    color: var(--gray-hd); margin-bottom: 5px; display: block;
}
.page-appraisal .comment-block textarea {
    width: 100%; padding: 8px 10px;
    border: 1.5px solid var(--border); border-radius: 6px;
    font-family: var(--sans); font-size: 12.5px; resize: vertical;
    outline: none; transition: border-color .15s;
}
.page-appraisal .comment-block textarea:focus { border-color: var(--accent); }

/* del btn */
.page-appraisal .btn-del-row {
    background: none; border: none; color: #d1d5db;
    cursor: pointer; padding: 4px 7px; border-radius: 5px;
    font-size: 14px; transition: all .12s;
}
.page-appraisal .btn-del-row:hover { color: var(--danger); background: #fee2e2; }

/* add row btn */
.page-appraisal .btn-add-kpi {
    display: inline-flex; align-items: center; gap: 6px;
    margin: 0 16px 14px;
    padding: 7px 14px; background: var(--accent-lt);
    color: var(--accent); border: 1.5px dashed var(--accent);
    border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; transition: background .15s;
}
.page-appraisal .btn-add-kpi:hover { background: #bfdbfe; }

/* add KRA btn */
.page-appraisal .btn-add-kra {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; width: 100%; padding: 14px;
    background: #f9fafb; border: 2px dashed var(--border);
    border-radius: 10px; font-size: 14px; font-weight: 600;
    color: var(--gray-hd); cursor: pointer; transition: all .15s;
    margin-bottom: 20px;
}
.page-appraisal .btn-add-kra:hover { background: var(--accent-lt); border-color: var(--accent); color: var(--accent); }

/* actions bar */
.page-appraisal .form-actions {
    display: flex; gap: 12px; justify-content: flex-end;
    flex-wrap: wrap; padding: 20px;
    background: #f9fafb; border-top: 1px solid var(--border);
    border-radius: 0 0 10px 10px;
}

/* info strip */
.page-appraisal .info-strip {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; background: var(--accent-lt);
    border-radius: 8px; font-size: 13px; color: #ff9800;
    margin-bottom: 18px;
}

/* -----------------------------------------------
   PRINT / GENERATED TABLE
----------------------------------------------- */
.page-appraisal #printArea { display: none; }

@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { display: block !important; position: fixed; top: 0; left: 0; width: 100%; }
}

/* Print preview modal */
.page-appraisal .modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.55); z-index: 9000;
    align-items: flex-start; justify-content: center;
    padding: 30px 20px; overflow-y: auto;
}
.page-appraisal .modal-overlay.open { display: flex; }
.page-appraisal .modal-box {
    background: #fff; border-radius: 10px;
    width: 100%; max-width: 900px;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}
.page-appraisal .modal-head {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.page-appraisal .modal-head h3 { font-size: 16px; font-weight: 700; }
.page-appraisal .modal-body { padding: 20px; }
.page-appraisal .modal-foot {
    padding: 14px 20px; border-top: 1px solid var(--border);
    display: flex; gap: 10px; justify-content: flex-end;
}

/* -- Appraisal print table -- */
.page-appraisal .ap-table {
    width: 100%; border-collapse: collapse;
    font-family: Arial, sans-serif; font-size: 11px;
}
.page-appraisal .ap-table td, .page-appraisal .ap-table th {
    border: 1px solid #999;
    padding: 4px 6px;
    vertical-align: top;
}
.page-appraisal .ap-table .ap-header-info td { border: none; padding: 3px 6px; }
.page-appraisal .ap-kra-title {
    background: #1e3a5f; color: #fff;
    font-weight: 700; font-size: 11.5px;
}
.page-appraisal .ap-section-heading {
    background: #bdd7ee; font-weight: 700;
    font-size: 11px; text-align: center;
}
.page-appraisal .ap-rating-cell { text-align: center; width: 30px; }
.page-appraisal .ap-label { font-weight: 600; background: #f2f2f2; }
.page-appraisal .ap-comment-label { font-weight: 700; font-size: 10px; color: #333; }

@media (max-width: 768px) {
    .page-appraisal .col-2, .page-appraisal .col-3, .page-appraisal .col-4 { grid-template-columns: 1fr 1fr; }
    .page-appraisal .kra-meta { grid-template-columns: 1fr; }
    .page-appraisal .comment-grid { grid-template-columns: 1fr; }
    .page-appraisal .hero { flex-direction: column; }
}
</style>

<div class="page-appraisal">

<!-- -- Page -- -->
<div class="hero">
    <div>
        <h2><i class="fas fa-clipboard-check"></i> &nbsp;Performance Appraisal</h2>
        <p>Complete your self-assessment against Key Result Areas (KRAs)</p>
    </div>
    <div class="hero-btns">
        <button type="button" class="btn btn-print" onclick="generatePreview()">
            <i class="fas fa-eye"></i> Preview &amp; Print
        </button>
        <a href="my-kpis" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<form method="POST" action="includes/appraisal-process.php" id="appraisalForm">
<input type="hidden" name="action" value="create_appraisal">

<!-- -- Personal Information -- -->
<div class="card">
    <div class="card-head">
        <h4><i class="fas fa-user"></i> Personal &amp; Appraisal Information</h4>
    </div>
    <div class="card-body">
        <div class="info-strip">
            <i class="fas fa-info-circle"></i>
            Your profile and supervisor details are pre-filled. Complete the appraisal period and discussion date.
        </div>

        <div class="field-grid col-3" style="margin-bottom: 16px;">
            <div class="field">
                <label>Name (Last, First, MI)</label>
                <input type="text" name="full_name"
                       value="<?php echo htmlspecialchars($profile['full_name'] ?? ''); ?>" readonly>
            </div>
            <div class="field">
                <label>Department</label>
                <input type="text" name="department"
                       value="<?php echo htmlspecialchars(($profile['department_name'] ?? '')); ?>" readonly>
            </div>
            <div class="field">
                <label>Appraisal Period <span class="req">*</span></label>
                <div class="period-row">
                    <span>From</span>
                    <input type="date" name="period_from" style="flex:1"
                           value="<?php echo $current_year; ?>-01-01" required>
                    <span>To</span>
                    <input type="date" name="period_to"   style="flex:1"
                           value="<?php echo $current_year; ?>-12-31" required>
                </div>
            </div>
        </div>

        <div class="field-grid col-3">
            <div class="field">
                <label>Job Title</label>
                <input type="text" name="job_title"
                       value="<?php echo htmlspecialchars($profile['job_title'] ?? ''); ?>" readonly>
            </div>
            <div class="field">
                <label>Supervisor</label>
                <input type="text" name="supervisor_name"
                       value="<?php echo htmlspecialchars($profile['supervisor_name'] ?? 'No supervisor assigned'); ?>" readonly>
                <input type="hidden" name="supervisor_id" value="<?php echo $profile['supervisor_id'] ?? ''; ?>">
                <div class="hint">
                    <?php if (empty($profile['supervisor_name'])): ?>
                        <span style="color:var(--danger)"><i class="fas fa-exclamation-triangle"></i>
                        No supervisor assigned — contact HR.</span>
                    <?php else: ?>
                        Assigned from your employee profile.
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <label>Discussion Date <span class="req">*</span></label>
                <input type="date" name="discussion_date" required>
            </div>
        </div>
    </div>
</div>

<!-- -- KRA Builder -- -->
<div class="card">
    <div class="card-head">
        <h4><i class="fas fa-bullseye"></i> Assessment against Key Result Areas</h4>
        <span style="font-size:12px;color:var(--gray-hd);">Add KRAs and their KPIs below</span>
    </div>
    <div class="card-body" style="padding-bottom: 0;">
        <div class="info-strip">
            <i class="fas fa-lightbulb"></i>
            For each KRA, add KPI indicators. Rate yourself (1-5) per KPI. Your supervisor will complete Manager Rating after review.
        </div>
    </div>
</div>

<!-- KRA container -->
<div id="kraContainer"></div>

<!-- Add KRA btn -->
<button type="button" class="btn-add-kra" id="btnAddKra">
    <i class="fas fa-plus-circle"></i> Add Key Result Area (KRA)
</button>

<!-- -- Submit -- -->
<div class="card">
    <div class="form-actions">
        <button type="submit" name="save_draft" class="btn btn-outline">
            <i class="fas fa-save"></i> Save Draft
        </button>
        <button type="submit" name="submit_appraisal" class="btn btn-success">
            <i class="fas fa-paper-plane"></i> Submit for Review
        </button>
        <a href="my-kpis" class="btn btn-danger"><i class="fas fa-times"></i> Cancel</a>
    </div>
</div>

</form>

<!-- ------------------------------------------
     PREVIEW MODAL
------------------------------------------ -->
<div class="modal-overlay" id="previewModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-print"></i> &nbsp;Appraisal Preview</h3>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeModal()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-foot">
            <button type="button" class="btn btn-print" onclick="doPrint()">
                <i class="fas fa-print"></i> Print / Save PDF
            </button>
            <button type="button" class="btn btn-outline" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<!-- hidden print area -->
<div id="printArea"></div>

</div><!-- /.page-appraisal -->

<?php include 'includes/footer.php'; ?>

<script>
/* -----------------------------------------------
   STATE
----------------------------------------------- */
let kraCounter = 0;

const userInfo = {
    full_name:   <?php echo json_encode($profile['full_name'] ?? ''); ?>,
    department:  <?php echo json_encode(($profile['department_name'] ?? '')); ?>,
    job_title:   <?php echo json_encode($profile['job_title'] ?? ''); ?>,
    supervisor:  <?php echo json_encode($profile['supervisor_name'] ?? ''); ?>
};

/* -----------------------------------------------
   BUILD KRA BLOCK
----------------------------------------------- */
function addKra() {
    kraCounter++;
    const kid = kraCounter;
    const wrap = document.getElementById('kraContainer');

    const div = document.createElement('div');
    div.className = 'kra-block';
    div.dataset.kra = kid;

    div.innerHTML = `
    <!-- KRA header bar -->
    <div class="kra-header">
        <span class="kra-num">KRA ${kid}</span>
        <input type="text" name="kra[${kid}][title]"
               placeholder="e.g., Key Result Area 1: Internet Management"
               required>
        <button type="button" class="btn btn-danger btn-sm"
                onclick="removeKra(${kid})" style="flex-shrink:0;">
            <i class="fas fa-trash"></i>
        </button>
    </div>

    <!-- KRA-level meta -->
    <div class="kra-meta">
        <div class="field">
            <label>KRA Weight (%)</label>
            <input type="number" name="kra[${kid}][weight]" min="0" max="100" step="0.1"
                   placeholder="e.g., 30" oninput="updateTotalWeight()">
        </div>
        <div class="field">
            <label>KRA Rating (Computed)</label>
            <input type="text" name="kra[${kid}][kra_rating]" readonly placeholder="Auto-calculated"
                   id="kraRating${kid}"
                   title="Average of KPI employee ratings x weight">
        </div>
        <div class="field">
            <label>Agreed Rating (Overall)</label>
            <select name="kra[${kid}][agreed_rating]">
                <option value="">Select Agreed Rate</option>
                ${[1,2,3,4,5].map(n=>`<option value="${n}">${n}</option>`).join('')}
            </select>
        </div>
    </div>

    <!-- KPI rows -->
    <div class="kpi-table-wrap">
        <table class="kpi-table" id="kpiTable${kid}">
            <thead>
                <tr>
                    <th class="td-seq">#</th>
                    <th style="min-width:220px">KPI Indicator <span style="color:var(--danger)">*</span></th>
                    <th style="width:80px">Weight (%)</th>
                    <th class="th-center" colspan="5" style="width:180px">Employee Rating (1-5)</th>
                    <th class="th-center" colspan="5" style="width:180px">Manager Rating (1-5)</th>
                    <th class="th-center" colspan="5" style="width:180px">Agreed Rating (1-5)</th>
                    <th class="td-del"></th>
                </tr>
                <tr>
                    <th></th><th></th><th></th>
                    ${[1,2,3,4,5].map(n=>`<th class="th-center" style="width:36px">${n}</th>`).join('')}
                    ${[1,2,3,4,5].map(n=>`<th class="th-center" style="width:36px">${n}</th>`).join('')}
                    ${[1,2,3,4,5].map(n=>`<th class="th-center" style="width:36px">${n}</th>`).join('')}
                    <th></th>
                </tr>
            </thead>
            <tbody id="kpiBody${kid}"></tbody>
        </table>
    </div>
    <button type="button" class="btn-add-kpi" onclick="addKpi(${kid})">
        <i class="fas fa-plus"></i> Add KPI
    </button>

    <!-- Comments -->
    <div class="comment-grid">
        <div class="comment-block">
            <label>Employee Comments <span style="font-size:10px;font-weight:400">(specific examples)</span></label>
            <textarea name="kra[${kid}][emp_comments]" rows="3"
                      placeholder="Describe specific examples of your performance..."></textarea>
        </div>
        <div class="comment-block">
            <label>Manager Comments <span style="font-size:10px;font-weight:400">(specific examples)</span></label>
            <textarea name="kra[${kid}][mgr_comments]" rows="3"
                      placeholder="Supervisor will complete this section.."></textarea>
        </div>
    </div>
    `;

    wrap.appendChild(div);
    addKpi(kid); // start with one KPI
    renumberKras();
}

/* -----------------------------------------------
   BUILD KPI ROW
----------------------------------------------- */
let kpiCounters = {};

function addKpi(kid) {
    if (!kpiCounters[kid]) kpiCounters[kid] = 0;
    kpiCounters[kid]++;
    const pid = kpiCounters[kid];
    const tbody = document.getElementById('kpiBody' + kid);

    const tr = document.createElement('tr');
    tr.dataset.pid = pid;

    // Rating radio groups
    function ratingGroup(prefix) {
        return [1,2,3,4,5].map(n => `
            <td class="td-rating">
                <input type="radio" name="${prefix}[${n}]" value="${n}"
                       style="cursor:pointer;width:auto;"
                       onchange="calcKraRating(${kid})">
            </td>`).join('');
    }
    // Actually use radio per row: name = kra[kid][kpi][pid][emp_rating], value = chosen number
    // Simpler: single select per rating column
    function ratingSelect(fname, disabled) {
        return `
            <td class="td-rating" colspan="5">
                <select name="${fname}" class="rating-select"
                        ${disabled ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : ''}
                        onchange="calcKraRating(${kid})">
                    <option value="">Select Rate</option>
                    ${[1,2,3,4,5].map(n=>`<option value="${n}">${n}</option>`).join('')}
                </select>
            </td>`;
    }

    tr.innerHTML = `
        <td class="td-seq kpi-seq"></td>
        <td><input type="text" name="kra[${kid}][kpi][${pid}][title]"
                   placeholder="e.g., KPI 1: Ensure Network Works Seamlessly" required></td>
        <td><input type="number" name="kra[${kid}][kpi][${pid}][weight]"
                   min="0" max="100" step="0.1" placeholder="10"
                   oninput="calcKraRating(${kid})"></td>
        ${ratingSelect(`kra[${kid}][kpi][${pid}][emp_rating]`, false)}
        ${ratingSelect(`kra[${kid}][kpi][${pid}][mgr_rating]`, true)}
        ${ratingSelect(`kra[${kid}][kpi][${pid}][agreed_rating]`, false)}
        <td class="td-del">
            <button type="button" class="btn-del-row" onclick="removeKpi(this, ${kid})">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    renumberKpis(kid);
}

function removeKpi(btn, kid) {
    const rows = document.querySelectorAll(`#kpiBody${kid} tr`);
    if (rows.length <= 1) { alert('At least one KPI is required per KRA.'); return; }
    btn.closest('tr').remove();
    renumberKpis(kid);
    calcKraRating(kid);
}

function removeKra(kid) {
    const blocks = document.querySelectorAll('.kra-block');
    if (blocks.length <= 1) { alert('At least one KRA is required.'); return; }
    document.querySelector(`.kra-block[data-kra="${kid}"]`).remove();
    renumberKras();
    updateTotalWeight();
}

function renumberKpis(kid) {
    document.querySelectorAll(`#kpiBody${kid} .kpi-seq`).forEach((td, i) => {
        td.textContent = i + 1;
    });
}

function renumberKras() {
    document.querySelectorAll('.kra-block').forEach((div, i) => {
        div.querySelector('.kra-num').textContent = 'KRA ' + (i + 1);
    });
}

/* -----------------------------------------------
   WEIGHT & RATING CALCULATIONS
----------------------------------------------- */
function updateTotalWeight() {
    let total = 0;
    document.querySelectorAll('input[name*="[weight]"]').forEach(el => {
        if (el.name.match(/kra\[\d+\]\[weight\]/)) {
            total += parseFloat(el.value) || 0;
        }
    });
}

function calcKraRating(kid) {
    const rows = document.querySelectorAll(`#kpiBody${kid} tr`);
    let weightedSum = 0, totalW = 0;
    rows.forEach(tr => {
        const wInp = tr.querySelector('input[name*="[weight]"]');
        const rSel = tr.querySelector('select[name*="[emp_rating]"]');
        const w = parseFloat(wInp ? wInp.value : 0) || 0;
        const r = parseFloat(rSel ? rSel.value : 0) || 0;
        weightedSum += w * r;
        totalW += w;
    });
    const rating = totalW > 0 ? (weightedSum / totalW).toFixed(2) : '';
    const el = document.getElementById('kraRating' + kid);
    if (el) el.value = rating ? rating : '';
}

/* -----------------------------------------------
   PREVIEW GENERATION
----------------------------------------------- */
function generatePreview() {
    const pd_from = document.querySelector('input[name="period_from"]').value;
    const pd_to   = document.querySelector('input[name="period_to"]').value;
    const disc    = document.querySelector('input[name="discussion_date"]').value;

    function fmtDate(d) {
        if (!d) return '-';
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', year: 'numeric', day: 'numeric' });
    }

    let html = `
    <table class="ap-table" width="100%">
      <!-- -- Header info -- -->
      <tr class="ap-header-info">
        <td colspan="4" width="55%">
          <table width="100%">
            <tr>
              <td style="width:160px"><b>Name (Last, First, MI):</b></td>
              <td style="border-bottom:1px solid #999;min-width:180px">${esc(userInfo.full_name)}</td>
            </tr>
            <tr>
              <td><b>Department:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.department)}</td>
            </tr>
            <tr>
              <td><b>Job Title:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.job_title)}</td>
            </tr>
            <tr>
              <td><b>Supervisor:</b></td>
              <td style="border-bottom:1px solid #999">${esc(userInfo.supervisor)}</td>
            </tr>
          </table>
        </td>
        <td colspan="2" width="45%">
          <table width="100%">
            <tr>
              <td style="width:130px"><b>Appraisal Period:</b></td>
              <td>From: <b>${fmtDate(pd_from)}</b> &nbsp; To: <b>${fmtDate(pd_to)}</b></td>
            </tr>
            <tr>
              <td><b>Discussion Date:</b></td>
              <td style="border-bottom:1px solid #999">${fmtDate(disc)} </td>
            </tr>
          </table>
        </td>
      </tr>
      <tr><td colspan="6" style="padding:6px 0;border:none"></td></tr>
    </table>

    <!-- -- Section heading -- -->
    <table class="ap-table" width="100%" style="margin-top:10px;">
      <tr>
        <td colspan="20"
            style="background:#1e3a5f;color:#fff;font-size:14px;font-weight:700;padding:8px 10px;text-align:left;">
          Assessment against Key Result Areas
        </td>
      </tr>
    `;

    // Each KRA
    document.querySelectorAll('.kra-block').forEach((kraDiv, ki) => {
        const kraTitle  = kraDiv.querySelector('input[name*="[title]"]').value || `KRA ${ki+1}`;
        const kraWeight = kraDiv.querySelector('input[name*="[weight]"]').value || '';
        const kraRating = kraDiv.querySelector('input[name*="[kra_rating]"]').value || '';
        const kraAgreed = kraDiv.querySelector('select[name*="kra_rating\\]"]') ? '' :
                          (kraDiv.querySelector('select[name*="[agreed_rating]"]').value || '');
        const empCmt    = kraDiv.querySelector('textarea[name*="[emp_comments]"]').value || '';
        const mgrCmt    = kraDiv.querySelector('textarea[name*="[mgr_comments]"]').value || '';

        // Column spans: Activity | Weight | KRA Rating | 5 emp | 5 mgr | 5 agreed
        const ratingCols = 5;

        html += `
      <!-- KRA header row -->
      <tr>
        <td class="ap-kra-title" colspan="3">${esc(kraTitle)}</td>
        <td class="ap-section-heading" style="width:55px">Weight</td>
        <td class="ap-section-heading" style="width:65px">KRA Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Employee Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Manager Rating</td>
        <td class="ap-section-heading" colspan="${ratingCols}">Agreed Rating</td>
      </tr>
      <!-- Sub-heading with 1-5 columns -->
      <tr>
        <td colspan="3" style="background:#f2f2f2;font-weight:600;font-size:10px;">KPI Indicator</td>
        <td class="ap-rating-cell" style="background:#f2f2f2"></td>
        <td class="ap-rating-cell" style="background:#f2f2f2"></td>
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#dbe5f1;font-weight:700">${n}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#d9ead3;font-weight:700">${n}</td>`).join('')}
        ${[1,2,3,4,5].map(n=>`<td class="ap-rating-cell" style="background:#fce5cd;font-weight:700">${n}</td>`).join('')}
      </tr>
        `;

        // KPI rows
        kraDiv.querySelectorAll(`#kpiBody${kraDiv.dataset.kra} tr`).forEach((tr, pi) => {
            const kpiTitle  = tr.querySelector('input[name*="[title]"]').value || `KPI ${pi+1}`;
            const kpiWeight = tr.querySelector('input[name*="[weight]"]').value || '';
            const empRating = tr.querySelector('select[name*="[emp_rating]"]').value || '';
            const mgrRating = tr.querySelector('select[name*="[mgr_rating]"]').value || '';
            const agrRating = tr.querySelector('select[name*="kpi\\]"]') ? '' :
                              (tr.querySelector('select[name*="[agreed_rating]"]') ?
                               tr.querySelector('select[name*="[agreed_rating]"]').value : '');

            function ratingCells(chosen, bg) {
                return [1,2,3,4,5].map(n => `
                    <td class="ap-rating-cell" style="background:${bg}">
                        ${parseInt(chosen) === n ? '?' : ''}
                    </td>`).join('');
            }

            html += `
      <tr>
        <td colspan="3" style="padding-left:10px">${esc(kpiTitle)}</td>
        <td class="ap-rating-cell">${esc(kpiWeight)}${kpiWeight ? '%' : ''}</td>
        <td class="ap-rating-cell"></td>
        ${ratingCells(empRating, '#eef3fa')}
        ${ratingCells(mgrRating, '#eaf4e6')}
        ${ratingCells(agrRating, '#fef5ec')}
      </tr>
            `;
        });

        // KRA totals row
        html += `
      <tr>
        <td colspan="3" style="font-weight:700;background:#f2f2f2;text-align:right;padding-right:8px;">KRA Totals</td>
        <td class="ap-rating-cell" style="font-weight:700;background:#f2f2f2">${esc(kraWeight)}${kraWeight?'%':''}</td>
        <td class="ap-rating-cell" style="font-weight:700;background:#f2f2f2">${esc(kraRating)}</td>
        <td colspan="${ratingCols}" style="background:#f2f2f2"></td>
        <td colspan="${ratingCols}" style="background:#f2f2f2"></td>
        <td colspan="${ratingCols}" style="text-align:center;font-weight:700;background:#fef5ec">${esc(kraAgreed)}</td>
      </tr>
        `;

        // Comments rows
        html += `
      <tr>
        <td style="font-weight:700;font-size:10px;white-space:nowrap;width:120px">Employee<br>Comments<br><span style="font-weight:400;color:#555">(specific examples)</span></td>
        <td colspan="${3 + 1 + 1 + ratingCols + ratingCols + ratingCols - 1}" style="padding:6px 8px;line-height:1.5">
            ${esc(empCmt).replace(/\n/g,'<br>') || '<span style="color:#aaa;font-style:italic">-</span>'}
        </td>
      </tr>
      <tr>
        <td style="font-weight:700;font-size:10px;white-space:nowrap">Manager<br>Comments<br><span style="font-weight:400;color:#555">(specific examples)</span></td>
        <td colspan="${3 + 1 + 1 + ratingCols + ratingCols + ratingCols - 1}" style="padding:6px 8px;line-height:1.5">
            ${esc(mgrCmt).replace(/\n/g,'<br>') || '<span style="color:#aaa;font-style:italic">-</span>'}
        </td>
      </tr>
        `;
    });

    html += `</table>`;

    // Signature block
    html += `
    <table class="ap-table" width="100%" style="margin-top:16px;">
      <tr>
        <td colspan="3" style="font-weight:700;background:#1e3a5f;color:#fff;padding:6px 10px">Signatures</td>
      </tr>
      <tr>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Employee Signature &amp; Date</div>
        </td>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">Supervisor Signature &amp; Date</div>
        </td>
        <td style="width:33%;padding:20px 10px 6px">
            <div style="border-top:1px solid #333;padding-top:4px;font-size:10px">HR / Management Signature &amp; Date</div>
        </td>
      </tr>
    </table>
    `;

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('printArea').innerHTML = html;
    document.getElementById('previewModal').classList.add('open');
}

function closeModal() {
    document.getElementById('previewModal').classList.remove('open');
}

function doPrint() {
    window.print();
}

function esc(str) {
    return String(str || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

/* -----------------------------------------------
   INIT
----------------------------------------------- */
document.getElementById('btnAddKra').addEventListener('click', addKra);

// Close modal on overlay click
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Form submit guard
document.getElementById('appraisalForm').addEventListener('submit', function(e) {
    const kras = document.querySelectorAll('.kra-block');
    if (kras.length === 0) {
        e.preventDefault();
        alert('Please add at least one KRA before submitting.');
        return false;
    }
});

// Start with one KRA
addKra();
</script>