<?php
declare(strict_types=1);

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role([
    'Administrator',
    'Programs Lead',
    'Program Director',
    'Program Manager',
    'MEAL Lead',
    'Project Officer',
    'Reviewer'
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$autoloaded = false;

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
    die('PhpSpreadsheet is not installed. Install it with: composer require phpoffice/phpspreadsheet');
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function hcell(?string $value): string
{
    return trim((string)$value);
}

function safe_sheet_name(string $name): string
{
    $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '', $name);
    $name = trim((string)$name);

    if ($name === '') {
        $name = 'Venture';
    }

    return mb_substr($name, 0, 31);
}

function score_value(array $row, string $key): float
{
    return max(0, min(10, (float)($row[$key] ?? 0)));
}

function priority_from_score(float $score): string
{
    if ($score < 4) {
        return 'High';
    }

    if ($score < 7) {
        return 'Medium';
    }

    return 'Low';
}

function status_from_score(float $score): string
{
    if ($score >= 8) {
        return 'Addressed';
    }

    if ($score >= 5) {
        return 'In Progress';
    }

    return 'Open';
}

function lowest_category(array $row, array $categories): string
{
    $lowestName = '';
    $lowestScore = 999;

    foreach ($categories as $key => $label) {
        $score = score_value($row, $key);

        if ($score < $lowestScore) {
            $lowestScore = $score;
            $lowestName = $label;
        }
    }

    return $lowestName;
}

$templatePaths = [
    dirname(__DIR__) . '/templates/Milestone Template.xlsx',
    dirname(__DIR__) . '/assets/templates/Milestone Template.xlsx',
    dirname(__DIR__) . '/Milestone Template.xlsx',
    __DIR__ . '/../templates/Milestone Template.xlsx',
];

$templatePath = null;

foreach ($templatePaths as $path) {
    if (is_file($path)) {
        $templatePath = $path;
        break;
    }
}

$spreadsheet = $templatePath
    ? IOFactory::load($templatePath)
    : new Spreadsheet();

$dashboard = $spreadsheet->getSheetByName('Dashboard') ?: $spreadsheet->getActiveSheet();
$dashboard->setTitle('Dashboard');

$templateSheet = $spreadsheet->getSheetByName('V01 Venture 1');

$sql = "
    SELECT
        sfv.*,
        sm.milestone_title,
        sm.milestone_type,
        sm.due_date,
        COALESCE(a.startup_name, CONCAT('Application #', sfv.application_id)) AS startup_name,
        ar.overall_score AS review_overall_score,
        ar.recommendation AS review_recommendation,
        u.full_name AS submitted_by_name
    FROM startup_field_visits sfv
    LEFT JOIN startup_milestones sm ON sm.milestone_id = sfv.milestone_id
    LEFT JOIN applications a ON a.application_id = sfv.application_id
    LEFT JOIN application_reviews ar ON ar.review_id = sfv.review_id
    LEFT JOIN users u ON u.user_id = sfv.submitted_by
    WHERE sfv.status IN ('Visited','Report Uploaded','Completed')
       OR sfv.report_file IS NOT NULL
       OR sfv.field_overall_score > 0
    ORDER BY COALESCE(sfv.submitted_at, sfv.updated_at, sfv.created_at) DESC, sfv.visit_id DESC
";

$result = $conn->query($sql);
$reports = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }

    $result->close();
}

$categories = [
    'equity_score' => 'Equity',
    'video_intro_score' => 'Video Intro',
    'problem_depth_toc_score' => 'Problem Depth / TOC',
    'product_quality_pedagogy_score' => 'Product Quality / Pedagogy',
    'product_demo_link_score' => 'Product Demo Link',
    'scalability_traction_sustainability_score' => 'Scalability / Traction / Sustainability',
    'team_capability_commitment_score' => 'Team Capability / Commitment',
    'ursb_registration_score' => 'URSB Registration',
];

$dashboard->setCellValue('C8', count($reports));
$dashboard->setCellValue('I9', date('Y-m-d'));

$startRow = 13;
$rowNum = $startRow;
$index = 1;

