<?php

require_once 'includes/config.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="participants_template_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV headers
$headers = [
    'First Name*',
    'Last Name*',
    'Gender*',
    'Date of Birth',
    'Phone',
    'Email',
    'District',
    'Sub-county',
    'Village',
    'Education Level',
    'Occupation',
    'Is PWD',
    'Disability Type'
];

fputcsv($output, $headers);

// Add sample data rows
$sample_rows = [
    ['John', 'Doe', 'Male', '1990-05-15', '+256700000001', 'john@example.com', 'Kampala', 'Central', 'Nakawa', 'University', 'Software Developer', 'No', ''],
    ['Jane', 'Smith', 'Female', '1995-08-20', '+256700000002', 'jane@example.com', 'Wakiso', 'Kira', 'Namugongo', 'Secondary', 'Trader', 'No', ''],
    ['Bob', 'Johnson', 'Male', '1988-12-10', '+256700000003', 'bob@example.com', 'Mukono', 'Mukono TC', 'Seeta', 'Tertiary', 'Mechanic', 'Yes', 'Physical'],
];

foreach ($sample_rows as $row) {
    fputcsv($output, $row);
}

$instructions = [
    [''],
    ['INSTRUCTIONS:'],
    ['- Fields marked with * are required'],
    ['- First Name: Beneficiary first name (required)'],
    ['- Last Name: Beneficiary last name (required)'],
    ['- Gender: Must be one of: Male, Female, Other (required)'],
    ['- Date of Birth: Format YYYY-MM-DD (e.g., 1990-05-15)'],
    ['- Phone: Phone number in format +256700000000'],
    ['- Email: Valid email address'],
    ['- District: Select from Uganda districts'],
    ['- Sub-county: Sub-county name'],
    ['- Village: Village name'],
    ['- Education Level: None, Primary, Secondary, Tertiary, or University'],
    ['- Occupation: Current occupation'],
    ['- Is PWD: Yes or No (whether person has disability)'],
    ['- Disability Type: Type of disability if PWD is Yes (e.g., Visual, Physical, Hearing)'],
    [''],
    ['VALID VALUES:'],
    ['- Gender: Male, Female, Other'],
    ['- Education Level: None, Primary, Secondary, Tertiary, University'],
    ['- Is PWD: Yes, No'],
    [''],
    ['DELETE THE SAMPLE ROWS AND INSTRUCTIONS BEFORE UPLOADING']
];

foreach ($instructions as $instruction) {
    fputcsv($output, $instruction);
}

fclose($output);
exit();
?>