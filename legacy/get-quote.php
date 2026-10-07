<?php

header('Content-Type: application/json');

$required_files = [
    'config'  => __DIR__ . '/includes/config.php',
    'pricing' => __DIR__ . '/includes/pricing.php',
];
foreach ($required_files as $label => $path) {
    if (!file_exists($path)) {
        http_response_code(500);
        echo json_encode([
            'error' => "Server setup issue: includes/{$label}.php was not found at $path. " .
                       "Make sure it has been uploaded to the includes/ folder.",
        ]);
        exit();
    }
}

session_start();
require_once $required_files['config'];
require_once $required_files['pricing'];

try {

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit();
    }

    $user_id = $_SESSION['user_id'];

    $member_result = $conn->query("SELECT member_id FROM members WHERE user_id = " . intval($user_id));
    if (!$member_result || $member_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Member profile not found']);
        exit();
    }
    $member_id = $member_result->fetch_assoc()['member_id'];

    $space_id = intval($_GET['space_id'] ?? 0);
    $space_name = trim((string) ($_GET['space_name'] ?? ''));
    $booking_date = $_GET['booking_date'] ?? '';
    $booking_type = $_GET['booking_type'] ?? 'hourly';
    $start_time = $_GET['start_time'] ?? '';
    $end_time = $_GET['end_time'] ?? '';
    $exclude_booking_id = isset($_GET['exclude_booking_id']) && $_GET['exclude_booking_id'] !== ''
        ? intval($_GET['exclude_booking_id'])
        : null;

    if ((!$space_id && $space_name === '') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
        echo json_encode(['amount' => 0, 'duration_hours' => 0]);
        exit();
    }

    if ($space_id) {
        $space_result = $conn->query("SELECT * FROM space_availability WHERE availability_id = $space_id");
    } else {
        $space_name_esc = $conn->real_escape_string($space_name);
        $space_result = $conn->query("SELECT * FROM space_availability WHERE space_name = '$space_name_esc' LIMIT 1");
    }

    if (!$space_result) {
        http_response_code(500);
        echo json_encode(['error' => 'Space lookup query failed: ' . $conn->error]);
        exit();
    }

    if ($space_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Space not found']);
        exit();
    }
    $space = $space_result->fetch_assoc();


    $duration_hours = 0;
    if ($booking_type === 'half_day') {
        $duration_hours = 4;
    } elseif ($booking_type === 'full_day') {
        $duration_hours = 8;
    } elseif ($start_time && $end_time) {
        try {
            $start = new DateTime($start_time);
            $end = new DateTime($end_time);
            $interval = $start->diff($end);
            $duration_hours = $interval->h + ($interval->i / 60);
        } catch (Exception $e) {
            $duration_hours = 0;
        }
    }

    if ($duration_hours <= 0) {
        echo json_encode(['amount' => 0, 'duration_hours' => 0]);
        exit();
    }

    $pricing = calculate_booking_amount($conn, $member_id, $space, $booking_type, $duration_hours, $booking_date, $exclude_booking_id);
    $pricing['duration_hours'] = $duration_hours;
    $pricing['space_type'] = $space['space_type'];

    echo json_encode($pricing);
    exit();

} catch (Throwable $e) {
    error_log('get-quote.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Quote calculation failed: ' . $e->getMessage()]);
    exit();
}