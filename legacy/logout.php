<?php
require_once __DIR__ . '/includes/config.php';

if (isset($_SESSION['user_id'])) {
    log_action($_SESSION['user_id'], 'Logout', 'users', $_SESSION['user_id'], 'User logged out');
}

// Destroy session data, the session cookie and the server-side session
$_SESSION = [];

if (ini_get('session.use_cookies') && !headers_sent()) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $cookie['path'],
        'domain'   => $cookie['domain'],
        'secure'   => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

// Redirect to login
header("Location: login");
exit();
?>