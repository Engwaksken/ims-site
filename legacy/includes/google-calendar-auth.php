<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role(IMS_ADMIN_ROLES);

try {
    if (!gcal_is_configured($conn)) {
        throw new RuntimeException(
            'Google Calendar configuration is incomplete or has not been confirmed.'
        );
    }

    $url = gcal_auth_url(
        $conn,
        (int)($_SESSION['user_id'] ?? 0)
    );

    header('Location: ' . $url);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] =
        'Could not start Google Calendar connection: '
        . $e->getMessage();

    header(
        'Location: ../settings.php?tab=google'
    );

    exit;
}
