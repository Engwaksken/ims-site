<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role(IMS_ADMIN_ROLES);

$userId = (int)($_SESSION['user_id'] ?? 0);

foreach ([
    'google_access_token',
    'google_refresh_token',
    'google_access_token_expires_at',
] as $key) {
    gcal_setting_save(
        $conn,
        $key,
        '',
        $userId
    );
}

gcal_setting_save(
    $conn,
    'google_calendar_connection_status',
    'Disconnected',
    $userId
);

gcal_setting_save(
    $conn,
    'google_calendar_connected_at',
    '',
    $userId
);

$_SESSION['success'] =
    'Google Calendar disconnected successfully.';

header(
    'Location: ../settings?tab=google'
);

exit;
