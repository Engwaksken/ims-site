<?php
/*
Add this inside Settings > Google Calendar after the configuration status box.
Requires includes/google-calendar-service.php on the page.
*/

require_once __DIR__ . '/includes/google-calendar-service.php';

$googleCalendarConnected =
    gcal_is_connected($conn);
?>

<div class="settings-confirm-box">
    <h4>
        <i class="fab fa-google"></i>
        Google Calendar Connection
    </h4>

    <div class="info-row">
        <span>API Connection</span>
        <strong>
            <?= $googleCalendarConnected ? 'Connected' : 'Not Connected' ?>
        </strong>
    </div>

    <div class="settings-actions">
        <?php if ($googleCalendarConnected): ?>
            <a
                href="includes/google-calendar-disconnect.php"
                class="btn btn-danger"
            >
                <i class="fas fa-unlink"></i>
                Disconnect Google Calendar
            </a>
        <?php else: ?>
            <a
                href="includes/google-calendar-auth.php"
                class="btn btn-primary"
            >
                <i class="fab fa-google"></i>
                Connect Google Calendar
            </a>
        <?php endif; ?>
    </div>
</div>
