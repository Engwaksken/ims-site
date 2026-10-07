<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
check_login();
check_role(IMS_STAFF_ROLES);

$type = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';

if (!in_array($type, ['projects', 'beneficiaries', 'indicators', 'events', 'hub_visitors', 'memberships'], true)) {
    die('Export type not specified');
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_export_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

switch ($type) {
    case 'projects':
        // Projects export
        ims_fputcsv($output, ['Project Code', 'Project Name', 'Donor', 'Start Date', 'End Date', 'Budget', 'Currency', 'Status', 'Beneficiaries', 'Indicators']);
        
        $query = "SELECT p.*, d.donor_name,
            (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.project_id = p.project_id) as beneficiary_count,
            (SELECT COUNT(*) FROM indicators i WHERE i.project_id = p.project_id) as indicator_count
            FROM projects p 
            LEFT JOIN donors d ON p.donor_id = d.donor_id 
            ORDER BY p.created_at DESC";
        
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['project_code'],
                $row['project_name'],
                $row['donor_name'] ?? 'N/A',
                $row['start_date'],
                $row['end_date'],
                $row['budget'],
                $row['currency'],
                $row['status'],
                $row['beneficiary_count'],
                $row['indicator_count']
            ]);
        }
        break;
        
    case 'beneficiaries':
        // Beneficiaries export
        ims_fputcsv($output, ['ID', 'First Name', 'Last Name', 'Gender', 'Age', 'Phone', 'Email', 'District', 'Subcounty', 'Village', 'PWD', 'PWD Type', 'Education', 'Occupation', 'Registration Date']);
        
        $query = "SELECT * FROM beneficiaries ORDER BY created_at DESC";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['beneficiary_id'],
                $row['first_name'],
                $row['last_name'],
                $row['gender'],
                $row['age'],
                $row['phone'],
                $row['email'],
                $row['district'],
                $row['subcounty'],
                $row['village'],
                $row['is_pwd'] ? 'Yes' : 'No',
                $row['pwd_type'],
                $row['education_level'],
                $row['occupation'],
                $row['registration_date']
            ]);
        }
        break;
        
    case 'indicators':
        // Indicators export
        ims_fputcsv($output, ['Project Code', 'Project Name', 'Indicator Name', 'Type', 'Unit', 'Baseline', 'Target', 'Current', 'Achievement %', 'Data Source']);
        
        $query = "SELECT i.*, p.project_name, p.project_code,
            CASE WHEN i.target_value > 0 THEN (i.current_value / i.target_value * 100) ELSE 0 END as achievement_percentage
            FROM indicators i 
            JOIN projects p ON i.project_id = p.project_id 
            ORDER BY p.project_name, i.indicator_type";
        
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['project_code'],
                $row['project_name'],
                $row['indicator_name'],
                $row['indicator_type'],
                $row['unit_of_measure'],
                $row['baseline_value'],
                $row['target_value'],
                $row['current_value'],
                number_format($row['achievement_percentage'], 2) . '%',
                $row['data_source']
            ]);
        }
        break;
        
    case 'events':
        // Events export
        ims_fputcsv($output, ['Event Name', 'Type', 'Date', 'Time', 'Venue', 'Organizer', 'Project', 'Expected Participants', 'Actual Participants', 'Status']);
        
        $query = "SELECT e.*, p.project_name 
            FROM events e 
            LEFT JOIN projects p ON e.project_id = p.project_id 
            ORDER BY e.event_date DESC";
        
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['event_name'],
                $row['event_type'],
                $row['event_date'],
                $row['start_time'],
                $row['venue'],
                $row['organizer'],
                $row['project_name'] ?? 'N/A',
                $row['expected_participants'],
                $row['actual_participants'],
                $row['status']
            ]);
        }
        break;
        
    case 'hub_visitors':
        // Hub visitors export
        ims_fputcsv($output, ['Visitor Name', 'Phone', 'Email', 'Organization', 'Visit Date', 'Visit Time', 'Purpose', 'Remarks']);
        
        $query = "SELECT * FROM hub_visitors ORDER BY visit_date DESC";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['visitor_name'],
                $row['phone'],
                $row['email'],
                $row['organization'],
                $row['visit_date'],
                $row['visit_time'],
                $row['purpose'],
                $row['remarks']
            ]);
        }
        break;
        
    case 'memberships':
        // Memberships export
        ims_fputcsv($output, ['Member Name', 'Phone', 'Email', 'Organization', 'Membership Type', 'Start Date', 'End Date', 'Monthly Fee', 'Status']);
        
        $query = "SELECT * FROM memberships ORDER BY start_date DESC";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            ims_fputcsv($output, [
                $row['member_name'],
                $row['phone'],
                $row['email'],
                $row['organization'],
                $row['membership_type'],
                $row['start_date'],
                $row['end_date'],
                $row['monthly_fee'],
                $row['status']
            ]);
        }
        break;
        
    default:
        ims_fputcsv($output, ['Error', 'Invalid export type']);
        break;
}

fclose($output);

// Log export action
log_action($_SESSION['user_id'], 'Export Data', $type, null, "Exported $type data");

exit();
?>