foreach ($reports as $report) {
    $critical = 0;
    $addressed = 0;

    foreach ($categories as $key => $label) {
        $score = score_value($report, $key);

        if ($score < 5) {
            $critical++;
        }

        if ($score >= 8) {
            $addressed++;
        }
    }

    $progress = score_value($report, 'field_overall_score') * 10;

    $dashboard->setCellValue("A{$rowNum}", $index);
    $dashboard->setCellValue("B{$rowNum}", hcell($report['startup_name'] ?? ''));
    $dashboard->setCellValue("C{$rowNum}", hcell($report['field_recommendation'] ?? ''));
    $dashboard->setCellValue("D{$rowNum}", count($categories));
    $dashboard->setCellValue("E{$rowNum}", $addressed);
    $dashboard->setCellValue("F{$rowNum}", $progress / 100);
    $dashboard->setCellValue("G{$rowNum}", lowest_category($report, $categories));
    $dashboard->setCellValue("H{$rowNum}", $critical);
    $dashboard->setCellValue("I{$rowNum}", hcell($report['business_status'] ?? $report['status'] ?? ''));
    $dashboard->setCellValue("J{$rowNum}", hcell($report['submitted_at'] ?? $report['updated_at'] ?? ''));
    $dashboard->setCellValue("K{$rowNum}", hcell($report['risks'] ?? ''));
    $dashboard->setCellValue("L{$rowNum}", hcell($report['next_steps'] ?? ''));

    $dashboard->getStyle("F{$rowNum}")->getNumberFormat()->setFormatCode('0%');

    $sheetTitle = safe_sheet_name('V' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . ' ' . ($report['startup_name'] ?? 'Venture'));

    if ($templateSheet) {
        $sheet = clone $templateSheet;
        $sheet->setTitle($sheetTitle);
        $spreadsheet->addSheet($sheet);
    } else {
        $sheet = new Worksheet($spreadsheet, $sheetTitle);
        $spreadsheet->addSheet($sheet);
    }

    $sheet->setCellValue('A2', strtoupper((string)$report['startup_name']) . ' | Field Gap Analysis | Startup Field Report');
    $sheet->setCellValue('C5', hcell($report['startup_name'] ?? ''));
    $sheet->setCellValue('L5', hcell($report['field_recommendation'] ?? ''));
    $sheet->setCellValue('C6', hcell($report['contact_person'] ?? ''));
    $sheet->setCellValue('L6', 'EdTech');
    $sheet->setCellValue('C7', hcell($report['location'] ?? ''));
    $sheet->setCellValue('L7', hcell($report['actual_visit_date'] ?? $report['planned_visit_date'] ?? ''));
    $sheet->setCellValue('C8', hcell($report['assigned_to'] ?? ''));
    $sheet->setCellValue('L8', hcell($report['submitted_at'] ?? ''));

    $detailStart = 25;
    $gapNo = 1;

    foreach ($categories as $key => $label) {
        $score = score_value($report, $key);
        $priority = priority_from_score($score);
        $status = status_from_score($score);
        $specificGap = $score >= 8 ? 'No major gap identified' : 'Field score indicates support gap in ' . $label;
        $intervention = $score >= 8 ? 'Maintain progress and monitor' : 'Provide targeted coaching/support for ' . $label;

        $sheet->setCellValue("A{$detailStart}", $gapNo);
        $sheet->setCellValue("B{$detailStart}", $label);
        $sheet->setCellValue("C{$detailStart}", $specificGap);
        $sheet->setCellValue("D{$detailStart}", hcell($report['observations'] ?? ''));
        $sheet->setCellValue("E{$detailStart}", $score < 5 ? 'H' : ($score < 8 ? 'M' : 'L'));
        $sheet->setCellValue("F{$detailStart}", $priority === 'High' ? 'H' : ($priority === 'Medium' ? 'M' : 'L'));
        $sheet->setCellValue("G{$detailStart}", 10 - $score);
        $sheet->setCellValue("H{$detailStart}", $intervention);
        $sheet->setCellValue("I{$detailStart}", hcell($report['assigned_to'] ?? ''));
        $sheet->setCellValue("J{$detailStart}", hcell($report['planned_visit_date'] ?? ''));
        $sheet->setCellValue("K{$detailStart}", hcell($report['support_needed'] ?? ''));
        $sheet->setCellValue("L{$detailStart}", $status);
        $sheet->setCellValue("M{$detailStart}", $score / 10);
        $sheet->setCellValue("N{$detailStart}", hcell($report['report_file'] ?? ''));
        $sheet->setCellValue("O{$detailStart}", hcell($report['actual_visit_date'] ?? ''));
        $sheet->setCellValue("P{$detailStart}", hcell($report['field_recommendation'] ?? ''));
        $sheet->setCellValue("Q{$detailStart}", hcell($report['milestone_title'] ?? ''));
        $sheet->setCellValue("R{$detailStart}", hcell($report['submitted_by_name'] ?? ''));
        $sheet->setCellValue("S{$detailStart}", hcell($report['next_steps'] ?? ''));

        $sheet->getStyle("M{$detailStart}")->getNumberFormat()->setFormatCode('0%');

        $detailStart++;
        $gapNo++;
    }

    $sheet->setCellValue("A{$detailStart}", 'SIGNATURES');
    $sheet->setCellValue("B{$detailStart}", 'Field Team Signature');
    $sheet->setCellValue("C{$detailStart}", hcell($report['field_team_signature'] ?? ''));
    $sheet->setCellValue("E{$detailStart}", 'Supervisor Signature');
    $sheet->setCellValue("F{$detailStart}", hcell($report['supervisor_signature'] ?? ''));
    $sheet->setCellValue("H{$detailStart}", 'Approval Status');
    $sheet->setCellValue("I{$detailStart}", hcell($report['report_approval_status'] ?? ''));

    $sheet->getStyle("A{$detailStart}:I{$detailStart}")->getFont()->setBold(true);
    $sheet->getStyle("A{$detailStart}:I{$detailStart}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4ED');

    $rowNum++;
    $index++;
}

$lastRow = max($startRow, $rowNum - 1);
$dashboard->getStyle("A12:L{$lastRow}")->getAlignment()->setWrapText(true);
$dashboard->getStyle("A12:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

foreach (range('A', 'L') as $col) {
    $dashboard->getColumnDimension($col)->setAutoSize(true);
}

if ($templateSheet && $templateSheet->getTitle() === 'V01 Venture 1') {
    $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($templateSheet));
}

$spreadsheet->setActiveSheetIndex(0);

$filename = 'startup-overall-field-gap-report-' . date('Ymd-His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit();
