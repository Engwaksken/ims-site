<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

check_role(['Administrator','MEAL Lead','Programs Lead']);

/* ----------------------------------------------------------
HELPERS
---------------------------------------------------------- */
if (!function_exists('h')) { // config.php already defines h(); redeclaring was fatal
    function h(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function clean(mixed $v): string {
    return trim((string)$v);
}

function redirectWithMsg(int $surveyId, string $type, string $msg): void {
    $_SESSION['builder_' . $type] = $msg;
    header("Location: ../survey-builder.php?id={$surveyId}");
    exit;
}

/* ----------------------------------------------------------
SURVEY ID
---------------------------------------------------------- */
$surveyId = (int)($_GET['id'] ?? ($_POST['survey_id'] ?? 0));
if ($surveyId <= 0) {
    die("Invalid survey ID.");
}

/* ----------------------------------------------------------
FLASH MESSAGES
---------------------------------------------------------- */
$successMsg = $_SESSION['builder_success'] ?? '';
$errorMsg   = $_SESSION['builder_error'] ?? '';
unset($_SESSION['builder_success'], $_SESSION['builder_error']);

/* ----------------------------------------------------------
HANDLE ADD / EDIT QUESTION
---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $questionText = clean($_POST['question_text'] ?? '');
    $questionType = clean($_POST['question_type'] ?? '');
    $isRequired   = isset($_POST['is_required']) ? 1 : 0;

    $allowedTypes = ['text','textarea','number','radio','checkbox','select','date'];

    if ($questionText === '') {
        redirectWithMsg($surveyId, 'error', 'Question text is required.');
    }

    if (!in_array($questionType, $allowedTypes, true)) {
        redirectWithMsg($surveyId, 'error', 'Invalid question type.');
    }

    // Clean and filter options for multiple choice questions
    $options = [];
    if (in_array($questionType, ['radio','checkbox','select'], true) && !empty($_POST['options'])) {
        $options = array_filter(array_map('clean', $_POST['options']), fn($v) => $v !== '');
    }

    /* ---------- ADD QUESTION ---------- */
    if ($action === 'add_question') {

        $res = $conn->query("SELECT MAX(question_order) AS max_order FROM survey_questions WHERE survey_id = {$surveyId}");
        $order = ($row = $res->fetch_assoc()) ? ((int)$row['max_order'] + 1) : 1;

        $stmt = $conn->prepare("
            INSERT INTO survey_questions (survey_id, question_text, question_type, is_required, question_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            redirectWithMsg($surveyId, 'error', 'Database error: ' . $conn->error);
        }

        $stmt->bind_param("issii", $surveyId, $questionText, $questionType, $isRequired, $order);
        $stmt->execute();
        $newQuestionId = $stmt->insert_id;
        $stmt->close();

        // Insert options if applicable
        if ($options) {
            $stmtOpt = $conn->prepare("INSERT INTO survey_options (question_id, option_label, option_value) VALUES (?, ?, ?)");
            foreach ($options as $opt) {
                $stmtOpt->bind_param("iss", $newQuestionId, $opt, $opt);
                $stmtOpt->execute();
            }
            $stmtOpt->close();
        }

        redirectWithMsg($surveyId, 'success', 'Question added.');
    }

    /* ---------- EDIT QUESTION ---------- */
    if ($action === 'edit_question' && !empty($_POST['question_id'])) {

        $questionId = (int)$_POST['question_id'];

        // The question must belong to this survey (options are replaced below).
        $own = $conn->prepare("SELECT 1 FROM survey_questions WHERE question_id = ? AND survey_id = ?");
        $own->bind_param("ii", $questionId, $surveyId);
        $own->execute();
        $ownsQuestion = (bool)$own->get_result()->fetch_row();
        $own->close();
        if (!$ownsQuestion) {
            redirectWithMsg($surveyId, 'error', 'Question not found.');
        }

        $stmt = $conn->prepare("
            UPDATE survey_questions
            SET question_text = ?, question_type = ?, is_required = ?
            WHERE question_id = ? AND survey_id = ?
        ");
        $stmt->bind_param("ssiii", $questionText, $questionType, $isRequired, $questionId, $surveyId);
        if (!$stmt->execute()) {
            redirectWithMsg($surveyId, 'error', 'Failed to update question.');
        }
        $stmt->close();

        // Remove old options
        $conn->query("DELETE FROM survey_options WHERE question_id = {$questionId}");

        // Insert new options if applicable
        if ($options) {
            $stmtOpt = $conn->prepare("INSERT INTO survey_options (question_id, option_label, option_value) VALUES (?, ?, ?)");
            foreach ($options as $opt) {
                $stmtOpt->bind_param("iss", $questionId, $opt, $opt);
                $stmtOpt->execute();
            }
            $stmtOpt->close();
        }

        redirectWithMsg($surveyId, 'success', 'Question updated.');
    }
}

/* ----------------------------------------------------------
DELETE QUESTION
---------------------------------------------------------- */
if (isset($_GET['delete'])) {
    csrf_protect(true); // state-changing GET link: token required
    $qid = (int)$_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM survey_questions WHERE question_id = ? AND survey_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $qid, $surveyId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        // Delete associated options (only if the question belonged to this survey)
        if ($deleted) {
            $conn->query("DELETE FROM survey_options WHERE question_id = {$qid}");
        }

        redirectWithMsg($surveyId, 'success', 'Question deleted.');
    } else {
        redirectWithMsg($surveyId, 'error', 'Database error: ' . $conn->error);
    }
}

/* ----------------------------------------------------------
LOAD QUESTIONS
---------------------------------------------------------- */
$questions = [];
$res = $conn->query("
    SELECT q.*, 
           GROUP_CONCAT(o.option_label ORDER BY o.option_id ASC) AS options
    FROM survey_questions q
    LEFT JOIN survey_options o ON o.question_id = q.question_id
    WHERE q.survey_id = {$surveyId}
    GROUP BY q.question_id
    ORDER BY q.question_order ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['options'] = $row['options'] ? explode(',', $row['options']) : [];
        $questions[] = $row;
    }
}