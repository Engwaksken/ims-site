<?php
declare(strict_types=1);
require_once 'config.php';
session_start();
check_role(['Administrator','MEAL Lead','Programs Lead']);

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$survey_id = (int)($input['survey_id'] ?? 0);
$order = $input['order'] ?? [];

if ($survey_id <= 0 || !is_array($order)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$stmt = $conn->prepare("UPDATE survey_questions SET question_order = ? WHERE question_id = ? AND survey_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => $conn->error]);
    exit;
}

foreach ($order as $item) {
    $qid = (int)($item['id'] ?? 0);
    $pos = (int)($item['position'] ?? 0);
    $stmt->bind_param("iii", $pos, $qid, $survey_id);
    $stmt->execute();
}

$stmt->close();
echo json_encode(['success' => true]);