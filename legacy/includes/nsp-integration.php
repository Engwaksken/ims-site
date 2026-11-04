<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hive NSP integration (contract: nsp-platform docs/integration-contract-v1.md)
|--------------------------------------------------------------------------
|
| Everything here is inert unless NSP_INTEGRATION_ENABLED=true and the
| NSP_* settings in .env are complete. Every entry point fails soft: a
| missing table, a bad config value or an NSP outage is written to the
| error log and never breaks the application form or status changes.
|
| What IMS sends to NSP is limited to the whitelisted event data in contract
| section 4.1. Scores, reviewer comments, application answers, eligibility
| notes and attachments are never sent.
|
| Pieces:
|   - config / switch            nsp_enabled(), nsp_config()
|   - signatures (section 4)     nsp_sign(), nsp_verify_signature()
|   - connect flow (section 2)   nsp_connect_url(), nsp_exchange_code(),
|                                nsp_prefill_map(), nsp_session_prefill()
|   - outbox (section 4.3)       nsp_queue_event(), nsp_deliver_outbox()
|   - inbox / webhook (4.2)      nsp_handle_webhook()
|   - application hooks          nsp_on_application_submitted(),
|                                nsp_on_application_status_change()
*/

if (defined('IMS_NSP_INTEGRATION_LOADED')) {
    return;
}

define('IMS_NSP_INTEGRATION_LOADED', true);

require_once __DIR__ . '/security.php';

const NSP_HTTP_TIMEOUT = 10;
const NSP_SIGNATURE_TOLERANCE = 300;
const NSP_MAX_ATTEMPTS = 8;
/** Retry delays after the 1st..5th failure, in minutes; then every 12 hours. */
const NSP_BACKOFF_MINUTES = [1, 5, 15, 60, 240];
const NSP_BACKOFF_TAIL_MINUTES = 720;
/** A connect-flow prefill is usable for this long after the callback. */
const NSP_PREFILL_TTL = 14400;
const NSP_STATE_TTL = 900;

const NSP_EVENT_DATA_WHITELIST = [
    'ims.application.submitted.v1' => ['application_id', 'program_title', 'cohort_name', 'submitted_at'],
    'ims.application.status_changed.v1' => ['application_id', 'status', 'program_title', 'cohort_name', 'changed_at'],
    'ims.program.enrolled.v1' => ['application_id', 'program_title', 'cohort_name', 'start_date', 'end_date'],
];

/** IMS status => contract status. IMS statuses not listed (Draft, Pending...) are not sent. */
const NSP_STATUS_MAP = [
    'submitted' => 'Submitted',
    'under review' => 'Under Review',
    'shortlisted' => 'Shortlisted',
    'accepted' => 'Accepted',
    'selected' => 'Selected',
    'rejected' => 'Rejected',
    'withdrawn' => 'Withdrawn',
];

/*
|--------------------------------------------------------------------------
| Config
|--------------------------------------------------------------------------
*/

function nsp_config(): array
{
    return [
        'base_url' => rtrim(trim((string) ims_env('NSP_BASE_URL', '')), '/'),
        'client_id' => trim((string) ims_env('NSP_CLIENT_ID', '')),
        'client_secret' => (string) ims_env('NSP_CLIENT_SECRET', ''),
        'webhook_secret' => (string) ims_env('NSP_WEBHOOK_SECRET', ''),
        'redirect_uri' => trim((string) ims_env('NSP_REDIRECT_URI', '')),
    ];
}

/** HTTPS is required except for a local development NSP. */
function nsp_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme === 'https') {
        return true;
    }
    return $scheme === 'http' && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '::1'], true);
}

/** The master switch. False unless enabled AND fully configured. */
function nsp_enabled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!ims_env_bool('NSP_INTEGRATION_ENABLED', false)) {
        return $cached = false;
    }

    $cfg = nsp_config();
    $missing = [];
    foreach (['base_url', 'client_id', 'client_secret', 'webhook_secret', 'redirect_uri'] as $key) {
        if ($cfg[$key] === '') {
            $missing[] = 'NSP_' . strtoupper($key);
        }
    }
    if ($missing) {
        error_log('NSP integration disabled: missing ' . implode(', ', $missing));
        return $cached = false;
    }
    if (!nsp_url_allowed($cfg['base_url']) || !nsp_url_allowed($cfg['redirect_uri'])) {
        error_log('NSP integration disabled: NSP_BASE_URL and NSP_REDIRECT_URI must be https:// URLs');
        return $cached = false;
    }
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $cfg['client_id'])) {
        error_log('NSP integration disabled: NSP_CLIENT_ID has unexpected characters');
        return $cached = false;
    }

    return $cached = true;
}

/*
|--------------------------------------------------------------------------
| Schema (created lazily; see database/migrations/2026_11_05_nsp_integration.sql)
|--------------------------------------------------------------------------
*/

