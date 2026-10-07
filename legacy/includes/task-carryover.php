<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Daily task carry-over
|--------------------------------------------------------------------------
|
| Tasks are workplan_deliverables. A task with calendar_frequency = 'Daily'
| that is still Pending / In Progress / Delayed after its end_date is rolled
| forward to "today" (the next day when run just after midnight).
|
| History is kept: the first original dates are stored in
| original_start_date / original_end_date, carried_over / carried_over_count
| / last_carried_over_at are updated, and each move is written to
| task_carryover_log.
|
| Requires database/migrations/2026_10_07_task_carryover.sql. If the columns
| are missing the functions do nothing (no errors, no silent overwrites).
|
| Entry points:
|   - legacy/cron/carry-over-pending-tasks.php   (CLI cron)
|   - task_carryover_maybe_run($conn)             (lazy, once per day, from
|                                                  the task pages)
|
*/

if (!defined('TASK_CARRYOVER_STATUSES')) {
    define('TASK_CARRYOVER_STATUSES', ['Pending', 'In Progress', 'Delayed']);
}

if (!function_exists('task_carryover_column_exists')) {
    function task_carryover_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );

        if (!$stmt) {
            return $cache[$key] = false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$key] = $exists;
    }
}

if (!function_exists('task_carryover_table_exists')) {
    function task_carryover_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];

        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );

        if (!$stmt) {
            return $cache[$table] = false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$table] = $exists;
    }
}

if (!function_exists('task_carryover_supported')) {
    /** True when the migration has been applied. */
    function task_carryover_supported(mysqli $conn): bool
    {
        foreach (['calendar_frequency', 'original_start_date', 'original_end_date', 'carried_over', 'carried_over_count', 'last_carried_over_at'] as $column) {
            if (!task_carryover_column_exists($conn, 'workplan_deliverables', $column)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('task_carryover_run')) {
    /**
     * Roll pending daily tasks forward to $today.
     *
     * @return array{moved:int, checked:int, skipped:bool, reason:string, date:string}
     */
    function task_carryover_run(mysqli $conn, string $source = 'cron', ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $result = ['moved' => 0, 'checked' => 0, 'skipped' => false, 'reason' => '', 'date' => $today];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return ['skipped' => true, 'reason' => 'invalid date'] + $result;
        }

        if (!task_carryover_supported($conn)) {
            return ['skipped' => true, 'reason' => 'migration 2026_10_07_task_carryover.sql not applied'] + $result;
        }

        // Only one carry-over at a time (cron + lazy page loads).
        $lock = $conn->query("SELECT GET_LOCK('ims_task_carryover', 5) AS l");
        $locked = $lock ? (int) ($lock->fetch_assoc()['l'] ?? 0) === 1 : false;

        if (!$locked) {
            return ['skipped' => true, 'reason' => 'another carry-over is running'] + $result;
        }

        $hasLog = task_carryover_table_exists($conn, 'task_carryover_log');
        $source = substr(preg_replace('/[^a-z_-]/i', '', $source) ?: 'cron', 0, 20);

        try {
            $statuses = TASK_CARRYOVER_STATUSES;
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));

            $select = $conn->prepare(
                "SELECT id, start_date, end_date, status
                 FROM workplan_deliverables
                 WHERE calendar_frequency = 'Daily'
                   AND status IN ($placeholders)
                   AND end_date IS NOT NULL
                   AND end_date < ?
                 ORDER BY id"
            );

            if (!$select) {
                throw new RuntimeException('select prepare failed: ' . $conn->error);
            }

            $types = str_repeat('s', count($statuses)) . 's';
            $params = array_merge($statuses, [$today]);
            $select->bind_param($types, ...$params);
            $select->execute();
            $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);
            $select->close();

            $result['checked'] = count($rows);

            if (!$rows) {
                return $result;
            }

            $update = $conn->prepare(
                "UPDATE workplan_deliverables
                 SET original_start_date = COALESCE(original_start_date, start_date),
                     original_end_date   = COALESCE(original_end_date, end_date),
                     start_date          = ?,
                     end_date            = ?,
                     carried_over        = 1,
                     carried_over_count  = carried_over_count + 1,
                     last_carried_over_at = NOW()
                 WHERE id = ?
                   AND end_date < ?
                   AND calendar_frequency = 'Daily'"
            );

            $log = $hasLog ? $conn->prepare(
                "INSERT INTO task_carryover_log
                    (deliverable_id, from_start_date, from_end_date, to_start_date, to_end_date, status_at_move, trigger_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            ) : null;

            if (!$update) {
                throw new RuntimeException('update prepare failed: ' . $conn->error);
            }

            $conn->begin_transaction();

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $fromStart = $row['start_date'] !== null ? (string) $row['start_date'] : null;
                $fromEnd = (string) $row['end_date'];

                // A one-day task moves entirely; a multi-day task keeps its
                // start date and has its due date extended to today.
                $toStart = ($fromStart === null || $fromStart === $fromEnd || $fromStart > $today) ? $today : $fromStart;
                $toEnd = $today;

                $update->bind_param('ssis', $toStart, $toEnd, $id, $today);
                $update->execute();

                if ($update->affected_rows < 1) {
                    continue;
                }

                $result['moved']++;

                if ($log) {
                    $status = (string) $row['status'];
                    $log->bind_param('issssss', $id, $fromStart, $fromEnd, $toStart, $toEnd, $status, $source);
                    $log->execute();
                }
            }

            $conn->commit();
            $update->close();
            $log?->close();

            if ($result['moved'] > 0 && function_exists('log_action')) {
                log_action(null, 'Task Carry-over', 'workplan_deliverables', null,
                    "Carried {$result['moved']} pending daily task(s) over to {$today} ({$source})");
            }
        } catch (Throwable $exception) {
            @$conn->rollback();
            error_log('[task-carryover] ' . $exception->getMessage());

            return ['skipped' => true, 'reason' => 'error: ' . $exception->getMessage()] + $result;
        } finally {
            $conn->query("SELECT RELEASE_LOCK('ims_task_carryover')");
        }

        return $result;
    }
}

