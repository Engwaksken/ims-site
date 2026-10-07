<?php
declare(strict_types=1);

ob_start();

$page_title = 'Application Details';
require_once 'includes/header.php';
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_STAFF_ROLES); // was IMS_ALL_ROLES: applicants/members could read any application by id


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

function va_timestamp(mixed $value): ?int
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $ts = strtotime((string)$value);

    return $ts === false ? null : $ts;
}

function va_date(
    mixed $value,
    string $format = 'd M Y',
    string $fallback = 'Not recorded'
): string {
    $ts = va_timestamp($value);

    return $ts === null ? $fallback : date($format, $ts);
}

function va_json_array(mixed $value): array
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

function va_list(array $items): string
{
    $clean = [];

    foreach ($items as $item) {
        if (is_scalar($item) && trim((string)$item) !== '') {
            $clean[] = trim((string)$item);
        }
    }

    return implode(', ', $clean);
}

function va_status_badge(string $status): string
{
    return match ($status) {
        'Accepted', 'Approved', 'Selected' => 'badge-success',
        'Rejected' => 'badge-danger',
        'Shortlisted' => 'badge-shortlisted',
        'Under Review' => 'badge-under-review',
        'Waitlisted' => 'badge-waitlisted',
        'Submitted' => 'badge-under-review',
        'Draft' => 'badge-secondary',
        'Withdrawn' => 'badge-secondary',
        default => 'badge-secondary',
    };
}

function va_status_icon(string $status): string
{
    return match ($status) {
        'Accepted', 'Approved', 'Selected' => 'check-circle',
        'Rejected' => 'times-circle',
        'Shortlisted' => 'star',
        'Under Review' => 'search',
        'Waitlisted' => 'hourglass-half',
        'Submitted' => 'paper-plane',
        'Draft' => 'edit',
        'Withdrawn' => 'undo',
        default => 'file-alt',
    };
}

/*
|--------------------------------------------------------------------------
| APPLICATION ID
|--------------------------------------------------------------------------
*/
$application_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if ($application_id < 1) {
    $_SESSION['error'] = 'Invalid application ID.';
    header('Location: application-opportunities.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD APPLICATION
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        a.*,
        o.opportunity_title,
        o.opportunity_type,
        o.deadline,
        o.start_date,
        o.description AS opportunity_description,
        o.eligibility_criteria,
        o.required_documents,
        o.sectors_allowed,
        o.available_slots,
        o.is_featured,
        u.full_name AS applicant_name,
        u.email AS account_email,
        COALESCE(agg.review_count, 0) AS review_count,
        COALESCE(agg.avg_score, 0) AS avg_score
    FROM applications a
    LEFT JOIN application_opportunities o
        ON a.opportunity_id = o.opportunity_id
    LEFT JOIN users u
        ON a.submitted_by = u.user_id
    LEFT JOIN (
        SELECT
            application_id,
            COUNT(*) AS review_count,
            ROUND(AVG(overall_score), 1) AS avg_score
        FROM application_reviews
        GROUP BY application_id
    ) agg
        ON agg.application_id = a.application_id
    WHERE a.application_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('view-application prepare failed: ' . $conn->error);
    $_SESSION['error'] = 'Unable to load this application.';
    header('Location: application-opportunities.php');
    exit;
}

$stmt->bind_param('i', $application_id);
$stmt->execute();

$result = $stmt->get_result();
$app = $result ? $result->fetch_assoc() : null;

$stmt->close();

if (!$app) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: application-opportunities.php');
    exit;
}

$reference = !empty($app['reference_number'])
    ? (string)$app['reference_number']
    : 'APP-' . str_pad((string)$application_id, 6, '0', STR_PAD_LEFT);

/*
|--------------------------------------------------------------------------
| REVIEWS
|--------------------------------------------------------------------------
*/
$reviews = [];

