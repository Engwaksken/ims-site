<?php
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to upload documents.";
    header("Location: login");
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: my-applications");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Get application ID
$application_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;

if (!$application_id) {
    $_SESSION['error'] = "Invalid application ID.";
    header("Location: my-applications");
    exit();
}

// Verify application belongs to user (IDOR protection)
$check = $conn->prepare("SELECT application_id, opportunity_id FROM applications WHERE application_id = ? AND submitted_by = ? LIMIT 1");
$check->bind_param('ii', $application_id, $user_id);
$check->execute();
$application = $check->get_result()->fetch_assoc();
$check->close();

if (!$application) {
    $_SESSION['error'] = "Application not found or you don't have permission to upload documents.";
    header("Location: my-applications");
    exit();
}

// Get document type
$document_type = mb_substr(trim(strip_tags((string)($_POST['document_type'] ?? ''))), 0, 100);
if ($document_type === '') {
    $document_type = 'Additional Document';
}

// Handle file upload
if (!isset($_FILES['document']) || (int)$_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = "No file uploaded or upload error occurred.";
    header("Location: my-applications");
    exit();
}

$file = $_FILES['document'];

// Validate extension + MIME type + size (10MB max)
$validation = ims_validate_upload(
    $file,
    ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'],
    10 * 1024 * 1024
);

if (!$validation['ok']) {
    $_SESSION['error'] = $validation['error'] . " Allowed: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, PNG (max 10MB).";
    header("Location: my-applications");
    exit();
}

// Create upload directory
$upload_dir = 'uploads/applications/' . (int)$application['opportunity_id'] . '/additional/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique, server-controlled filename
$filename = 'doc_' . $application_id . '_' . $validation['safe_name'];
$filepath = $upload_dir . $filename;

// Move uploaded file
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    $file_size = (int)filesize($filepath);
    $original_name = mb_substr(basename((string)$file['name']), 0, 255);

    $stmt = $conn->prepare(
        "INSERT INTO application_attachments (application_id, document_name, document_type, file_path, file_size)
         VALUES (?, ?, ?, ?, ?)"
    );

    if ($stmt) {
        $stmt->bind_param('isssi', $application_id, $original_name, $document_type, $filepath, $file_size);
    }

    if ($stmt && $stmt->execute()) {
        $stmt->close();

        if (function_exists('log_action')) {
            log_action($user_id, 'Upload Document', 'application_attachments', $application_id, "Uploaded {$document_type} for application #{$application_id}");
        }

        $_SESSION['success'] = "Document uploaded successfully!";
    } else {
        // Delete file if database insert fails
        error_log('upload-application-docs insert failed: ' . $conn->error);
        @unlink($filepath);
        $_SESSION['error'] = "Error saving document information. Please try again.";
    }
} else {
    $_SESSION['error'] = "Error uploading file. Please try again.";
}

header("Location: my-applications");
exit();
