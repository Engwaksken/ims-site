<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header_remove('X-Frame-Options');
header("Content-Security-Policy: frame-ancestors *");

require_once __DIR__ . '/includes/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtDate($date): string
{
    if (empty($date)) return '-';

    $time = strtotime((string)$date);
    return $time ? date('d M Y', $time) : '-';
}

function daysUntil($date): ?int
{
    if (empty($date)) return null;

    $time = strtotime((string)$date);
    if (!$time) return null;

    $today  = strtotime(date('Y-m-d'));
    $target = strtotime(date('Y-m-d', $time));

    return (int)floor(($target - $today) / 86400);
}

function siteUrl(string $path = ''): string
{
    $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    return $base . '/' . ltrim($path, '/');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid opportunity ID.');
}

$stmt = $conn->prepare("
    SELECT *
    FROM application_opportunities
    WHERE opportunity_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Failed to prepare opportunity query.');
}

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    http_response_code(404);
    exit('Opportunity not found.');
}

$opp = $result->fetch_assoc();
$stmt->close();

$status = strtolower(trim((string)($opp['status'] ?? '')));

if ($status !== 'published') {
    http_response_code(403);
    exit('This opportunity is not published.');
}

$title       = trim((string)($opp['opportunity_title'] ?? 'Opportunity'));
$type        = trim((string)($opp['opportunity_type'] ?? 'Opportunity'));
$description = trim((string)($opp['description'] ?? ''));

if ($title === '') {
    $title = 'Opportunity';
}

$applyUrl = trim((string)($opp['application_url'] ?? ''));

if ($applyUrl === '') {
    $applyUrl = siteUrl('submit-application.php?opportunity_id=' . $id);
}

$deadlineDays   = daysUntil($opp['deadline'] ?? null);
$deadlinePassed = $deadlineDays !== null && $deadlineDays < 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?= h($title) ?></title>

<link rel="icon" type="image/png" href="<?= h(siteUrl('assets/images/favicon.png')) ?>">
<link rel="shortcut icon" type="image/png" href="<?= h(siteUrl('assets/images/favicon.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(siteUrl('assets/images/favicon.png')) ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous" referrerpolicy="no-referrer">

<style>
:root{
    --primary:#ea580c;
    --primary-dark:#c2410c;
    --primary-light:#fff7ed;
    --text:#111827;
    --text-light:#6b7280;
    --border:#e5e7eb;
    --bg:#fff7ed;
    --success:#16a34a;
    --danger:#dc2626;
    --radius-lg:24px;
    --radius-md:16px;
    --shadow:0 8px 30px rgba(17,24,39,.06);
}

*{box-sizing:border-box}

html,body{
    margin:0;
    padding:0;
    width:100%;
    min-height:100%;
}

body{
    font-family:'Inter',Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    line-height:1.6;
}

.wrap{
    max-width:1100px;
    margin:0 auto;
    padding:24px;
}

.hero{
    position:relative;
    overflow:hidden;
    background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);
    padding:48px;
    border-radius:var(--radius-lg);
    color:#fff;
    box-shadow:var(--shadow);
}

.hero::before,
.hero::after{
    content:"";
    position:absolute;
    border-radius:50%;
    background:rgba(255,255,255,.08);
}

.hero::before{
    top:-80px;
    right:-80px;
    width:260px;
    height:260px;
}

.hero::after{
    bottom:-100px;
    right:120px;
    width:200px;
    height:200px;
}

.hero-content{
    position:relative;
    z-index:1;
}

.badge-row{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:18px;
}

.badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 16px;
    background:rgba(255,255,255,.18);
    border-radius:999px;
    font-weight:700;
    font-size:13px;
    text-transform:uppercase;
}

.badge.deadline-warn{
    background:#fff;
    color:var(--primary-dark);
}

.badge.deadline-closed{
    background:rgba(0,0,0,.25);
}

.hero h1{
    margin:0;
    font-family:'Poppins',sans-serif;
    font-size:clamp(28px,4vw,46px);
    font-weight:800;
    line-height:1.2;
}

.hero p{
    margin:18px 0 0;
    font-size:16px;
    line-height:1.9;
    opacity:.95;
    max-width:760px;
}

.btn,
.btn-outline{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    border-radius:999px;
    text-decoration:none;
    font-weight:700;
    transition:transform .15s ease, box-shadow .15s ease;
}

.btn{
    padding:16px 32px;
    margin-top:28px;
    background:#fff;
    color:var(--primary-dark);
    box-shadow:0 6px 18px rgba(0,0,0,.12);
}

.btn-outline{
    padding:14px 30px;
    background:var(--primary);
    color:#fff;
    box-shadow:0 6px 18px rgba(234,88,12,.25);
}

.btn:hover,
.btn-outline:hover{
    transform:translateY(-2px);
}

.disabled{
    opacity:.65;
    cursor:not-allowed;
    pointer-events:none;
}

.card{
    margin-top:24px;
    background:#fff;
    padding:32px;
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow);
}

.card h2{
    margin:0;
    font-family:'Poppins',sans-serif;
}

.card-subtitle{
    color:var(--text-light);
    font-size:14px;
    margin-top:4px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:16px;
    margin-top:22px;
}

.info{
    padding:20px;
    border:1px solid var(--border);
    border-radius:var(--radius-md);
    display:flex;
    gap:14px;
}