$stmt = $conn->prepare("
    SELECT
        r.*,
        u.full_name AS reviewer_name
    FROM application_reviews r
    LEFT JOIN users u
        ON r.reviewer_id = u.user_id
    WHERE r.application_id = ?
    ORDER BY r.created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $application_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| STATUS HISTORY
|--------------------------------------------------------------------------
*/
$status_history = [];

$stmt = $conn->prepare("
    SELECT
        h.*,
        u.full_name AS changed_by_name
    FROM application_status_history h
    LEFT JOIN users u
        ON h.changed_by = u.user_id
    WHERE h.application_id = ?
    ORDER BY h.changed_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $application_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $status_history[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| NEW APPLICATION DATA
|--------------------------------------------------------------------------
*/
$sector_focus = va_json_array($app['sector_focus'] ?? null);
$heard_about = va_json_array($app['heard_about'] ?? null);
$regions_served = va_json_array($app['regions_served'] ?? null);
$revenue_models = va_json_array($app['revenue_models'] ?? null);
$data_uses = va_json_array($app['data_uses'] ?? null);
$founders = va_json_array($app['founders'] ?? null);
$other_team_members = va_json_array($app['other_team_members'] ?? null);
$incubation_programmes = va_json_array($app['incubation_programmes'] ?? null);
$external_funding_details = va_json_array($app['external_funding_details'] ?? null);

$status = trim((string)($app['status'] ?? 'Draft'));
$badgeClass = va_status_badge($status);
$statusIcon = va_status_icon($status);

$reviewCount = (int)($app['review_count'] ?? 0);
$avgScore = (float)($app['avg_score'] ?? 0);

$doc_fields = [
    'legal_docs_path' => ['Legal / Registration Documents', 'fa-file-alt', 'doc'],
    'safeguarding_policy_path' => ['Safeguarding Policy', 'fa-shield-alt', 'doc'],
    'business_plan_path' => ['Business Plan', 'fa-file-pdf', 'pdf'],
    'pitch_deck_path' => ['Pitch Deck', 'fa-file-powerpoint', 'pptx'],
    'financial_projections_path' => ['Financial Projections', 'fa-file-excel', 'xls'],
    'registration_certificate_path' => ['Registration Certificate', 'fa-file-alt', 'doc'],
    'other_documents_path' => ['Other Documents', 'fa-file', 'doc'],
];

$hasDocuments = false;

foreach ($doc_fields as $field => $meta) {
    if (!empty($app[$field])) {
        $hasDocuments = true;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| REVIEW ACTION AVAILABILITY
|--------------------------------------------------------------------------
*/
$reviewableStatuses = ['Submitted', 'Under Review', 'Shortlisted'];
$canReview = in_array($status, $reviewableStatuses, true);
$canShortlist = in_array($status, ['Submitted', 'Under Review'], true);
$canSelect = in_array($status, ['Submitted', 'Under Review', 'Shortlisted'], true);
$canReject = in_array($status, ['Submitted', 'Under Review', 'Shortlisted'], true);
?>

<link rel="stylesheet" href="css/opportunities.css">

<style>
.va-tab-nav{display:flex;border-bottom:2px solid var(--ink-100);margin-bottom:24px;overflow-x:auto;scrollbar-width:none}
.va-tab-nav::-webkit-scrollbar{display:none}
.va-tab-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 16px;font-family:var(--font-body);font-size:13px;font-weight:600;color:var(--ink-300);background:transparent;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;white-space:nowrap;flex-shrink:0}
.va-tab-btn.active{color:var(--ink-700);border-bottom-color:var(--brand-500);font-weight:700}
.va-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;font-size:10px;font-weight:700;background:var(--ink-100);color:var(--ink-400);border-radius:999px}
.va-tab-btn.active .va-tab-count{background:var(--brand-500);color:#fff}
.va-tab-panel{display:none}.va-tab-panel.active{display:block}
.va-two-col{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}
.va-detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.va-detail-item{background:var(--ink-50);border-radius:var(--radius-md);padding:12px 14px;border-left:3px solid var(--brand-400)}
.va-detail-item.full{grid-column:1/-1}
.va-detail-label{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-300);margin-bottom:4px}
.va-detail-value{font-size:13.5px;font-weight:600;color:var(--ink-700);word-break:break-word;line-height:1.5}
.va-prose-block{margin-bottom:20px}
.va-prose-label{font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--brand-600);margin-bottom:6px}
.va-prose{font-size:13.5px;color:var(--ink-400);line-height:1.8;white-space:pre-wrap;background:var(--ink-50);border-radius:var(--radius-md);padding:14px 16px;margin:0}
.va-table-wrap{overflow-x:auto}
.va-table{width:100%;border-collapse:collapse;font-size:13px}
.va-table th,.va-table td{padding:10px 12px;border-bottom:1px solid var(--ink-100);text-align:left;vertical-align:top}
.va-table th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-300);background:var(--ink-50)}
.va-doc-list{display:flex;flex-direction:column;gap:10px}
.va-doc-item{display:flex;align-items:center;justify-content:space-between;gap:14px;background:var(--ink-50);border-radius:var(--radius-md);padding:12px 15px;border:1px solid var(--ink-100)}
.va-doc-left{display:flex;align-items:center;gap:12px}
.va-doc-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:grid;place-items:center;font-size:18px;flex-shrink:0}
.va-doc-icon.pdf{background:#fef2f2;color:#dc2626}.va-doc-icon.pptx{background:#fff7ed;color:#c2410c}.va-doc-icon.xls{background:#f0fdf4;color:#15803d}.va-doc-icon.doc{background:var(--blue-bg);color:var(--blue-fg)}
.va-review-card{background:var(--surface-card);border:1.5px solid var(--ink-100);border-left:4px solid #ff6b35;border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:14px}
.va-review-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.va-reviewer-name{font-size:14px;font-weight:800;color:var(--ink-700)}
.va-review-date{font-size:11.5px;color:var(--ink-300);margin-top:2px}
.va-score-bars{display:flex;flex-direction:column;gap:10px;margin:14px 0}
.va-score-row{display:flex;align-items:center;gap:12px;font-size:12.5px;color:var(--ink-400)}
.va-score-row-label{width:150px;flex-shrink:0;font-weight:600}
.va-score-track{flex:1;height:7px;background:var(--ink-100);border-radius:999px;overflow:hidden}
.va-score-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--brand-600),var(--brand-400))}
.va-score-num{font-weight:800;color:var(--ink-700);width:42px;text-align:right;flex-shrink:0}
.va-timeline{position:relative;padding-left:28px}
.va-timeline:before{content:'';position:absolute;left:8px;top:8px;bottom:8px;width:2px;background:var(--ink-100)}
.va-tl-item{position:relative;background:var(--ink-50);border-radius:var(--radius-md);padding:12px 14px;margin-bottom:10px}
.va-tl-item:before{content:'';position:absolute;left:-22px;top:15px;width:12px;height:12px;border-radius:50%;background:var(--brand-500);border:3px solid var(--surface-card);box-shadow:0 0 0 2px var(--brand-400)}
.va-tl-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.va-status-pill,.va-score-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 18px;border-radius:var(--radius-lg);font-size:13.5px;font-weight:800;border:1.5px solid transparent}
.va-score-pill{background:var(--green-bg);color:var(--green-fg);border-color:var(--green-border)}
.badge-danger{background:var(--red-bg);color:var(--red-fg);border-color:var(--red-border)}
.badge-shortlisted{background:var(--amber-bg);color:var(--amber-fg);border-color:var(--amber-border)}
.badge-under-review{background:var(--blue-bg);color:var(--blue-fg);border-color:var(--blue-border)}
.badge-waitlisted{background:var(--slate-bg);color:var(--slate-fg);border-color:var(--slate-border)}
.va-quick-actions{display:flex;flex-direction:column;gap:8px}
.va-quick-actions .btn{justify-content:center}
.va-empty{text-align:center;padding:40px 20px;color:var(--ink-300)}
.va-empty i{font-size:32px;opacity:.45;margin-bottom:10px;display:block}

