<?php
require_once 'includes/config.php';
check_login();
check_role(['Administrator', 'Programs Lead']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = intval($_POST['project_id']);
    $project_name = sanitize_input($_POST['project_name']);
    $project_code = sanitize_input($_POST['project_code']);
    $donor_id = intval($_POST['donor_id']);
    $status = sanitize_input($_POST['status']);

    $stmt = $conn->prepare("UPDATE projects SET project_name=?, project_code=?, donor_id=?, status=? WHERE project_id=?");
    $stmt->bind_param("ssisi", $project_name, $project_code, $donor_id, $status, $project_id);
    $stmt->execute();

    log_action($_SESSION['user_id'], 'Edit Project', 'projects', $project_id, 'Project updated');
    send_notification($_SESSION['user_id'], 'Project updated successfully', 'success');

    header("Location: projects.php");
    exit();
}
