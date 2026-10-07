<?php
declare(strict_types=1);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function redirectPlans(): never {
    header('Location: internet_plans');
    exit;
}

$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id              = (int)($_POST['id'] ?? 0);
            $planName        = trim((string)($_POST['plan_name'] ?? ''));
            $durationType    = trim((string)($_POST['duration_type'] ?? ''));
            $durationMinutes = (int)($_POST['duration_minutes'] ?? 0);
            $price           = (float)($_POST['price'] ?? 0);
            $discountPercent = (float)($_POST['discount_percent'] ?? 0);
            $bandwidthLimit  = trim((string)($_POST['bandwidth_limit'] ?? ''));
            $deviceLimit     = (int)($_POST['device_limit'] ?? 1);
            $status          = trim((string)($_POST['status'] ?? 'active'));

            if ($planName === '') {
                throw new RuntimeException('Plan name is required.');
            }

            if (!in_array($durationType, ['daily', 'monthly', 'annual'], true)) {
                throw new RuntimeException('Invalid duration type.');
            }

            if ($durationMinutes <= 0) {
                throw new RuntimeException('Duration minutes must be greater than zero.');
            }

            if ($price <= 0) {
                throw new RuntimeException('Price must be greater than zero.');
            }

            if ($discountPercent < 0 || $discountPercent > 100) {
                throw new RuntimeException('Discount must be between 0 and 100.');
            }

            if ($deviceLimit <= 0) {
                throw new RuntimeException('Device limit must be at least 1.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $finalPrice = $price - (($price * $discountPercent) / 100);

            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE internet_plans
                    SET plan_name = ?,
                        duration_type = ?,
                        duration_minutes = ?,
                        price = ?,
                        discount_percent = ?,
                        final_price = ?,
                        bandwidth_limit = ?,
                        device_limit = ?,
                        status = ?
                    WHERE id = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }

                $stmt->bind_param(
                    'ssidddsisi',
                    $planName,
                    $durationType,
                    $durationMinutes,
                    $price,
                    $discountPercent,
                    $finalPrice,
                    $bandwidthLimit,
                    $deviceLimit,
                    $status,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                $_SESSION['success'] = 'Internet plan updated successfully.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO internet_plans (
                        plan_name,
                        duration_type,
                        duration_minutes,
                        price,
                        discount_percent,
                        final_price,
                        bandwidth_limit,
                        device_limit,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }

                $stmt->bind_param(
                    'ssidddsis',
                    $planName,
                    $durationType,
                    $durationMinutes,
                    $price,
                    $discountPercent,
                    $finalPrice,
                    $bandwidthLimit,
                    $deviceLimit,
                    $status
                );

                $stmt->execute();
                $stmt->close();

                $_SESSION['success'] = 'Internet plan created successfully.';
            }

            redirectPlans();
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid plan selected.');
            }

            $stmt = $conn->prepare("
                UPDATE internet_plans
                SET status = IF(status = 'active', 'inactive', 'active')
                WHERE id = ?
            ");

            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Plan status updated.';
            redirectPlans();
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Invalid plan selected.');
            }

            $check = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM internet_subscriptions
                WHERE plan_id = ?
            ");

            if (!$check) {
                throw new RuntimeException($conn->error);
            }

            $check->bind_param('i', $id);
            $check->execute();
            $used = (int)($check->get_result()->fetch_assoc()['total'] ?? 0);
            $check->close();

            if ($used > 0) {
                throw new RuntimeException('This plan has subscriptions. Deactivate it instead of deleting.');
            }

            $stmt = $conn->prepare("DELETE FROM internet_plans WHERE id = ?");

            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['success'] = 'Plan deleted successfully.';
            redirectPlans();
        }

    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirectPlans();
    }
}

$plans = $conn->query("
    SELECT *
    FROM internet_plans
    ORDER BY FIELD(duration_type, 'daily', 'monthly', 'annual'), price ASC
");