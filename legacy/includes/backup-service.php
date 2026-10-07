<?php
declare(strict_types=1);

/**
 * IMS Backup Service
 *
 * - Creates compressed MySQL backups (.sql.gz)
 * - Stores locally
 * - Optionally uploads to Google Drive using a service account
 * - Reads all configuration from system_settings
 */

function backup_setting(
    mysqli $conn,
    string $key,
    string $default = ''
): string {
    $stmt = $conn->prepare("
        SELECT setting_value
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return isset($row['setting_value'])
        ? ims_setting_decode($key, (string)$row['setting_value'])
        : $default;
}

function backup_setting_save(
    mysqli $conn,
    string $key,
    string $value,
    int $userId = 0
): void {
    $value = ims_setting_encode($key, $value); // encrypt secrets at rest
    $stmt = $conn->prepare("
        SELECT setting_id
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Could not inspect backup setting.'
        );
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $exists = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if ($exists) {
        $stmt = $conn->prepare("
            UPDATE system_settings
            SET
                setting_value = ?,
                updated_by = ?
            WHERE setting_key = ?
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Could not update backup setting.'
            );
        }

        $stmt->bind_param(
            'sis',
            $value,
            $userId,
            $key
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO system_settings
            (
                setting_key,
                setting_value,
                updated_by
            )
            VALUES (?, ?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException(
                'Could not insert backup setting.'
            );
        }

        $stmt->bind_param(
            'ssi',
            $key,
            $value,
            $userId
        );
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Could not save backup setting: '
            . $error
        );
    }

    $stmt->close();
}

function backup_base64url(string $data): string
{
    return rtrim(
        strtr(
            base64_encode($data),
            '+/',
            '-_'
        ),
        '='
    );
}

function backup_google_access_token(
    array $serviceAccount
): string {
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException(
            'OpenSSL extension is required for Google Drive backup.'
        );
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'cURL extension is required for Google Drive backup.'
        );
    }

    $clientEmail = trim(
        (string)($serviceAccount['client_email'] ?? '')
    );

    $privateKey = (string)(
        $serviceAccount['private_key']
        ?? ''
    );

    $tokenUri = trim(
        (string)(
            $serviceAccount['token_uri']
            ?? 'https://oauth2.googleapis.com/token'
        )
    );

    if ($clientEmail === '' || $privateKey === '') {
        throw new RuntimeException(
            'Google service account JSON is missing client_email or private_key.'
        );
    }

    $now = time();

    $header = backup_base64url(
        json_encode(
            [
                'alg' => 'RS256',
                'typ' => 'JWT',
            ],
            JSON_UNESCAPED_SLASHES
        )
    );

    $claim = backup_base64url(
        json_encode(
            [
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            JSON_UNESCAPED_SLASHES
        )
    );

    $unsigned = $header . '.' . $claim;

    $signature = '';

    $ok = openssl_sign(
        $unsigned,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    if (!$ok) {
        throw new RuntimeException(
            'Could not sign Google service account token.'
        );
    }

    $jwt =
        $unsigned
        . '.'
        . backup_base64url($signature);

    $curl = curl_init($tokenUri);

    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'grant_type'
                        => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'
                        => $jwt,
                ]
            ),
        ]
    );

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($curl);

    curl_close($curl);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Could not obtain Google access token. '
            . ($curlError !== '' ? $curlError : (string)$response)
        );
    }

    $decoded = json_decode(
        (string)$response,
        true
    );

    $token = trim(
        (string)(
            $decoded['access_token']
            ?? ''
        )
    );

    if ($token === '') {
        throw new RuntimeException(
            'Google token response did not contain an access token.'
        );
    }

    return $token;
}

function backup_upload_to_google_drive(
    string $filePath,
    string $folderId,
    string $serviceAccountJson
): array {
    if (!is_file($filePath)) {
        throw new RuntimeException(
            'Backup file does not exist.'
        );
    }

    $serviceAccount = json_decode(
        $serviceAccountJson,
        true
    );

    if (!is_array($serviceAccount)) {
        throw new RuntimeException(
            'Google service account JSON is invalid.'
        );
    }

    $token = backup_google_access_token(
        $serviceAccount
    );

    $metadata = [
        'name' => basename($filePath),
    ];

    if ($folderId !== '') {
        $metadata['parents'] = [$folderId];
    }

    $boundary =
        'ims_backup_'
        . bin2hex(random_bytes(12));

    $body =
        '--' . $boundary . "\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES
        )
        . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: application/gzip\r\n\r\n"
        . file_get_contents($filePath)
        . "\r\n"
        . '--' . $boundary . "--\r\n";

    $url =
        'https://www.googleapis.com/upload/drive/v3/files'
        . '?uploadType=multipart'
        . '&supportsAllDrives=true'
        . '&fields=id,name,webViewLink,createdTime,size';

    $curl = curl_init($url);

    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/related; boundary=' . $boundary,
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_POSTFIELDS => $body,
        ]
    );

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    $curlError = curl_error($curl);

    curl_close($curl);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Google Drive upload failed. '
            . ($curlError !== '' ? $curlError : (string)$response)
        );
    }

    $decoded = json_decode(
        (string)$response,
        true
    );

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Google Drive returned an invalid response.'
        );
    }

    return $decoded;
}

function backup_create_database_dump(
    string $destinationFile
): void {
    $sqlFile =
        preg_replace(
            '/\.gz$/i',
            '',
            $destinationFile
        );

    if ($sqlFile === null || $sqlFile === '') {
        throw new RuntimeException(
            'Invalid backup destination.'
        );
    }

    $command =
        'mysqldump'
        . ' --single-transaction'
        . ' --quick'
        . ' --skip-lock-tables'
        . ' --user='
        . escapeshellarg(DB_USER)
        . ' --password='
        . escapeshellarg(DB_PASS)
        . ' --host='
        . escapeshellarg(DB_HOST)
        . ' '
        . escapeshellarg(DB_NAME)
        . ' > '
        . escapeshellarg($sqlFile)
        . ' 2>&1';

    $output = [];
    $returnCode = 1;

    exec(
        $command,
        $output,
        $returnCode
    );

    if (
        $returnCode !== 0
        ||
        !is_file($sqlFile)
        ||
        filesize($sqlFile) < 1
    ) {
        @unlink($sqlFile);

        throw new RuntimeException(
            'mysqldump failed: '
            . implode(
                PHP_EOL,
                $output
            )
        );
    }

    if (!function_exists('gzopen')) {
        @unlink($sqlFile);

        throw new RuntimeException(
            'PHP zlib extension is required to compress backups.'
        );
    }

    $input = fopen(
        $sqlFile,
        'rb'
    );

    $outputHandle = gzopen(
        $destinationFile,
        'wb6'
    );

    if (!$input || !$outputHandle) {
        if (is_resource($input)) {
            fclose($input);
        }

        if ($outputHandle) {
            gzclose($outputHandle);
        }

        @unlink($sqlFile);
        @unlink($destinationFile);

        throw new RuntimeException(
            'Could not compress database backup.'
        );
    }

    while (!feof($input)) {
        $chunk = fread(
            $input,
            1024 * 1024
        );

        if ($chunk === false) {
            fclose($input);
            gzclose($outputHandle);
            @unlink($sqlFile);
            @unlink($destinationFile);

            throw new RuntimeException(
                'Could not read temporary SQL backup.'
            );
        }

        if ($chunk !== '') {
            gzwrite(
                $outputHandle,
                $chunk
            );
        }
    }

    fclose($input);
    gzclose($outputHandle);

    @unlink($sqlFile);

    if (
        !is_file($destinationFile)
        ||
        filesize($destinationFile) < 1
    ) {
        throw new RuntimeException(
            'Compressed backup was not created.'
        );
    }
}