function nsp_schema_statements(): array
{
    return [
        'tables' => [
            "CREATE TABLE IF NOT EXISTS nsp_outbox (
                event_id CHAR(36) NOT NULL PRIMARY KEY,
                type VARCHAR(100) NOT NULL,
                payload LONGTEXT NOT NULL,
                subject_uuid CHAR(36) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                dead_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                KEY idx_nsp_outbox_due (sent_at, dead_at, next_attempt_at),
                KEY idx_nsp_outbox_subject (subject_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS nsp_inbox (
                event_id CHAR(36) NOT NULL PRIMARY KEY,
                type VARCHAR(100) NOT NULL,
                subject_uuid CHAR(36) NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) NOT NULL,
                note VARCHAR(1000) NULL,
                KEY idx_nsp_inbox_subject (subject_uuid, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        'columns' => [
            'registry_startup_uuid' => 'ALTER TABLE applications ADD COLUMN registry_startup_uuid CHAR(36) NULL',
            'nsp_snapshot' => 'ALTER TABLE applications ADD COLUMN nsp_snapshot LONGTEXT NULL',
            'nsp_snapshot_at' => 'ALTER TABLE applications ADD COLUMN nsp_snapshot_at DATETIME NULL',
            'nsp_consent_revoked_at' => 'ALTER TABLE applications ADD COLUMN nsp_consent_revoked_at DATETIME NULL',
        ],
        'index' => 'CREATE INDEX idx_applications_registry_startup_uuid ON applications (registry_startup_uuid)',
    ];
}

/**
 * True when the NSP tables and application columns exist, creating them on
 * first use. False (logged once per request) when they cannot be created.
 */
function nsp_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    // Read-only checks first: DDL implicitly commits, so it only runs when
    // something is actually missing (normally once, or never after the migration).
    try {
        $schema = nsp_schema_statements();
        $tables = [];
        $res = $conn->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('nsp_outbox','nsp_inbox')"
        );
        if (!$res) {
            throw new RuntimeException('table check: ' . $conn->error);
        }
        while ($row = $res->fetch_row()) {
            $tables[strtolower((string) $row[0])] = true;
        }
        $res->free();
        if (count($tables) < 2) {
            foreach ($schema['tables'] as $sql) {
                if (!$conn->query($sql)) {
                    throw new RuntimeException('create table: ' . $conn->error);
                }
            }
        }

        $have = [];
        $res = $conn->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications'
                AND COLUMN_NAME IN ('registry_startup_uuid','nsp_snapshot','nsp_snapshot_at','nsp_consent_revoked_at')"
        );
        if (!$res) {
            throw new RuntimeException('column check: ' . $conn->error);
        }
        while ($row = $res->fetch_row()) {
            $have[(string) $row[0]] = true;
        }
        $res->free();

        $added = false;
        foreach ($schema['columns'] as $column => $sql) {
            if (!isset($have[$column])) {
                if (!$conn->query($sql)) {
                    throw new RuntimeException('add column ' . $column . ': ' . $conn->error);
                }
                $added = true;
            }
        }

        if ($added || !isset($have['registry_startup_uuid'])) {
            $idx = $conn->query(
                "SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications'
                    AND INDEX_NAME = 'idx_applications_registry_startup_uuid' LIMIT 1"
            );
            if ($idx && $idx->num_rows === 0) {
                if (!$conn->query($schema['index'])) {
                    error_log('NSP integration: could not add registry_startup_uuid index: ' . $conn->error);
                }
            }
        }

        return $ready = true;
    } catch (Throwable $e) {
        error_log('NSP integration disabled: schema not ready (' . $e->getMessage() . '). Run database/migrations/2026_11_05_nsp_integration.sql');
        return $ready = false;
    }
}

/** Enabled AND schema present. Use this before any DB work. */
function nsp_active(?mysqli $conn): bool
{
    return nsp_enabled() && $conn instanceof mysqli && nsp_schema_ready($conn);
}

/*
|--------------------------------------------------------------------------
| Small helpers
|--------------------------------------------------------------------------
*/

