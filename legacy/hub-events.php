<?php
declare(strict_types=1);

$page_title = 'Hub Space Booking';

include 'includes/header.php';

require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection unavailable.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('hub_book_e')) {
    function hub_book_e(mixed $value): string
    {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

$userId = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT
        m.*,
        u.full_name,
        u.email
    FROM members m
    INNER JOIN users u
        ON u.user_id = m.user_id
    WHERE m.user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $userId);
$stmt->execute();

$member =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$member) {
    echo '
        <div class="alert alert-danger">
            Only registered Hive Colab members can access space booking.
        </div>
    ';

    include 'includes/footer.php';
    exit;
}

$isActive =
    strcasecmp(
        (string)(
            $member['membership_status']
            ?? ''
        ),
        'Active'
    )
    === 0;

/*
 * Space list:
 * 1. use configured hub_spaces/spaces table when available;
 * 2. otherwise reuse distinct spaces already known by bookings;
 * 3. ensure the common Hive space types remain available.
 */
$spaces = [];

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'hub_spaces'"
    );

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $result = $conn->query("
        SELECT
            space_name,
            space_type
        FROM hub_spaces
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY space_type, space_name
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spaces[] = $row;
        }
    }
}

if (!$spaces) {
    $result = $conn->query("
        SELECT DISTINCT
            space_type,
            space_name
        FROM space_bookings
        WHERE space_type IS NOT NULL
          AND TRIM(space_type) <> ''
          AND space_name IS NOT NULL
          AND TRIM(space_name) <> ''
        ORDER BY space_type, space_name
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $spaces[] = $row;
        }
    }
}

if (!$spaces) {
    $spaces = [
        [
            'space_type' => 'Boardroom',
            'space_name' => 'Boardroom',
        ],
        [
            'space_type' => 'Meeting Room',
            'space_name' => 'Meeting Room',
        ],
        [
            'space_type' => 'Event Space',
            'space_name' => 'Event Space',
        ],
        [
            'space_type' => 'Private Office',
            'space_name' => 'Private Office',
        ],
        [
            'space_type' => 'Hot Desk',
            'space_name' => 'Hot Desk',
        ],
    ];
}

$myBookings = [];

