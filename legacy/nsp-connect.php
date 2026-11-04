<?php
declare(strict_types=1);

/*
 * Start of the Hive NSP connect flow (integration contract section 2).
 * Linked from the application form ("Prefill from my Hive NSP startup
 * profile"). Stores a single-use state and the form to return to in the
 * session, then sends the browser to NSP. The return path is built here from
 * integer ids; nothing from the query string is used as a redirect target.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/nsp-integration.php';

if (!nsp_enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

$opportunityId = filter_input(INPUT_GET, 'opportunity_id', FILTER_VALIDATE_INT) ?: 0;
$draftId = filter_input(INPUT_GET, 'draft_id', FILTER_VALIDATE_INT) ?: 0;

if ($opportunityId < 1) {
    header('Location: /apply-embed');
    exit;
}

$state = nsp_begin_connect(nsp_form_return_path((int) $opportunityId, (int) $draftId));

header('Cache-Control: no-store');
header('Location: ' . nsp_connect_url($state));
exit;
