<?php
require_once 'includes/config.php';
check_login();
check_role(['Administrator', 'Programs Lead']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $program_id = intval($_POST['program_id']);
    $program_name = sanitize_input($_POST['program_name']);
    $program_code = sanitize_input($_POST['program_code']);
    $donor_id = intval($_POST['donor_id']);
    $status = sanitize_input($_POST['status']);

    $stmt = $conn->prepare("UPDATE programs SET program_name=?, program_code=?, donor_id=?, status=? WHERE program_id=?");
    $stmt->bind_param("ssisi", $program_name, $program_code, $donor_id, $status, $program_id);
    $stmt->execute();

    log_action($_SESSION['user_id'], 'Edit program', 'programs', $program_id, 'program updated');
    send_notification($_SESSION['user_id'], 'program updated successfully', 'success');

    header("Location: programs");
    exit();
}
