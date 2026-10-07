<?php

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Only administrators can manage permissions
check_role(['Administrator']);

// Handle Update Permissions
if (isset($_POST['update_permissions'])) {
    $user_id = intval($_POST['user_id']);
    $role = sanitize_input($_POST['role']);
    $status = sanitize_input($_POST['status']);
    
    // Validate required fields
    if (empty($role) || empty($status)) {
        send_notification($_SESSION['user_id'], 'Role and status are required', 'danger');
        header("Location: user-permissions.php?edit=$user_id");
        exit();
    }
    
    // Validate role
    if (!in_array($role, IMS_ALL_ROLES, true)) {
        send_notification($_SESSION['user_id'], 'Invalid role selected', 'danger');
        header("Location: user-permissions.php?edit=$user_id");
        exit();
    }
    
    // Validate status
    if (!in_array($status, ['1', '0'])) {
        send_notification($_SESSION['user_id'], 'Invalid status selected', 'danger');
        header("Location: user-permissions.php?edit=$user_id");
        exit();
    }
    
    // Get current user data
    $user_result = $conn->query("SELECT full_name, role, is_active FROM users WHERE user_id = " . (int)$user_id);
    if ($user_result->num_rows == 0) {
        send_notification($_SESSION['user_id'], 'User not found', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    $current_user = $user_result->fetch_assoc();
    
    // Prevent self-demotion from Administrator
    if ($user_id == $_SESSION['user_id'] && $_SESSION['role'] == 'Administrator' && $role != 'Administrator') {
        send_notification($_SESSION['user_id'], 'You cannot remove your own administrator privileges', 'danger');
        header("Location: user-permissions.php?edit=$user_id");
        exit();
    }
    
    // Prevent self-deactivation
    if ($user_id == $_SESSION['user_id'] && $status === '0') {
        send_notification($_SESSION['user_id'], 'You cannot deactivate your own account', 'danger');
        header("Location: user-permissions.php?edit=$user_id");
        exit();
    }
    
    // Update user permissions
    $stmt = $conn->prepare("UPDATE users SET role = ?, is_active = ? WHERE user_id = ?");
    $statusInt = (int)$status;
    $stmt->bind_param("sii", $role, $statusInt, $user_id);
    
    if ($stmt->execute()) {
        $changes = [];
        if ($current_user['role'] != $role) {
            $changes[] = "role from '{$current_user['role']}' to '$role'";
        }
        if ($current_user['is_active'] != $status) {
            $changes[] = "status from '{$current_user['is_active']}' to '$status'";
        }
        
        $change_desc = implode(', ', $changes);
        log_action($_SESSION['user_id'], 'Update User Permissions', 'users', $user_id, "Updated permissions for {$current_user['full_name']}: $change_desc");
        send_notification($_SESSION['user_id'], 'User permissions updated successfully', 'success');
        
        // Update session if user edited themselves
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['role'] = $role;
        }
    } else {
        send_notification($_SESSION['user_id'], 'Error updating permissions: ' . $conn->error, 'danger');
    }
    
    header("Location: user-permissions.php");
    exit();
}

// Handle Toggle Status
if (isset($_POST['toggle_status'])) {
    $user_id = intval($_POST['user_id']);
    $status = sanitize_input($_POST['status']);
    
    // Validate status
    if (!in_array($status, ['1', '0'])) {
        send_notification($_SESSION['user_id'], 'Invalid status', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    
    // Prevent self-deactivation
    if ($user_id == $_SESSION['user_id']) {
        send_notification($_SESSION['user_id'], 'You cannot change your own status', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    
    // Get user name
    $user_result = $conn->query("SELECT full_name FROM users WHERE user_id = " . (int)$user_id);
    if ($user_result->num_rows == 0) {
        send_notification($_SESSION['user_id'], 'User not found', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    $user_name = $user_result->fetch_assoc()['full_name'];
    
    // Update status
    $statusInt = (int)$status;
    $toggleStmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $toggleStmt->bind_param('ii', $statusInt, $user_id);
    if ($toggleStmt->execute()) {
        $action = $status == '1' ? 'activated' : 'deactivated';
        log_action($_SESSION['user_id'], 'Toggle User Status', 'users', $user_id, ucfirst($action) . " user: $user_name");
        send_notification($_SESSION['user_id'], "User $action successfully", 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating status: ' . $conn->error, 'danger');
    }
    
    header("Location: user-permissions.php");
    exit();
}

// Handle Reset Password
if (isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');
    
    // Validate passwords
    if (empty($new_password) || empty($confirm_password)) {
        send_notification($_SESSION['user_id'], 'Both password fields are required', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    
    if (strlen($new_password) < 8) {
        send_notification($_SESSION['user_id'], 'Password must be at least 8 characters', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    
    if ($new_password !== $confirm_password) {
        send_notification($_SESSION['user_id'], 'Passwords do not match', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    
    // Get user details
    $user_result = $conn->query("SELECT username, full_name, email FROM users WHERE user_id = " . (int)$user_id);
    if ($user_result->num_rows == 0) {
        send_notification($_SESSION['user_id'], 'User not found', 'danger');
        header("Location: user-permissions.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    
    // Hash new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update password
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Reset User Password', 'users', $user_id, "Reset password for user: {$user['username']}");
        send_notification($_SESSION['user_id'], 'Password reset successfully', 'success');
        
        // TODO: Send email notification to user with new password
        // mail($user['email'], 'Password Reset', "Your new password is: $new_password");
    } else {
        send_notification($_SESSION['user_id'], 'Error resetting password: ' . $conn->error, 'danger');
    }
    
    header("Location: user-permissions.php");
    exit();
}

// Handle AJAX: Get User Details
if (isset($_GET['action']) && $_GET['action'] == 'get_user') {
    $user_id = intval($_GET['user_id'] ?? 0);
    header('Content-Type: application/json; charset=UTF-8');
    
    // Never return password_hash to the browser.
    $query = "SELECT u.user_id, u.username, u.email, u.full_name, u.role, u.phone, u.is_active, u.created_at, u.last_login, 
        (SELECT COUNT(*) FROM activity_log WHERE user_id = u.user_id) as total_actions
        FROM users u 
        WHERE u.user_id = $user_id";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'user' => $user
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
    }
    exit();
}

// If no valid action, redirect
header("Location: user-permissions.php");
exit();
?>