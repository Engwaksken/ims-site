<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google-calendar-service.php';

check_role(IMS_PROGRAMME_ROLES);

function clean_post(string $key): string {
    return sanitize_input((string)($_POST[$key] ?? ''));
}

function redirect_events(string $query = ''): never {
    $url = '../events.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header("Location: {$url}");
    exit();
}

function notify_and_redirect(string $message, string $type = 'danger', string $query = ''): never {
    send_notification($_SESSION['user_id'] ?? 0, $message, $type);
    redirect_events($query);
}

function nullable_time(string $key): ?string {
    $value = trim((string)($_POST[$key] ?? ''));
    return $value !== '' ? sanitize_input($value) : null;
}

function nullable_int(string $key): ?int {
    $value = trim((string)($_POST[$key] ?? ''));
    return $value !== '' && (int)$value > 0 ? (int)$value : null;
}

function get_event_days(): array {
    $days = $_POST['event_days'] ?? [];

    if (!is_array($days)) {
        $days = [];
    }

    $cleanDays = [];

    foreach ($days as $day) {
        $day = trim((string)$day);

        if ($day === '') {
            continue;
        }

        if (!strtotime($day)) {
            continue;
        }

        $cleanDays[] = date('Y-m-d', strtotime($day));
    }

    $cleanDays = array_values(array_unique($cleanDays));
    sort($cleanDays);

    return $cleanDays;
}

function get_event_links(): array {
    $link_type = sanitize_input((string)($_POST['link_type'] ?? 'none'));

    $project_id = null;
    $program_id = null;

    if ($link_type === 'project') {
        $project_id = nullable_int('project_id');
    } elseif ($link_type === 'program') {
        $program_id = nullable_int('program_id');
    }

    return [$project_id, $program_id];
}

function validate_common_event_data(
    string $event_title,
    string $event_type,
    array $event_days,
    string $event_status,
    ?string $start_time,
    ?string $end_time,
    string $query = ''
): void {
    if ($event_title === '' || $event_type === '' || empty($event_days) || $event_status === '') {
        notify_and_redirect('Event title, type, event day, and status are required.', 'danger', $query);
    }

    $allowed_statuses = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
    if (!in_array($event_status, $allowed_statuses, true)) {
        notify_and_redirect('Invalid event status selected.', 'danger', $query);
    }

    foreach ($event_days as $day) {
        $event_timestamp = strtotime($day);

        if (!$event_timestamp) {
            notify_and_redirect('Invalid event day selected.', 'danger', $query);
        }

        if ($event_timestamp < strtotime('-1 year')) {
            notify_and_redirect('Event day cannot be more than 1 year in the past.', 'danger', $query);
        }
    }

    if ($start_time && $end_time && strtotime($end_time) <= strtotime($start_time)) {
        notify_and_redirect('End time must be after start time.', 'danger', $query);
    }
}

