<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role(IMS_STAFF_ROLES);

$type = trim(
    (string)($_GET['type'] ?? 'event')
);

$id = max(
    0,
    (int)($_GET['id'] ?? 0)
);

$returnTo = trim(
    (string)($_GET['return'] ?? '')
);

if (
    $returnTo === ''
    ||
    str_contains($returnTo, '://')
    ||
    str_starts_with($returnTo, '//')
) {
    $returnTo =
        $type === 'activity'
            ? '../activities-calendar'
            : '../events';
}

if ($id < 1) {
    $_SESSION['error'] =
        'Invalid record selected for Google Calendar sync.';

    header(
        'Location: ' . $returnTo
    );

    exit;
}

try {
    if (!gcal_is_connected($conn)) {
        throw new RuntimeException(
            'Google Calendar is not connected. Ask an Administrator to connect it from Settings.'
        );
    }

    if ($type === 'activity') {
        gcal_sync_activity(
            $conn,
            $id,
            (int)($_SESSION['user_id'] ?? 0)
        );

        $_SESSION['success'] =
            'Activity synced to Google Calendar successfully.';
    } else {
        gcal_sync_hub_event(
            $conn,
            $id,
            (int)($_SESSION['user_id'] ?? 0)
        );

        $_SESSION['success'] =
            'Event synced to Google Calendar successfully.';
    }
} catch (Throwable $e) {
    if ($type === 'activity') {
        gcal_mark_activity_error(
            $conn,
            $id,
            $e->getMessage()
        );
    } else {
        gcal_mark_hub_event_error(
            $conn,
            $id,
            $e->getMessage()
        );
    }

    $_SESSION['error'] =
        'Google Calendar sync failed: '
        . $e->getMessage();
}

header(
    'Location: ' . $returnTo
);

exit;
