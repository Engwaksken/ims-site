<?php

require_once __DIR__ . '/includes/config.php';

check_role(['Administrator']);

// Handle Add User
if (isset($_POST['add_user'])) {
    $username = sanitize_input($_POST['username']);
    $full_name = sanitize_input($_POST['full_name']);
    $email = sanitize_input($_POST['email']);
    $role = sanitize_input($_POST['role']);
    $phone = sanitize_input($_POST['phone']);
    $password = $_POST['password'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($username) || empty($full_name) || empty($email) || empty($role) || empty($password)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../users");
        exit();
    }
    
    // Validate password length
    if (strlen($password) < 6) {
        send_notification($_SESSION['user_id'], 'Password must be at least 6 characters', 'danger');
        header("Location: ../users");
        exit();
    }
    
    // Check if username already exists
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Username already exists. Please choose a different username.', 'danger');
        header("Location: ../users");
        exit();
    }
    
    // Check if email already exists
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Email already exists. Please use a different email.', 'danger');
        header("Location: ../users");
        exit();
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert user
    $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, role, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $username, $full_name, $email, $role, $phone, $hashed_password, $is_active);
    
    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        log_action($_SESSION['user_id'], 'Add User', 'users', $new_user_id, "Added user: $username");
        send_notification($_SESSION['user_id'], 'User added successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error adding user: ' . $conn->error, 'danger');
    }
    
    header("Location: ../users");
    exit();
}

// Handle Edit User
if (isset($_POST['edit_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = sanitize_input($_POST['username']);
    $full_name = sanitize_input($_POST['full_name']);
    $email = sanitize_input($_POST['email']);
    $role = sanitize_input($_POST['role']);
    $phone = sanitize_input($_POST['phone']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($username) || empty($full_name) || empty($email) || empty($role)) {
        send_notification($_SESSION['user_id'], 'All required fields must be filled', 'danger');
        header("Location: ../users?edit=$user_id");
        exit();
    }
    
    // Check if username already exists (excluding current user)
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $check_stmt->bind_param("si", $username, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Username already exists. Please choose a different username.', 'danger');
        header("Location: ../users?edit=$user_id");
        exit();
    }
    
    // Check if email already exists (excluding current user)
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $check_stmt->bind_param("si", $email, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        send_notification($_SESSION['user_id'], 'Email already exists. Please use a different email.', 'danger');
        header("Location: ../users?edit=$user_id");
        exit();
    }
    
    // Check if password is being changed
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        
        // Validate password length
        if (strlen($password) < 6) {
            send_notification($_SESSION['user_id'], 'Password must be at least 6 characters', 'danger');
            header("Location: ../users?edit=$user_id");
            exit();
        }
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, password_hash = ?, is_active = ? WHERE user_id = ?");
        $stmt->bind_param("ssssssii", $username, $full_name, $email, $role, $phone, $hashed_password, $is_active, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ? WHERE user_id = ?");
        $stmt->bind_param("sssssii", $username, $full_name, $email, $role, $phone, $is_active, $user_id);
    }
    
    if ($stmt->execute()) {
        log_action($_SESSION['user_id'], 'Edit User', 'users', $user_id, "Updated user: $username");
        send_notification($_SESSION['user_id'], 'User updated successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error updating user: ' . $conn->error, 'danger');
    }
    
    header("Location: ../users");
    exit();
}

// Handle Delete User
if (isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        send_notification($_SESSION['user_id'], 'Cannot delete your own account', 'danger');
        header("Location: ../users");
        exit();
    }
    
    // Get username for logging
    $user_result = $conn->query("SELECT username FROM users WHERE user_id = " . (int)$user_id);
    $user_data = $user_result->fetch_assoc();
    $username = $user_data['username'] ?? 'Unknown';
    
    // Delete user
    if ($conn->query("DELETE FROM users WHERE user_id = $user_id")) {
        log_action($_SESSION['user_id'], 'Delete User', 'users', $user_id, "Deleted user: $username");
        send_notification($_SESSION['user_id'], 'User deleted successfully', 'success');
    } else {
        send_notification($_SESSION['user_id'], 'Error deleting user: ' . $conn->error, 'danger');
    }
    
    header("Location: ../users");
    exit();
}

// If no valid action, redirect to users page
header("Location: ../users");
exit();
?>