<?php
// Previously required non-existent ./header.php and ./auth.php and let any
// logged-in user download any report. Same roles as risk_ratings_report.php.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login('login.php');
check_role(['Administrator', 'MEAL Lead', 'Programs Lead', 'Reviewer', 'Project Officer']);

$conn = db_connect();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid report ID');
}

$stmt = $conn->prepare("SELECT generated_report_path, generated_report_name FROM risk_ratings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row || empty($row['generated_report_path'])) {
    die('Report file not found.');
}

$filePath = realpath(__DIR__ . '/' . ltrim($row['generated_report_path'], '/'));
if ($filePath === false || !str_starts_with($filePath, __DIR__ . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    die('Physical report file missing.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename($row['generated_report_name'] ?: 'risk_rating_report.pdf') . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;