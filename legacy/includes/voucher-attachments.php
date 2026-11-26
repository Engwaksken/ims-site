<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payment voucher supporting files
|--------------------------------------------------------------------------
| Invoices, receipts, quotations etc. attached to a payment voucher so the
| approver (Senior Finance / HOD) can preview them before signing.
|
| Files are stored in legacy/uploads/voucher_attachments/<voucher id>/ under
| a random name (the uploads folder is not web-readable) and are served only
| through voucher-attachment.php, which applies the same access rule as
| get_voucher.php: vouchers.view, or the voucher's assigned approver.
|
| Used by includes/process_voucher.php (save / delete), includes/get_voucher.php
| (list) and voucher-attachment.php (serve).
*/

const PVF_MAX_FILE_BYTES = 20 * 1024 * 1024; // 20 MB per file (php.ini may be lower)
const PVF_MAX_FILES = 20;                     // per voucher

/** Allowed extensions => MIME type sent when serving the file. */
const PVF_TYPES = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv' => 'text/csv',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'txt' => 'text/plain',
    'zip' => 'application/zip',
];

/** Types a browser can show safely inside the preview pane. */
const PVF_INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];

if (!function_exists('pvf_ensure_schema')) {
    function pvf_ensure_schema(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            $ok = $conn->query(
                'CREATE TABLE IF NOT EXISTS payment_voucher_attachments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    voucher_id INT NOT NULL,
                    original_name VARCHAR(255) NOT NULL,
                    stored_name VARCHAR(120) NOT NULL,
                    mime_type VARCHAR(120) NOT NULL,
                    file_size INT UNSIGNED NOT NULL DEFAULT 0,
                    uploaded_by INT NULL,
                    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_pva_voucher (voucher_id)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            if (!$ok) {
                error_log('[voucher-attachments] could not create table: ' . $conn->error);
            }

            return $ready = (bool)$ok;
        } catch (Throwable $e) {
            error_log('[voucher-attachments] schema check failed: ' . $e->getMessage());
            return $ready = false;
        }
    }
}

if (!function_exists('pvf_directory')) {
    function pvf_directory(int $voucherId): string
    {
        return dirname(__DIR__) . '/uploads/voucher_attachments/' . $voucherId;
    }
}

if (!function_exists('pvf_ini_bytes')) {
    function pvf_ini_bytes(string $value): int
    {
        $value = trim($value);
        $number = (float)$value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }

        return (int)$number;
    }
}

if (!function_exists('pvf_limit_bytes')) {
    /** Largest single file accepted (app cap and php.ini upload_max_filesize). */
    function pvf_limit_bytes(): int
    {
        $ini = pvf_ini_bytes((string)ini_get('upload_max_filesize'));

        return $ini > 0 ? min(PVF_MAX_FILE_BYTES, $ini) : PVF_MAX_FILE_BYTES;
    }
}

if (!function_exists('pvf_format_bytes')) {
    function pvf_format_bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
    }
}

if (!function_exists('pvf_accept_attribute')) {
    /** Value for <input type="file" accept="...">. */
    function pvf_accept_attribute(): string
    {
        return implode(',', array_map(static fn(string $ext): string => '.' . $ext, array_keys(PVF_TYPES)));
    }
}

