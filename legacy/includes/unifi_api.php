<?php
declare(strict_types=1);

// UniFi controller credentials live in .env (UNIFI_API_BASE, UNIFI_API_KEY,
// UNIFI_SITE_ID). The API key was previously hardcoded here: rotate it.
if (!function_exists('ims_env')) {
    require_once __DIR__ . '/security.php';
}
define('UNIFI_API_BASE', (string) ims_env('UNIFI_API_BASE', 'https://192.168.1.1/proxy/network/integration'));
define('UNIFI_API_KEY', (string) ims_env('UNIFI_API_KEY', ''));
define('UNIFI_SITE_ID', (string) ims_env('UNIFI_SITE_ID', ''));


function unifiRequest(string $method, string $endpoint, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'message' => 'PHP cURL extension is not enabled.',
        ];
    }

    $url = rtrim(UNIFI_API_BASE, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);

    $headers = [
        'X-API-Key: ' . UNIFI_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,

        // UniFi often uses self-signed SSL certificates.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'code'    => 0,
            'message' => $curlError ?: 'UniFi request failed.',
        ];
    }

    $data = json_decode((string)$response, true);

    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'code'    => $httpCode,
        'data'    => is_array($data) ? $data : [],
        'raw'     => $response,
        'message' => $httpCode >= 200 && $httpCode < 300
            ? 'Request successful.'
            : 'UniFi API error. HTTP Code: ' . $httpCode,
    ];
}

/**
 * Find UniFi client ID using MAC address.
 */
function unifiFindClientIdByMac(string $mac): ?string
{
    $mac = strtolower(trim($mac));

    if ($mac === '') {
        return null;
    }

    $endpoint = '/v1/sites/' . rawurlencode(UNIFI_SITE_ID)
              . '/clients?filter=macAddress.eq(%27'
              . rawurlencode($mac)
              . '%27)';

    $res = unifiRequest('GET', $endpoint);

    if (empty($res['success'])) {
        return null;
    }

    $data = $res['data'] ?? [];

    if (isset($data[0]['id'])) {
        return (string)$data[0]['id'];
    }

    if (isset($data['data'][0]['id'])) {
        return (string)$data['data'][0]['id'];
    }

    if (isset($data['items'][0]['id'])) {
        return (string)$data['items'][0]['id'];
    }

    return null;
}

/**
 * Authorize guest internet access.
 */
function unifiAuthorizeGuest(string $mac, int $minutes): array
{
    $mac = strtolower(trim($mac));

    if ($mac === '') {
        return [
            'success' => false,
            'message' => 'MAC address is missing.',
        ];
    }

    if ($minutes <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid access duration.',
        ];
    }

    $clientId = unifiFindClientIdByMac($mac);

    if (!$clientId) {
        return [
            'success' => false,
            'message' => 'Client device was not found in UniFi. Ask the member to connect to WiFi first.',
        ];
    }

    $endpoint = '/v1/sites/' . rawurlencode(UNIFI_SITE_ID)
              . '/clients/' . rawurlencode($clientId)
              . '/actions';

    return unifiRequest('POST', $endpoint, [
        'action' => 'AUTHORIZE_GUEST_ACCESS',
        'timeLimitMinutes' => $minutes,
    ]);
}

/**
 * Optional: revoke/block guest access.
 */
function unifiUnauthorizeGuest(string $mac): array
{
    $mac = strtolower(trim($mac));

    if ($mac === '') {
        return [
            'success' => false,
            'message' => 'MAC address is missing.',
        ];
    }

    $clientId = unifiFindClientIdByMac($mac);

    if (!$clientId) {
        return [
            'success' => false,
            'message' => 'Client device was not found in UniFi.',
        ];
    }

    $endpoint = '/v1/sites/' . rawurlencode(UNIFI_SITE_ID)
              . '/clients/' . rawurlencode($clientId)
              . '/actions';

    return unifiRequest('POST', $endpoint, [
        'action' => 'UNAUTHORIZE_GUEST_ACCESS',
    ]);
}