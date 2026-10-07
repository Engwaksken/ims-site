<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cron: carry pending daily tasks over to the next day
|--------------------------------------------------------------------------
|
| Schedule shortly after midnight (Africa/Kampala), e.g.:
|
|   5 0 * * * /usr/local/bin/php /home/USER/ims/legacy/cron/carry-over-pending-tasks.php >> /home/USER/ims/storage/logs/carryover.log 2>&1
|
| Options:
|   --date=YYYY-MM-DD   carry tasks over to this date instead of today
|
| CLI only. The activities calendar also runs this lazily once per day,
| so it still works if cron is not configured.
|
*/

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/task-carryover.php';

$options = getopt('', ['date::']);
$date = isset($options['date']) && is_string($options['date']) ? $options['date'] : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
    fwrite(STDERR, "Invalid --date, expected YYYY-MM-DD\n");
    exit(1);
}

$conn = db_connect();

$result = task_carryover_run($conn, 'cron', $date);

// Tell the lazy fallback that today's run already happened.
if (!$result['skipped'] && $date === date('Y-m-d')) {
    task_carryover_mark_ran($conn, $date);
}

echo sprintf(
    "[%s] carry-over to %s: checked=%d moved=%d%s\n",
    date('Y-m-d H:i:s'),
    $result['date'],
    $result['checked'],
    $result['moved'],
    $result['skipped'] ? ' skipped (' . $result['reason'] . ')' : ''
);

exit($result['skipped'] && str_starts_with($result['reason'], 'error') ? 1 : 0);
