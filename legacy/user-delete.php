<?php
require_once __DIR__ . '/includes/config.php';
check_role(['Administrator']);

// State-changing endpoint: require a CSRF token (POST field, X-CSRF-Token header or ?csrf_token=).
csrf_protect(true);

header('Content-Type: application/json');

$response = ['success' => false];

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

// Prevent deleting yourself
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
    exit;
}

// Delete user
$deleteStmt = $conn->prepare('DELETE FROM users WHERE user_id = ? LIMIT 1');
$deleteStmt->bind_param('i', $user_id);

if ($deleteStmt->execute()) {
    log_action($_SESSION['user_id'], 'Delete User', 'users', $user_id, 'User deleted');
    $response['success'] = true;
} else {
    error_log('user-delete failed: ' . $conn->error);
    $response['message'] = 'Failed to delete user.';
}

echo json_encode($response);