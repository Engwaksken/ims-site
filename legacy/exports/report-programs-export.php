<?php
require_once __DIR__ . '/../includes/config.php';
check_role(['Administrator','Programs Lead','MEAL Lead']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=programs_report_'.date('Ymd').'.csv');

$output = fopen('php://output', 'w');

ims_fputcsv($output, [
    'program Code',
    'program Name',
    'Donor',
    'Status',
    'Start Date',
    'End Date',
    'Budget',
    'Currency',
    'Participants',
    'Indicators'
]);

$conditions = [];
$params = [];
$types = '';

if (!empty($_GET['status'])) {
    $conditions[] = "p.status=?";
    $params[] = $_GET['status'];
    $types .= 's';
}

if (!empty($_GET['donor'])) {
    $conditions[] = "p.donor_id=?";
    $params[] = intval($_GET['donor']);
    $types .= 'i';
}

$where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

$sql = "
SELECT 
    p.program_code,
    p.program_name,
    d.donor_name,
    p.status,
    p.start_date,
    p.end_date,
    p.budget,
    p.currency,
    (SELECT COUNT(*) FROM programs_beneficiaries WHERE program_id=p.id) participants,
    (SELECT COUNT(*) FROM indicators WHERE program_id=p.id) indicators
FROM programs p
LEFT JOIN donors d ON p.donor_id=d.donor_id
$where
ORDER BY p.start_date DESC
";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    ims_fputcsv($output, $row);
}
fclose($output);
exit;
