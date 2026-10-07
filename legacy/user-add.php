<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
check_role(['Administrator']);

header('Content-Type: application/json');

$response = ['success' => false];

// Get form data
$username = trim($_POST['username'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role = trim($_POST['role'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = trim($_POST['password'] ?? '');
$is_active = isset($_POST['is_active']) ? 1 : 0;

// Validate required fields
if (empty($username) || empty($full_name) || empty($email) || empty($role) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

if (!in_array($role, IMS_ALL_ROLES, true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Invalid role, email or password (min 8 characters).']);
    exit;
}

// Check for duplicate username or email
$check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
$check->bind_param('ss', $username, $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Username or Email already exists']);
    exit;
}

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$stmt = $conn->prepare("INSERT INTO users (username, full_name, email, role, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param('ssssssi', $username, $full_name, $email, $role, $phone, $hashed_password, $is_active);

if ($stmt->execute()) {
    $user_id = $stmt->insert_id;
    
    // Log action
    log_action($_SESSION['user_id'], 'Add User', 'users', $user_id, "Added user: $username");
    
    // Return user data for table update
    $response['success'] = true;
    $response['user'] = [
        'user_id' => $user_id,
        'username' => $username,
        'full_name' => $full_name,
        'email' => $email,
        'role' => $role,
        'phone' => $phone,
        'is_active' => $is_active,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => null
    ];
} else {
    error_log('user-add failed: ' . $conn->error);
    $response['message'] = 'Failed to add user.';
}

echo json_encode($response);