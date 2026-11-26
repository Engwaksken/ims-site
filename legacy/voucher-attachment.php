<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Serve a payment voucher supporting file
|--------------------------------------------------------------------------
| voucher-attachment.php?id=<attachment id>[&download=1]
|
| Same access rule as includes/get_voucher.php: anyone with vouchers.view,
| or the approver the voucher is assigned to. PDFs, images and plain text
| open inline (for the preview pane); everything else downloads.
*/

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/voucher-approval.php';
require_once __DIR__ . '/includes/voucher-attachments.php';

/** @return never */
function vaFail(int $status, string $message): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

if (empty($_SESSION['user_id'])) {
    vaFail(401, 'Please log in again.');
}

if (!isset($conn) || !($conn instanceof mysqli) || !pvf_ensure_schema($conn)) {
    vaFail(500, 'Supporting files are unavailable.');
}

$userId = (int)$_SESSION['user_id'];
$attachmentId = (int)($_GET['id'] ?? 0);

if ($attachmentId <= 0) {
    vaFail(404, 'File not found.');
}

$approvalReady = pva_ensure_schema($conn);
$stmt = $conn->prepare(
    'SELECT a.voucher_id, a.original_name, a.stored_name, a.mime_type' .
    ($approvalReady ? ', v.approver_id' : ', 0 AS approver_id') . '
     FROM payment_voucher_attachments a
     JOIN payment_vouchers v ON v.id = a.voucher_id
     WHERE a.id = ? LIMIT 1'
);

if (!$stmt) {
    vaFail(500, 'Supporting files are unavailable.');
}

$stmt->bind_param('i', $attachmentId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    vaFail(404, 'File not found.');
}

$isAssignedApprover = (int)($file['approver_id'] ?? 0) === $userId;

if (
    !$isAssignedApprover
    && !ims_allowed('vouchers.view', ['Administrator', 'Finance', 'Accountant'], 'View, print and download payment vouchers')
) {
    error_log(sprintf('[security] voucher attachment denied: user=%d attachment=%d', $userId, $attachmentId));
    vaFail(403, 'You do not have permission to open this file.');
}

$base = realpath(pvf_directory((int)$file['voucher_id']));
$path = $base !== false ? realpath($base . '/' . basename((string)$file['stored_name'])) : false;

if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    vaFail(404, 'File not found.');
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = PVF_TYPES[$extension] ?? 'application/octet-stream';
$inline = (string)($_GET['download'] ?? '') !== '1' && in_array($mime, PVF_INLINE_TYPES, true);

$filename = (string)$file['original_name'];
$asciiName = trim((string)preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename));
$asciiName = $asciiName !== '' ? $asciiName : 'file.' . $extension;

if (function_exists('log_action') && !$inline) {
    log_action($userId, 'Download File', 'payment_voucher_attachments', $attachmentId, $filename);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header_remove('Content-Security-Policy');
header('Content-Type: ' . ($mime === 'text/plain' ? 'text/plain; charset=UTF-8' : $mime));
header(
    'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: private, no-store');

if (str_starts_with($mime, 'image/')) {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
} elseif ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'self'");
}

readfile($path);
exit;
