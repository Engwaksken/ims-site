<?php
declare(strict_types=1);

/*
 * Hive NSP connect callback (integration contract section 2, steps 3-4).
 * NSP_REDIRECT_URI must point here, e.g. https://ims.hivecolab.com/nsp-callback
 *
 * Checks the single-use state, exchanges the code server-to-server and keeps
 * the returned profile fields in the session as a prefill for the form.
 * Always redirects back to the internal path stored by nsp-connect.php.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/nsp-integration.php';

if (!nsp_enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

header('Cache-Control: no-store');

$state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
$code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';

$returnPath = nsp_consume_state($state);

if ($returnPath === null) {
    $_SESSION['error'] = 'Your Hive NSP connection could not be verified or has expired. Please try again.';
    header('Location: /apply-embed');
    exit;
}

if ($error !== '') {
    $_SESSION['error'] = $error === 'access_denied'
        ? 'You cancelled sharing your Hive NSP profile. You can fill in the form yourself.'
        : 'Hive NSP did not share your startup profile. You can fill in the form yourself.';
    header('Location: ' . $returnPath);
    exit;
}

$result = nsp_exchange_code($code);

if (!$result['ok']) {
    $_SESSION['error'] = 'We could not fetch your Hive NSP startup profile. Please try again or fill in the form yourself.';
    header('Location: ' . $returnPath);
    exit;
}

nsp_store_prefill($result);

header('Location: ' . $returnPath);
exit;
