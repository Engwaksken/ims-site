<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to submit feedback.";
    header("Location: ../login");
    exit();
}

$user_id = $_SESSION['user_id'];

/**
 * Helper: fetch member_id
 */
function get_member_id($conn, $user_id) {
    $stmt = $conn->prepare("SELECT member_id FROM members WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) return null;
    return $res->fetch_assoc()['member_id'];
}

/**
 * Helper: log activity
 */
function log_activity($conn, $user_id, $action, $description) {
    $stmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, description, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param("isss", $user_id, $action, $description, $ip);
    $stmt->execute();
}

/**
 * Helper: sanitize input
 */
function clean_input($conn, $input) {
    return mysqli_real_escape_string($conn, trim($input));
}

/* ======================
   SUBMIT FEEDBACK
====================== */
if (isset($_POST['submit_feedback'])) {

    $member_id = get_member_id($conn, $user_id);
    if (!$member_id) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../submit-feedback");
        exit();
    }

    // Get and sanitize form data
    $feedback_type    = clean_input($conn, $_POST['feedback_type']);
    $subject          = clean_input($conn, $_POST['subject']);
    $feedback_message = clean_input($conn, $_POST['feedback_message']);
    $priority         = clean_input($conn, $_POST['priority'] ?? 'Medium');
    $is_anonymous     = isset($_POST['is_anonymous']) ? 1 : 0;
    $rating           = isset($_POST['rating']) && $_POST['rating'] !== '' ? (int)$_POST['rating'] : null;

    // Validation
    $valid_types = ['General', 'Facilities', 'Services', 'Events', 'Complaint', 'Suggestion'];
    $valid_priorities = ['Low', 'Medium', 'High', 'Urgent'];

    if (!$feedback_type || !$subject || !$feedback_message) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: ../submit-feedback");
        exit();
    }

    if (!in_array($feedback_type, $valid_types)) {
        $_SESSION['error'] = "Invalid feedback type.";
        header("Location: ../submit-feedback");
        exit();
    }

    if (!in_array($priority, $valid_priorities)) $priority = 'Medium';
    if ($rating !== null && ($rating < 0 || $rating > 5)) $rating = null;
    if (strlen($feedback_message) < 10) {
        $_SESSION['error'] = "Feedback message must be at least 10 characters.";
        header("Location: ../submit-feedback");
        exit();
    }

    // Handle file attachments
    $attachments = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $upload_dir = "../uploads/feedback/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $file_count = count($_FILES['attachments']['name']);
        if ($file_count > 5) {
            $_SESSION['error'] = "Maximum 5 files allowed.";
            header("Location: ../submit-feedback");
            exit();
        }

        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif', 
            'application/pdf', 
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['attachments']['error'][$i] === 0) {
                $file_tmp  = $_FILES['attachments']['tmp_name'][$i];
                $file_name = $_FILES['attachments']['name'][$i];
                $file_type = $_FILES['attachments']['type'][$i];
                $file_size = $_FILES['attachments']['size'][$i];

                if (!in_array($file_type, $allowed_types)) {
                    $_SESSION['error'] = "Invalid file type for $file_name.";
                    header("Location: ../submit-feedback");
                    exit();
                }

                if ($file_size > 5 * 1024 * 1024) {
                    $_SESSION['error'] = "File $file_name exceeds max size of 5MB.";
                    header("Location: ../submit-feedback");
                    exit();
                }

                // Server-side extension + content check (the browser-supplied
                // MIME type above is attacker controlled).
                $attachmentCheck = ims_validate_upload([
                    'name'     => $file_name,
                    'type'     => $file_type,
                    'tmp_name' => $file_tmp,
                    'error'    => $_FILES['attachments']['error'][$i],
                    'size'     => $file_size,
                ], ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'], 5 * 1024 * 1024);
                if (!$attachmentCheck['ok']) {
                    $_SESSION['error'] = 'Invalid file: ' . $attachmentCheck['error'];
                    header("Location: ../submit-feedback");
                    exit();
                }

                $ext = $attachmentCheck['extension'];
                $unique_name = 'feedback_' . time() . '_' . $i . '_' . uniqid() . '.' . $ext;
                $upload_path = $upload_dir . $unique_name;

                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $attachments[] = substr($upload_path, 3); // store relative path
                } else {
                    $_SESSION['error'] = "Error uploading file $file_name.";
                    header("Location: ../submit-feedback");
                    exit();
                }
            }
        }
    }

    $attachments_json = !empty($attachments) ? json_encode($attachments) : null;

    // Insert feedback using prepared statement
    $stmt = $conn->prepare("
        INSERT INTO member_feedback (
            member_id, feedback_type, subject, feedback_message, 
            rating, is_anonymous, feedback_status, priority, attachments
        ) VALUES (?, ?, ?, ?, ?, ?, 'New', ?, ?)
    ");
    $stmt->bind_param(
        "isssiiss",
        $member_id,
        $feedback_type,
        $subject,
        $feedback_message,
        $rating,
        $is_anonymous,
        $priority,
        $attachments_json
    );

    if ($stmt->execute()) {
        $feedback_id = $conn->insert_id;
        log_activity($conn, $user_id, "Submit Feedback", "$feedback_type feedback submitted: $subject (ID: $feedback_id)");
        $_SESSION['success'] = "Thank you for your feedback! Reference ID: #$feedback_id";
    } else {
        $_SESSION['error'] = "Error submitting feedback: " . $conn->error;
    }

    header("Location: ../submit-feedback");
    exit();
}

