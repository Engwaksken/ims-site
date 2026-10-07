<?php
require_once 'includes/config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer', 'Operations/Admin']);

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="event_attendance_template_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV headers
$headers = [
    'Attendee Name*',
    'Phone',
    'Email',
    'Organization',
    'Attendance Status*',
    'Feedback Score',
    'Feedback Comments'
];

fputcsv($output, $headers);

// Add sample data rows
$sample_rows = [
    ['John Doe', '+256700000001', 'john@example.com', 'Company A', 'Registered', '', ''],
    ['Jane Smith', '+256700000002', 'jane@example.com', 'Company B', 'Attended', '5', 'Great event!'],
    ['Bob Johnson', '+256700000003', 'bob@example.com', 'Company C', 'No Show', '', ''],
];

foreach ($sample_rows as $row) {
    fputcsv($output, $row);
}

// Add instructions as comments
$instructions = [
    [''],
    ['INSTRUCTIONS:'],
    ['- Fields marked with * are required'],
    ['- Attendee Name: Full name of the attendee (required)'],
    ['- Phone: Phone number in format +256700000000'],
    ['- Email: Valid email address'],
    ['- Organization: Company or organization name'],
    ['- Attendance Status: Must be one of: Registered, Attended, No Show (required)'],
    ['- Feedback Score: Number from 1 to 5 (leave empty if not applicable)'],
    ['- Feedback Comments: Any feedback text'],
    [''],
    ['DELETE THE SAMPLE ROWS AND INSTRUCTIONS BEFORE UPLOADING']
];

foreach ($instructions as $instruction) {
    fputcsv($output, $instruction);
}

fclose($output);
exit();
?>