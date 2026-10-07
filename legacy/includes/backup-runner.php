<?php
declare(strict_types=1);

/**
 * Automatic backup runner.
 *
 * Recommended cPanel cron:
 * Run this file from cPanel cron every 15 minutes.
 *
 * The runner decides whether a backup is due based on Settings > Backup.
 */

/*
 * Access control: this runner was reachable by anyone over the web and
 * writes full database dumps. Allow it only from the CLI (php backup-runner.php)
 * or, for URL-based cron (wget/curl), with ?key=<CRON_SECRET from .env>.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    require_once __DIR__ . '/security.php';

    $cronSecret = (string) ims_env('CRON_SECRET', '');
    $providedKey = (string) ($_GET['key'] ?? '');

    if ($cronSecret === '' || strlen($cronSecret) < 24 || !hash_equals($cronSecret, $providedKey)) {
        http_response_code(404);
        exit('Not Found');
    }

    header('Content-Type: text/plain; charset=UTF-8');

    if (!defined('STDERR')) {
        define('STDERR', fopen('php://output', 'w'));
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backup-service.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(
        STDERR,
        "Database connection not available.\n"
    );

    exit(1);
}

$conn->set_charset('utf8mb4');

try {
    if (!backup_is_due($conn)) {
        echo "No backup due.\n";
        exit(0);
    }

    $result = backup_run(
        $conn,
        0,
        'automatic'
    );

    echo
        "Backup completed: "
        . ($result['filename'] ?? 'unknown')
        . "\n";

    exit(0);
} catch (Throwable $e) {
    try {
        backup_setting_save(
            $conn,
            'backup_last_run_status',
            'Failed: ' . $e->getMessage(),
            0
        );
    } catch (Throwable $ignored) {
    }

    fwrite(
        STDERR,
        'Backup failed: '
        . $e->getMessage()
        . "\n"
    );

    exit(1);
}