if (!function_exists('pvf_list')) {
    /** @return list<array{id:int,name:string,mime:string,size:int,size_label:string,kind:string,uploaded_by:string,uploaded_at:string,url:string,download_url:string}> */
    function pvf_list(mysqli $conn, int $voucherId): array
    {
        if ($voucherId <= 0 || !pvf_ensure_schema($conn)) {
            return [];
        }

        $stmt = $conn->prepare(
            'SELECT a.id, a.original_name, a.mime_type, a.file_size, a.uploaded_at, COALESCE(u.full_name, \'\') AS uploaded_by
             FROM payment_voucher_attachments a
             LEFT JOIN users u ON u.user_id = a.uploaded_by
             WHERE a.voucher_id = ?
             ORDER BY a.id'
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $voucherId);
        $stmt->execute();
        $result = $stmt->get_result();
        $files = [];

        while ($row = $result->fetch_assoc()) {
            $mime = (string)$row['mime_type'];
            $id = (int)$row['id'];
            $files[] = [
                'id' => $id,
                'name' => (string)$row['original_name'],
                'mime' => $mime,
                'size' => (int)$row['file_size'],
                'size_label' => pvf_format_bytes((int)$row['file_size']),
                'kind' => str_starts_with($mime, 'image/') ? 'image'
                    : ($mime === 'application/pdf' ? 'pdf'
                    : ($mime === 'text/plain' ? 'text' : 'file')),
                'uploaded_by' => (string)$row['uploaded_by'],
                'uploaded_at' => (string)$row['uploaded_at'],
                'url' => 'voucher-attachment.php?id=' . $id,
                'download_url' => 'voucher-attachment.php?id=' . $id . '&download=1',
            ];
        }

        $stmt->close();

        return $files;
    }
}

if (!function_exists('pvf_counts')) {
    /**
     * Number of files per voucher id.
     *
     * @param list<int> $voucherIds
     * @return array<int,int>
     */
    function pvf_counts(mysqli $conn, array $voucherIds): array
    {
        $voucherIds = array_values(array_unique(array_filter(array_map('intval', $voucherIds))));

        if ($voucherIds === [] || !pvf_ensure_schema($conn)) {
            return [];
        }

        $result = $conn->query(
            'SELECT voucher_id, COUNT(*) AS files FROM payment_voucher_attachments
             WHERE voucher_id IN (' . implode(',', $voucherIds) . ') GROUP BY voucher_id'
        );
        $counts = [];

        while ($result && ($row = $result->fetch_assoc())) {
            $counts[(int)$row['voucher_id']] = (int)$row['files'];
        }

        return $counts;
    }
}

if (!function_exists('pvf_incoming')) {
    /**
     * Validates the files posted in $_FILES[$field] (multiple input) without
     * moving them. Returns the accepted uploads.
     *
     * @return list<array{tmp:string,name:string,ext:string,mime:string,size:int}>
     * @throws InvalidArgumentException
     */
    function pvf_incoming(string $field = 'attachments'): array
    {
        $raw = $_FILES[$field] ?? null;

        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }

        $names = (array)$raw['name'];
        $limit = pvf_limit_bytes();
        $files = [];

        foreach ($names as $index => $name) {
            $error = (int)(((array)$raw['error'])[$index] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $name = trim((string)$name);
            $label = $name !== '' ? $name : 'A file';

            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new InvalidArgumentException($label . ' is larger than the ' . pvf_format_bytes($limit) . ' limit.');
            }

            $tmp = (string)(((array)$raw['tmp_name'])[$index] ?? '');

            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException($label . ' could not be uploaded. Please try again.');
            }

            $size = (int)(((array)$raw['size'])[$index] ?? 0);

            if ($size <= 0) {
                throw new InvalidArgumentException($label . ' is empty.');
            }

            if ($size > $limit) {
                throw new InvalidArgumentException($label . ' is larger than the ' . pvf_format_bytes($limit) . ' limit.');
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!isset(PVF_TYPES[$ext])) {
                throw new InvalidArgumentException(
                    $label . ' is not an accepted file type. Use PDF, images (PNG/JPG/GIF/WEBP), Word, Excel, PowerPoint, CSV, TXT or ZIP.'
                );
            }

            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && @getimagesize($tmp) === false) {
                throw new InvalidArgumentException($label . ' is not a valid image.');
            }

            if ($ext === 'pdf') {
                $head = (string)@file_get_contents($tmp, false, null, 0, 1024);

                if (!str_contains($head, '%PDF')) {
                    throw new InvalidArgumentException($label . ' is not a valid PDF.');
                }
            }

            // Keep a readable original name for display (path parts and control characters removed).
            $clean = (string)preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename(str_replace('\\', '/', $name)));
            $clean = mb_substr($clean !== '' ? $clean : 'file.' . $ext, -200);

            $files[] = ['tmp' => $tmp, 'name' => $clean, 'ext' => $ext, 'mime' => PVF_TYPES[$ext], 'size' => $size];
        }

        return $files;
    }
}

