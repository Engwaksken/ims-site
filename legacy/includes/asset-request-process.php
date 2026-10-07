<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail-function.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



function go_back(): never
{
    header('Location: ../my_requests');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    go_back();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    $_SESSION['error'] = 'Please log in to submit an asset request.';
    go_back();
}

$asset_id       = (int)($_POST['asset_id'] ?? 0);
$asset_name     = trim((string)($_POST['asset_name'] ?? ''));
$category_id    = (int)($_POST['category_id'] ?? 0);
$urgency_level  = trim((string)($_POST['urgency_level'] ?? 'Medium'));
$request_reason = trim((string)($_POST['request_reason'] ?? ''));

if (!in_array($urgency_level, ['Low', 'Medium', 'High'], true)) {
    $urgency_level = 'Medium';
}

if ($asset_name === '' || $category_id <= 0 || $request_reason === '') {
    $_SESSION['error'] = 'Asset name, category, and request reason are required.';
    go_back();
}

$assetIdForDb = $asset_id > 0 ? $asset_id : null;

$stmt = $conn->prepare("
    INSERT INTO asset_requests
        (staff_id, asset_id, asset_name, category_id, request_reason, urgency_level, status)
    VALUES (?, ?, ?, ?, ?, ?, 'Pending')
");

if (!$stmt) {
    $_SESSION['error'] = 'Failed to prepare asset request.';
    go_back();
}

$stmt->bind_param(
    "iiisss",
    $user_id,
    $assetIdForDb,
    $asset_name,
    $category_id,
    $request_reason,
    $urgency_level
);

if (!$stmt->execute()) {
    $_SESSION['error'] = 'Failed to submit asset request.';
    $stmt->close();
    go_back();
}

$request_id = $stmt->insert_id;
$stmt->close();

$userStmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userRow = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$staff_name  = $userRow['full_name'] ?? 'User';
$staff_email = $userRow['email'] ?? '';

$catStmt = $conn->prepare("
    SELECT category_name
    FROM asset_categories
    WHERE category_id = ?
    LIMIT 1
");
$catStmt->bind_param("i", $category_id);
$catStmt->execute();
$catRow = $catStmt->get_result()->fetch_assoc();
$catStmt->close();

$category_name = $catRow['category_name'] ?? 'N/A';

$recipients = [];

$roleStmt = $conn->prepare("
    SELECT email
    FROM users
    WHERE is_active = 1
      AND email <> ''
      AND role IN ('Administrator', 'Procurement Officer', 'Operations/Admin')
");
$roleStmt->execute();
$res = $roleStmt->get_result();

while ($row = $res->fetch_assoc()) {
    $recipients[] = $row['email'];
}

$roleStmt->close();

$requestData = [
    'request_id'     => $request_id,
    'staff_name'     => $staff_name,
    'asset_name'     => $asset_name,
    'category'       => $category_name,
    'urgency_level'  => $urgency_level,
    'request_reason' => $request_reason,
];

send_asset_request_admin_notification($requestData, $recipients);
send_asset_request_user_confirmation($staff_email, $requestData);

$_SESSION['success'] = 'Asset request submitted successfully. Admin and procurement officer have been notified.';
go_back();