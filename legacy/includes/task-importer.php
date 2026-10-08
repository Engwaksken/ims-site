<?php
declare(strict_types=1);

if (!function_exists('task_import_monday_spreadsheet')) {
    /** Import a Monday.com export or a compatible Excel/CSV task sheet. */
    function task_import_monday_spreadsheet(mysqli $conn, array $upload, int $createdBy): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'errors' => []];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new RuntimeException('Choose a valid Excel or CSV file to upload.');
        }
        if ((int)($upload['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new RuntimeException('The file is too large. The maximum upload size is 15 MB.');
        }
        $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx','xls','csv'], true)) {
            throw new RuntimeException('Use a .xlsx, .xls, or .csv export from Monday.com.');
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('Spreadsheet support is unavailable on this server. Install PhpSpreadsheet, then try again.');
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile((string)$upload['tmp_name']);
            $reader->setReadDataOnly(true);
            $book = $reader->load((string)$upload['tmp_name']);
            $sheet = $book->getActiveSheet();
            $rows = $sheet->toArray(null, false, false, false);
        } catch (Throwable $exception) {
            throw new RuntimeException('The spreadsheet could not be read. Export it as .xlsx or .csv and try again.');
        }

        $normalize = static fn($value): string => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', trim((string)$value)));
        $headerRow = null;
        $headerIndex = -1;
        $titleHeaders = ['task','taskname','tasktitle','name','item','itemname','title'];
        foreach ($rows as $index => $row) {
            $normalizedRow = array_map($normalize, $row);
            if (array_intersect($titleHeaders, $normalizedRow)) {
                $headerRow = $row;
                $headerIndex = $index;
                break;
            }
        }
        if ($headerRow === null) throw new RuntimeException('The spreadsheet is empty or has no task header. Add a Name, Task, or Task Name column.');

        $headers = [];
        foreach ($headerRow as $column => $value) {
            $key = $normalize($value);
            if ($key !== '') $headers[$key] = $column;
        }
        $columnFor = static function (array $aliases) use ($headers, $normalize): ?int {
            foreach ($aliases as $alias) {
                $key = $normalize($alias);
                if (array_key_exists($key, $headers)) return $headers[$key];
            }
            return null;
        };
        $columns = [
            'title' => $columnFor(['Task','Task Name','Task Title','Name','Item','Item Name','Title']),
            'details' => $columnFor(['Description','Details','Notes','Update','Task Notes']),
            'due' => $columnFor(['Due Date Time','Due Date','Deadline','Date','End Date','Timeline','Due']),
            'time' => $columnFor(['Due Time','Time']),
            'owner' => $columnFor(['Owner','Person','People','Assignee','Assignees','Assigned To','Responsible']),
            'status' => $columnFor(['Status']),
            'frequency' => $columnFor(['Frequency','Cadence']),
            'recurring' => $columnFor(['Recurring','Repeat','Is Recurring']),
            'days' => $columnFor(['Repeat On','Weekdays','Days','Days Of Week','Recurrence Days']),
            'end' => $columnFor(['Repeat Until','Recurrence End Date']),
            'reminder' => $columnFor(['Reminder Date Time','Reminder At','Reminder']),
            'reminderTime' => $columnFor(['Reminder Time']),
            'kpi' => $columnFor(['KPI','KPI Title','Linked KPI']),
            'kra' => $columnFor(['KRA','KRA Title','Linked KRA']),
        ];
        if ($columns['title'] === null) throw new RuntimeException('Could not find a task-name column. Include a header such as Name, Task, or Task Name.');

        $valueAt = static function (array $row, ?int $index): mixed {
            return $index !== null ? ($row[$index] ?? null) : null;
        };
        $parseDateTime = static function (mixed $value, mixed $timeValue = null, bool $defaultMorning = true): ?DateTimeImmutable {
            if ($value === null || trim((string)$value) === '') return null;
            try {
                if ($value instanceof DateTimeInterface) {
                    $dateTime = DateTimeImmutable::createFromInterface($value);
                } elseif (is_numeric($value)) {
                    $dateTime = DateTimeImmutable::createFromMutable(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value));
                    if ($defaultMorning && (float)$value === floor((float)$value)) $dateTime = $dateTime->setTime(9, 0);
                } else {
                    $text = trim((string)$value);
                    $timelineParts = preg_split('/\s+(?:-|to)\s+/i', $text);
                    if ($timelineParts && count($timelineParts) > 1) $text = trim((string)end($timelineParts));
                    $dateTime = new DateTimeImmutable($text);
                    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $text) && $defaultMorning) $dateTime = $dateTime->setTime(9, 0);
                }
                if ($timeValue !== null && trim((string)$timeValue) !== '') {
                    $clockText = trim((string)$timeValue);
                    if (is_numeric($timeValue)) {
                        $seconds = (int)round(((float)$timeValue - floor((float)$timeValue)) * 86400);
                        $clockText = sprintf('%02d:%02d', intdiv($seconds, 3600) % 24, intdiv($seconds % 3600, 60));
                    }
                    $clock = new DateTimeImmutable($clockText);
                    $dateTime = $dateTime->setTime((int)$clock->format('H'), (int)$clock->format('i'));
                }
                return $dateTime;
            } catch (Throwable $exception) {
                return null;
            }
        };
        $resolveAssignee = static function (mixed $value) use ($conn, $createdBy): ?int {
            $person = trim((string)$value);
            if ($person === '') return $createdBy;
            $person = trim(explode(';', $person)[0]);
            $sql = filter_var($person, FILTER_VALIDATE_EMAIL)
                ? 'SELECT user_id FROM users WHERE is_active=1 AND email=? AND role NOT IN ("Member","Applicant","Donor/Partner") LIMIT 1'
                : 'SELECT user_id FROM users WHERE is_active=1 AND LOWER(full_name)=LOWER(?) AND role NOT IN ("Member","Applicant","Donor/Partner") LIMIT 1';
            $stmt = $conn->prepare($sql);
            if (!$stmt) return null;
            $stmt->bind_param('s', $person); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row ? (int)$row['user_id'] : null;
        };
        $resolveKpi = static function (string $kpiTitle, string $kraTitle, int $ownerId) use ($conn): array|null {
            if ($kpiTitle === '') return ['source' => null, 'id' => null, 'kra' => null];
            if ($kraTitle !== '') {
                $stmt = $conn->prepare('SELECT ak.kpi_id,ak.kra_id FROM appraisal_kpis ak JOIN appraisal_kras kr ON kr.kra_id=ak.kra_id JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.user_id=? AND LOWER(ak.title)=LOWER(?) AND LOWER(kr.title)=LOWER(?) LIMIT 1');
                $stmt->bind_param('iss', $ownerId, $kpiTitle, $kraTitle); $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
                return $row ? ['source' => 'appraisal', 'id' => (int)$row['kpi_id'], 'kra' => (int)$row['kra_id']] : null;
            }
            $stmt = $conn->prepare('SELECT kpi_id FROM kpis WHERE user_id=? AND LOWER(kpi_title)=LOWER(?) LIMIT 1');
            $stmt->bind_param('is', $ownerId, $kpiTitle); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($row) return ['source' => 'employee', 'id' => (int)$row['kpi_id'], 'kra' => null];
            $stmt = $conn->prepare('SELECT ak.kpi_id,ak.kra_id FROM appraisal_kpis ak JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.user_id=? AND LOWER(ak.title)=LOWER(?) LIMIT 1');
            $stmt->bind_param('is', $ownerId, $kpiTitle); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            return $row ? ['source' => 'appraisal', 'id' => (int)$row['kpi_id'], 'kra' => (int)$row['kra_id']] : null;
        };

        $weekdayNumbers = ['mon'=>1,'monday'=>1,'tue'=>2,'tues'=>2,'tuesday'=>2,'wed'=>3,'wednesday'=>3,'thu'=>4,'thur'=>4,'thurs'=>4,'thursday'=>4,'fri'=>5,'friday'=>5,'sat'=>6,'saturday'=>6,'sun'=>7,'sunday'=>7];
        $insert = $conn->prepare('INSERT INTO employee_tasks (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,task_date,task_due_at,status,is_recurring,recurrence_days,recurrence_end_date,reminder_at,reminder_time) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if (!$insert) throw new RuntimeException('Could not prepare the task import: ' . $conn->error);

        $dataRows = array_slice($rows, $headerIndex + 1);
        if (count($dataRows) > 2000) throw new RuntimeException('The import is limited to 2,000 task rows at a time.');
        $assignedNotifications = [];
        $conn->begin_transaction();
        try {
            foreach ($dataRows as $offset => $row) {
                if (trim((string)$valueAt($row, $columns['title'])) === '') continue;
                $rowNumber = $headerIndex + $offset + 2;
                $title = trim((string)$valueAt($row, $columns['title']));
                $details = trim((string)$valueAt($row, $columns['details']));
                $ownerId = $resolveAssignee($valueAt($row, $columns['owner']));
                $due = $parseDateTime($valueAt($row, $columns['due']), $valueAt($row, $columns['time']));
                if ($ownerId === null || $due === null || strlen($title) > 240) {
                    $summary['skipped']++;
                    if (count($summary['errors']) < 12) $summary['errors'][] = 'Row ' . $rowNumber . ': missing or unrecognized task name, assignee, or due date/time.';
                    continue;
                }
                $statusText = strtolower(trim((string)$valueAt($row, $columns['status'])));
                $status = in_array($statusText, ['done','complete','completed','closed'], true) ? 'Completed'
                    : (in_array($statusText, ['working on it','in progress','started','doing'], true) ? 'In Progress' : 'Pending');
                $frequencyText = strtolower(trim((string)$valueAt($row, $columns['frequency'])));
                $frequency = str_contains($frequencyText, 'week') ? 'Weekly' : 'Daily';
                $daysText = strtolower(trim((string)$valueAt($row, $columns['days'])));
                $recurringText = strtolower(trim((string)$valueAt($row, $columns['recurring'])));
                $isRecurring = in_array($recurringText, ['1','yes','true','y','recurring'], true) || $daysText !== '';
                $days = [];
                if ($daysText !== '') {
                    foreach (preg_split('/[,;|]+/', $daysText) ?: [] as $dayText) {
                        $key = trim($dayText);
                        if (isset($weekdayNumbers[$key])) $days[] = $weekdayNumbers[$key];
                        elseif (preg_match('/\b(mon|tue|wed|thu|fri|sat|sun)\b/', $key, $match)) $days[] = $weekdayNumbers[$match[1]];
                    }
                }
                $days = array_values(array_unique($days)); sort($days);
                if ($isRecurring && !$days) {
                    $summary['skipped']++;
                    if (count($summary['errors']) < 12) $summary['errors'][] = 'Row ' . $rowNumber . ': recurring tasks need weekday names in a Repeat On or Weekdays column.';
                    continue;
                }
                $kpiTitle = trim((string)$valueAt($row, $columns['kpi']));
                $kraTitle = trim((string)$valueAt($row, $columns['kra']));
                $kpi = $resolveKpi($kpiTitle, $kraTitle, $ownerId);
                if ($kpi === null) {
                    $summary['skipped']++;
                    if (count($summary['errors']) < 12) $summary['errors'][] = 'Row ' . $rowNumber . ': the linked KPI/KRA was not found for the assignee.';
                    continue;
                }
                $endValue = $valueAt($row, $columns['end']);
                $endDateTime = $endValue !== null && trim((string)$endValue) !== '' ? $parseDateTime($endValue) : null;
                if ($isRecurring && $endValue !== null && trim((string)$endValue) !== '' && $endDateTime === null) {
                    $summary['skipped']++;
                    if (count($summary['errors']) < 12) $summary['errors'][] = 'Row ' . $rowNumber . ': invalid recurrence end date.';
                    continue;
                }
                $reminderValue = $valueAt($row, $columns['reminder']);
                $reminderTimeValue = $valueAt($row, $columns['reminderTime']);
                $reminderDateTime = $reminderValue !== null && trim((string)$reminderValue) !== ''
                    ? $parseDateTime($reminderValue, $reminderTimeValue, false)
                    : ($reminderTimeValue !== null && trim((string)$reminderTimeValue) !== '' ? $parseDateTime($due->format('Y-m-d'), $reminderTimeValue, false) : null);
                $reminderTime = $isRecurring && $reminderDateTime ? $reminderDateTime->format('H:i:s') : null;
                $reminderAt = !$isRecurring && $reminderDateTime ? $reminderDateTime->format('Y-m-d H:i:s') : null;
                $taskDate = $due->format('Y-m-d');
                $taskDueAt = $due->format('Y-m-d H:i:s');
                $recurrenceDays = $isRecurring ? implode(',', $days) : null;
                $recurrenceEndDate = $endDateTime ? $endDateTime->format('Y-m-d') : null;
                $taskFrequency = $frequency;
                $kpiSource = $kpi['source']; $kpiId = $kpi['id']; $kraId = $kpi['kra'];
                $insert->bind_param('ssiisiissssissss', $title, $details, $ownerId, $createdBy, $kpiSource, $kpiId, $kraId, $taskFrequency, $taskDate, $taskDueAt, $status, $isRecurring, $recurrenceDays, $recurrenceEndDate, $reminderAt, $reminderTime);
                if ($insert->execute()) {
                    $summary['created']++;
                    $taskId = (int)$conn->insert_id;
                    if ($ownerId !== $createdBy) $assignedNotifications[] = [$ownerId, $title, $taskDueAt, $taskId];
                } else {
                    $summary['skipped']++;
                    if (count($summary['errors']) < 12) $summary['errors'][] = 'Row ' . $rowNumber . ': ' . $insert->error;
                }
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $insert->close();
            throw new RuntimeException('Task import failed: ' . $exception->getMessage());
        }
        $insert->close();
        foreach ($assignedNotifications as [$ownerId, $title, $taskDueAt, $taskId]) {
            notify_user($ownerId, 'A task was assigned to you', $title . ' · Due ' . $taskDueAt, 'info', $taskId, 'employee_task');
        }
        task_generate_recurring_occurrences($conn);
        return $summary;
    }
}