function nsp_uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function nsp_is_uuid(mixed $value): bool
{
    return is_string($value)
        && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

function nsp_iso8601(?string $dbDateTime = null): string
{
    $ts = $dbDateTime !== null && $dbDateTime !== '' ? strtotime($dbDateTime) : time();
    return date('c', $ts === false ? time() : $ts);
}

function nsp_json(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Trim an error message for storage; never contains secrets (we never put them in). */
function nsp_short_error(string $message): string
{
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;
    return mb_substr(trim($message), 0, 900);
}

/*
|--------------------------------------------------------------------------
| Signatures (contract section 4)
|--------------------------------------------------------------------------
*/

/** "sha256={hex HMAC-SHA256 of "{timestamp}.{raw body}"}" */
function nsp_sign(int|string $timestamp, string $rawBody, ?string $secret = null): string
{
    $secret ??= nsp_config()['webhook_secret'];
    return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
}

/** Case-insensitive header lookup over a name => value array or $_SERVER. */
function nsp_header(array $headers, string $name): string
{
    $want = strtolower($name);
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach ($headers as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        if (strtolower($key) === $want || strtoupper($key) === $serverKey) {
            return is_array($value) ? (string) reset($value) : (string) $value;
        }
    }
    return '';
}

/**
 * Verify X-Hive-Timestamp / X-Hive-Signature over the raw body.
 * Rejects timestamps more than 300 seconds old or ahead.
 */
function nsp_verify_signature(array $headers, string $rawBody, ?int $now = null, ?string $secret = null): bool
{
    $secret ??= nsp_config()['webhook_secret'];
    if ($secret === '') {
        return false;
    }

    $timestamp = trim(nsp_header($headers, 'X-Hive-Timestamp'));
    $signature = trim(nsp_header($headers, 'X-Hive-Signature'));

    if (!preg_match('/^\d{1,12}$/', $timestamp) || !str_starts_with($signature, 'sha256=')) {
        return false;
    }

    $now ??= time();
    if (abs($now - (int) $timestamp) > NSP_SIGNATURE_TOLERANCE) {
        return false;
    }

    return hash_equals(nsp_sign($timestamp, $rawBody, $secret), strtolower($signature));
}

/*
|--------------------------------------------------------------------------
| HTTP client
|--------------------------------------------------------------------------
*/

/**
 * Call NSP with client credentials. Returns
 * ['status' => int (0 on transport error), 'body' => string, 'json' => ?array, 'error' => ?string].
 */
function nsp_http(string $method, string $path, ?string $rawBody = null, array $extraHeaders = []): array
{
    $cfg = nsp_config();
    $url = $cfg['base_url'] . $path;

    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'curl extension not available'];
    }

    $headers = array_merge([
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['client_id'] . '.' . $cfg['client_secret'],
    ], $extraHeaders);
    if ($rawBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $isLocalHttp = str_starts_with(strtolower($url), 'http://');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => NSP_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => NSP_HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => $isLocalHttp ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Hive-IMS-NSP/1',
    ]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? ('transport error: ' . curl_error($ch)) : null;
    curl_close($ch);

    $body = is_string($body) ? $body : '';
    $json = json_decode($body, true);

    return [
        'status' => $error === null ? $status : 0,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
        'error' => $error,
    ];
}

/*
|--------------------------------------------------------------------------
| Connect flow (contract section 2)
|--------------------------------------------------------------------------
*/

function nsp_connect_url(string $state): string
{
    $cfg = nsp_config();
    return $cfg['base_url'] . '/connect/' . rawurlencode($cfg['client_id'])
        . '?' . http_build_query(['redirect_uri' => $cfg['redirect_uri'], 'state' => $state], '', '&', PHP_QUERY_RFC3986);
}

/** Only same-site absolute paths ("/x?y"), never "//host" or "/\host" or a scheme. */
function nsp_is_internal_path(string $path): bool
{
    return $path !== ''
        && $path[0] === '/'
        && !str_starts_with($path, '//')
        && !str_starts_with($path, '/\\')
        && !preg_match('/[\x00-\x1F\x7F\\\\]/', $path)
        && strlen($path) <= 500;
}

/** Where the connect flow returns to: the application form, built from integers only. */
function nsp_form_return_path(int $opportunityId, int $draftId = 0): string
{
    $path = '/submit-application?opportunity_id=' . max(0, $opportunityId);
    if ($draftId > 0) {
        $path .= '&draft_id=' . $draftId;
    }
    return $path;
}

/** Start a connect flow: store a single-use state in the session. Returns the state. */
function nsp_begin_connect(string $returnPath): string
{
    if (!nsp_is_internal_path($returnPath)) {
        $returnPath = '/apply-embed';
    }
    $state = bin2hex(random_bytes(32)); // 64 chars
    $_SESSION['nsp_connect'] = [
        'state' => $state,
        'return' => $returnPath,
        'created' => time(),
    ];
    return $state;
}

/**
 * Validate and consume the connect state (single use, constant-time compare).
 * Returns the stored return path, or null when the state is wrong/expired.
 */
function nsp_consume_state(string $state, ?int $now = null): ?string
{
    $stored = $_SESSION['nsp_connect'] ?? null;
    unset($_SESSION['nsp_connect']);

    if (!is_array($stored) || !is_string($stored['state'] ?? null) || $stored['state'] === '' || $state === '') {
        return null;
    }
    if (!hash_equals($stored['state'], $state)) {
        return null;
    }
    $now ??= time();
    if ($now - (int) ($stored['created'] ?? 0) > NSP_STATE_TTL) {
        return null;
    }
    $return = (string) ($stored['return'] ?? '');
    return nsp_is_internal_path($return) ? $return : '/apply-embed';
}

/**
 * Exchange the authorization code at NSP. Returns
 * ['ok' => true, 'startup_uuid', 'version', 'fields', 'consent'] or ['ok' => false, 'error' => code].
 */
