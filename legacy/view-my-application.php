<?php
declare(strict_types=1);

$page_title = 'Application Details';
include 'includes/header.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}


$sessionRole = strtolower(trim((string)(
    $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? ''
)));

if ($sessionRole !== 'applicant') {
    $roleStmt = $conn->prepare("
        SELECT role
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $databaseRole = '';

    if ($roleStmt) {
        $roleStmt->bind_param('i', $user_id);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        $databaseRole = strtolower(trim((string)($roleRow['role'] ?? '')));
    }

    if ($databaseRole !== 'applicant') {
        $_SESSION['error'] = 'Access denied. This page is only for applicants.';
        header('Location: dashboard');
        exit;
    }

    // Repair session role for the rest of the applicant portal.
    $_SESSION['role'] = 'Applicant';
    $_SESSION['user_role'] = 'Applicant';
} else {
    // Keep both session keys consistent.
    $_SESSION['role'] = 'Applicant';
    $_SESSION['user_role'] = 'Applicant';
}

$user_id = (int)$_SESSION['user_id'];

$application_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if ($application_id < 1) {
    $_SESSION['error'] = 'Application ID not provided.';
    header('Location: my-applications');
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function vma_value(array $row, string $key, string $fallback = 'Not provided'): string
{
    $value = $row[$key] ?? null;

    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }

    return (string)$value;
}

function vma_date(mixed $value, string $format = 'd M Y', string $fallback = 'Not recorded'): string
{
    if ($value === null || trim((string)$value) === '') {
        return $fallback;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? $fallback : date($format, $ts);
}

function vma_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? null : $ts;
}

function vma_json_array(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if ($value === null || trim((string)$value) === '') {
        return [];
    }

    $decoded = json_decode((string)$value, true);

    return is_array($decoded) ? $decoded : [];
}

function vma_list(array $items): string
{
    $clean = [];

    foreach ($items as $item) {
        if (is_scalar($item) && trim((string)$item) !== '') {
            $clean[] = trim((string)$item);
        }
    }

    return implode(', ', $clean);
}

/*
|--------------------------------------------------------------------------
| LOAD APPLICATION
|--------------------------------------------------------------------------
|
| IMPORTANT:
| New applications are owned through applications.submitted_by.
| The previous page used a.user_id, which is why the page could not load.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        a.*,
        o.opportunity_title,
        o.opportunity_type,
        o.description AS opportunity_description,
        o.deadline,
        o.start_date,
        o.eligibility_criteria,
        o.required_documents,
        o.min_team_size,
        o.max_team_size,
        o.sectors_allowed,
        o.available_slots,
        o.is_featured,
        reviewer.full_name AS reviewer_name,
        reviewer.email AS reviewer_email
    FROM applications a
    LEFT JOIN application_opportunities o
        ON a.opportunity_id = o.opportunity_id
    LEFT JOIN users reviewer
        ON a.reviewed_by = reviewer.user_id
    WHERE a.application_id = ?
      AND a.submitted_by = ?
    LIMIT 1
");

if (!$stmt) {
    error_log('view-my-application prepare failed: ' . $conn->error);
    $_SESSION['error'] = 'Unable to load the application at this time.';
    header('Location: my-applications');
    exit;
}

$stmt->bind_param('ii', $application_id, $user_id);
$stmt->execute();

$result = $stmt->get_result();
$application = $result ? $result->fetch_assoc() : null;

$stmt->close();

if (!$application) {
    $_SESSION['error'] = 'Application not found or access denied.';
    header('Location: my-applications');
    exit;
}

/*
|--------------------------------------------------------------------------
| DERIVED VALUES
|--------------------------------------------------------------------------
*/

$status = trim((string)($application['status'] ?? 'Draft'));

$progress_map = [
    'Draft'        => 10,
    'Pending'      => 25,
    'Submitted'    => 35,
    'Under Review' => 50,
    'Shortlisted'  => 70,
    'Accepted'     => 100,
    'Rejected'     => 100,
    'Withdrawn'    => 100,
];

$progress = $progress_map[$status] ?? 20;

$submitted_at = $application['submitted_at'] ?? null;

if (vma_timestamp($submitted_at) === null) {
    $submitted_at = $application['last_saved_at'] ?? $application['created_at'] ?? null;
}

$submitted_ts = vma_timestamp($submitted_at);
$days_ago = $submitted_ts === null
    ? null
    : max(0, (int)floor((time() - $submitted_ts) / 86400));

$badgeMap = [
    'Draft'        => 'badge-secondary',
    'Pending'      => 'badge-pending',
    'Submitted'    => 'badge-under-review',
    'Under Review' => 'badge-under-review',
    'Shortlisted'  => 'badge-under-review',
    'Accepted'     => 'badge-success',
    'Rejected'     => 'badge-danger',
    'Withdrawn'    => 'badge-secondary',
];

$badgeClass = $badgeMap[$status] ?? 'badge-secondary';

$alertMap = [
    'Draft'        => ['withdrawn', 'edit', 'Draft Application', 'Your application is saved as a draft. You can continue editing it before submission.'],
    'Pending'      => ['pending', 'clock', 'Pending Review', 'Your application is waiting to be reviewed.'],
    'Submitted'    => ['pending', 'paper-plane', 'Application Submitted', 'Your application was submitted successfully and is awaiting review.'],
    'Under Review' => ['pending', 'search', 'Under Review', 'Your application is currently being reviewed.'],
    'Shortlisted'  => ['shortlisted', 'star', 'Congratulations - Shortlisted!', 'Your application has been shortlisted for the next stage.'],
    'Accepted'     => ['accepted', 'check-circle', 'Application Accepted!', 'Congratulations! Your application has been accepted.'],
    'Rejected'     => ['rejected', 'times-circle', 'Application Not Selected', 'Thank you for applying. Please review any feedback provided below.'],
    'Withdrawn'    => ['withdrawn', 'undo', 'Application Withdrawn', 'This application has been withdrawn.'],
];

$alert = $alertMap[$status] ?? ['pending', 'info-circle', $status, ''];

$fillClass = match ($status) {
    'Accepted' => 'accepted',
    'Shortlisted' => 'shortlist',
    'Rejected', 'Withdrawn' => 'rejected',
    default => 'pending',
};

/*
|--------------------------------------------------------------------------
| NEW APPLICATION DATA
|--------------------------------------------------------------------------
*/

$sector_focus = vma_json_array($application['sector_focus'] ?? null);
$heard_about = vma_json_array($application['heard_about'] ?? null);
$regions_served = vma_json_array($application['regions_served'] ?? null);
$revenue_models = vma_json_array($application['revenue_models'] ?? null);
$data_uses = vma_json_array($application['data_uses'] ?? null);
$founders = vma_json_array($application['founders'] ?? null);
$other_team_members = vma_json_array($application['other_team_members'] ?? null);
$incubation_programmes = vma_json_array($application['incubation_programmes'] ?? null);
$external_funding_details = vma_json_array($application['external_funding_details'] ?? null);

$has_team = !empty($founders)
    || !empty($other_team_members)
    || !empty($application['contact_person'])
    || !empty($application['team_positioning']);

$doc_fields = [
    'legal_docs_path' => ['Legal / Registration Documents', 'fa-file-alt', 'doc'],
    'safeguarding_policy_path' => ['Safeguarding Policy', 'fa-shield-alt', 'doc'],
    'business_plan_path' => ['Business Plan', 'fa-file-pdf', 'pdf'],
    'pitch_deck_path' => ['Pitch Deck', 'fa-file-powerpoint', 'pptx'],
    'financial_projections_path' => ['Financial Projections', 'fa-file-excel', 'xls'],
    'registration_certificate_path' => ['Registration Certificate', 'fa-file-alt', 'doc'],
    'other_documents_path' => ['Other Documents', 'fa-file', 'doc'],
];

$has_docs = false;

foreach ($doc_fields as $field => $meta) {
    if (!empty($application[$field])) {
        $has_docs = true;
        break;
    }
}
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
.vma-tab-nav{display:flex;border-bottom:2px solid var(--ink-100);margin-bottom:24px;overflow-x:auto;scrollbar-width:none}
.vma-tab-nav::-webkit-scrollbar{display:none}
.vma-tab-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 16px;font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--ink-300);background:transparent;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;white-space:nowrap;flex-shrink:0}
.vma-tab-btn.active{color:var(--ink-700);border-bottom-color:var(--brand-500);font-weight:700}
.vma-tab-panel{display:none}.vma-tab-panel.active{display:block}
.vma-progress-wrap{background:var(--surface-card);border-radius:var(--radius-xl);box-shadow:var(--shadow-md);border:1px solid rgba(0,0,0,.04);padding:22px 24px;margin-bottom:20px}
.vma-progress-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:10px}
.vma-progress-label{font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-300)}
.vma-progress-pct{font-size:22px;font-weight:800;color:var(--ink-700)}
.vma-progress-track{height:10px;background:var(--ink-100);border-radius:999px;overflow:hidden;margin-bottom:8px}
.vma-progress-fill{height:100%;border-radius:999px}
.vma-progress-fill.accepted{background:linear-gradient(90deg,var(--green-fg),#4ade80)}
.vma-progress-fill.pending{background:linear-gradient(90deg,var(--amber-fg),#fbbf24)}
.vma-progress-fill.shortlist{background:linear-gradient(90deg,var(--blue-fg),#60a5fa)}
.vma-progress-fill.rejected{background:linear-gradient(90deg,var(--red-fg),#f87171)}
.vma-progress-sub{font-size:12px;color:var(--ink-300);font-weight:600}
.vma-status-alert{display:flex;align-items:flex-start;gap:13px;padding:14px 18px;border-radius:var(--radius-lg);border:1.5px solid;margin-bottom:20px;font-size:13px;font-weight:600;line-height:1.55}
.vma-status-alert i{font-size:18px;flex-shrink:0;margin-top:1px}
.vma-status-alert strong{display:block;font-size:13.5px;margin-bottom:2px}
.vma-status-alert.pending{background:var(--amber-bg);border-color:var(--amber-border);color:var(--amber-fg)}
.vma-status-alert.shortlisted{background:var(--blue-bg);border-color:var(--blue-border);color:var(--blue-fg)}
.vma-status-alert.accepted{background:var(--green-bg);border-color:var(--green-border);color:var(--green-fg)}
.vma-status-alert.rejected{background:var(--red-bg);border-color:var(--red-border);color:var(--red-fg)}
.vma-status-alert.withdrawn{background:var(--slate-bg);border-color:var(--slate-border);color:var(--slate-fg)}
.vma-detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.vma-detail-item{background:var(--ink-50);border-radius:var(--radius-md);padding:12px 14px;border-left:3px solid var(--brand-400)}
.vma-detail-item.full{grid-column:1/-1}
.vma-detail-label{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-300);margin-bottom:4px}
.vma-detail-value{font-size:13.5px;font-weight:600;color:var(--ink-700);word-break:break-word;line-height:1.5}
.vma-detail-value a{color:var(--brand-600)}
.vma-prose-block{margin-bottom:18px}
.vma-prose-label{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--brand-600);margin-bottom:6px}
.vma-prose{font-size:13.5px;color:var(--ink-400);line-height:1.8;white-space:pre-wrap;background:var(--ink-50);border-radius:var(--radius-md);padding:14px 16px;margin:0}
.vma-table-wrap{overflow-x:auto}
.vma-table{width:100%;border-collapse:collapse;font-size:13px}
.vma-table th,.vma-table td{padding:10px 12px;border-bottom:1px solid var(--ink-100);text-align:left;vertical-align:top}
.vma-table th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-300);background:var(--ink-50)}
.vma-doc-list{display:flex;flex-direction:column;gap:10px}
.vma-doc-item{display:flex;align-items:center;justify-content:space-between;gap:14px;background:var(--ink-50);border-radius:var(--radius-md);padding:12px 15px;border:1px solid var(--ink-100)}
.vma-doc-left{display:flex;align-items:center;gap:12px}
.vma-doc-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:grid;place-items:center;font-size:18px;flex-shrink:0}
.vma-doc-icon.pdf{background:#fef2f2;color:#dc2626}.vma-doc-icon.pptx{background:#fff7ed;color:#c2410c}.vma-doc-icon.xls{background:#f0fdf4;color:#15803d}.vma-doc-icon.doc{background:var(--blue-bg);color:var(--blue-fg)}
.vma-feedback-card{border-radius:var(--radius-lg);padding:16px 20px;border:1.5px solid;margin-bottom:14px}
.vma-feedback-card.notes{background:var(--blue-bg);border-color:var(--blue-border)}
.vma-feedback-card.rejection{background:var(--red-bg);border-color:var(--red-border)}
.vma-feedback-title{font-size:13px;font-weight:800;margin-bottom:10px}
.vma-feedback-body{font-size:13.5px;color:var(--ink-500);line-height:1.75}
.vma-timeline{position:relative;padding-left:28px}
.vma-timeline:before{content:'';position:absolute;left:8px;top:8px;bottom:8px;width:2px;background:var(--ink-100)}
.vma-tl-item{position:relative;background:var(--ink-50);border-radius:var(--radius-md);padding:12px 14px;margin-bottom:10px}
.vma-tl-item:before{content:'';position:absolute;left:-22px;top:15px;width:12px;height:12px;border-radius:50%;background:var(--brand-500);border:3px solid var(--surface-card);box-shadow:0 0 0 2px var(--brand-400)}
.vma-tl-title{font-size:13px;font-weight:700;color:var(--ink-700);margin-bottom:3px}
.vma-tl-sub{font-size:11.5px;color:var(--ink-300);line-height:1.55}
.badge-pending{background:var(--amber-bg);color:var(--amber-fg);border-color:var(--amber-border)}
.badge-danger{background:var(--red-bg);color:var(--red-fg);border-color:var(--red-border)}
.badge-under-review{background:var(--blue-bg);color:var(--blue-fg);border-color:var(--blue-border)}
@media(max-width:900px){.vma-two-col{grid-template-columns:1fr!important}}
@media(max-width:640px){.vma-detail-grid{grid-template-columns:1fr}.vma-tab-btn{padding:10px 12px;font-size:12px}}
</style>

<div class="opp-hero" style="margin-bottom:20px;">
    <div class="opp-hero-left">
        <div class="opp-eyebrow">
            <span class="opp-dot"></span>
            <?= h(vma_value($application, 'opportunity_type', 'Opportunity')) ?>
            <?php if (!empty($application['is_featured'])): ?>
                <span style="margin-left:6px;background:rgba(255,255,255,.15);border-radius:999px;padding:2px 10px;font-size:10px;font-weight:700;">
                    <i class="fas fa-star" style="color:#fbbf24;"></i> Featured
                </span>
            <?php endif; ?>
        </div>

        <h1><?= h(vma_value($application, 'opportunity_title', 'Application Details')) ?></h1>

        <div class="opp-meta" style="margin-top:8px;opacity:.9;">
            <span class="opp-meta-item">
                <i class="fas fa-hashtag"></i>
                #<?= (int)$application_id ?>
            </span>

            <?php if ($submitted_ts !== null): ?>
                <span class="opp-meta-item">
                    <i class="fas fa-calendar"></i>
                    <?= $status === 'Draft' ? 'Last saved' : 'Submitted' ?>
                    <?= h(vma_date($submitted_at)) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="opp-hero-actions" style="position:relative;flex-direction:column;align-items:flex-end;gap:10px;">
        <span class="badge <?= h($badgeClass) ?>" style="font-size:13px;padding:7px 18px;">
            <?= h($status) ?>
        </span>

        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="my-applications" class="btn btn-white btn-sm">
                <i class="fas fa-arrow-left"></i> My Applications
            </a>

            <?php if ($status === 'Draft'): ?>
                <a
                    href="submit-application?opportunity_id=<?= (int)($application['opportunity_id'] ?? 0) ?>&draft_id=<?= (int)$application_id ?>"
                    class="btn btn-white btn-sm"
                >
                    <i class="fas fa-edit"></i> Continue Draft
                </a>
            <?php endif; ?>

            <button type="button" onclick="window.print()" class="btn btn-white btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<div class="vma-progress-wrap">
    <div class="vma-progress-header">
        <span class="vma-progress-label">Application Progress</span>
        <span class="vma-progress-pct"><?= (int)$progress ?>%</span>
    </div>

    <div class="vma-progress-track">
        <div class="vma-progress-fill <?= h($fillClass) ?>" style="width:<?= (int)$progress ?>%;"></div>
    </div>

    <div class="vma-progress-sub">
        <?= h($status) ?>
        <?php if ($days_ago !== null): ?>
            &nbsp;.&nbsp;
            <?= $days_ago === 0 ? 'Today' : h($days_ago) . ' day' . ($days_ago === 1 ? '' : 's') . ' ago' ?>
        <?php endif; ?>
    </div>
</div>

<div class="vma-status-alert <?= h($alert[0]) ?>">
    <i class="fas fa-<?= h($alert[1]) ?>"></i>
    <div>
        <strong><?= h($alert[2]) ?></strong>
        <?= h($alert[3]) ?>
    </div>
</div>

<div class="vma-tab-nav">
    <button class="vma-tab-btn active" data-tab="overview"><i class="fas fa-rocket"></i> Venture</button>
    <button class="vma-tab-btn" data-tab="team"><i class="fas fa-users"></i> Team</button>
    <button class="vma-tab-btn" data-tab="solution"><i class="fas fa-lightbulb"></i> Problem & Solution</button>
    <button class="vma-tab-btn" data-tab="traction"><i class="fas fa-chart-line"></i> Traction</button>
    <button class="vma-tab-btn" data-tab="business"><i class="fas fa-briefcase"></i> Business & Funding</button>
    <button class="vma-tab-btn" data-tab="safeguarding"><i class="fas fa-shield-alt"></i> Safeguarding</button>
    <button class="vma-tab-btn" data-tab="mel"><i class="fas fa-chart-bar"></i> MEL</button>
    <?php if ($has_docs): ?>
        <button class="vma-tab-btn" data-tab="documents"><i class="fas fa-paperclip"></i> Documents</button>
    <?php endif; ?>
    <button class="vma-tab-btn" data-tab="timeline"><i class="fas fa-history"></i> Timeline</button>
</div>

<div class="vma-tab-panel active" id="tab-overview">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-rocket" style="color:var(--brand-500);"></i> Venture Information</h3>
        </div>

        <div class="opp-panel-body">
            <div class="vma-detail-grid">
                <?php
                $venture_items = [
                    'startup_name' => 'Startup Name',
                    'business_stage' => 'Business Stage',
                    'has_physical_location' => 'Physical Location',
                    'district' => 'District',
                    'city_town' => 'City / Town',
                    'founded_month' => 'Founded',
                    'formally_registered' => 'Formally Registered',
                    'legal_status' => 'Legal Status',
                    'registration_authority' => 'Registration Authority',
                    'website' => 'Website',
                    'incubation_participated' => 'Incubation Participated',
                ];
                foreach ($venture_items as $field => $label):
                    if (!array_key_exists($field, $application)) continue;
                    $value = $application[$field];
                    if ($value === null || trim((string)$value) === '') continue;
                ?>
                    <div class="vma-detail-item">
                        <div class="vma-detail-label"><?= h($label) ?></div>
                        <div class="vma-detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($application['physical_address'])): ?>
                    <div class="vma-detail-item full">
                        <div class="vma-detail-label">Physical Address</div>
                        <div class="vma-detail-value"><?= nl2br(h($application['physical_address'])) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($sector_focus): ?>
                    <div class="vma-detail-item full">
                        <div class="vma-detail-label">Sector Focus</div>
                        <div class="vma-detail-value"><?= h(vma_list($sector_focus)) ?></div>
                    </div>
                <?php elseif (!empty($application['sector'])): ?>
                    <div class="vma-detail-item full">
                        <div class="vma-detail-label">Sector</div>
                        <div class="vma-detail-value"><?= h($application['sector']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($heard_about): ?>
                    <div class="vma-detail-item full">
                        <div class="vma-detail-label">How They Heard About the Opportunity</div>
                        <div class="vma-detail-value"><?= h(vma_list($heard_about)) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($incubation_programmes): ?>
                <div style="margin-top:20px;">
                    <div class="vma-prose-label">Incubation / Accelerator Programmes</div>
                    <div class="vma-table-wrap">
                        <table class="vma-table">
                            <thead><tr><th>Programme</th><th>Organisation</th><th>Year</th><th>Outcomes</th></tr></thead>
                            <tbody>
                            <?php foreach ($incubation_programmes as $row): ?>
                                <tr>
                                    <td><?= h($row['programme'] ?? '—') ?></td>
                                    <td><?= h($row['organisation'] ?? '—') ?></td>
                                    <td><?= h($row['year'] ?? '—') ?></td>
                                    <td><?= h($row['outcomes'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-team">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-users" style="color:var(--brand-500);"></i> Team Information</h3>
        </div>
        <div class="opp-panel-body">
            <div class="vma-detail-grid" style="margin-bottom:20px;">
                <?php foreach ([
                    'contact_person' => 'Contact Person',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'contact_gender' => 'Gender',
                    'full_time_staff' => 'Full-Time Staff',
                    'part_time_staff' => 'Part-Time Staff',
                ] as $field => $label):
                    if (empty($application[$field]) && $application[$field] !== '0') continue;
                ?>
                    <div class="vma-detail-item">
                        <div class="vma-detail-label"><?= h($label) ?></div>
                        <div class="vma-detail-value"><?= h($application[$field]) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($founders): ?>
                <div class="vma-prose-label">Founders</div>
                <div class="vma-table-wrap" style="margin-bottom:20px;">
                    <table class="vma-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Age</th><th>Gender</th><th>Shareholding</th><th>Full Time</th><th>PWD</th></tr></thead>
                        <tbody>
                        <?php foreach ($founders as $row): ?>
                            <tr>
                                <td><?= h($row['name'] ?? '—') ?></td>
                                <td><?= h($row['role'] ?? '—') ?></td>
                                <td><?= h($row['age'] ?? '—') ?></td>
                                <td><?= h($row['gender'] ?? '—') ?></td>
                                <td><?= h($row['shareholding'] ?? '—') ?></td>
                                <td><?= h($row['full_time'] ?? '—') ?></td>
                                <td><?= h($row['pwd_status'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($other_team_members): ?>
                <div class="vma-prose-label">Other Team Members</div>
                <div class="vma-table-wrap" style="margin-bottom:20px;">
                    <table class="vma-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Age</th><th>Gender</th><th>PWD</th></tr></thead>
                        <tbody>
                        <?php foreach ($other_team_members as $row): ?>
                            <tr>
                                <td><?= h($row['name'] ?? '—') ?></td>
                                <td><?= h($row['role'] ?? '—') ?></td>
                                <td><?= h($row['age'] ?? '—') ?></td>
                                <td><?= h($row['gender'] ?? '—') ?></td>
                                <td><?= h($row['pwd_status'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($application['team_positioning'])): ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label">Team Positioning</div>
                    <p class="vma-prose"><?= h($application['team_positioning']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-solution">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-lightbulb" style="color:var(--brand-500);"></i> Problem & Solution</h3>
        </div>
        <div class="opp-panel-body">
            <?php foreach ([
                'problem_solution' => 'Problem & Solution',
                'primary_users' => 'Primary Users',
                'solution_languages' => 'Solution Languages',
                'platforms_devices' => 'Platforms / Devices',
                'works_offline' => 'Works Offline',
                'refugee_settlements_specify' => 'Refugee Settlements',
                'regions_other' => 'Other Regions',
                'demo_link' => 'Demo Link',
            ] as $field => $label):
                if (empty($application[$field])) continue;
            ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label"><?= h($label) ?></div>
                    <p class="vma-prose"><?= h($application[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($regions_served): ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label">Regions Served</div>
                    <p class="vma-prose"><?= h(vma_list($regions_served)) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-traction">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-chart-line" style="color:var(--brand-500);"></i> Traction</h3>
        </div>
        <div class="opp-panel-body">
            <div class="vma-detail-grid">
                <?php foreach ([
                    'total_users_reached' => 'Total Users Reached',
                    'total_learners' => 'Total Learners',
                    'active_learners' => 'Active Learners',
                    'paying_customers' => 'Paying Customers',
                    'partner_organisations' => 'Partner Organisations',
                    'revenue_last_12_months' => 'Revenue - Last 12 Months',
                    'customers' => 'Customers',
                    'growth_rate' => 'Growth Rate',
                ] as $field => $label):
                    if (!array_key_exists($field, $application)) continue;
                    $value = $application[$field];
                    if ($value === null || trim((string)$value) === '') continue;
                ?>
                    <div class="vma-detail-item">
                        <div class="vma-detail-label"><?= h($label) ?></div>
                        <div class="vma-detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($application['other_traction_metrics'])): ?>
                <div class="vma-prose-block" style="margin-top:20px;">
                    <div class="vma-prose-label">Other Traction Metrics</div>
                    <p class="vma-prose"><?= h($application['other_traction_metrics']) ?></p>
                </div>
            <?php elseif (!empty($application['traction'])): ?>
                <div class="vma-prose-block" style="margin-top:20px;">
                    <div class="vma-prose-label">Traction</div>
                    <p class="vma-prose"><?= h($application['traction']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-business">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-briefcase" style="color:var(--brand-500);"></i> Business & Funding</h3>
        </div>
        <div class="opp-panel-body">
            <?php if ($revenue_models): ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label">Revenue Models</div>
                    <p class="vma-prose"><?= h(vma_list($revenue_models)) ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ([
                'who_pays' => 'Who Pays',
                'revenue_model_other' => 'Other Revenue Model',
                'revenue_streams' => 'Revenue Streams',
                'raised_external_funding' => 'Raised External Funding',
                'fellowship_grant_use' => 'Planned Fellowship Grant Use',
                'fellowship_growth_value' => 'How the Fellowship Will Support Growth',
            ] as $field => $label):
                if (empty($application[$field])) continue;
            ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label"><?= h($label) ?></div>
                    <p class="vma-prose"><?= h($application[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($external_funding_details): ?>
                <div class="vma-prose-label">External Funding Details</div>
                <div class="vma-table-wrap">
                    <table class="vma-table">
                        <thead><tr><th>Funder</th><th>Type</th><th>Amount</th><th>Currency</th><th>Year</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($external_funding_details as $row): ?>
                            <tr>
                                <td><?= h($row['funder'] ?? '—') ?></td>
                                <td><?= h($row['type'] ?? '—') ?></td>
                                <td><?= h($row['amount'] ?? '—') ?></td>
                                <td><?= h($row['currency'] ?? '—') ?></td>
                                <td><?= h($row['year'] ?? '—') ?></td>
                                <td><?= h($row['status'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-safeguarding">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-shield-alt" style="color:var(--brand-500);"></i> Safeguarding & Inclusion</h3>
        </div>
        <div class="opp-panel-body">
            <?php foreach ([
                'has_safeguarding_policy' => 'Has Safeguarding Policy',
                'safeguarding_measures' => 'Safeguarding Measures',
                'has_reporting_procedures' => 'Has Reporting Procedures',
                'reporting_procedures_description' => 'Reporting Procedures',
                'users_include_minors' => 'Users Include Minors',
                'parental_consent_process' => 'Parental Consent Process',
                'safeguarding_focal_name' => 'Safeguarding Focal Person',
                'safeguarding_focal_role' => 'Safeguarding Focal Role',
                'safeguarding_focal_contact' => 'Safeguarding Focal Contact',
                'accessibility_inclusion' => 'Accessibility & Inclusion',
            ] as $field => $label):
                if (empty($application[$field])) continue;
            ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label"><?= h($label) ?></div>
                    <p class="vma-prose"><?= h($application[$field]) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="vma-tab-panel" id="tab-mel">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-chart-bar" style="color:var(--brand-500);"></i> Monitoring, Evaluation & Learning</h3>
        </div>
        <div class="opp-panel-body">
            <?php foreach ([
                'impact_measurement' => 'Impact Measurement',
                'data_collection_frequency' => 'Data Collection Frequency',
                'data_use_other' => 'Other Data Use',
                'has_mel_framework' => 'Has MEL Framework',
                'data_tools' => 'Data Tools',
                'learning_outcomes_evidence' => 'Learning Outcomes Evidence',
                'pdpo_status' => 'PDPO Status',
            ] as $field => $label):
                if (empty($application[$field])) continue;
            ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label"><?= h($label) ?></div>
                    <p class="vma-prose"><?= h($application[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($data_uses): ?>
                <div class="vma-prose-block">
                    <div class="vma-prose-label">How Data Is Used</div>
                    <p class="vma-prose"><?= h(vma_list($data_uses)) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($has_docs): ?>
<div class="vma-tab-panel" id="tab-documents">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-paperclip" style="color:var(--brand-500);"></i> Supporting Documents</h3>
        </div>
        <div class="opp-panel-body">
            <div class="vma-doc-list">
                <?php foreach ($doc_fields as $field => [$label, $icon, $type]):
                    if (empty($application[$field])) continue;
                    $path = (string)$application[$field];
                ?>
                    <div class="vma-doc-item">
                        <div class="vma-doc-left">
                            <div class="vma-doc-icon <?= h($type) ?>">
                                <i class="fas <?= h($icon) ?>"></i>
                            </div>
                            <div>
                                <div style="font-size:13px;font-weight:700;color:var(--ink-700);"><?= h($label) ?></div>
                                <div style="font-size:11px;color:var(--ink-300);margin-top:2px;"><?= h(basename($path)) ?></div>
                            </div>
                        </div>

                        <a href="<?= h(ims_upload_url($path)) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                            <i class="fas fa-eye"></i> Open
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="vma-tab-panel" id="tab-timeline">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-history" style="color:var(--brand-500);"></i> Application Timeline</h3>
        </div>
        <div class="opp-panel-body">
            <div class="vma-timeline">
                <?php if (!empty($application['created_at'])): ?>
                    <div class="vma-tl-item">
                        <div class="vma-tl-title">Application Started</div>
                        <div class="vma-tl-sub"><?= h(vma_date($application['created_at'], 'd M Y, g:i A')) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($application['last_saved_at'])): ?>
                    <div class="vma-tl-item">
                        <div class="vma-tl-title">Draft Last Saved</div>
                        <div class="vma-tl-sub"><?= h(vma_date($application['last_saved_at'], 'd M Y, g:i A')) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($application['submitted_at'])): ?>
                    <div class="vma-tl-item">
                        <div class="vma-tl-title">Application Submitted</div>
                        <div class="vma-tl-sub"><?= h(vma_date($application['submitted_at'], 'd M Y, g:i A')) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($application['reviewed_at'])): ?>
                    <div class="vma-tl-item">
                        <div class="vma-tl-title">Application Reviewed</div>
                        <div class="vma-tl-sub">
                            <?php if (!empty($application['reviewer_name'])): ?>
                                By <?= h($application['reviewer_name']) ?><br>
                            <?php endif; ?>
                            <?= h(vma_date($application['reviewed_at'], 'd M Y, g:i A')) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (in_array($status, ['Shortlisted', 'Accepted', 'Rejected', 'Withdrawn'], true)): ?>
                    <div class="vma-tl-item">
                        <div class="vma-tl-title"><?= h($status) ?></div>
                        <div class="vma-tl-sub">Current application status.</div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($application['reviewer_notes'])): ?>
                <div class="vma-feedback-card notes" style="margin-top:20px;">
                    <div class="vma-feedback-title"><i class="fas fa-comment-dots"></i> Reviewer Notes</div>
                    <div class="vma-feedback-body"><?= nl2br(h($application['reviewer_notes'])) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($status === 'Rejected' && !empty($application['rejection_reason'])): ?>
                <div class="vma-feedback-card rejection">
                    <div class="vma-feedback-title"><i class="fas fa-times-circle"></i> Rejection Reason</div>
                    <div class="vma-feedback-body"><?= nl2br(h($application['rejection_reason'])) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function switchTab(name) {
    document.querySelectorAll('.vma-tab-btn').forEach(function (btn) {
        btn.classList.toggle('active', btn.dataset.tab === name);
    });

    document.querySelectorAll('.vma-tab-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.id === 'tab-' + name);
    });

    const button = document.querySelector('.vma-tab-btn[data-tab="' + name + '"]');

    if (button) {
        button.scrollIntoView({inline: 'nearest', behavior: 'smooth'});
    }

    history.replaceState(null, '', '#' + name);
}

document.querySelectorAll('.vma-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        switchTab(btn.dataset.tab);
    });
});

(function () {
    const hash = window.location.hash.replace('#', '');

    if (hash && document.getElementById('tab-' + hash)) {
        switchTab(hash);
    }
})();
</script>
