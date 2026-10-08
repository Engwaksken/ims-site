<?php
declare(strict_types=1);

if (!function_exists('task_generate_recurring_occurrences')) {
    /** Create today's occurrences for every matching recurring rule, exactly once. */
    function task_generate_recurring_occurrences(mysqli $conn, ?string $date = null): int
    {
        $date ??= date('Y-m-d');
        $weekday = (int)date('N', strtotime($date));
        $stmt = $conn->prepare(
            'SELECT task_id FROM employee_tasks
             WHERE is_recurring=1 AND status="Pending" AND task_date <= ?
               AND (recurrence_end_date IS NULL OR recurrence_end_date >= ?)
               AND FIND_IN_SET(?, recurrence_days) > 0'
        );
        if (!$stmt) return 0;
        $weekdayString = (string)$weekday;
        $stmt->bind_param('sss', $date, $date, $weekdayString);
        $stmt->execute();
        $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $insert = $conn->prepare(
            'INSERT IGNORE INTO employee_tasks
                (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,
                 task_date,status,recurrence_parent_id,reminder_at)
             SELECT title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,
                    ?,"Pending",task_id,IF(reminder_time IS NULL,NULL,CONCAT(?," ",reminder_time))
             FROM employee_tasks WHERE task_id=? AND is_recurring=1'
        );
        if (!$insert) return 0;
        $created = 0;
        foreach ($rules as $rule) {
            $ruleId = (int)$rule['task_id'];
            $insert->bind_param('ssi', $date, $date, $ruleId);
            if ($insert->execute()) $created += max(0, $insert->affected_rows);
        }
        $insert->close();
        return $created;
    }
}

if (!function_exists('task_dispatch_due_reminders')) {
    /** Send each due reminder once. Can be run lazily or from cron. */
    function task_dispatch_due_reminders(mysqli $conn, int $limit = 300): int
    {
        $limit = max(1, min(1000, $limit));
        $result = $conn->query(
            'SELECT task_id,assigned_to,title,task_date FROM employee_tasks
             WHERE reminder_at IS NOT NULL AND reminder_at <= NOW() AND reminder_sent_at IS NULL
               AND status IN ("Pending","In Progress")
             ORDER BY reminder_at ASC LIMIT ' . $limit
        );
        if (!$result) return 0;
        $claim = $conn->prepare(
            'UPDATE employee_tasks SET reminder_sent_at=NOW()
             WHERE task_id=? AND reminder_sent_at IS NULL AND status IN ("Pending","In Progress")'
        );
        if (!$claim) return 0;
        $sent = 0;
        while ($task = $result->fetch_assoc()) {
            $taskId = (int)$task['task_id'];
            $claim->bind_param('i', $taskId);
            $claim->execute();
            if ($claim->affected_rows !== 1) continue;
            if (function_exists('notify_user') && notify_user(
                (int)$task['assigned_to'],
                'Task reminder',
                (string)$task['title'] . ' · Due ' . (string)$task['task_date'],
                'warning',
                $taskId,
                'employee_task'
            )) $sent++;
        }
        $claim->close();
        $result->free();
        return $sent;
    }
}
