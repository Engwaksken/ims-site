<?php
session_start();

$isPortalRequest =
    isset($_GET['id']) ||
    isset($_GET['mac']) ||
    isset($_GET['client_mac']) ||
    isset($_GET['ap']) ||
    isset($_GET['site']);

if ($isPortalRequest) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /internet_portal' . ($query ? '?' . $query : ''));
    exit;
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit();