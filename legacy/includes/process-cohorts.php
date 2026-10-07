<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config.php';
//require_once __DIR__ . '/auth.php';

check_role([
    'Administrator',
    'Programs Lead',
    'MEAL Lead',
    'Project Officer',
    'Program Director',
    'Program Manager',
]);

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('cohorts_redirect')) {
    function cohorts_redirect(): never
    {
        header('Location: /cohorts');
        exit;
    }
}

if (!function_exists('cohorts_flash')) {
    function cohorts_flash(string $type, string $message): void
    {
        if (function_exists('set_flash')) {
            set_flash('cohorts', $type, $message);
            return;
        }

        $_SESSION['flash']['cohorts'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('cohorts_slugify')) {
    function cohorts_slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

if (!function_exists('cohorts_nullable_string')) {
    function cohorts_nullable_string(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

if (!function_exists('cohorts_valid_date')) {
    function cohorts_valid_date(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = (string) ($_POST['csrf_token'] ?? '');

if (
    $sessionToken === ''
    || $requestToken === ''
    || !hash_equals($sessionToken, $requestToken)
) {
    cohorts_flash(
        'error',
        'Your session expired. Refresh the page and try again.'
    );

    cohorts_redirect();
}

$action = trim((string) ($_POST['action'] ?? ''));

try {
    /*
    |--------------------------------------------------------------------------
    | Delete cohort
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {
        $id = filter_input(
            INPUT_POST,
            'id',
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id === null || $id < 1) {
            throw new RuntimeException(
                'Invalid cohort selected.'
            );
        }

        $existsStmt = $conn->prepare(
            'SELECT id FROM cohorts WHERE id = ? LIMIT 1'
        );

        if (!$existsStmt) {
            throw new RuntimeException(
                'Unable to verify the selected cohort.'
            );
        }

        $existsStmt->bind_param('i', $id);
        $existsStmt->execute();
        $existsStmt->store_result();
        $cohortExists = $existsStmt->num_rows > 0;
        $existsStmt->close();

        if (!$cohortExists) {
            throw new RuntimeException(
                'The selected cohort was not found.'
            );
        }

        $countStmt = $conn->prepare(
            'SELECT COUNT(application_id)
             FROM applications
             WHERE cohort_id = ?'
        );

        if (!$countStmt) {
            throw new RuntimeException(
                'Unable to verify cohort applications.'
            );
        }

        $countStmt->bind_param('i', $id);

        if (!$countStmt->execute()) {
            $countStmt->close();

            throw new RuntimeException(
                'Unable to verify cohort applications.'
            );
        }

        $countStmt->bind_result($applicationCount);
        $countStmt->fetch();
        $countStmt->close();

        if ((int) $applicationCount > 0) {
            throw new RuntimeException(
                'This cohort has applications. Move them to another cohort before deleting it.'
            );
        }

        $deleteStmt = $conn->prepare(
            'DELETE FROM cohorts WHERE id = ? LIMIT 1'
        );

        if (!$deleteStmt) {
            throw new RuntimeException(
                'Unable to prepare cohort deletion.'
            );
        }

        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            $deleteStmt->close();

            throw new RuntimeException(
                'The cohort could not be deleted.'
            );
        }

        $deletedRows = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($deletedRows < 1) {
            throw new RuntimeException(
                'The selected cohort was not found.'
            );
        }

        cohorts_flash(
            'success',
            'Cohort deleted successfully.'
        );

        cohorts_redirect();
    }

    /*
    |--------------------------------------------------------------------------
    | Create or update cohort
    |--------------------------------------------------------------------------
    */

    if ($action !== 'save') {
        throw new RuntimeException(
            'Unsupported cohort action.'
        );
    }

    $id = max(0, (int) ($_POST['id'] ?? 0));
    $name = trim((string) ($_POST['name'] ?? ''));

    $slug = cohorts_slugify(
        (string) ($_POST['slug'] ?? '')
    );

    $tagline = cohorts_nullable_string(
        $_POST['tagline'] ?? null
    );

    $description = cohorts_nullable_string(
        $_POST['description'] ?? null
    );

    $startDate = cohorts_nullable_string(
        $_POST['start_date'] ?? null
    );

    $endDate = cohorts_nullable_string(
        $_POST['end_date'] ?? null
    );

    $applicationStatus = trim(
        (string) ($_POST['application_status'] ?? 'closed')
    );

    $applicationLink = cohorts_nullable_string(
        $_POST['application_link'] ?? null
    );

    $status = trim(
        (string) ($_POST['status'] ?? 'active')
    );

    $sortOrder = max(
        0,
        (int) ($_POST['sort_order'] ?? 0)
    );

    if ($name === '') {
        throw new RuntimeException(
            'Cohort name is required.'
        );
    }

    if (mb_strlen($name) > 250) {
        throw new RuntimeException(
            'Cohort name cannot exceed 250 characters.'
        );
    }

    if ($slug === '') {
        $slug = cohorts_slugify($name);
    }

    if ($slug === '') {
        throw new RuntimeException(
            'A valid cohort slug is required.'
        );
    }

    if (mb_strlen($slug) > 250) {
        throw new RuntimeException(
            'Cohort slug cannot exceed 250 characters.'
        );
    }

    if (
        $tagline !== null
        && mb_strlen($tagline) > 600
    ) {
        throw new RuntimeException(
            'Tagline cannot exceed 600 characters.'
        );
    }

    if (
        $applicationLink !== null
        && (
            mb_strlen($applicationLink) > 600
            || filter_var(
                $applicationLink,
                FILTER_VALIDATE_URL
            ) === false
        )
    ) {
        throw new RuntimeException(
            'Enter a valid application URL.'
        );
    }

    if (!cohorts_valid_date($startDate)) {
        throw new RuntimeException(
            'Start date is invalid.'
        );
    }

    if (!cohorts_valid_date($endDate)) {
        throw new RuntimeException(
            'End date is invalid.'
        );
    }

    if (
        $startDate !== null
        && $endDate !== null
        && $endDate < $startDate
    ) {
        throw new RuntimeException(
            'End date cannot be earlier than the start date.'
        );
    }

    if (!in_array(
        $applicationStatus,
        ['open', 'closed', 'coming_soon'],
        true
    )) {
        throw new RuntimeException(
            'Invalid application status.'
        );
    }

    if (!in_array(
        $status,
        ['active', 'inactive', 'draft'],
        true
    )) {
        throw new RuntimeException(
            'Invalid cohort status.'
        );
    }

    if ($id > 0) {
        $existsStmt = $conn->prepare(
            'SELECT id FROM cohorts WHERE id = ? LIMIT 1'
        );

        if (!$existsStmt) {
            throw new RuntimeException(
                'Unable to verify the selected cohort.'
            );
        }

        $existsStmt->bind_param('i', $id);
        $existsStmt->execute();
        $existsStmt->store_result();
        $cohortExists = $existsStmt->num_rows > 0;
        $existsStmt->close();

        if (!$cohortExists) {
            throw new RuntimeException(
                'The selected cohort was not found.'
            );
        }
    }

    $slugStmt = $conn->prepare(
        'SELECT id
         FROM cohorts
         WHERE slug = ?
           AND id <> ?
         LIMIT 1'
    );

    if (!$slugStmt) {
        throw new RuntimeException(
            'Unable to verify the cohort slug.'
        );
    }

    $slugStmt->bind_param('si', $slug, $id);

    if (!$slugStmt->execute()) {
        $slugStmt->close();

        throw new RuntimeException(
            'Unable to verify the cohort slug.'
        );
    }

    $slugStmt->store_result();
    $slugExists = $slugStmt->num_rows > 0;
    $slugStmt->close();

    if ($slugExists) {
        throw new RuntimeException(
            'That cohort slug is already in use.'
        );
    }

    if ($id > 0) {
        $stmt = $conn->prepare(
            'UPDATE cohorts
             SET
                 name = ?,
                 slug = ?,
                 tagline = ?,
                 description = ?,
                 start_date = ?,
                 end_date = ?,
                 application_status = ?,
                 application_link = ?,
                 status = ?,
                 sort_order = ?
             WHERE id = ?
             LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare the cohort update.'
            );
        }

        $stmt->bind_param(
            'sssssssssii',
            $name,
            $slug,
            $tagline,
            $description,
            $startDate,
            $endDate,
            $applicationStatus,
            $applicationLink,
            $status,
            $sortOrder,
            $id
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO cohorts
                (
                    name,
                    slug,
                    tagline,
                    description,
                    start_date,
                    end_date,
                    application_status,
                    application_link,
                    status,
                    sort_order
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare the cohort creation.'
            );
        }

        $stmt->bind_param(
            'sssssssssi',
            $name,
            $slug,
            $tagline,
            $description,
            $startDate,
            $endDate,
            $applicationStatus,
            $applicationLink,
            $status,
            $sortOrder
        );
    }

    if (!$stmt->execute()) {
        $statementErrorNumber = $stmt->errno;
        $statementError = $stmt->error;
        $stmt->close();

        if ($statementErrorNumber === 1062) {
            throw new RuntimeException(
                'That cohort slug is already in use.'
            );
        }

        error_log(
            'Cohort save statement failed: '
            . $statementError
        );

        throw new RuntimeException(
            $id > 0
                ? 'The cohort could not be updated.'
                : 'The cohort could not be created.'
        );
    }

    $stmt->close();

    cohorts_flash(
        'success',
        $id > 0
            ? 'Cohort updated successfully.'
            : 'Cohort created successfully.'
    );
} catch (Throwable $exception) {
    error_log(
        'Cohort processing error: '
        . $exception->getMessage()
    );

    cohorts_flash(
        'error',
        $exception->getMessage()
    );
}

cohorts_redirect();