$stmt = $conn->prepare("
    SELECT *
    FROM space_bookings
    WHERE member_id = ?
    ORDER BY
        booking_date DESC,
        start_time DESC
    LIMIT 100
");

$stmt->bind_param(
    'i',
    $member['member_id']
);

$stmt->execute();

$result =
    $stmt->get_result();

while (
    $row =
        $result->fetch_assoc()
) {
    $myBookings[] = $row;
}

$stmt->close();

$pendingCount = 0;
$confirmedCount = 0;

foreach ($myBookings as $booking) {
    if (
        ($booking['booking_status'] ?? '')
        === 'Pending'
    ) {
        $pendingCount++;
    }

    if (
        ($booking['booking_status'] ?? '')
        === 'Confirmed'
    ) {
        $confirmedCount++;
    }
}

$activeTab =
    (string)(
        $_GET['tab']
        ?? 'book'
    );

if (
    !in_array(
        $activeTab,
        [
            'book',
            'my-bookings',
        ],
        true
    )
) {
    $activeTab = 'book';
}
?>

<style>
.hb-hero{
    background:#fff;
    border:1px solid #e2e8f0;
    border-left:5px solid #ff6b35;
    border-radius:12px;
    padding:22px;
    margin-bottom:16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    flex-wrap:wrap;
}
.hb-hero h1{margin:0;color:#0f172a;font-size:22px;}
.hb-hero p{margin:5px 0 0;color:#64748b;font-size:11px;}
.hb-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#fff7ed;color:#9a3412;font-size:10px;font-weight:800;}
.hb-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px;}
.hb-stat{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #ff6b35;border-radius:9px;padding:13px;}
.hb-stat:nth-child(2){border-left-color:#f59e0b}.hb-stat:nth-child(3){border-left-color:#16a34a}
.hb-stat small{display:block;color:#64748b;font-size:9px;font-weight:800;text-transform:uppercase}
.hb-stat strong{display:block;margin-top:3px;color:#0f172a;font-size:19px}
.hb-tabs{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:16px}
.hb-tab{padding:11px 15px;margin-bottom:-2px;border-bottom:2px solid transparent;text-decoration:none;color:#64748b;font-size:11px;font-weight:800}
.hb-tab.active{color:#ea580c;border-bottom-color:#ea580c}
.hb-card{background:#fff;border:1px solid #e2e8f0;border-radius:11px;overflow:hidden;margin-bottom:16px}
.hb-card-head{padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.hb-card-head h3{margin:0;color:#0f172a;font-size:14px}
.hb-card-body{padding:16px}
.hb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.hb-grid .full{grid-column:1/-1}
.hb-note{padding:10px 12px;border-left:4px solid #2563eb;border-radius:7px;background:#eff6ff;color:#1e40af;font-size:10.5px;margin-bottom:13px}
.hb-warning{padding:12px;border-left:4px solid #dc2626;border-radius:7px;background:#fef2f2;color:#991b1b;font-size:11px}
.hb-slots{margin-top:8px;display:flex;gap:6px;flex-wrap:wrap}
.hb-slot{padding:5px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:9px;font-weight:700}
.hb-free{color:#166534;background:#dcfce7}
.hb-table-wrap{overflow-x:auto}
.hb-table{width:100%;border-collapse:collapse}
.hb-table th{background:#f8fafc;color:#64748b;padding:9px;text-align:left;font-size:8.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0}
.hb-table td{padding:10px 9px;border-bottom:1px solid #f1f5f9;font-size:10px;color:#334155}
.hb-status{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:8.5px;font-weight:800}
.hb-status.Pending{background:#fff7ed;color:#9a3412}
.hb-status.Confirmed{background:#ecfdf5;color:#166534}
.hb-status.Rejected,.hb-status.Cancelled{background:#fef2f2;color:#991b1b}
@media(max-width:800px){.hb-grid,.hb-stats{grid-template-columns:1fr}.hb-grid .full{grid-column:auto}}
</style>

<div class="hb-hero">
    <div>
        <h1>
            <i class="fas fa-calendar-check"></i>
            Hub Space Booking
        </h1>
        <p>
            Internal Hive Colab members can request only free dates and time slots.
            Operations/Admin reviews every request.
        </p>
    </div>

    <span class="hb-badge">
        <i class="fas fa-id-card"></i>
        <?= hub_book_e($member['membership_number'] ?? 'Member') ?>
        ·
        <?= hub_book_e($member['membership_status'] ?? '') ?>
    </span>
</div>

<div class="hb-stats">
    <div class="hb-stat">
        <small>My Requests</small>
        <strong><?= count($myBookings) ?></strong>
    </div>

    <div class="hb-stat">
        <small>Pending Approval</small>
        <strong><?= $pendingCount ?></strong>
    </div>

    <div class="hb-stat">
        <small>Confirmed</small>
        <strong><?= $confirmedCount ?></strong>
    </div>
</div>

<div class="hb-tabs">
    <a
        href="hub-events?tab=book"
        class="hb-tab <?= $activeTab === 'book' ? 'active' : '' ?>"
    >
        <i class="fas fa-plus-circle"></i>
        Request Booking
    </a>

    <a
        href="hub-events?tab=my-bookings"
        class="hb-tab <?= $activeTab === 'my-bookings' ? 'active' : '' ?>"
    >
        <i class="fas fa-list"></i>
        My Booking Requests
    </a>
</div>

<?php if (!$isActive): ?>
    <div class="hb-warning">
        <i class="fas fa-triangle-exclamation"></i>
        Your membership is not Active. Please contact Hub Operations before requesting a space.
    </div>
<?php elseif ($activeTab === 'book'): ?>

<div class="hb-card">
    <div class="hb-card-head">
        <h3>
            <i class="fas fa-calendar-plus"></i>
            Request a Free Slot
        </h3>

        <span style="font-size:9.5px;color:#64748b;">
            Pending requests reserve the slot until Operations decides.
        </span>
    </div>

    <div class="hb-card-body">

        <div class="hb-note">
            <i class="fas fa-circle-info"></i>
            Select a space, date and time. Existing Pending/Confirmed bookings are blocked.
            Hive Google Calendar events that use the same space/location are also blocked.
        </div>

        <form
            method="POST"
            action="includes/hub-events-process.php"
            id="hubBookingForm"
        >
            <div class="hb-grid">

                <div>
                    <label class="form-label required">
                        Space
                    </label>

                    <select
                        id="spaceSelect"
                        class="form-control"
                        required
                    >
                        <option value="">
                            Select a space...
                        </option>

                        <?php foreach ($spaces as $space): ?>
                            <option
                                value="<?= hub_book_e(
                                    ($space['space_type'] ?? '')
                                    . '||'
                                    . ($space['space_name'] ?? '')
                                ) ?>"
                            >
                                <?= hub_book_e($space['space_type'] ?? '') ?>
                                -
                                <?= hub_book_e($space['space_name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input
                        type="hidden"
                        name="space_type"
                        id="spaceType"
                    >

                    <input
                        type="hidden"
                        name="space_name"
                        id="spaceName"
                    >
                </div>

                <div>
                    <label class="form-label required">
                        Booking Date
                    </label>

                    <input
                        type="date"
                        name="booking_date"
                        id="bookingDate"
                        class="form-control"
                        min="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div>
                    <label class="form-label required">
                        Start Time
                    </label>

                    <input
                        type="time"
                        name="start_time"
                        id="startTime"
                        class="form-control"
                        required
                    >
                </div>

                <div>
                    <label class="form-label required">
                        End Time
                    </label>

                    <input
                        type="time"
                        name="end_time"
                        id="endTime"
                        class="form-control"
                        required
                    >
                </div>

                <div class="full">
                    <label class="form-label">
                        Occupied Slots
                    </label>

                    <div
                        id="occupiedSlots"
                        class="hb-slots"
                    >
                        <span
                            class="hb-slot hb-free"
                        >
                            Select a space and date to check availability.
                        </span>
                    </div>
                </div>

                <div class="full">
                    <label class="form-label required">
                        Purpose of Booking
                    </label>

                    <input
                        type="text"
                        name="purpose"
                        class="form-control"
                        placeholder="e.g. Team meeting, client session, workshop"
                        required
                    >
                </div>

                <div>
                    <label class="form-label">
                        Number of Attendees
                    </label>

                    <input
                        type="number"
                        name="number_of_attendees"
                        class="form-control"
                        min="1"
                        value="1"
                    >
                </div>

                <div>
                    <label class="form-label">
                        Additional Notes
                    </label>

                    <input
                        type="text"
                        name="notes"
                        class="form-control"
                        placeholder="Optional"
                    >
                </div>

                <div class="full">
                    <button
                        type="submit"
                        name="request_booking"
                        class="btn btn-primary"
                    >
                        <i class="fas fa-paper-plane"></i>
                        Submit Booking Request
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<?php else: ?>

<div class="hb-card">
    <div class="hb-card-head">
        <h3>
            <i class="fas fa-clock-rotate-left"></i>
            My Booking Requests
        </h3>
    </div>

    <div class="hb-table-wrap">
        <table class="hb-table">
            <thead>
                <tr>
                    <th>Space</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>

            <?php if (!$myBookings): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:28px;">
                        You have not submitted a booking request yet.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($myBookings as $booking): ?>
                <?php
                $bookingStatus =
                    (string)(
                        $booking['booking_status']
                        ?? 'Pending'
                    );
                ?>
                <tr>
                    <td>
                        <strong>
                            <?= hub_book_e($booking['space_name'] ?? '') ?>
                        </strong>
                        <br>
                        <small>
                            <?= hub_book_e($booking['space_type'] ?? '') ?>
                        </small>
                    </td>

                    <td>
                        <?= !empty($booking['booking_date'])
                            ? date(
                                'd M Y',
                                strtotime((string)$booking['booking_date'])
                            )
                            : '—' ?>
                    </td>

                    <td>
                        <?= !empty($booking['start_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['start_time'])
                            )
                            : '—' ?>
                        -
                        <?= !empty($booking['end_time'])
                            ? date(
                                'h:i A',
                                strtotime((string)$booking['end_time'])
                            )
                            : '—' ?>
                    </td>

                    <td>
                        <?= hub_book_e(
                            $booking['purpose']
                            ?? '—'
                        ) ?>
                    </td>

                    <td>
                        <span class="hb-status <?= hub_book_e($bookingStatus) ?>">
                            <?= hub_book_e($bookingStatus) ?>
                        </span>
                    </td>

                    <td>
                        <?php if (
                            $bookingStatus === 'Rejected'
                            &&
                            !empty($booking['rejection_reason'])
                        ): ?>
                            <?= hub_book_e($booking['rejection_reason']) ?>
                        <?php elseif (
                            $bookingStatus === 'Confirmed'
                        ): ?>
                            Approved by Operations
                        <?php elseif (
                            $bookingStatus === 'Pending'
                        ): ?>
                            Awaiting review
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<script>
const spaceSelect =
    document.getElementById(
        'spaceSelect'
    );

const bookingDate =
    document.getElementById(
        'bookingDate'
    );

async function loadAvailability() {
    if (
        !spaceSelect
        ||
        !bookingDate
        ||
        !spaceSelect.value
        ||
        !bookingDate.value
    ) {
        return;
    }

    const [
        spaceType,
        spaceName
    ] =
        spaceSelect.value.split(
            '||'
        );

    document
        .getElementById(
            'spaceType'
        )
        .value =
        spaceType || '';

    document
        .getElementById(
            'spaceName'
        )
        .value =
        spaceName || '';

    const target =
        document.getElementById(
            'occupiedSlots'
        );

    target.innerHTML =
        '<span class="hb-slot hb-free">Checking...</span>';

    try {
        const url =
            'includes/hub-events-process.php'
            + '?action=availability'
            + '&space_type='
            + encodeURIComponent(
                spaceType
            )
            + '&space_name='
            + encodeURIComponent(
                spaceName
            )
            + '&booking_date='
            + encodeURIComponent(
                bookingDate.value
            );

        const response =
            await fetch(
                url,
                {
                    credentials:
                        'same-origin',
                }
            );

        const data =
            await response.json();

        if (
            !data.success
            ||
            !Array.isArray(
                data.slots
            )
        ) {
            throw new Error(
                data.message
                ||
                'Availability unavailable.'
            );
        }

        if (
            data.slots.length
            ===
            0
        ) {
            target.innerHTML =
                '<span class="hb-slot hb-free">No IMS bookings on this date — choose your preferred time.</span>';

            return;
        }

        target.innerHTML =
            data.slots
            .map(
                slot =>
                    '<span class="hb-slot">'
                    + String(
                        slot.start_time
                        || ''
                    ).substring(0, 5)
                    + ' - '
                    + String(
                        slot.end_time
                        || ''
                    ).substring(0, 5)
                    + ' · '
                    + (
                        slot.booking_status
                        || ''
                    )
                    + '</span>'
            )
            .join('');
    } catch (error) {
        target.innerHTML =
            '<span class="hb-slot">Could not load availability. The server will still validate before saving.</span>';
    }
}

if (spaceSelect) {
    spaceSelect.addEventListener(
        'change',
        loadAvailability
    );
}

if (bookingDate) {
    bookingDate.addEventListener(
        'change',
        loadAvailability
    );
}

const bookingForm =
    document.getElementById(
        'hubBookingForm'
    );

if (bookingForm) {
    bookingForm.addEventListener(
        'submit',
        function (event) {
            const start =
                document.getElementById(
                    'startTime'
                ).value;

            const end =
                document.getElementById(
                    'endTime'
                ).value;

            if (
                start
                &&
                end
                &&
                end <= start
            ) {
                event.preventDefault();

                alert(
                    'End time must be after start time.'
                );

                return;
            }

            if (
                spaceSelect
                &&
                spaceSelect.value
            ) {
                const [
                    type,
                    name
                ] =
                    spaceSelect.value.split(
                        '||'
                    );

                document
                    .getElementById(
                        'spaceType'
                    )
                    .value =
                    type || '';

                document
                    .getElementById(
                        'spaceName'
                    )
                    .value =
                    name || '';
            }
        }
    );
}
</script>

<?php include 'includes/footer.php'; ?>