.info-icon{
    width:42px;
    height:42px;
    flex-shrink:0;
    border-radius:12px;
    background:var(--primary-light);
    color:var(--primary-dark);
    display:flex;
    align-items:center;
    justify-content:center;
}

.label{
    font-size:11px;
    text-transform:uppercase;
    font-weight:700;
    letter-spacing:.6px;
    color:var(--text-light);
    margin-bottom:6px;
}

.value{
    font-size:17px;
    font-weight:700;
}

.deadline-warn-text{color:var(--danger)}
.deadline-ok-text{color:var(--success)}

.section{
    margin-top:32px;
    padding-top:28px;
    border-top:1px solid var(--border);
}

.section h3{
    display:flex;
    align-items:center;
    gap:10px;
    margin:0 0 14px;
    font-family:'Poppins',sans-serif;
    font-size:18px;
}

.section h3 i{
    color:var(--primary);
}

.section p{
    margin:0;
    line-height:1.9;
    white-space:pre-wrap;
    color:#374151;
}

.footer-cta{
    text-align:center;
}

.footer-cta h3{
    margin:0;
    font-family:'Poppins',sans-serif;
}

.footer-cta p{
    color:var(--text-light);
    margin:8px 0 18px;
}

@media(max-width:640px){
    .wrap{padding:14px}
    .hero{padding:28px 22px}
    .card{padding:22px}
}
</style>
</head>

<body>

<div class="wrap">

    <section class="hero">
        <div class="hero-content">

            <div class="badge-row">
                <span class="badge">
                    <i class="fas fa-tag"></i>
                    <?= h($type) ?>
                </span>

                <?php if ($deadlineDays !== null): ?>
                    <?php if ($deadlinePassed): ?>
                        <span class="badge deadline-closed">
                            <i class="fas fa-lock"></i>
                            Closed
                        </span>
                    <?php elseif ($deadlineDays <= 7): ?>
                        <span class="badge deadline-warn">
                            <i class="fas fa-hourglass-half"></i>
                            <?= $deadlineDays === 0 ? 'Closes today' : h($deadlineDays . ' day' . ($deadlineDays === 1 ? '' : 's') . ' left') ?>
                        </span>
                    <?php else: ?>
                        <span class="badge">
                            <i class="fas fa-circle-check"></i>
                            Open for applications
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <h1><?= h($title) ?></h1>

            <?php if ($description !== ''): ?>
                <p><?= nl2br(h($description)) ?></p>
            <?php endif; ?>

            <?php if ($deadlinePassed): ?>
                <span class="btn disabled">
                    <i class="fas fa-ban"></i>
                    Applications Closed
                </span>
            <?php else: ?>
                <a class="btn" href="<?= h($applyUrl) ?>" target="_blank" rel="noopener">
                    <i class="fas fa-paper-plane"></i>
                    Apply Now
                </a>
            <?php endif; ?>

        </div>
    </section>

    <section class="card">
        <h2>Opportunity Details</h2>
        <div class="card-subtitle">Key dates and information about this opportunity</div>

        <div class="grid">

            <div class="info">
                <div class="info-icon"><i class="fas fa-calendar-plus"></i></div>
                <div>
                    <div class="label">Start Date</div>
                    <div class="value"><?= h(fmtDate($opp['start_date'] ?? null)) ?></div>
                </div>
            </div>

            <div class="info">
                <div class="info-icon"><i class="fas fa-calendar-xmark"></i></div>
                <div>
                    <div class="label">Deadline</div>
                    <div class="value <?= $deadlinePassed ? 'deadline-warn-text' : 'deadline-ok-text' ?>">
                        <?= h(fmtDate($opp['deadline'] ?? null)) ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($opp['announcement_date'])): ?>
                <div class="info">
                    <div class="info-icon"><i class="fas fa-bullhorn"></i></div>
                    <div>
                        <div class="label">Announcement</div>
                        <div class="value"><?= h(fmtDate($opp['announcement_date'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($opp['available_slots'])): ?>
                <div class="info">
                    <div class="info-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="label">Available Slots</div>
                        <div class="value"><?= (int)$opp['available_slots'] ?></div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <?php if (!empty($opp['eligibility_criteria'])): ?>
            <div class="section">
                <h3><i class="fas fa-clipboard-check"></i> Eligibility Criteria</h3>
                <p><?= nl2br(h($opp['eligibility_criteria'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($opp['required_documents'])): ?>
            <div class="section">
                <h3><i class="fas fa-file-lines"></i> Required Documents</h3>
                <p><?= nl2br(h($opp['required_documents'])) ?></p>
            </div>
        <?php endif; ?>

        <div class="section footer-cta">
            <h3>Ready to apply?</h3>
            <p>Make sure you meet the eligibility criteria and have your documents ready before submitting.</p>

            <?php if ($deadlinePassed): ?>
                <span class="btn-outline disabled">
                    <i class="fas fa-ban"></i>
                    Applications Closed
                </span>
            <?php else: ?>
                <a class="btn-outline" href="<?= h($applyUrl) ?>" target="_blank" rel="noopener">
                    <i class="fas fa-paper-plane"></i>
                    Apply Now
                </a>
            <?php endif; ?>
        </div>
    </section>

</div>

</body>
</html>