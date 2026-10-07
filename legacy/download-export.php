<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authenticated download for generated exports and database backups
|--------------------------------------------------------------------------
|
| legacy/exports/ is no longer web-readable (see .htaccess). Files are
| streamed from here after an authentication + role check.
|
|   download-export.php?file=program-summary_2026-03-01_to_2026-03-10.pdf
|   download-export.php?file=backup_2026-01-08_19-13-15.sql   (admins only)
|
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_login('login');

$file = basename((string) ($_GET['file'] ?? ''));

if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_starts_with($file, '.')) {
    http_response_code(404);
    exit('File not found.');
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$isBackup = str_starts_with($file, 'backup_');

$allowedTypes = [
    'pdf' => 'application/pdf',
    'csv' => 'text/csv; charset=UTF-8',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel',
    'sql' => 'application/sql',
    'gz' => 'application/gzip',
];

if (!isset($allowedTypes[$extension])) {
    http_response_code(404);
    exit('File not found.');
}

// Database backups contain every record (including password hashes):
// administrators only. Other exports: staff roles.
if ($isBackup || in_array($extension, ['sql', 'gz'], true)) {
    check_role(['Administrator', 'IT Officer']);
} else {
    check_role(IMS_STAFF_ROLES);
}

$directory = realpath(__DIR__ . '/exports');
$path = $directory !== false ? realpath($directory . DIRECTORY_SEPARATOR . $file) : false;

if (
    $directory === false
    || $path === false
    || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)
    || !is_file($path)
) {
    http_response_code(404);
    exit('File not found.');
}

if (function_exists('log_action')) {
    log_action((int) ($_SESSION['user_id'] ?? 0), 'Download Export', 'exports', null, $file);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $allowedTypes[$extension]);
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($path);
exit;
