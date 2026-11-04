<?php
declare(strict_types=1);

/*
 * Sends queued IMS -> Hive NSP events (integration contract section 4.3).
 * Does nothing unless NSP_INTEGRATION_ENABLED=true and NSP_* is configured.
 * Retries after 1, 5, 15, 60 and 240 minutes, then every 12 hours; an event
 * is marked dead after 8 failed attempts (see the NSP integration admin page).
 *
 * cPanel cron, every minute:
 *   * * * * * /usr/local/bin/php /home/USER/ims.hivecolab.com/legacy/cron/nsp-outbox.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/nsp-integration.php';

if (!nsp_enabled()) {
    exit(0);
}

$conn = db_connect();
$stats = nsp_deliver_outbox($conn, 100);
echo sprintf(
    "[%s] nsp outbox: sent=%d retry=%d dead=%d%s\n",
    date('Y-m-d H:i:s'),
    $stats['sent'],
    $stats['failed'],
    $stats['dead'],
    $stats['skipped'] ? ' (skipped: locked or schema missing)' : ''
);