function nsp_exchange_code(string $code): array
{
    if ($code === '' || strlen($code) > 512) {
        return ['ok' => false, 'error' => 'invalid_code'];
    }

    $resp = nsp_http('POST', '/api/v1/connect/token', nsp_json([
        'code' => $code,
        'redirect_uri' => nsp_config()['redirect_uri'],
    ]));

    if ($resp['status'] !== 200 || !is_array($resp['json'])) {
        $err = is_array($resp['json']) && is_string($resp['json']['error'] ?? null) ? $resp['json']['error'] : 'http_' . $resp['status'];
        error_log('NSP token exchange failed: ' . ($resp['error'] ?? ('HTTP ' . $resp['status'] . ' ' . $err)));
        return ['ok' => false, 'error' => preg_replace('/[^a-z0-9_]/i', '', (string) $err) ?: 'error'];
    }

    $json = $resp['json'];
    if (!nsp_is_uuid($json['startup_uuid'] ?? null) || !is_array($json['fields'] ?? null)) {
        error_log('NSP token exchange: malformed response');
        return ['ok' => false, 'error' => 'malformed_response'];
    }

    // Keep scalar fields exactly as received (financial fields are never included by NSP).
    $fields = [];
    foreach ($json['fields'] as $key => $value) {
        if (is_string($key) && (is_scalar($value) || $value === null)) {
            $fields[$key] = $value;
        }
    }

    $consent = is_array($json['consent'] ?? null) ? $json['consent'] : [];

    return [
        'ok' => true,
        'startup_uuid' => strtolower((string) $json['startup_uuid']),
        'version' => isset($json['version']) && is_scalar($json['version']) ? $json['version'] : null,
        'fields' => $fields,
        'consent' => array_intersect_key($consent, array_flip(['purpose', 'policy_version', 'granted_at'])),
    ];
}

/** Store the exchanged profile as the session prefill. */
function nsp_store_prefill(array $exchange): void
{
    $_SESSION['nsp_prefill'] = [
        'startup_uuid' => $exchange['startup_uuid'],
        'version' => $exchange['version'],
        'fields' => $exchange['fields'],
        'consent' => $exchange['consent'],
        'received_at' => date('Y-m-d H:i:s'),
        'received_ts' => time(),
    ];
}

/** The current session prefill when the integration is enabled and it has not expired. */
function nsp_session_prefill(): ?array
{
    if (!nsp_enabled()) {
        return null;
    }
    $p = $_SESSION['nsp_prefill'] ?? null;
    if (!is_array($p) || !nsp_is_uuid($p['startup_uuid'] ?? null) || !is_array($p['fields'] ?? null)) {
        return null;
    }
    if (time() - (int) ($p['received_ts'] ?? 0) > NSP_PREFILL_TTL) {
        unset($_SESSION['nsp_prefill']);
        return null;
    }
    return $p;
}

function nsp_clear_prefill(): void
{
    unset($_SESSION['nsp_prefill']);
}

/** Snapshot JSON stored with the application: exactly what NSP sent (no user data). */
function nsp_snapshot_json(array $prefill): string
{
    return nsp_json([
        'startup_uuid' => $prefill['startup_uuid'],
        'version' => $prefill['version'] ?? null,
        'fields' => $prefill['fields'],
        'consent' => $prefill['consent'] ?? [],
        'received_at' => nsp_iso8601((string) ($prefill['received_at'] ?? '')),
    ]);
}

/**
 * Map NSP fields to the IMS application form (submit-application.php).
 * Only fields with a real equivalent are mapped; values stay editable.
 *   display_name / legal_name -> startup_name
 *   website                   -> website (http/https only)
 *   description               -> problem_solution
 *   headquarters_location     -> city_town
 *   sector                    -> sector_focus[] (only on an exact option match)
 * registration_number, country, team_size and year_founded have no IMS form
 * equivalent (the form asks for a founding month and full/part-time staff);
 * year_founded is shown as a hint instead.
 */
