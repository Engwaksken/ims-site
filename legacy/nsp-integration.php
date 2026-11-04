<?php
declare(strict_types=1);

/*
 * Hive NSP integration status for Administrators: outbox counts, dead
 * events with their last error, and a manual retry (contract section 4.3).
 * Event payloads are not shown; they only carry whitelisted status data.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/nsp-integration.php';

check_role(['Administrator']);

if (!nsp_enabled()) {
    http_response_code(404);
    $page_title = 'Hive NSP Integration';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container-fluid" style="padding:24px"><div class="alert alert-info">The Hive NSP integration is switched off (NSP_INTEGRATION_ENABLED is not true, or NSP_* settings are incomplete).</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$schemaOk = nsp_active($conn);

if ($schemaOk && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    csrf_protect();
    $eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) && $_POST['event_id'] !== '' ? $_POST['event_id'] : null;
    $n = nsp_retry_dead($conn, $eventId);
    $_SESSION['success'] = $n . ' dead event(s) queued for another attempt.';
    log_action((int) $_SESSION['user_id'], 'NSP Retry Dead Events', 'nsp_outbox', 0, $n . ' event(s) re-queued');
    header('Location: /nsp-integration');
    exit;
}

$counts = $schemaOk ? nsp_outbox_counts($conn) : [];
$dead = [];
$inbox = [];
if ($schemaOk) {
    $res = $conn->query("SELECT event_id, type, subject_uuid, created_at, attempts, dead_at, last_error FROM nsp_outbox WHERE dead_at IS NOT NULL AND sent_at IS NULL ORDER BY dead_at DESC LIMIT 100");
    $dead = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $res = $conn->query("SELECT event_id, type, subject_uuid, received_at, status, note FROM nsp_inbox ORDER BY received_at DESC LIMIT 20");
    $inbox = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$page_title = 'Hive NSP Integration';
require_once __DIR__ . '/includes/header.php';
$x = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="container-fluid" style="padding:24px;display:grid;gap:20px">
    <h1>Hive NSP Integration</h1>
    <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= $x($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!$schemaOk): ?>
        <div class="alert alert-warning">The NSP tables are missing and could not be created. Run database/migrations/2026_11_05_nsp_integration.sql.</div>
    <?php else: ?>
        <section class="stats-grid">
            <?php foreach (['pending' => 'Pending', 'retrying' => 'Retrying', 'dead' => 'Dead', 'sent' => 'Sent'] as $k => $label): ?>
                <div class="stat-card"><div class="stat-info"><span class="stat-label"><?= $x($label) ?></span><span class="stat-value"><?= (int) $counts[$k] ?></span></div></div>
            <?php endforeach; ?>
        </section>

        <div class="card"><div class="card-body">
            <h2>Dead events</h2>
            <?php if (!$dead): ?>
                <p>No dead events.</p>
            <?php else: ?>
                <form method="post" style="margin-bottom:12px"><button type="submit" class="btn btn-primary">Retry all dead events</button></form>
                <div style="overflow:auto"><table class="table">
                    <thead><tr><th>Type</th><th>Startup</th><th>Created</th><th>Attempts</th><th>Dead since</th><th>Last error</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($dead as $row): ?>
                        <tr>
                            <td><?= $x($row['type']) ?></td>
                            <td><code><?= $x($row['subject_uuid']) ?></code></td>
                            <td><?= $x($row['created_at']) ?></td>
                            <td><?= (int) $row['attempts'] ?></td>
                            <td><?= $x($row['dead_at']) ?></td>
                            <td><?= $x($row['last_error']) ?></td>
                            <td><form method="post"><input type="hidden" name="event_id" value="<?= $x($row['event_id']) ?>"><button type="submit" class="btn btn-secondary btn-sm">Retry</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div></div>

        <div class="card"><div class="card-body">
            <h2>Recent events from NSP</h2>
            <?php if (!$inbox): ?>
                <p>None received yet.</p>
            <?php else: ?>
                <div style="overflow:auto"><table class="table">
                    <thead><tr><th>Received</th><th>Type</th><th>Startup</th><th>Status</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php foreach ($inbox as $row): ?>
                        <tr><td><?= $x($row['received_at']) ?></td><td><?= $x($row['type']) ?></td><td><code><?= $x($row['subject_uuid']) ?></code></td><td><?= $x($row['status']) ?></td><td><?= $x($row['note']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