/* =========================
   ADD EVENT
========================= */
if (isset($_POST['add_event'])) {
    $event_title = clean_post('event_title');
    $event_type  = clean_post('event_type');

    $event_days = get_event_days();
    $event_date = $event_days[0] ?? '';

    $event_days_json = json_encode($event_days, JSON_UNESCAPED_SLASHES);

    $start_time = nullable_time('start_time');
    $end_time   = nullable_time('end_time');

    $venue                 = clean_post('venue');
    [$project_id, $program_id] = get_event_links();
    $organizer             = clean_post('organizer');
    $expected_participants = nullable_int('expected_participants');
    $description           = clean_post('description');

    $event_status = sanitize_input((string)($_POST['event_status'] ?? $_POST['status'] ?? 'Planned'));

    validate_common_event_data(
        $event_title,
        $event_type,
        $event_days,
        $event_status,
        $start_time,
        $end_time
    );

    $stmt = $conn->prepare("
        INSERT INTO hub_events (
            event_title,
            event_type,
            event_date,
            event_days,
            start_time,
            end_time,
            venue,
            project_id,
            program_id,
            organizer,
            expected_participants,
            description,
            event_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare add event query.');
    }

    $stmt->bind_param(
        "sssssssiiisss",
        $event_title,
        $event_type,
        $event_date,
        $event_days_json,
        $start_time,
        $end_time,
        $venue,
        $project_id,
        $program_id,
        $organizer,
        $expected_participants,
        $description,
        $event_status
    );

    if ($stmt->execute()) {
        $new_event_id = (int)$stmt->insert_id;

        log_action(
            $_SESSION['user_id'] ?? 0,
            'Add Event',
            'events',
            $new_event_id,
            "Added event: {$event_title}"
        );

        if (gcal_is_connected($conn)) {
            try {
                gcal_sync_hub_event(
                    $conn,
                    $new_event_id,
                    (int)($_SESSION['user_id'] ?? 0)
                );
            } catch (Throwable $syncError) {
                gcal_mark_hub_event_error(
                    $conn,
                    $new_event_id,
                    $syncError->getMessage()
                );

                error_log(
                    'Google event sync failed after add: '
                    . $syncError->getMessage()
                );
            }
        }

        send_notification(
            $_SESSION['user_id'] ?? 0,
            gcal_is_connected($conn)
                ? 'Event added successfully and sent to Google Calendar.'
                : 'Event added successfully.',
            'success'
        );
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error adding event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

/* =========================
   EDIT EVENT
========================= */
if (isset($_POST['edit_event'])) {
    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($event_id <= 0) {
        notify_and_redirect('Invalid event selected.');
    }

    $event_title = clean_post('event_title');
    $event_type  = clean_post('event_type');

    $event_days = get_event_days();
    $event_date = $event_days[0] ?? '';

    $event_days_json = json_encode($event_days, JSON_UNESCAPED_SLASHES);

    $start_time = nullable_time('start_time');
    $end_time   = nullable_time('end_time');

    $venue                 = clean_post('venue');
    [$project_id, $program_id] = get_event_links();
    $organizer             = clean_post('organizer');
    $expected_participants = nullable_int('expected_participants');
    $description           = clean_post('description');

    $event_status = sanitize_input((string)($_POST['event_status'] ?? $_POST['status'] ?? ''));

    validate_common_event_data(
        $event_title,
        $event_type,
        $event_days,
        $event_status,
        $start_time,
        $end_time,
        'edit=' . $event_id
    );

    $stmt = $conn->prepare("
        UPDATE hub_events SET
            event_title = ?,
            event_type = ?,
            event_date = ?,
            event_days = ?,
            start_time = ?,
            end_time = ?,
            venue = ?,
            project_id = ?,
            program_id = ?,
            organizer = ?,
            expected_participants = ?,
            description = ?,
            event_status = ?
        WHERE event_id = ?
    ");

    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare update event query.', 'danger', 'edit=' . $event_id);
    }

    $stmt->bind_param(
        "sssssssiiisssi",
        $event_title,
        $event_type,
        $event_date,
        $event_days_json,
        $start_time,
        $end_time,
        $venue,
        $project_id,
        $program_id,
        $organizer,
        $expected_participants,
        $description,
        $event_status,
        $event_id
    );

    if ($stmt->execute()) {
        log_action(
            $_SESSION['user_id'] ?? 0,
            'Edit Event',
            'events',
            $event_id,
            "Updated event: {$event_title}"
        );

        if (gcal_is_connected($conn)) {
            try {
                gcal_sync_hub_event(
                    $conn,
                    $event_id,
                    (int)($_SESSION['user_id'] ?? 0)
                );
            } catch (Throwable $syncError) {
                gcal_mark_hub_event_error(
                    $conn,
                    $event_id,
                    $syncError->getMessage()
                );

                error_log(
                    'Google event sync failed after edit: '
                    . $syncError->getMessage()
                );
            }
        }

        send_notification(
            $_SESSION['user_id'] ?? 0,
            gcal_is_connected($conn)
                ? 'Event updated successfully and Google Calendar was updated.'
                : 'Event updated successfully.',
            'success'
        );
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error updating event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

/* =========================
   DELETE EVENT
========================= */
if (isset($_POST['delete_event'])) {
    if (!in_array($_SESSION['role'] ?? '', ['Administrator', 'Operations/Admin'], true)) {
        notify_and_redirect('You are not allowed to delete events.');
    }

    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($event_id <= 0) {
        notify_and_redirect('Invalid event selected.');
    }

    $event_name = 'Unknown';
    $google_event_id = '';

    $stmt = $conn->prepare("SELECT event_title, google_event_id FROM hub_events WHERE event_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $event_id);
        $stmt->execute();

        $event_result = $stmt->get_result();
        if ($event_result && $event_result->num_rows > 0) {
            $event_data = $event_result->fetch_assoc();
            $event_name = $event_data['event_title'] ?? 'Unknown';
            $google_event_id = (string)($event_data['google_event_id'] ?? '');
        }

        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM event_registrations WHERE event_id = ?");
    if (!$stmt) {
        notify_and_redirect('Database error: failed to check attendance records.');
    }

    $stmt->bind_param("i", $event_id);
    $stmt->execute();

    $check_result = $stmt->get_result();
    $count = (int)($check_result->fetch_assoc()['total'] ?? 0);

    $stmt->close();

    if ($count > 0) {
        notify_and_redirect("Cannot delete event. It has {$count} attendance record(s). Delete attendance records first.");
    }

    if ($google_event_id !== '' && gcal_is_connected($conn)) {
        try {
            gcal_delete_google_event(
                $conn,
                $google_event_id,
                (int)($_SESSION['user_id'] ?? 0)
            );
        } catch (Throwable $syncError) {
            error_log(
                'Google event delete failed: '
                . $syncError->getMessage()
            );
        }
    }

    $stmt = $conn->prepare("DELETE FROM hub_events WHERE event_id = ?");
    if (!$stmt) {
        notify_and_redirect('Database error: failed to prepare delete event query.');
    }

    $stmt->bind_param("i", $event_id);

    if ($stmt->execute()) {
        log_action(
            $_SESSION['user_id'] ?? 0,
            'Delete Event',
            'events',
            $event_id,
            "Deleted event: {$event_name}"
        );

        send_notification($_SESSION['user_id'] ?? 0, 'Event deleted successfully.', 'success');
    } else {
        send_notification($_SESSION['user_id'] ?? 0, 'Error deleting event: ' . $stmt->error, 'danger');
    }

    $stmt->close();
    redirect_events();
}

redirect_events();