/* ======================
   UPDATE FEEDBACK
====================== */
if (isset($_POST['update_feedback'])) {
    $feedback_id = (int)$_POST['feedback_id'];
    $member_id = get_member_id($conn, $user_id);
    if (!$member_id) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-feedback");
        exit();
    }

    $stmt = $conn->prepare("SELECT feedback_status FROM member_feedback WHERE feedback_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $feedback_id, $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $_SESSION['error'] = "Feedback not found or does not belong to you.";
        header("Location: ../my-feedback");
        exit();
    }

    $row = $res->fetch_assoc();
    if (!in_array($row['feedback_status'], ['New', 'In Review'])) {
        $_SESSION['error'] = "Cannot edit feedback that has been responded to or closed.";
        header("Location: ../my-feedback");
        exit();
    }

    $subject = clean_input($conn, $_POST['subject']);
    $feedback_message = clean_input($conn, $_POST['feedback_message']);
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : null;
    if ($rating !== null && ($rating < 0 || $rating > 5)) $rating = null;

    $stmt = $conn->prepare("
        UPDATE member_feedback SET 
            subject = ?, feedback_message = ?, rating = ?
        WHERE feedback_id = ? AND member_id = ?
    ");
    $stmt->bind_param("ssiii", $subject, $feedback_message, $rating, $feedback_id, $member_id);
    if ($stmt->execute()) {
        log_activity($conn, $user_id, "Update Feedback", "Updated feedback #$feedback_id");
        $_SESSION['success'] = "Feedback updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating feedback: " . $conn->error;
    }

    header("Location: ../my-feedback");
    exit();
}

/* ======================
   DELETE FEEDBACK
====================== */
if (isset($_GET['delete_feedback'])) {
    $feedback_id = (int)$_GET['delete_feedback'];
    $member_id = get_member_id($conn, $user_id);
    if (!$member_id) {
        $_SESSION['error'] = "Member profile not found.";
        header("Location: ../my-feedback");
        exit();
    }

    $stmt = $conn->prepare("SELECT feedback_status, attachments FROM member_feedback WHERE feedback_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $feedback_id, $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $_SESSION['error'] = "Feedback not found or does not belong to you.";
        header("Location: ../my-feedback");
        exit();
    }

    $row = $res->fetch_assoc();
    if ($row['feedback_status'] != 'New') {
        $_SESSION['error'] = "Cannot delete feedback that is being reviewed or has been responded to.";
        header("Location: ../my-feedback");
        exit();
    }

    // Delete attachments
    if ($row['attachments']) {
        $attachments = json_decode($row['attachments'], true);
        foreach ($attachments as $file) {
            if (file_exists("../$file")) unlink("../$file");
        }
    }

    $stmt = $conn->prepare("DELETE FROM member_feedback WHERE feedback_id = ? AND member_id = ?");
    $stmt->bind_param("ii", $feedback_id, $member_id);
    if ($stmt->execute()) {
        log_activity($conn, $user_id, "Delete Feedback", "Deleted feedback #$feedback_id");
        $_SESSION['success'] = "Feedback deleted successfully.";
    } else {
        $_SESSION['error'] = "Error deleting feedback: " . $conn->error;
    }

    header("Location: ../my-feedback");
    exit();
}

// If no valid action
$_SESSION['error'] = "Invalid action.";
header("Location: ../submit-feedback");
exit();
?>