if (!function_exists('task_carryover_mark_ran')) {
    /** Record that the carry-over ran for $today. Returns true if this call claimed the day. */
    function task_carryover_mark_ran(mysqli $conn, string $today): bool
    {
        $key = 'task_carryover_last_run';

        $stmt = $conn->prepare(
            "UPDATE system_settings SET setting_value = ?
             WHERE setting_key = ? AND (setting_value IS NULL OR setting_value <> ?)"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sss', $today, $key, $today);
        $stmt->execute();
        $claimed = $stmt->affected_rows > 0;
        $stmt->close();

        if ($claimed) {
            return true;
        }

        // Row missing? create it (first run ever).
        $check = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");

        if (!$check) {
            return false;
        }

        $check->bind_param('s', $key);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if ($row) {
            return false; // already ran today
        }

        $insert = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value, description)
             VALUES (?, ?, 'Last date the daily task carry-over ran')"
        );

        if (!$insert) {
            return false;
        }

        $insert->bind_param('ss', $key, $today);
        $ok = $insert->execute();
        $insert->close();

        return $ok;
    }
}

if (!function_exists('task_carryover_maybe_run')) {
    /**
     * Lazy fallback: run the carry-over at most once per day from a page
     * load, so it works even when no cron job is configured. Never throws.
     */
    function task_carryover_maybe_run(mysqli $conn): ?array
    {
        try {
            if (!task_carryover_supported($conn)) {
                return null;
            }

            $today = date('Y-m-d');

            if (!task_carryover_mark_ran($conn, $today)) {
                return null;
            }

            return task_carryover_run($conn, 'page', $today);
        } catch (Throwable $exception) {
            error_log('[task-carryover] lazy run failed: ' . $exception->getMessage());

            return null;
        }
    }
}