/* --------------------------------------------------------------------------
   EXPANDABLE LIST CARD GRIDS
--------------------------------------------------------------------------- */
.va-card-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    align-items:start
}
.va-list-card{
    min-width:0;
    background:var(--surface-card);
    border:1px solid var(--ink-100);
    border-radius:var(--radius-lg);
    overflow:hidden;
    box-shadow:0 2px 8px rgba(15,23,42,.05);
    align-self:start
}
.va-list-card:hover{border-color:var(--brand-200)}
.va-list-card.open{grid-column:span 2}
.va-list-head{
    width:100%;
    appearance:none;
    border:0;
    background:var(--surface-card);
    padding:13px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    text-align:left;
    cursor:pointer
}
.va-list-head:hover{background:var(--ink-50)}
.va-list-title{
    min-width:0;
    display:flex;
    align-items:center;
    gap:8px;
    color:var(--ink-700);
    font-size:12.5px;
    font-weight:800;
    line-height:1.35
}
.va-list-title i{color:var(--brand-500);width:18px;text-align:center;flex-shrink:0}
.va-list-chevron{
    color:var(--ink-300);
    font-size:11px;
    flex-shrink:0;
    transition:transform .2s ease
}
.va-list-card.open .va-list-chevron{transform:rotate(180deg)}
.va-list-body{
    display:none;
    padding:12px 14px 14px;
    border-top:1px solid var(--ink-100)
}
.va-list-card.open .va-list-body{display:block}
.va-list-text{
    margin:0;
    color:var(--ink-400);
    font-size:13px;
    line-height:1.75;
    white-space:pre-wrap;
    overflow-wrap:anywhere
}
.va-list-meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:7px}
.va-reviewer-small{display:block;color:var(--ink-700);font-size:12.5px;font-weight:800}
.va-review-date-small{display:block;margin-top:3px;color:var(--ink-300);font-size:10.5px}
.va-review-score{
    display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;
    background:var(--green-bg);color:var(--green-fg);font-size:10px;font-weight:800
}
.va-mini-score-list{display:flex;flex-direction:column;gap:9px}
.va-mini-score-row{display:flex;align-items:center;gap:8px;font-size:11.5px}
.va-mini-score-label{width:115px;flex-shrink:0;color:var(--ink-400);font-weight:600}
.va-mini-score-track{flex:1;height:7px;background:var(--ink-100);border-radius:999px;overflow:hidden}
.va-mini-score-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--brand-600),var(--brand-400))}
.va-mini-score-value{width:42px;flex-shrink:0;text-align:right;color:var(--ink-700);font-size:11px;font-weight:800}
@media(max-width:1200px){
    .va-card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .va-list-card.open{grid-column:span 2}
}
@media(max-width:640px){
    .va-card-grid{grid-template-columns:1fr}
    .va-list-card.open{grid-column:span 1}
    .va-mini-score-label{width:95px}
}

@media(max-width:1024px){.va-two-col{grid-template-columns:1fr}}
@media(max-width:640px){.va-detail-grid{grid-template-columns:1fr}.va-score-row-label{width:110px}.va-tab-btn{padding:10px 12px;font-size:12px}}
</style>

