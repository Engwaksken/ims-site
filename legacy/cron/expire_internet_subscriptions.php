<?php
declare(strict_types=1);

// CLI only (was reachable over the web).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/config.php';

$conn = db_connect();

$stmt = $conn->prepare("
    UPDATE internet_subscriptions
    SET status = 'expired'
    WHERE status = 'active'
      AND expires_at IS NOT NULL
      AND expires_at < NOW()
");

$stmt->execute();

echo "Expired subscriptions updated: " . $stmt->affected_rows;