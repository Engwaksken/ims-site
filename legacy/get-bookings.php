<?php

session_start();
require_once 'includes/config.php';

header('Content-Type: application/json');


if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}


$start = isset($_GET['start']) ? substr($_GET['start'], 0, 10) : date('Y-m-d');
$end   = isset($_GET['end'])   ? substr($_GET['end'], 0, 10)   : date('Y-m-d', strtotime('+1 month'));


if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $end = date('Y-m-d', strtotime('+1 month'));
}

$start = $conn->real_escape_string($start);
$end   = $conn->real_escape_string($end);

$space_filter = '';
if (isset($_GET['space_name']) && $_GET['space_name'] !== '') {
    $space_name   = $conn->real_escape_string($_GET['space_name']);
    $space_filter = " AND space_name = '$space_name' ";
}

$query = "SELECT booking_id, space_name, space_type, booking_date, start_time, end_time, booking_status
          FROM space_bookings
          WHERE booking_date >= '$start'
          AND booking_date <= '$end'
          AND booking_status NOT IN ('Cancelled')
          $space_filter
          ORDER BY booking_date ASC, start_time ASC";

$result = $conn->query($query);

$status_colors = [
    'Pending'   => '#F39C12', // orange 
    'Confirmed' => '#27AE60', // green
    'Completed' => '#3498DB', // blue
    'No Show'   => '#95A5A6', // grey
];

$events = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $color = $status_colors[$row['booking_status']] ?? '#E74C3C';

        $events[] = [
            'id'    => (int) $row['booking_id'],
            'title' => $row['space_name'] . ' - ' . $row['booking_status'],
            'start' => $row['booking_date'] . 'T' . $row['start_time'],
            'end'   => $row['booking_date'] . 'T' . $row['end_time'],
            'color' => $color,
            'extendedProps' => [
                'space_name' => $row['space_name'],
                'space_type' => $row['space_type'],
                'status'     => $row['booking_status'],
                'date'       => $row['booking_date'],
                'start_time' => $row['start_time'],
                'end_time'   => $row['end_time'],
            ],
        ];
    }
}

echo json_encode($events);
exit();