<!-- Hero -->
<div class="opp-hero" style="margin-bottom:20px;">
    <div class="opp-hero-left">
        <div class="opp-eyebrow">
            <span class="opp-dot"></span>
            <?= h($app['opportunity_type'] ?? 'Application') ?>
        </div>

        <h1><?= h(($app['startup_name'] ?? '') !== '' ? $app['startup_name'] : 'Unnamed Venture') ?></h1>

        <div class="opp-meta" style="margin-top:8px;opacity:.88;">
            <span class="opp-meta-item">
                <i class="fas fa-hashtag"></i>
                <?= h($reference) ?>
            </span>

            <?php if (!empty($app['opportunity_title'])): ?>
                <span class="opp-meta-item">
                    <i class="fas fa-tag"></i>
                    <?= h($app['opportunity_title']) ?>
                </span>
            <?php endif; ?>

            <?php if (!empty($app['applicant_name'])): ?>
                <span class="opp-meta-item">
                    <i class="fas fa-user"></i>
                    <?= h($app['applicant_name']) ?>
                </span>
            <?php endif; ?>

            <span class="opp-meta-item">
                <i class="fas fa-calendar"></i>
                <?= $status === 'Draft' ? 'Last saved' : 'Submitted' ?>
                <?= h(
                    va_date(
                        $app['submitted_at']
                        ?? $app['last_saved_at']
                        ?? $app['created_at']
                        ?? null
                    )
                ) ?>
            </span>
        </div>
    </div>

    <div class="opp-hero-actions" style="position:relative;flex-direction:column;align-items:flex-end;gap:10px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a
                href="manage-applications.php?opportunity_id=<?= (int)($app['opportunity_id'] ?? 0) ?>"
                class="btn btn-white btn-sm"
            >
                <i class="fas fa-arrow-left"></i> Back
            </a>

            <?php $contactEmail = trim((string)($app['email'] ?? $app['account_email'] ?? '')); ?>
            <?php if ($contactEmail !== ''): ?>
                <a href="mailto:<?= h($contactEmail) ?>" class="btn btn-white btn-sm">
                    <i class="fas fa-envelope"></i> Email
                </a>
            <?php endif; ?>

            <button type="button" onclick="window.print()" class="btn btn-white btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- Status + actions -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
    <span class="va-status-pill <?= h($badgeClass) ?>">
        <i class="fas fa-<?= h($statusIcon) ?>"></i>
        <?= h($status) ?>
    </span>

    <?php if ($reviewCount > 0): ?>
        <span class="va-score-pill">
            <i class="fas fa-chart-line"></i>
            Avg Score: <?= number_format($avgScore, 1) ?>/100
            <span style="opacity:.72;font-size:11px;">
                (<?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>)
            </span>
        </span>
    <?php endif; ?>

    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($canReview): ?>
            <button type="button" onclick="openModal('reviewModal')" class="btn btn-primary btn-sm">
                <i class="fas fa-star"></i> Add Review
            </button>
        <?php endif; ?>

        <?php if ($canShortlist): ?>
            <button type="button" onclick="changeStatus('Shortlisted')" class="btn btn-warning btn-sm">
                <i class="fas fa-star"></i> Shortlist
            </button>
        <?php endif; ?>

        <?php if ($canSelect): ?>
            <button type="button" onclick="changeStatus('Selected')" class="btn btn-success btn-sm">
                <i class="fas fa-check-circle"></i> Select
            </button>
        <?php endif; ?>

        <?php if ($canReject): ?>
            <button type="button" onclick="openModal('rejectModal')" class="btn btn-danger btn-sm">
                <i class="fas fa-times-circle"></i> Reject
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<div class="va-tab-nav">
    <button class="va-tab-btn active" data-tab="venture"><i class="fas fa-rocket"></i> Venture</button>
    <button class="va-tab-btn" data-tab="team"><i class="fas fa-users"></i> Team</button>
    <button class="va-tab-btn" data-tab="solution"><i class="fas fa-lightbulb"></i> Problem & Solution</button>
    <button class="va-tab-btn" data-tab="traction"><i class="fas fa-chart-line"></i> Traction</button>
    <button class="va-tab-btn" data-tab="business"><i class="fas fa-briefcase"></i> Business & Funding</button>
    <button class="va-tab-btn" data-tab="safeguarding"><i class="fas fa-shield-alt"></i> Safeguarding</button>
    <button class="va-tab-btn" data-tab="mel"><i class="fas fa-chart-bar"></i> MEL</button>

    <?php if ($hasDocuments): ?>
        <button class="va-tab-btn" data-tab="documents"><i class="fas fa-paperclip"></i> Documents</button>
    <?php endif; ?>

    <button class="va-tab-btn" data-tab="reviews">
        <i class="fas fa-star"></i> Reviews
        <span class="va-tab-count"><?= $reviewCount ?></span>
    </button>

    <button class="va-tab-btn" data-tab="history">
        <i class="fas fa-history"></i> History
        <span class="va-tab-count"><?= count($status_history) ?></span>
    </button>
</div>