function backup_cleanup_local(
    string $directory,
    int $retentionCount
): void {
    $retentionCount = max(
        1,
        $retentionCount
    );

    $files = glob(
        rtrim($directory, '/\\')
        . '/backup_*.sql.gz'
    ) ?: [];

    usort(
        $files,
        static function (
            string $a,
            string $b
        ): int {
            return filemtime($b)
                <=>
                filemtime($a);
        }
    );

    $delete = array_slice(
        $files,
        $retentionCount
    );

    foreach ($delete as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function backup_run(
    mysqli $conn,
    int $userId = 0,
    string $source = 'manual'
): array {
    $timezone = backup_setting(
        $conn,
        'timezone',
        'Africa/Kampala'
    );

    try {
        date_default_timezone_set(
            $timezone
        );
    } catch (Throwable $e) {
        date_default_timezone_set(
            'Africa/Kampala'
        );
    }

    $exportsDir =
        dirname(__DIR__)
        . '/exports';

    if (
        !is_dir($exportsDir)
        &&
        !mkdir(
            $exportsDir,
            0755,
            true
        )
        &&
        !is_dir($exportsDir)
    ) {
        throw new RuntimeException(
            'Could not create exports directory.'
        );
    }

    $fileName =
        'backup_'
        . date('Y-m-d_H-i-s')
        . '.sql.gz';

    $filePath =
        $exportsDir
        . '/'
        . $fileName;

    backup_create_database_dump(
        $filePath
    );

    $storage =
        backup_setting(
            $conn,
            'backup_storage_destination',
            'local'
        );

    $driveResult = null;

    if (
        in_array(
            $storage,
            ['google_drive', 'both'],
            true
        )
    ) {
        $folderId = trim(
            backup_setting(
                $conn,
                'backup_google_drive_folder_id'
            )
        );

        $serviceJson =
            backup_setting(
                $conn,
                'backup_google_service_account_json'
            );

        if ($serviceJson === '') {
            throw new RuntimeException(
                'Google Drive backup is selected but service account JSON is not configured.'
            );
        }

        $driveResult =
            backup_upload_to_google_drive(
                $filePath,
                $folderId,
                $serviceJson
            );
    }

    /*
     * Google Drive-only mode removes local copy after upload.
     */
    if ($storage === 'google_drive') {
        @unlink($filePath);
    } else {
        $retentionCount = max(
            1,
            (int)backup_setting(
                $conn,
                'backup_retention_count',
                '14'
            )
        );

        backup_cleanup_local(
            $exportsDir,
            $retentionCount
        );
    }

    backup_setting_save(
        $conn,
        'backup_last_run_at',
        date('Y-m-d H:i:s'),
        $userId
    );

    backup_setting_save(
        $conn,
        'backup_last_run_status',
        'Success',
        $userId
    );

    backup_setting_save(
        $conn,
        'backup_last_run_source',
        $source,
        $userId
    );

    backup_setting_save(
        $conn,
        'backup_last_filename',
        $fileName,
        $userId
    );

    if (is_array($driveResult)) {
        backup_setting_save(
            $conn,
            'backup_last_google_file_id',
            (string)($driveResult['id'] ?? ''),
            $userId
        );
    }

    return [
        'success' => true,
        'filename' => $fileName,
        'local_path' => is_file($filePath)
            ? $filePath
            : '',
        'storage' => $storage,
        'google_drive' => $driveResult,
    ];
}

function backup_is_due(
    mysqli $conn
): bool {
    if (
        backup_setting(
            $conn,
            'backup_auto_enabled',
            '0'
        ) !== '1'
    ) {
        return false;
    }

    $timezone = backup_setting(
        $conn,
        'timezone',
        'Africa/Kampala'
    );

    try {
        $tz = new DateTimeZone(
            $timezone
        );
    } catch (Throwable $e) {
        $tz = new DateTimeZone(
            'Africa/Kampala'
        );
    }

    $now = new DateTimeImmutable(
        'now',
        $tz
    );

    $lastRunRaw = backup_setting(
        $conn,
        'backup_last_run_at'
    );

    $lastRun = null;

    if ($lastRunRaw !== '') {
        try {
            $lastRun = new DateTimeImmutable(
                $lastRunRaw,
                $tz
            );
        } catch (Throwable $e) {
            $lastRun = null;
        }
    }

    $frequency =
        backup_setting(
            $conn,
            'backup_frequency',
            'daily'
        );

    $timeValue =
        backup_setting(
            $conn,
            'backup_time',
            '02:00'
        );

    if (
        !preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $timeValue
        )
    ) {
        $timeValue = '02:00';
    }

    [$hour, $minute] = array_map(
        'intval',
        explode(':', $timeValue)
    );

    if ($frequency === 'hourly') {
        if (!$lastRun) {
            return true;
        }

        return (
            $now->getTimestamp()
            -
            $lastRun->getTimestamp()
        ) >= 3600;
    }

    $scheduledToday = $now->setTime(
        $hour,
        $minute,
        0
    );

    if ($frequency === 'daily') {
        if ($now < $scheduledToday) {
            return false;
        }

        return !$lastRun
            ||
            $lastRun < $scheduledToday;
    }

    if ($frequency === 'weekly') {
        $weekday = max(
            1,
            min(
                7,
                (int)backup_setting(
                    $conn,
                    'backup_weekday',
                    '1'
                )
            )
        );

        $todayWeekday =
            (int)$now->format('N');

        $daysBack =
            ($todayWeekday - $weekday + 7) % 7;

        $scheduled =
            $now
                ->modify(
                    '-' . $daysBack . ' days'
                )
                ->setTime(
                    $hour,
                    $minute,
                    0
                );

        if ($now < $scheduled) {
            $scheduled =
                $scheduled->modify(
                    '-7 days'
                );
        }

        return !$lastRun
            ||
            $lastRun < $scheduled;
    }

    if ($frequency === 'monthly') {
        $day = max(
            1,
            min(
                28,
                (int)backup_setting(
                    $conn,
                    'backup_month_day',
                    '1'
                )
            )
        );

        $scheduled =
            $now
                ->setDate(
                    (int)$now->format('Y'),
                    (int)$now->format('m'),
                    $day
                )
                ->setTime(
                    $hour,
                    $minute,
                    0
                );

        if ($now < $scheduled) {
            $scheduled =
                $scheduled
                    ->modify(
                        'first day of previous month'
                    )
                    ->setDate(
                        (int)$scheduled
                            ->modify('first day of previous month')
                            ->format('Y'),
                        (int)$scheduled
                            ->modify('first day of previous month')
                            ->format('m'),
                        $day
                    )
                    ->setTime(
                        $hour,
                        $minute,
                        0
                    );
        }

        return !$lastRun
            ||
            $lastRun < $scheduled;
    }

    return false;
}
