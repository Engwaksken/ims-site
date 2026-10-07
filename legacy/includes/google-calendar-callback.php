<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

check_role(IMS_ADMIN_ROLES);

$state = trim(
    (string)($_GET['state'] ?? '')
);

$expected = trim(
    (string)(
        $_SESSION['google_calendar_oauth_state']
        ?? ''
    )
);

if (
    $state === ''
    ||
    $expected === ''
    ||
    !hash_equals(
        $expected,
        $state
    )
) {
    $_SESSION['error'] =
        'Google Calendar connection failed because the OAuth state could not be verified.';

    header(
        'Location: ../settings.php?tab=google'
    );

    exit;
}

unset(
    $_SESSION['google_calendar_oauth_state']
);

if (!empty($_GET['error'])) {
    $_SESSION['error'] =
        'Google Calendar authorization was not completed: '
        . (string)$_GET['error'];

    header(
        'Location: ../settings.php?tab=google'
    );

    exit;
}

$code = trim(
    (string)($_GET['code'] ?? '')
);

if ($code === '') {
    $_SESSION['error'] =
        'Google Calendar did not return an authorization code.';

    header(
        'Location: ../settings.php?tab=google'
    );

    exit;
}

try {
    gcal_exchange_code(
        $conn,
        $code,
        (int)($_SESSION['user_id'] ?? 0)
    );

    $_SESSION['success'] =
        'Google Calendar connected successfully.';
} catch (Throwable $e) {
    error_log(
        'Google Calendar callback failed: '
        . $e->getMessage()
    );

    $_SESSION['error'] =
        'Google Calendar connection failed: '
        . $e->getMessage();
}

header(
    'Location: ../settings.php?tab=google'
);

exit;