if (!function_exists('pvf_store')) {
    /**
     * Moves validated uploads into the voucher's folder and records them.
     * Returns the stored file paths so a failed transaction can remove them.
     *
     * @param list<array{tmp:string,name:string,ext:string,mime:string,size:int}> $files
     * @return list<string>
     */
    function pvf_store(mysqli $conn, int $voucherId, array $files, int $userId): array
    {
        if ($files === []) {
            return [];
        }

        if (!pvf_ensure_schema($conn)) {
            throw new RuntimeException('Supporting files are not available (could not create payment_voucher_attachments).');
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS files FROM payment_voucher_attachments WHERE voucher_id = ?');
        $stmt->bind_param('i', $voucherId);
        $stmt->execute();
        $existing = (int)($stmt->get_result()->fetch_assoc()['files'] ?? 0);
        $stmt->close();

        if ($existing + count($files) > PVF_MAX_FILES) {
            throw new InvalidArgumentException('A voucher can have at most ' . PVF_MAX_FILES . ' supporting files.');
        }

        $directory = pvf_directory($voucherId);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the folder for supporting files.');
        }

        $insert = $conn->prepare(
            'INSERT INTO payment_voucher_attachments (voucher_id, original_name, stored_name, mime_type, file_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        if (!$insert) {
            throw new RuntimeException('Could not save supporting files: ' . $conn->error);
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $storedName = bin2hex(random_bytes(16)) . '.' . $file['ext'];
                $path = $directory . '/' . $storedName;

                if (!move_uploaded_file($file['tmp'], $path)) {
                    throw new RuntimeException('Could not save ' . $file['name'] . '.');
                }

                $stored[] = $path;
                $insert->bind_param('isssii', $voucherId, $file['name'], $storedName, $file['mime'], $file['size'], $userId);

                if (!$insert->execute()) {
                    throw new RuntimeException('Could not save ' . $file['name'] . ': ' . $insert->error);
                }
            }
        } catch (Throwable $e) {
            pvf_unlink_paths($stored);
            throw $e;
        } finally {
            $insert->close();
        }

        return $stored;
    }
}

if (!function_exists('pvf_unlink_paths')) {
    /** @param list<string> $paths */
    function pvf_unlink_paths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

if (!function_exists('pvf_remove')) {
    /**
     * Deletes the given attachment rows of a voucher and returns the file
     * paths to unlink once the transaction has committed.
     *
     * @param list<int> $attachmentIds
     * @return list<string>
     */
    function pvf_remove(mysqli $conn, int $voucherId, array $attachmentIds): array
    {
        $attachmentIds = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

        if ($attachmentIds === [] || !pvf_ensure_schema($conn)) {
            return [];
        }

        return pvf_delete_where($conn, $voucherId, 'AND id IN (' . implode(',', $attachmentIds) . ')');
    }
}

if (!function_exists('pvf_remove_all')) {
    /** @return list<string> file paths to unlink after commit */
    function pvf_remove_all(mysqli $conn, int $voucherId): array
    {
        return pvf_ensure_schema($conn) ? pvf_delete_where($conn, $voucherId, '') : [];
    }
}

if (!function_exists('pvf_delete_where')) {
    /** @return list<string> */
    function pvf_delete_where(mysqli $conn, int $voucherId, string $extra): array
    {
        $stmt = $conn->prepare("SELECT stored_name FROM payment_voucher_attachments WHERE voucher_id = ? $extra");

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $voucherId);
        $stmt->execute();
        $result = $stmt->get_result();
        $paths = [];

        while ($row = $result->fetch_assoc()) {
            $paths[] = pvf_directory($voucherId) . '/' . basename((string)$row['stored_name']);
        }

        $stmt->close();

        $delete = $conn->prepare("DELETE FROM payment_voucher_attachments WHERE voucher_id = ? $extra");

        if ($delete) {
            $delete->bind_param('i', $voucherId);
            $delete->execute();
            $delete->close();
        }

        return $paths;
    }
}
