<?php
declare(strict_types=1);

/*
 * Hive NSP -> IMS event receiver (integration contract sections 4 and 4.2).
 * Register https://ims.hivecolab.com/nsp-webhook as the client's webhook_url.
 *
 * POST only, 404 when the integration is off. Authenticated by the
 * X-Hive-Signature HMAC over the raw body instead of a session, so it is
 * exempt from the session CSRF check (see ims_csrf_auto_enforce()).
 * Responses: 202 processed, 200 duplicate, 400 malformed, 401 bad
 * signature/timestamp, 503 temporary failure (NSP retries).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/nsp-integration.php';

function nsp_webhook_respond(int $status, array $body): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!nsp_enabled()) {
    nsp_webhook_respond(404, ['error' => 'not_found']);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    nsp_webhook_respond(405, ['error' => 'method_not_allowed']);
}

$rawBody = file_get_contents('php://input', false, null, 0, 1048576);
if (!is_string($rawBody) || $rawBody === '') {
    nsp_webhook_respond(400, ['error' => 'empty_body']);
}

if (!isset($conn) || !($conn instanceof mysqli) || !nsp_schema_ready($conn)) {
    nsp_webhook_respond(503, ['error' => 'temporary_failure']);
}

[$status, $body] = nsp_handle_webhook($conn, $rawBody, $_SERVER);
nsp_webhook_respond($status, $body);
