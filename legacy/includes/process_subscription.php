<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/unifi_api.php';

$conn = db_connect();

if (!$conn instanceof mysqli) {
    $_SESSION['error'] = 'Database connection failed.';
    header('Location: ../internet_subscriptions.php');
    exit;
}

$conn->set_charset('utf8mb4');

function sub_redirect_error(string $msg): never
{
    $_SESSION['error'] = $msg;
    header('Location: ../internet_subscriptions.php');
    exit;
}

function sub_redirect_success(string $msg): never
{
    $_SESSION['success'] = $msg;
    header('Location: ../internet_subscriptions.php');
    exit;
}

// Login stores user_id/role directly in the session (no $_SESSION['user']
// array), so build it from those keys; otherwise every request was denied.
$user = $_SESSION['user'] ?? (
    !empty($_SESSION['user_id'])
        ? ['user_id' => (int)$_SESSION['user_id'], 'role' => (string)($_SESSION['role'] ?? '')]
        : null
);

$allowedRoles = [
    'administrator',
    'operational_admin',
    'Administrator',
    'Operations/Admin',
    'Accountant'
];

if (!$user || !in_array((string)($user['role'] ?? ''), $allowedRoles, true)) {
    sub_redirect_error('Access denied.');
}

$action = trim((string)($_POST['action'] ?? ''));
$subscriptionId = (int)($_POST['subscription_id'] ?? 0);
$adminId = (int)($user['user_id'] ?? $_SESSION['user_id'] ?? 0);

if ($subscriptionId <= 0) {
    sub_redirect_error('Invalid subscription selected.');
}

if ($adminId <= 0) {
    sub_redirect_error('Invalid administrator session.');
}

try {
    if ($action === 'approve') {
        $stmt = $conn->prepare("
            SELECT 
                s.*,
                p.plan_name,
                p.duration_minutes,
                p.device_limit,
                m.membership_number,
                m.company_name,
                u.full_name,
                u.phone
            FROM internet_subscriptions s
            INNER JOIN internet_plans p ON p.id = s.plan_id
            INNER JOIN members m ON m.member_id = s.member_id
            INNER JOIN users u ON u.user_id = m.user_id
            WHERE s.id = ?
              AND s.status = 'pending'
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        $stmt->bind_param('i', $subscriptionId);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sub) {
            sub_redirect_error('Pending subscription not found.');
        }

        $macAddress = trim((string)($sub['mac_address'] ?? ''));
        $minutes = (int)($sub['duration_minutes'] ?? 0);

        if ($macAddress === '') {
            sub_redirect_error('Client MAC address is missing.');
        }

        if ($minutes <= 0) {
            sub_redirect_error('Invalid plan duration.');
        }

        $start = date('Y-m-d H:i:s');
        $end   = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));

        $uni = unifiAuthorizeGuest($macAddress, $minutes);

        if (!is_array($uni) || empty($uni['success'])) {
            sub_redirect_error($uni['message'] ?? 'UniFi authorization failed.');
        }

        $update = $conn->prepare("
            UPDATE internet_subscriptions
            SET 
                status = 'active',
                starts_at = ?,
                expires_at = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
              AND status = 'pending'
        ");

        if (!$update) {
            throw new RuntimeException($conn->error);
        }

        $update->bind_param('ssii', $start, $end, $adminId, $subscriptionId);
        $update->execute();

        if ($update->affected_rows <= 0) {
            $update->close();
            sub_redirect_error('Subscription could not be approved.');
        }

        $update->close();

        sub_redirect_success('Subscription approved and internet connected successfully.');
    }

    if ($action === 'reject') {
        $reason = trim((string)($_POST['rejection_reason'] ?? ''));

        if ($reason === '') {
            $reason = 'Rejected by administrator.';
        }

        $stmt = $conn->prepare("
            UPDATE internet_subscriptions
            SET 
                status = 'rejected',
                rejection_reason = ?,
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
              AND status = 'pending'
        ");

        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        $stmt->bind_param('sii', $reason, $adminId, $subscriptionId);
        $stmt->execute();

        if ($stmt->affected_rows <= 0) {
            $stmt->close();
            sub_redirect_error('Pending subscription not found or already processed.');
        }

        $stmt->close();

        sub_redirect_success('Subscription rejected successfully.');
    }

    if ($action === 'cancel') {
        $stmt = $conn->prepare("
            UPDATE internet_subscriptions
            SET status = 'cancelled'
            WHERE id = ?
              AND status IN ('pending','active')
        ");

        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        $stmt->bind_param('i', $subscriptionId);
        $stmt->execute();

        if ($stmt->affected_rows <= 0) {
            $stmt->close();
            sub_redirect_error('Subscription could not be cancelled.');
        }

        $stmt->close();

        sub_redirect_success('Subscription cancelled successfully.');
    }

    sub_redirect_error('Invalid action.');

} catch (Throwable $e) {
    sub_redirect_error($e->getMessage());
}