function nsp_prefill_map(array $fields, array $sectorOptions = []): array
{
    $str = static function (mixed $v): string {
        return is_scalar($v) ? trim((string) $v) : '';
    };

    $out = [];

    $name = $str($fields['display_name'] ?? '') ?: $str($fields['legal_name'] ?? '');
    if ($name !== '') {
        $out['startup_name'] = mb_substr($name, 0, 255);
    }

    $website = $str($fields['website'] ?? '');
    if ($website !== '' && preg_match('#^https?://#i', $website) && filter_var($website, FILTER_VALIDATE_URL)) {
        $out['website'] = $website;
    }

    $description = $str($fields['description'] ?? '');
    if ($description !== '') {
        $out['problem_solution'] = $description;
    }

    $location = $str($fields['headquarters_location'] ?? '');
    if ($location !== '') {
        $out['city_town'] = mb_substr($location, 0, 255);
    }

    $sector = $str($fields['sector'] ?? '');
    if ($sector !== '') {
        foreach ($sectorOptions as $opt) {
            if (strcasecmp((string) $opt, $sector) === 0) {
                $out['sector_focus'] = [(string) $opt];
                break;
            }
        }
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| Outbox (contract sections 4.1 and 4.3)
|--------------------------------------------------------------------------
*/

/**
 * Write an event to the outbox using the caller's connection, so it commits
 * or rolls back with the caller's transaction. Data keys outside the
 * contract whitelist are dropped. Returns the event_id, or null on failure.
 */
function nsp_queue_event(mysqli $conn, string $type, string $startupUuid, array $data, ?string $correlationId = null): ?string
{
    if (!nsp_active($conn)) {
        return null;
    }
    if (!isset(NSP_EVENT_DATA_WHITELIST[$type]) || !nsp_is_uuid($startupUuid)) {
        error_log('NSP queue: refused event type/subject ' . $type);
        return null;
    }

    $clean = [];
    foreach (NSP_EVENT_DATA_WHITELIST[$type] as $key) {
        $clean[$key] = $data[$key] ?? null;
    }

    $eventId = nsp_uuid_v4();
    $payload = nsp_json([
        'event_id' => $eventId,
        'type' => $type,
        'schema_version' => 1,
        'occurred_at' => nsp_iso8601(),
        'source' => 'ims',
        'subject_uuid' => strtolower($startupUuid),
        'correlation_id' => $correlationId,
        'data' => (object) $clean,
    ]);

    try {
        $stmt = $conn->prepare(
            'INSERT INTO nsp_outbox (event_id, type, payload, subject_uuid, created_at, attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, NOW(), 0, NOW())'
        );
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }
        $subject = strtolower($startupUuid);
        $stmt->bind_param('ssss', $eventId, $type, $payload, $subject);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        if (!$ok) {
            throw new RuntimeException($err);
        }
        return $eventId;
    } catch (Throwable $e) {
        error_log('NSP queue failed for ' . $type . ': ' . $e->getMessage());
        return null;
    }
}

/** Seconds to wait after the Nth failed attempt (N >= 1). */
function nsp_backoff_seconds(int $failedAttempts): int
{
    $i = max(1, $failedAttempts) - 1;
    $minutes = NSP_BACKOFF_MINUTES[$i] ?? NSP_BACKOFF_TAIL_MINUTES;
    return $minutes * 60;
}

/** POST one envelope to NSP. Returns [ok, error]. */
function nsp_send_event(string $payload): array
{
    $ts = (string) time();
    $resp = nsp_http('POST', '/api/v1/events', $payload, [
        'X-Hive-Timestamp: ' . $ts,
        'X-Hive-Signature: ' . nsp_sign($ts, $payload),
    ]);
    if ($resp['status'] >= 200 && $resp['status'] < 300) {
        return [true, null];
    }
    if ($resp['error'] !== null) {
        return [false, $resp['error']];
    }
    return [false, 'HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 300)];
}

/**
 * Send due outbox events. Retries after 1, 5, 15, 60, 240 minutes then every
 * 12 hours; dead after 8 failed attempts. Returns counts.
 * $sender is injectable for tests: fn(string $payload): array{bool, ?string}.
 */
function nsp_deliver_outbox(mysqli $conn, int $limit = 50, ?callable $sender = null): array
{
    $stats = ['sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => false];
    if (!nsp_active($conn)) {
        $stats['skipped'] = true;
        return $stats;
    }

    // One sender at a time (cron overlap).
    $lock = $conn->query("SELECT GET_LOCK('nsp_outbox_delivery', 0)");
    $got = $lock ? (int) ($lock->fetch_row()[0] ?? 0) : 0;
    if ($got !== 1) {
        $stats['skipped'] = true;
        return $stats;
    }

    $sender ??= 'nsp_send_event';

    try {
        $limit = max(1, min(500, $limit));
        $res = $conn->query(
            "SELECT event_id, payload, attempts FROM nsp_outbox
              WHERE sent_at IS NULL AND dead_at IS NULL AND next_attempt_at <= NOW()
              ORDER BY created_at ASC LIMIT {$limit}"
        );
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        $markSent = $conn->prepare('UPDATE nsp_outbox SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE event_id = ?');
        $markFail = $conn->prepare('UPDATE nsp_outbox SET attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND), dead_at = IF(? = 1, NOW(), NULL) WHERE event_id = ?');

        foreach ($rows as $row) {
            $eventId = (string) $row['event_id'];
            try {
                [$ok, $error] = $sender((string) $row['payload']);
            } catch (Throwable $e) {
                [$ok, $error] = [false, 'exception: ' . $e->getMessage()];
            }

            if ($ok) {
                $markSent->bind_param('s', $eventId);
                $markSent->execute();
                $stats['sent']++;
                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            $dead = $attempts >= NSP_MAX_ATTEMPTS ? 1 : 0;
            $delay = nsp_backoff_seconds($attempts);
            $err = nsp_short_error((string) $error);
            $markFail->bind_param('isiis', $attempts, $err, $delay, $dead, $eventId);
            $markFail->execute();
            $stats[$dead ? 'dead' : 'failed']++;
        }

        $markSent->close();
        $markFail->close();
    } finally {
        $conn->query("SELECT RELEASE_LOCK('nsp_outbox_delivery')");
    }

    return $stats;
}

/** Put dead events (one, or all when $eventId is null) back in the queue. */
function nsp_retry_dead(mysqli $conn, ?string $eventId = null): int
{
    if (!nsp_active($conn)) {
        return 0;
    }
    if ($eventId !== null) {
        if (!nsp_is_uuid($eventId)) {
            return 0;
        }
        $stmt = $conn->prepare('UPDATE nsp_outbox SET dead_at = NULL, attempts = 0, next_attempt_at = NOW() WHERE event_id = ? AND dead_at IS NOT NULL AND sent_at IS NULL');
        $stmt->bind_param('s', $eventId);
    } else {
        $stmt = $conn->prepare('UPDATE nsp_outbox SET dead_at = NULL, attempts = 0, next_attempt_at = NOW() WHERE dead_at IS NOT NULL AND sent_at IS NULL');
    }
    $stmt->execute();
    $n = (int) $stmt->affected_rows;
    $stmt->close();
    return $n;
}

function nsp_outbox_counts(mysqli $conn): array
{
    $counts = ['pending' => 0, 'retrying' => 0, 'dead' => 0, 'sent' => 0];
    $res = $conn->query(
        "SELECT
            SUM(sent_at IS NULL AND dead_at IS NULL) AS pending,
            SUM(sent_at IS NULL AND dead_at IS NULL AND attempts > 0) AS retrying,
            SUM(dead_at IS NOT NULL AND sent_at IS NULL) AS dead,
            SUM(sent_at IS NOT NULL) AS sent
         FROM nsp_outbox"
    );
    if ($res && ($row = $res->fetch_assoc())) {
        foreach ($counts as $k => $_) {
            $counts[$k] = (int) ($row[$k] ?? 0);
        }
    }
    return $counts;
}

/*
|--------------------------------------------------------------------------
| Application hooks
|--------------------------------------------------------------------------
*/

/** Linked startup, program and cohort for an application, or null. */
function nsp_application_context(mysqli $conn, int $applicationId): ?array
{
    $stmt = $conn->prepare(
        'SELECT a.application_id, a.status, a.submitted_at, a.registry_startup_uuid, a.nsp_consent_revoked_at,
                o.opportunity_title AS program_title, c.name AS cohort_name
           FROM applications a
           LEFT JOIN application_opportunities o ON o.opportunity_id = a.opportunity_id
           LEFT JOIN cohorts c ON c.id = COALESCE(a.cohort_id, o.cohort_id)
          WHERE a.application_id = ?
          LIMIT 1'
    );
    if (!$stmt) {
        error_log('NSP application context failed: ' . $conn->error);
        return null;
    }
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** True when this application is linked to NSP and consent has not been revoked. */
function nsp_application_linked(?array $ctx): bool
{
    return is_array($ctx)
        && nsp_is_uuid($ctx['registry_startup_uuid'] ?? null)
        && empty($ctx['nsp_consent_revoked_at']);
}

/** Has NSP told us consent for this startup was revoked after $since? */
function nsp_consent_revoked_since(mysqli $conn, string $startupUuid, string $since): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM nsp_inbox
          WHERE type = 'registry.consent.revoked.v1' AND status = 'processed'
            AND subject_uuid = ? AND received_at >= ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $startupUuid, $since);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

/**
 * Called inside the submission transaction. When a prefill is in the session,
 * saves registry_startup_uuid + snapshot on the (still Draft-or-just-written)
 * row. When $isSubmit and the row is linked, queues ims.application.submitted.v1.
 * Never throws.
 */
function nsp_on_application_saved(mysqli $conn, int $applicationId, bool $isSubmit, ?string $submittedAt): void
{
    try {
        if (!nsp_active($conn)) {
            return;
        }

        $prefill = nsp_session_prefill();
        if ($prefill !== null) {
            $uuid = strtolower((string) $prefill['startup_uuid']);
            $snapshot = nsp_snapshot_json($prefill);
            $revoked = nsp_consent_revoked_since($conn, $uuid, (string) $prefill['received_at']);

            // Only reached for the row this request just saved (a draft, or a
            // draft/new row being submitted now). Nothing else writes these
            // columns, so a submitted application keeps its snapshot.
            $stmt = $conn->prepare(
                'UPDATE applications
                    SET registry_startup_uuid = ?, nsp_snapshot = ?, nsp_snapshot_at = NOW(),
                        nsp_consent_revoked_at = ' . ($revoked ? 'NOW()' : 'NULL') . '
                  WHERE application_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ssi', $uuid, $snapshot, $applicationId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (!$isSubmit) {
            return;
        }

        $ctx = nsp_application_context($conn, $applicationId);
        if (!nsp_application_linked($ctx)) {
            return;
        }

        nsp_queue_event($conn, 'ims.application.submitted.v1', (string) $ctx['registry_startup_uuid'], [
            'application_id' => (string) $applicationId,
            'program_title' => $ctx['program_title'] !== null ? (string) $ctx['program_title'] : null,
            'cohort_name' => $ctx['cohort_name'] !== null ? (string) $ctx['cohort_name'] : null,
            'submitted_at' => nsp_iso8601($submittedAt ?? (string) ($ctx['submitted_at'] ?? '')),
        ]);
    } catch (Throwable $e) {
        error_log('NSP on-save hook failed for application ' . $applicationId . ': ' . $e->getMessage());
    }
}

/** Contract status for an IMS status, or null when it is not reported. */
function nsp_contract_status(string $imsStatus): ?string
{
    return NSP_STATUS_MAP[strtolower(trim($imsStatus))] ?? null;
}

/**
 * Queue ims.application.status_changed.v1 for linked applications whose
 * consent is not revoked. Call after the status UPDATE, on the same
 * connection (inside the transaction where there is one). Never throws.
 */
function nsp_on_application_status_change(mysqli $conn, int $applicationId, string $newStatus): void
{
    try {
        if (!nsp_enabled() || $applicationId < 1) {
            return;
        }
        $status = nsp_contract_status($newStatus);
        if ($status === null || !nsp_active($conn)) {
            return;
        }
        $ctx = nsp_application_context($conn, $applicationId);
        if (!nsp_application_linked($ctx)) {
            return;
        }
        nsp_queue_event($conn, 'ims.application.status_changed.v1', (string) $ctx['registry_startup_uuid'], [
            'application_id' => (string) $applicationId,
            'status' => $status,
            'program_title' => $ctx['program_title'] !== null ? (string) $ctx['program_title'] : null,
            'cohort_name' => $ctx['cohort_name'] !== null ? (string) $ctx['cohort_name'] : null,
            'changed_at' => nsp_iso8601(),
        ]);
    } catch (Throwable $e) {
        error_log('NSP status hook failed for application ' . $applicationId . ': ' . $e->getMessage());
    }
}

/**
 * Before a (bulk) status UPDATE: the ids among $applicationIds that are
 * linked to NSP and whose status will actually change. Pass the result to
 * nsp_on_application_status_change() after the UPDATE. Empty when disabled.
 * $onlyFromStatus limits it to rows currently in that status.
 */
function nsp_linked_ids_changing_status(mysqli $conn, array $applicationIds, string $newStatus, ?string $onlyFromStatus = null): array
{
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $applicationIds), static fn(int $id): bool => $id > 0)));
        if (!$ids || !nsp_enabled() || nsp_contract_status($newStatus) === null || !nsp_active($conn)) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT application_id FROM applications
              WHERE application_id IN ($marks)
                AND registry_startup_uuid IS NOT NULL AND nsp_consent_revoked_at IS NULL
                AND (status IS NULL OR status <> ?)"
                . ($onlyFromStatus !== null ? ' AND status = ?' : '')
        );
        if (!$stmt) {
            return [];
        }
        $params = array_merge($ids, [$newStatus]);
        $types = str_repeat('i', count($ids)) . 's';
        if ($onlyFromStatus !== null) {
            $params[] = $onlyFromStatus;
            $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $out = array_map(static fn(array $r): int => (int) $r[0], $stmt->get_result()->fetch_all(MYSQLI_NUM));
        $stmt->close();
        return $out;
    } catch (Throwable $e) {
        error_log('NSP status pre-check failed: ' . $e->getMessage());
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| Webhook receiver (contract section 4.2)
|--------------------------------------------------------------------------
*/

/** Process one signed NSP delivery. Returns [httpStatus, responseArray]. */
function nsp_handle_webhook(mysqli $conn, string $rawBody, array $headers, ?int $now = null): array
{
    if (!nsp_verify_signature($headers, $rawBody, $now)) {
        return [401, ['error' => 'invalid_signature']];
    }

    $event = json_decode($rawBody, true);
    $eventId = is_array($event) ? ($event['event_id'] ?? null) : null;
    $type = is_array($event) ? ($event['type'] ?? null) : null;

    if (!is_array($event) || !nsp_is_uuid($eventId) || !is_string($type) || $type === '' || strlen($type) > 100) {
        if (nsp_is_uuid($eventId)) {
            nsp_inbox_record($conn, strtolower((string) $eventId), is_string($type) ? mb_substr($type, 0, 100) : 'unknown', null, 'rejected', 'malformed envelope');
        }
        return [400, ['error' => 'malformed']];
    }

    $eventId = strtolower($eventId);
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $subject = nsp_is_uuid($event['subject_uuid'] ?? null) ? strtolower((string) $event['subject_uuid']) : null;

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("INSERT IGNORE INTO nsp_inbox (event_id, type, subject_uuid, received_at, status, note) VALUES (?, ?, ?, NOW(), 'processing', NULL)");
        if (!$ins) {
            throw new RuntimeException('inbox prepare: ' . $conn->error);
        }
        $ins->bind_param('sss', $eventId, $type, $subject);
        if (!$ins->execute()) {
            throw new RuntimeException('inbox insert: ' . $ins->error);
        }
        $fresh = $ins->affected_rows === 1;
        $ins->close();

        if (!$fresh) {
            $conn->rollback();
            return [200, ['status' => 'duplicate', 'event_id' => $eventId]];
        }

        [$code, $status, $note] = nsp_apply_event($conn, $type, $subject, $data);

        $upd = $conn->prepare('UPDATE nsp_inbox SET status = ?, note = ? WHERE event_id = ?');
        $note = nsp_short_error($note);
        $upd->bind_param('sss', $status, $note, $eventId);
        $upd->execute();
        $upd->close();

        $conn->commit();
        return [$code, ['status' => $status, 'event_id' => $eventId]];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('NSP webhook processing failed for ' . $eventId . ': ' . $e->getMessage());
        return [503, ['error' => 'temporary_failure']];
    }
}

/** Record a rejected delivery outside the main transaction (best effort). */
function nsp_inbox_record(mysqli $conn, string $eventId, string $type, ?string $subject, string $status, string $note): void
{
    try {
        $stmt = $conn->prepare('INSERT IGNORE INTO nsp_inbox (event_id, type, subject_uuid, received_at, status, note) VALUES (?, ?, ?, NOW(), ?, ?)');
        if ($stmt) {
            $stmt->bind_param('sssss', $eventId, $type, $subject, $status, $note);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('NSP inbox record failed: ' . $e->getMessage());
    }
}

/** Apply an event inside the inbox transaction. Returns [httpCode, inboxStatus, note]. */
function nsp_apply_event(mysqli $conn, string $type, ?string $subject, array $data): array
{
    switch ($type) {
        case 'registry.consent.revoked.v1':
            $purpose = (string) ($data['purpose'] ?? '');
            $recipient = (string) ($data['recipient_system'] ?? 'ims');
            if ($subject === null) {
                return [400, 'rejected', 'missing subject_uuid'];
            }
            if ($purpose !== 'ims_prefill' || ($recipient !== '' && $recipient !== 'ims')) {
                return [202, 'ignored', 'purpose ' . $purpose . ' / ' . $recipient . ' not used by IMS'];
            }
            $stmt = $conn->prepare('UPDATE applications SET nsp_consent_revoked_at = NOW() WHERE registry_startup_uuid = ? AND nsp_consent_revoked_at IS NULL');
            $stmt->bind_param('s', $subject);
            if (!$stmt->execute()) {
                throw new RuntimeException('revoke update: ' . $stmt->error);
            }
            $n = (int) $stmt->affected_rows;
            $stmt->close();
            // Queued but unsent events for this startup are no longer shared.
            $stmt = $conn->prepare("UPDATE nsp_outbox SET dead_at = NOW(), last_error = 'consent revoked before delivery' WHERE subject_uuid = ? AND sent_at IS NULL AND dead_at IS NULL");
            $stmt->bind_param('s', $subject);
            $stmt->execute();
            $stmt->close();
            return [202, 'processed', 'consent revoked on ' . $n . ' application(s)'];

        case 'registry.startup.merged.v1':
            $source = $data['source_uuid'] ?? null;
            $target = $data['target_uuid'] ?? null;
            if (!nsp_is_uuid($source) || !nsp_is_uuid($target)) {
                return [400, 'rejected', 'merged event without valid source_uuid/target_uuid'];
            }
            $source = strtolower($source);
            $target = strtolower($target);
            $stmt = $conn->prepare('UPDATE applications SET registry_startup_uuid = ? WHERE registry_startup_uuid = ?');
            $stmt->bind_param('ss', $target, $source);
            if (!$stmt->execute()) {
                throw new RuntimeException('merge update: ' . $stmt->error);
            }
            $n = (int) $stmt->affected_rows;
            $stmt->close();
            nsp_rewrite_pending_subject($conn, $source, $target);
            return [202, 'processed', 'merged ' . $source . ' into ' . $target . ' on ' . $n . ' application(s)'];

        case 'registry.startup.unmerged.v1':
            error_log('NSP: startup unmerged event received for ' . ($subject ?? '?') . '; staff to review manually');
            return [202, 'logged', 'unmerge logged for manual review'];

        default:
            return [202, 'ignored', 'type not handled by IMS'];
    }
}

/** Point unsent outbox events at the surviving startup after a merge. */
function nsp_rewrite_pending_subject(mysqli $conn, string $source, string $target): void
{
    $stmt = $conn->prepare('SELECT event_id, payload FROM nsp_outbox WHERE subject_uuid = ? AND sent_at IS NULL');
    $stmt->bind_param('s', $source);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $upd = $conn->prepare('UPDATE nsp_outbox SET subject_uuid = ?, payload = ? WHERE event_id = ?');
    foreach ($rows as $row) {
        $env = json_decode((string) $row['payload'], true);
        if (!is_array($env)) {
            continue;
        }
        $env['subject_uuid'] = $target;
        if (isset($env['data']) && $env['data'] === []) {
            $env['data'] = new stdClass();
        }
        $payload = nsp_json($env);
        $id = (string) $row['event_id'];
        $upd->bind_param('sss', $target, $payload, $id);
        $upd->execute();
    }
    $upd->close();
}
