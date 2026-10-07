<?php
$page_title = 'Job Seeker Dashboard';

require_once 'includes/config.php';
require_once 'helpers/auth_redirect.php';
session_start();

// Must be logged in
require_login();

// Only Job Seekers allowed
if ($_SESSION['role'] !== 'job_seeker') {
    redirect_by_role($_SESSION['role']);
}

require_once 'includes/header.php';

$user_id = (int)$_SESSION['user_id'];
?>

<?php require_once 'includes/footer.php'; ?>
