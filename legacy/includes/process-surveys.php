<?php
declare(strict_types=1);

require_once 'config.php';

check_role(['Administrator', 'MEAL Lead', 'Programs Lead']);


function clean(mixed $value): string { return trim((string)$value); }

function redirectWithMessage(string $type, string $message, string $location = '../surveys'): void
{
    $_SESSION[$type] = $message;
    header("Location: $location");
    exit();
}

// --------------------
// HANDLE CREATE SURVEY
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -------------------- CREATE --------------------
    if ($action === 'create_survey') {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $programId   = (int)($_POST['program_id'] ?? 0);
        $surveyName  = clean($_POST['survey_name'] ?? '');
        $surveyType  = clean($_POST['survey_type'] ?? '');
        $surveyDate  = clean($_POST['survey_date'] ?? '');
        $toolUsed    = clean($_POST['tool_used'] ?? '');
        $respondents = max(0, (int)($_POST['respondents_count'] ?? 0));
        $filePath    = clean($_POST['file_path'] ?? '');
        $summary     = clean($_POST['summary'] ?? '');

        if ($projectId <= 0 && $programId <= 0) redirectWithMessage('survey_error', 'Select a project or program.');
        if ($surveyName === '') redirectWithMessage('survey_error', 'Survey name is required.');
        if ($surveyType === '') redirectWithMessage('survey_error', 'Survey type is required.');
        if ($surveyDate === '') redirectWithMessage('survey_error', 'Survey date is required.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $surveyDate)) redirectWithMessage('survey_error', 'Invalid date format.');
        if ($toolUsed === '') redirectWithMessage('survey_error', 'Tool used is required.');

        $allowedTools = ['google_forms','survey_cto','kobo','microsoft_forms','embedded','other'];
        $allowedTypes = ['baseline','midline','endline','feedback','assessment','other'];

        if (!in_array($toolUsed, $allowedTools, true)) redirectWithMessage('survey_error', 'Invalid tool.');
        if (!in_array($surveyType, $allowedTypes, true)) redirectWithMessage('survey_error', 'Invalid survey type.');

        if ($toolUsed === 'embedded') {
            $filePath = bin2hex(random_bytes(16));
        } elseif ($filePath === '') {
            redirectWithMessage('survey_error', 'File path or link required.');
        }

        $stmt = $conn->prepare("
            INSERT INTO surveys
            (project_id, program_id, survey_name, survey_type, survey_date, tool_used, respondents_count, file_path, summary, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) redirectWithMessage('survey_error', 'DB error: '.$conn->error);

        $stmt->bind_param("iissssiss", $projectId, $programId, $surveyName, $surveyType, $surveyDate, $toolUsed, $respondents, $filePath, $summary);
        if (!$stmt->execute()) redirectWithMessage('survey_error', 'Failed to create survey: '.$stmt->error);

        $stmt->close();
        redirectWithMessage('survey_success', 'Survey created successfully.');
    }

    // -------------------- EDIT --------------------
    if ($action === 'edit_survey') {
        $surveyId    = (int)($_POST['survey_id'] ?? 0);
        $surveyName  = clean($_POST['survey_name'] ?? '');
        $surveyType  = clean($_POST['survey_type'] ?? '');
        $surveyDate  = clean($_POST['survey_date'] ?? '');
        $toolUsed    = clean($_POST['tool_used'] ?? '');
        $respondents = max(0, (int)($_POST['respondents_count'] ?? 0));
        $filePath    = clean($_POST['file_path'] ?? '');
        $summary     = clean($_POST['summary'] ?? '');

        if ($surveyId <= 0) redirectWithMessage('survey_error', 'Invalid survey ID.');
        if ($surveyName === '') redirectWithMessage('survey_error', 'Survey name is required.');
        if ($surveyType === '') redirectWithMessage('survey_error', 'Survey type is required.');

        $stmt = $conn->prepare("
            UPDATE surveys
            SET survey_name=?, survey_type=?, survey_date=?, tool_used=?, respondents_count=?, file_path=?, summary=?
            WHERE survey_id=?
        ");
        if (!$stmt) redirectWithMessage('survey_error', 'DB error: '.$conn->error);

        $stmt->bind_param("ssssissi", $surveyName, $surveyType, $surveyDate, $toolUsed, $respondents, $filePath, $summary, $surveyId);
        if (!$stmt->execute()) redirectWithMessage('survey_error', 'Failed to update survey: '.$stmt->error);

        $stmt->close();
        redirectWithMessage('survey_success', 'Survey updated successfully.');
    }

    // -------------------- DELETE --------------------
    if ($action === 'delete_survey') {
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        if ($surveyId <= 0) redirectWithMessage('survey_error', 'Invalid survey ID.');

        // Optional: Delete associated questions & options
        $conn->query("DELETE FROM survey_options WHERE question_id IN (SELECT question_id FROM survey_questions WHERE survey_id = $surveyId)");
        $conn->query("DELETE FROM survey_questions WHERE survey_id = $surveyId");

        $stmt = $conn->prepare("DELETE FROM surveys WHERE survey_id=?");
        $stmt->bind_param("i", $surveyId);
        $stmt->execute();
        $stmt->close();

        redirectWithMessage('survey_success', 'Survey deleted successfully.');
    }
}

// -------------------- LOAD DATA --------------------
$projects = [];
$res = $conn->query("SELECT project_id, project_name FROM projects ORDER BY project_name ASC");
while ($res && $row = $res->fetch_assoc()) $projects[] = $row;

$programs = [];
$res = $conn->query("SELECT id, program_name FROM programs ORDER BY program_name ASC");
while ($res && $row = $res->fetch_assoc()) $programs[] = $row;

$surveys = [];
$res = $conn->query("
    SELECT s.*, p.project_name, pg.program_name
    FROM surveys s
    LEFT JOIN projects p ON p.project_id = s.project_id
    LEFT JOIN programs pg ON pg.id = s.program_id
    ORDER BY s.survey_id DESC
");
while ($res && $row = $res->fetch_assoc()) $surveys[] = $row;