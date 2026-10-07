<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
| Every authenticated user, including Applicant, may update only their own
| profile. No role-based restriction is applied here.
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to update your profile.';
    header('Location: ../login');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function profile_redirect(): never
{
    header('Location: ../user-profile');
    exit;
}

function profile_message(int $userId, string $message, string $type = 'success'): void
{
    if ($type === 'success') {
        $_SESSION['success'] = $message;
    } else {
        $_SESSION['error'] = $message;
    }

    if (function_exists('send_notification')) {
        try {
            send_notification($userId, $message, $type);
        } catch (Throwable $e) {
            error_log('Profile notification failed: ' . $e->getMessage());
        }
    }
}

function profile_fail(int $userId, string $message): never
{
    profile_message($userId, $message, 'danger');
    profile_redirect();
}

function profile_text(string $key, int $max = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));

    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| REQUEST / CSRF
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    profile_redirect();
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postedToken = (string)($_POST['csrf_token'] ?? '');

if (
    $sessionToken === '' ||
    $postedToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    profile_fail($user_id, 'Your session expired. Please refresh the page and try again.');
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if (isset($_POST['update_profile'])) {
    $full_name = profile_text('full_name', 200);
    $email = mb_strtolower(profile_text('email', 150));
    $phone = profile_text('phone', 20);
    $username = profile_text('username', 100);

    if ($full_name === '' || $email === '' || $username === '') {
        profile_fail($user_id, 'Full name, email address and username are required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        profile_fail($user_id, 'Please enter a valid email address.');
    }

    if (!preg_match('/^[\p{L}\p{N}._@+\-\s]+$/u', $username)) {
        profile_fail($user_id, 'Username contains unsupported characters.');
    }

    /*
     * Email and username must remain unique, but the current user's own
     * values are allowed.
     */
    $stmt = $conn->prepare("
        SELECT user_id
        FROM users
        WHERE user_id <> ?
          AND (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?))
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('Profile duplicate check prepare failed: ' . $conn->error);
        profile_fail($user_id, 'We could not validate your profile details. Please try again.');
    }

    $stmt->bind_param('iss', $user_id, $username, $email);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        profile_fail($user_id, 'That username or email address is already being used by another account.');
    }

    /*
     * Never update role, is_active or password here.
     * Applicants have the same right as other authenticated users to edit
     * their OWN basic profile information.
     */
    $stmt = $conn->prepare("
        UPDATE users
        SET
            full_name = ?,
            email = ?,
            phone = NULLIF(?, ''),
            username = ?
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('Profile update prepare failed: ' . $conn->error);
        profile_fail($user_id, 'We could not update your profile. Please try again.');
    }

    $stmt->bind_param(
        'ssssi',
        $full_name,
        $email,
        $phone,
        $username,
        $user_id
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        error_log('Profile update failed for user ' . $user_id . ': ' . $error);
        profile_fail($user_id, 'We could not update your profile. Please try again.');
    }

    $stmt->close();

    /*
     * Keep session identity in sync immediately.
     */
    $_SESSION['username'] = $username;
    $_SESSION['full_name'] = $full_name;
    $_SESSION['email'] = $email;

    if (function_exists('log_action')) {
        try {
            log_action(
                $user_id,
                'Update Profile',
                'users',
                $user_id,
                'Updated own profile information'
            );
        } catch (Throwable $e) {
            error_log('Profile audit log failed: ' . $e->getMessage());
        }
    }

    profile_message($user_id, 'Profile updated successfully.', 'success');

    /*
     * Rotate token after successful state-changing action.
     */
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    profile_redirect();
}

/*
|--------------------------------------------------------------------------
| CHANGE PASSWORD
|--------------------------------------------------------------------------
*/

if (isset($_POST['change_password'])) {
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        profile_fail($user_id, 'All password fields are required.');
    }

    if (strlen($new_password) < 8) {
        profile_fail($user_id, 'New password must be at least 8 characters long.');
    }

    if ($new_password !== $confirm_password) {
        profile_fail($user_id, 'New password and confirmation password do not match.');
    }

    /*
     * The users table uses password_hash, not password.
     */
    $stmt = $conn->prepare("
        SELECT password_hash
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('Password lookup prepare failed: ' . $conn->error);
        profile_fail($user_id, 'We could not verify your current password.');
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $storedHash = (string)($row['password_hash'] ?? '');

    if ($storedHash === '' || !password_verify($current_password, $storedHash)) {
        profile_fail($user_id, 'Current password is incorrect.');
    }

    if (password_verify($new_password, $storedHash)) {
        profile_fail($user_id, 'New password must be different from your current password.');
    }

    $newHash = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE users
        SET password_hash = ?
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('Password update prepare failed: ' . $conn->error);
        profile_fail($user_id, 'We could not change your password. Please try again.');
    }

    $stmt->bind_param('si', $newHash, $user_id);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        error_log('Password update failed for user ' . $user_id . ': ' . $error);
        profile_fail($user_id, 'We could not change your password. Please try again.');
    }

    $stmt->close();

    if (function_exists('log_action')) {
        try {
            log_action(
                $user_id,
                'Change Password',
                'users',
                $user_id,
                'Changed own account password'
            );
        } catch (Throwable $e) {
            error_log('Password audit log failed: ' . $e->getMessage());
        }
    }

    profile_message($user_id, 'Password changed successfully.', 'success');

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    profile_redirect();
}

profile_fail($user_id, 'No valid profile action was received.');