<!-- Venture -->
<div class="va-tab-panel active" id="tab-venture">
    <div class="va-two-col">
        <div class="opp-panel">
            <div class="opp-panel-head">
                <h3><i class="fas fa-rocket" style="color:var(--brand-500);"></i> Venture Information</h3>
            </div>

            <div class="opp-panel-body">
                <div class="va-detail-grid">
                    <?php foreach ([
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
                    ] as $field => $label):
                        $value = $app[$field] ?? null;
                        if ($value === null || trim((string)$value) === '') continue;
                    ?>
                        <div class="va-detail-item">
                            <div class="va-detail-label"><?= h($label) ?></div>
                            <div class="va-detail-value"><?= h($value) ?></div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($app['physical_address'])): ?>
                        <div class="va-detail-item full">
                            <div class="va-detail-label">Physical Address</div>
                            <div class="va-detail-value"><?= nl2br(h($app['physical_address'])) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($sector_focus): ?>
                        <div class="va-detail-item full">
                            <div class="va-detail-label">Sector Focus</div>
                            <div class="va-detail-value"><?= h(va_list($sector_focus)) ?></div>
                        </div>
                    <?php elseif (!empty($app['sector'])): ?>
                        <div class="va-detail-item full">
                            <div class="va-detail-label">Sector</div>
                            <div class="va-detail-value"><?= h($app['sector']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($heard_about): ?>
                        <div class="va-detail-item full">
                            <div class="va-detail-label">How They Heard About the Opportunity</div>
                            <div class="va-detail-value"><?= h(va_list($heard_about)) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($incubation_programmes): ?>
                    <div style="margin-top:20px;">
                        <div class="va-prose-label">Incubation / Accelerator Programmes</div>
                        <div class="va-table-wrap">
                            <table class="va-table">
                                <thead>
                                    <tr>
                                        <th>Programme</th>
                                        <th>Organisation</th>
                                        <th>Year</th>
                                        <th>Outcomes</th>
                                    </tr>
                                </thead>
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

        <div style="display:flex;flex-direction:column;gap:20px;">
            <div class="opp-panel">
                <div class="opp-panel-head">
                    <h3><i class="fas fa-address-book" style="color:var(--brand-500);"></i> Applicant Contact</h3>
                </div>

                <div class="opp-panel-body">
                    <div class="va-detail-grid">
                        <?php foreach ([
                            'contact_person' => 'Contact Person',
                            'email' => 'Email',
                            'phone' => 'Phone',
                            'contact_gender' => 'Gender',
                        ] as $field => $label):
                            $value = $app[$field] ?? null;
                            if ($value === null || trim((string)$value) === '') continue;
                        ?>
                            <div class="va-detail-item">
                                <div class="va-detail-label"><?= h($label) ?></div>
                                <div class="va-detail-value"><?= h($value) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if ($canReview || $canShortlist || $canSelect || $canReject): ?>
                <div class="opp-panel">
                    <div class="opp-panel-head">
                        <h3><i class="fas fa-bolt" style="color:var(--brand-500);"></i> Quick Actions</h3>
                    </div>

                    <div class="opp-panel-body">
                        <div class="va-quick-actions">
                            <?php if ($canReview): ?>
                                <button type="button" onclick="openModal('reviewModal')" class="btn btn-primary">
                                    <i class="fas fa-star"></i> Add Review
                                </button>
                            <?php endif; ?>

                            <?php if ($canShortlist): ?>
                                <button type="button" onclick="changeStatus('Shortlisted')" class="btn btn-warning">
                                    <i class="fas fa-star"></i> Shortlist
                                </button>
                            <?php endif; ?>

                            <?php if ($canSelect): ?>
                                <button type="button" onclick="changeStatus('Selected')" class="btn btn-success">
                                    <i class="fas fa-check-circle"></i> Select
                                </button>
                            <?php endif; ?>

                            <?php if ($canReject): ?>
                                <button type="button" onclick="openModal('rejectModal')" class="btn btn-danger">
                                    <i class="fas fa-times-circle"></i> Reject
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!--<?php if ($reviewCount > 0): ?>
                <div class="opp-panel">
                    <div class="opp-panel-head">
                        <h3><i class="fas fa-chart-bar" style="color:var(--brand-500);"></i> Score Summary</h3>
                    </div>

                    <div class="opp-panel-body" style="text-align:center;">
                        <div style="font-size:48px;font-weight:800;color:var(--green-fg);line-height:1;">
                            <?= number_format($avgScore, 1) ?>
                        </div>
                        <div style="font-size:11px;color:var(--ink-300);margin-top:5px;text-transform:uppercase;font-weight:700;">
                            Average score / 100
                        </div>
                        <div style="font-size:12px;color:var(--ink-300);margin-top:8px;">
                            <?= $reviewCount ?> review<?= $reviewCount === 1 ? '' : 's' ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?> -->
        </div>
    </div>
</div>

<!-- Team -->
<div class="va-tab-panel" id="tab-team">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-users" style="color:var(--brand-500);"></i> Team Information</h3>
        </div>

        <div class="opp-panel-body">
            <div class="va-detail-grid" style="margin-bottom:20px;">
                <?php foreach ([
                    'full_time_staff' => 'Full-Time Staff',
                    'part_time_staff' => 'Part-Time Staff',
                    'team_positioning' => 'Team Positioning',
                ] as $field => $label):
                    $value = $app[$field] ?? null;
                    if ($value === null || trim((string)$value) === '') continue;
                ?>
                    <div class="va-detail-item <?= $field === 'team_positioning' ? 'full' : '' ?>">
                        <div class="va-detail-label"><?= h($label) ?></div>
                        <div class="va-detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($founders): ?>
                <div class="va-prose-label">Founders</div>
                <div class="va-table-wrap" style="margin-bottom:20px;">
                    <table class="va-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Shareholding</th>
                                <th>Full Time</th>
                                <th>PWD</th>
                            </tr>
                        </thead>
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
                <div class="va-prose-label">Other Team Members</div>
                <div class="va-table-wrap">
                    <table class="va-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>PWD</th>
                            </tr>
                        </thead>
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

            <?php if (!$founders && !$other_team_members): ?>
                <div class="va-empty">
                    <i class="fas fa-users"></i>
                    No team details were provided.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Problem & Solution -->
<div class="va-tab-panel" id="tab-solution">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-lightbulb" style="color:var(--brand-500);"></i> Problem &amp; Solution</h3>
            <span style="font-size:11px;color:var(--ink-300);font-weight:700;">click to expand</span>
        </div>

        <div class="opp-panel-body">
            <?php
            $solutionCards = [];

            foreach ([
                'problem_solution' => ['Problem & Solution', 'fa-lightbulb'],
                'problem_statement' => ['Problem Statement', 'fa-exclamation-circle'],
                'affected_population' => ['Affected Population', 'fa-users'],
                'problem_evidence' => ['Problem Evidence', 'fa-clipboard-check'],
                'solution_description' => ['Solution Description', 'fa-puzzle-piece'],
                'uniqueness' => ['Uniqueness', 'fa-fingerprint'],
                'theory_of_change' => ['Theory of Change', 'fa-project-diagram'],
                'primary_users' => ['Primary Users', 'fa-user-group'],
                'solution_languages' => ['Solution Languages', 'fa-language'],
                'platforms_devices' => ['Platforms / Devices', 'fa-mobile-screen'],
                'works_offline' => ['Works Offline', 'fa-wifi'],
                'refugee_settlements_specify' => ['Refugee Settlements', 'fa-location-dot'],
                'regions_other' => ['Other Regions', 'fa-map'],
                'demo_link' => ['Demo Link', 'fa-link'],
                'video_link' => ['Video Link', 'fa-video'],
            ] as $field => [$label, $icon]) {
                if (!empty($app[$field])) {
                    $solutionCards[] = [
                        'label' => $label,
                        'icon' => $icon,
                        'value' => (string)$app[$field],
                    ];
                }
            }

            if ($regions_served) {
                $solutionCards[] = [
                    'label' => 'Regions Served',
                    'icon' => 'fa-map-location-dot',
                    'value' => va_list($regions_served),
                ];
            }
            ?>

            <?php if ($solutionCards): ?>
                <div class="va-card-grid" data-expand-group="solution">
                    <?php foreach ($solutionCards as $card): ?>
                        <article class="va-list-card">
                            <button type="button" class="va-list-head" aria-expanded="false">
                                <span class="va-list-title">
                                    <i class="fas <?= h($card['icon']) ?>"></i>
                                    <?= h($card['label']) ?>
                                </span>
                                <i class="fas fa-chevron-down va-list-chevron"></i>
                            </button>

                            <div class="va-list-body">
                                <p class="va-list-text"><?= h($card['value']) ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="va-empty">
                    <i class="fas fa-lightbulb"></i>
                    No problem and solution information was provided.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Traction -->
<div class="va-tab-panel" id="tab-traction">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-chart-line" style="color:var(--brand-500);"></i> Traction</h3>
        </div>

        <div class="opp-panel-body">
            <div class="va-detail-grid">
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
                    $value = $app[$field] ?? null;
                    if ($value === null || trim((string)$value) === '') continue;
                ?>
                    <div class="va-detail-item">
                        <div class="va-detail-label"><?= h($label) ?></div>
                        <div class="va-detail-value"><?= h($value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($app['other_traction_metrics'])): ?>
                <div class="va-prose-block" style="margin-top:20px;">
                    <div class="va-prose-label">Other Traction Metrics</div>
                    <p class="va-prose"><?= h($app['other_traction_metrics']) ?></p>
                </div>
            <?php elseif (!empty($app['traction'])): ?>
                <div class="va-prose-block" style="margin-top:20px;">
                    <div class="va-prose-label">Traction</div>
                    <p class="va-prose"><?= h($app['traction']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Business & Funding -->
<div class="va-tab-panel" id="tab-business">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-briefcase" style="color:var(--brand-500);"></i> Business & Funding</h3>
        </div>

        <div class="opp-panel-body">
            <?php if ($revenue_models): ?>
                <div class="va-prose-block">
                    <div class="va-prose-label">Revenue Models</div>
                    <p class="va-prose"><?= h(va_list($revenue_models)) ?></p>
                </div>
            <?php endif; ?>

            <?php foreach ([
                'who_pays' => 'Who Pays',
                'revenue_model_other' => 'Other Revenue Model',
                'revenue_streams' => 'Revenue Streams',
                'raised_external_funding' => 'Raised External Funding',
                'fellowship_grant_use' => 'Planned Fellowship Grant Use',
                'fellowship_growth_value' => 'How the Fellowship Will Support Growth',
                'funding_use' => 'Funding Use',
            ] as $field => $label):
                if (empty($app[$field])) continue;
            ?>
                <div class="va-prose-block">
                    <div class="va-prose-label"><?= h($label) ?></div>
                    <p class="va-prose"><?= h($app[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($external_funding_details): ?>
                <div class="va-prose-label">External Funding Details</div>
                <div class="va-table-wrap">
                    <table class="va-table">
                        <thead>
                            <tr>
                                <th>Funder</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Currency</th>
                                <th>Year</th>
                                <th>Status</th>
                            </tr>
                        </thead>
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

<!-- Safeguarding -->
<div class="va-tab-panel" id="tab-safeguarding">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-shield-alt" style="color:var(--brand-500);"></i> Safeguarding & Inclusion</h3>
        </div>

        <div class="opp-panel-body">
            <?php
            $safeguardingFields = [
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
            ];

            $hasSafeguarding = false;

            foreach ($safeguardingFields as $field => $label):
                if (empty($app[$field])) continue;
                $hasSafeguarding = true;
            ?>
                <div class="va-prose-block">
                    <div class="va-prose-label"><?= h($label) ?></div>
                    <p class="va-prose"><?= h($app[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if (!$hasSafeguarding): ?>
                <div class="va-empty">
                    <i class="fas fa-shield-alt"></i>
                    No safeguarding information was provided.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MEL -->
<div class="va-tab-panel" id="tab-mel">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-chart-bar" style="color:var(--brand-500);"></i> Monitoring, Evaluation & Learning</h3>
        </div>

        <div class="opp-panel-body">
            <?php
            $melFields = [
                'impact_measurement' => 'Impact Measurement',
                'data_collection_frequency' => 'Data Collection Frequency',
                'data_use_other' => 'Other Data Use',
                'has_mel_framework' => 'Has MEL Framework',
                'data_tools' => 'Data Tools',
                'learning_outcomes_evidence' => 'Learning Outcomes Evidence',
                'pdpo_status' => 'PDPO Status',
            ];

            $hasMel = false;

            foreach ($melFields as $field => $label):
                if (empty($app[$field])) continue;
                $hasMel = true;
            ?>
                <div class="va-prose-block">
                    <div class="va-prose-label"><?= h($label) ?></div>
                    <p class="va-prose"><?= h($app[$field]) ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($data_uses): $hasMel = true; ?>
                <div class="va-prose-block">
                    <div class="va-prose-label">How Data Is Used</div>
                    <p class="va-prose"><?= h(va_list($data_uses)) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$hasMel): ?>
                <div class="va-empty">
                    <i class="fas fa-chart-bar"></i>
                    No MEL information was provided.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Documents -->
<?php if ($hasDocuments): ?>
<div class="va-tab-panel" id="tab-documents">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-paperclip" style="color:var(--brand-500);"></i> Supporting Documents</h3>
        </div>

        <div class="opp-panel-body">
            <div class="va-doc-list">
                <?php foreach ($doc_fields as $field => [$label, $icon, $type]):
                    if (empty($app[$field])) continue;
                    $path = (string)$app[$field];
                ?>
                    <div class="va-doc-item">
                        <div class="va-doc-left">
                            <div class="va-doc-icon <?= h($type) ?>">
                                <i class="fas <?= h($icon) ?>"></i>
                            </div>

                            <div>
                                <div style="font-size:13px;font-weight:700;color:var(--ink-700);">
                                    <?= h($label) ?>
                                </div>
                                <div style="font-size:11px;color:var(--ink-300);margin-top:2px;">
                                    <?= h(basename($path)) ?>
                                </div>
                            </div>
                        </div>

                        <a
                            href="<?= h(ims_upload_url($path)) ?>"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-primary btn-sm"
                        >
                            <i class="fas fa-eye"></i> Open
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reviews -->
<div class="va-tab-panel" id="tab-reviews">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-star" style="color:var(--brand-500);"></i> Reviews &amp; Evaluations</h3>

            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <?php if ($reviewCount > 0): ?>
                    <span class="va-score-pill" style="font-size:11px;padding:5px 11px;">
                        <i class="fas fa-chart-line"></i>
                        Avg <?= number_format($avgScore, 1) ?>/10
                    </span>
                <?php endif; ?>

                <?php if ($canReview): ?>
                    <button type="button" onclick="openModal('reviewModal')" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Add Review
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="opp-panel-body">
            <?php if (!$reviews): ?>
                <div class="va-empty">
                    <i class="fas fa-star-half-alt"></i>
                    No reviews have been added yet.
                </div>
            <?php else: ?>
                <div class="va-card-grid" data-expand-group="reviews">
                    <?php foreach ($reviews as $review):
                        $overallScore = (
                            isset($review['overall_score'])
                            && $review['overall_score'] !== null
                            && $review['overall_score'] !== ''
                        ) ? (float)$review['overall_score'] : null;

                        $recommendation = trim((string)($review['recommendation'] ?? ''));
                    ?>
                        <article class="va-list-card">
                            <button type="button" class="va-list-head" aria-expanded="false">
                                <span style="min-width:0;">
                                    <span class="va-reviewer-small">
                                        <i class="fas fa-user-check" style="color:var(--brand-500);margin-right:5px;"></i>
                                        <?= h($review['reviewer_name'] ?? 'Reviewer') ?>
                                    </span>

                                    <span class="va-review-date-small">
                                        <?= h(va_date($review['created_at'] ?? null, 'd M Y, g:i A')) ?>
                                    </span>

                                    <span class="va-list-meta">
                                        <?php if ($overallScore !== null): ?>
                                            <span class="va-review-score">
                                                <?= number_format($overallScore, 1) ?>/10
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($recommendation !== ''): ?>
                                            <span class="badge badge-info"><?= h($recommendation) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>

                                <i class="fas fa-chevron-down va-list-chevron"></i>
                            </button>

                            <div class="va-list-body">
                                <div class="va-mini-score-list">
                                    <?php foreach ([
                                        'Innovation' => 'innovation_score',
                                        'Market Potential' => 'market_potential_score',
                                        'Team' => 'team_score',
                                        'Financial Viability' => 'financial_viability_score',
                                    ] as $label => $field):
                                        $score = (float)($review[$field] ?? 0);
                                        if ($score <= 0) continue;
                                        $percentage = max(0, min(100, ($score / 10) * 100));
                                    ?>
                                        <div class="va-mini-score-row">
                                            <span class="va-mini-score-label"><?= h($label) ?></span>
                                            <div class="va-mini-score-track">
                                                <div class="va-mini-score-fill" style="width:<?= $percentage ?>%;"></div>
                                            </div>
                                            <span class="va-mini-score-value"><?= number_format($score, 1) ?>/10</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (!empty($review['comments'])): ?>
                                    <div style="margin-top:14px;">
                                        <div class="va-detail-label">Reviewer Comments</div>
                                        <p class="va-list-text"><?= h($review['comments']) ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($recommendation !== ''): ?>
                                    <div style="margin-top:12px;">
                                        <div class="va-detail-label">Recommendation</div>
                                        <span class="badge badge-info"><?= h($recommendation) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- History -->
<div class="va-tab-panel" id="tab-history">
    <div class="opp-panel">
        <div class="opp-panel-head">
            <h3><i class="fas fa-history" style="color:var(--brand-500);"></i> Application History</h3>
        </div>

        <div class="opp-panel-body">
            <div class="va-timeline">
                <?php if (!empty($app['created_at'])): ?>
                    <div class="va-tl-item">
                        <div class="va-tl-row">
                            <div>
                                <strong>Application Started</strong>
                            </div>
                            <div><?= h(va_date($app['created_at'], 'd M Y, g:i A')) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($app['last_saved_at'])): ?>
                    <div class="va-tl-item">
                        <div class="va-tl-row">
                            <div>
                                <strong>Draft Last Saved</strong>
                            </div>
                            <div><?= h(va_date($app['last_saved_at'], 'd M Y, g:i A')) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($app['submitted_at'])): ?>
                    <div class="va-tl-item">
                        <div class="va-tl-row">
                            <div>
                                <strong>Application Submitted</strong>
                            </div>
                            <div><?= h(va_date($app['submitted_at'], 'd M Y, g:i A')) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($status_history as $history): ?>
                    <div class="va-tl-item">
                        <div class="va-tl-row">
                            <div>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                    <?php if (!empty($history['old_status'])): ?>
                                        <span class="badge badge-secondary"><?= h($history['old_status']) ?></span>
                                        <i class="fas fa-arrow-right" style="font-size:10px;color:var(--ink-300);"></i>
                                    <?php endif; ?>

                                    <span class="badge <?= h(va_status_badge((string)($history['new_status'] ?? ''))) ?>">
                                        <?= h($history['new_status'] ?? '') ?>
                                    </span>
                                </div>

                                <div style="font-size:11.5px;color:var(--ink-300);margin-top:4px;">
                                    By <?= h($history['changed_by_name'] ?? 'System') ?>
                                </div>

                                <?php if (!empty($history['comments'])): ?>
                                    <div style="font-size:13px;color:var(--ink-400);margin-top:6px;">
                                        <?= nl2br(h($history['comments'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="font-size:11px;color:var(--ink-300);">
                                <?= h(va_date($history['changed_at'] ?? null, 'd M Y, g:i A')) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="modal">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-head">
            <h3><i class="fas fa-star" style="color:var(--brand-500);"></i> Add Review</h3>
            <button type="button" class="modal-close" onclick="closeModal('reviewModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="review-application-process.php">
            <input type="hidden" name="action" value="add_review">
            <input type="hidden" name="application_id" value="<?= $application_id ?>">

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label required" for="review_type">Review Type</label>
                        <select id="review_type" name="review_type" class="form-control" required>
                            <option value="Initial Review">Initial Review</option>
                            <option value="Technical Review">Technical Review</option>
                            <option value="Final Review">Final Review</option>
                            <option value="Comment">Comment</option>
                        </select>
                    </div>

                    <?php foreach ([
                        'innovation_score' => 'Innovation',
                        'market_potential_score' => 'Market Potential',
                        'team_score' => 'Team',
                        'financial_viability_score' => 'Financial Viability',
                    ] as $field => $label): ?>
                        <div class="form-group">
                            <label class="form-label" for="<?= h($field) ?>"><?= h($label) ?> (1-10)</label>
                            <input
                                type="number"
                                id="<?= h($field) ?>"
                                name="<?= h($field) ?>"
                                class="form-control"
                                min="1"
                                max="10"
                                step="0.1"
                            >
                        </div>
                    <?php endforeach; ?>

                    <div class="form-group full">
                        <label class="form-label" for="comments">Comments</label>
                        <textarea id="comments" name="comments" class="form-control" rows="4"></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="form-label" for="recommendation">Recommendation</label>
                        <select id="recommendation" name="recommendation" class="form-control">
                            <option value="">Select recommendation...</option>
                            <option value="Accept">Accept</option>
                            <option value="Reject">Reject</option>
                            <option value="Needs More Info">Needs More Info</option>
                            <option value="Shortlist">Shortlist</option>
                            <option value="Hold">Hold</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" onclick="closeModal('reviewModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Submit Review
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-card" style="max-width:500px;">
        <div class="modal-head">
            <h3><i class="fas fa-times-circle" style="color:var(--red-fg);"></i> Reject Application</h3>
            <button type="button" class="modal-close" onclick="closeModal('rejectModal')" aria-label="Close">&times;</button>
        </div>

        <form method="POST" action="review-application-process.php">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="application_id" value="<?= $application_id ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required" for="rejection_reason">Reason for Rejection</label>
                    <textarea
                        id="rejection_reason"
                        name="rejection_reason"
                        class="form-control"
                        rows="4"
                        required
                        placeholder="Please provide a clear reason for rejection..."
                    ></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" onclick="closeModal('rejectModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-times-circle"></i> Reject Application
                </button>
            </div>
        </form>
    </div>
</div>

<?php
include 'includes/footer.php';
ob_end_flush();
?>

<script>
(function () {
    'use strict';

    window.switchTab = function (name) {
        document.querySelectorAll('.va-tab-btn').forEach(function (button) {
            button.classList.toggle('active', button.dataset.tab === name);
        });

        document.querySelectorAll('.va-tab-panel').forEach(function (panel) {
            panel.classList.toggle('active', panel.id === 'tab-' + name);
        });

        const activeButton = document.querySelector(
            '.va-tab-btn[data-tab="' + name + '"]'
        );

        if (activeButton) {
            activeButton.scrollIntoView({
                inline: 'nearest',
                behavior: 'smooth'
            });
        }

        history.replaceState(null, '', '#' + name);
    };

    document.querySelectorAll('.va-tab-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            switchTab(button.dataset.tab || 'venture');
        });
    });

    const hash = window.location.hash.replace('#', '');

    if (hash && document.getElementById('tab-' + hash)) {
        switchTab(hash);
    }


    /*
    |--------------------------------------------------------------------------
    | EXPANDABLE LIST CARDS
    |--------------------------------------------------------------------------
    | Only one card in each group stays open at a time.
    |--------------------------------------------------------------------------
    */
    document.querySelectorAll('[data-expand-group]').forEach(function (group) {
        const cards = Array.from(group.querySelectorAll('.va-list-card'));

        cards.forEach(function (card) {
            const header = card.querySelector('.va-list-head');

            if (!header) return;

            header.addEventListener('click', function () {
                const opening = !card.classList.contains('open');

                cards.forEach(function (otherCard) {
                    otherCard.classList.remove('open');

                    const otherHeader = otherCard.querySelector('.va-list-head');

                    if (otherHeader) {
                        otherHeader.setAttribute('aria-expanded', 'false');
                    }
                });

                if (opening) {
                    card.classList.add('open');
                    header.setAttribute('aria-expanded', 'true');
                }
            });
        });
    });

    window.changeStatus = function (status) {
        if (!window.confirm('Change application status to "' + status + '"?')) {
            return;
        }

        window.location.href =
            'review-application-process.php?action=change_status&csrf_token=<?= h(csrf_token()) ?>'
            + '&application_id=<?= $application_id ?>'
            + '&status=' + encodeURIComponent(status);
    };
})();
</script>
