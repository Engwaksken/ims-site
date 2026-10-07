<?php
ob_start();

date_default_timezone_set('Africa/Nairobi');

$page_title = 'Internet Connection Logs';
require_once 'includes/header.php';

check_role(['Administrator', 'Operations/Admin', 'Accountant']);

if (!isset($conn) || !$conn instanceof mysqli) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+03:00'");

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$q        = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$export   = trim((string)($_GET['export'] ?? ''));

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $where[] = "(
        l.mac_address LIKE ?
        OR l.ip_address LIKE ?
        OR m.membership_number LIKE ?
        OR u.full_name LIKE ?
        OR p.plan_name LIKE ?
    )";

    $like = '%' . $q . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

if ($dateFrom !== '') {
    $where[] = "DATE(l.connected_at) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = "DATE(l.connected_at) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| BASE QUERY
|--------------------------------------------------------------------------
*/
$baseSql = "
    FROM internet_connection_logs l
    LEFT JOIN internet_subscriptions s ON s.id = l.subscription_id
    LEFT JOIN internet_plans p ON p.id = s.plan_id
    LEFT JOIN members m ON m.member_id = l.member_id
    LEFT JOIN users u ON u.user_id = m.user_id
    $whereSql
";

/*
|--------------------------------------------------------------------------
| CSV EXPORT
|--------------------------------------------------------------------------
*/
if ($export === 'csv') {
    $sql = "
        SELECT
            l.connected_at,
            l.mac_address,
            l.ip_address,
            p.plan_name,
            m.membership_number,
            u.full_name
        $baseSql
        ORDER BY l.connected_at DESC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt && $types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=internet_connection_logs_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');

        ims_fputcsv($out, [
            'Connected At',
            'MAC Address',
            'IP Address',
            'Plan',
            'Membership Number',
            'Member Name',
        ]);

        while ($row = $res->fetch_assoc()) {
            ims_fputcsv($out, [
                $row['connected_at'] ?? '',
                $row['mac_address'] ?? '',
                $row['ip_address'] ?? '',
                $row['plan_name'] ?? '',
                $row['membership_number'] ?? '',
                $row['full_name'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| KPI COUNTS
|--------------------------------------------------------------------------
*/
$totalConnections = 0;
$todayConnections = 0;
$uniqueDevices = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM internet_connection_logs");
if ($res) {
    $totalConnections = (int)$res->fetch_assoc()['total'];
}

$res = $conn->query("SELECT COUNT(*) AS total FROM internet_connection_logs WHERE DATE(connected_at) = CURDATE()");
if ($res) {
    $todayConnections = (int)$res->fetch_assoc()['total'];
}

$res = $conn->query("SELECT COUNT(DISTINCT mac_address) AS total FROM internet_connection_logs");
if ($res) {
    $uniqueDevices = (int)$res->fetch_assoc()['total'];
}

/*
|--------------------------------------------------------------------------
| TOTAL FILTERED
|--------------------------------------------------------------------------
*/
$countSql = "SELECT COUNT(*) AS total $baseSql";
$countStmt = $conn->prepare($countSql);

if ($countStmt && $types !== '') {
    $countStmt->bind_param($types, ...$params);
}

$totalRows = 0;

if ($countStmt) {
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalRows = (int)($countRes->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $limit));

/*
|--------------------------------------------------------------------------
| FETCH LOGS
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        l.id,
        l.subscription_id,
        l.member_id,
        l.mac_address,
        l.ip_address,
        l.user_agent,
        l.connected_at,
        p.plan_name,
        p.duration_minutes,
        s.expires_at,
        s.status AS subscription_status,
        m.membership_number,
        u.full_name,
        u.phone
    $baseSql
    ORDER BY l.connected_at DESC
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listTypes = $types . 'ii';
$listParams[] = $limit;
$listParams[] = $offset;

$stmt = $conn->prepare($sql);

$logs = [];

if ($stmt) {
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }

    $stmt->close();
}

function remainingTime(?string $expiresAt): string {
    if (empty($expiresAt)) {
        return 'Not set';
    }

    $left = strtotime($expiresAt) - time();

    if ($left <= 0) {
        return 'Expired';
    }

    $minutes = (int)ceil($left / 60);

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    if ($minutes < 1440) {
        return round($minutes / 60, 1) . ' hrs';
    }

    return round($minutes / 1440, 1) . ' days';
}

$queryString = $_GET;
unset($queryString['page']);
$baseLink = '?' . http_build_query($queryString);
$baseLink .= $baseLink === '?' ? '' : '&';
?>
<style>
        .page-conn-logs {
            margin: 0;
            background: #f1f5f9;
            border-radius: 14px;
            color: #080808;
        }

        .page-conn-logs.page {
            padding: 24px;
        }

        .page-conn-logs .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-conn-logs h1 {
            margin: 0;
            font-size: 25px;
        }

        .page-conn-logs .header p {
            margin: 6px 0 0;
            color: #64748b;
        }

        .page-conn-logs .btn {
            display: inline-block;
            background: #ff6b35;
            color: #fff;
            padding: 10px 14px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }

        .page-conn-logs .btn.secondary {
            background: #475569;
        }

        .page-conn-logs .btn.green {
            background: #16a34a;
        }

        .page-conn-logs .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .page-conn-logs .card {
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(15,23,42,.08);
        }

        .page-conn-logs .card span {
            color: #64748b;
            font-size: 14px;
        }

        .page-conn-logs .card strong {
            display: block;
            font-size: 28px;
            margin-top: 8px;
        }

        .page-conn-logs .filters {
            background: #fff;
            padding: 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            box-shadow: 0 8px 20px rgba(15,23,42,.08);
        }

        .page-conn-logs .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 10px;
            align-items: end;
        }

        .page-conn-logs label {
            display: block;
            font-size: 13px;
            color: #475569;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .page-conn-logs input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        .page-conn-logs .table-wrap {
            background: #fff;
            border-radius: 14px;
            overflow-x: auto;
            box-shadow: 0 8px 20px rgba(15,23,42,.08);
        }

        .page-conn-logs table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .page-conn-logs th,
        .page-conn-logs td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            text-align: left;
        }

        .page-conn-logs th {
            background: #f8fafc;
            color: #334155;
        }

        .page-conn-logs tr:hover td {
            background: #f8fafc;
        }

        .page-conn-logs .badge {
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
        }

        .page-conn-logs .active {
            background: #dcfce7;
            color: #166534;
        }

        .page-conn-logs .expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .page-conn-logs .muted {
            color: #64748b;
        }

        .page-conn-logs .pagination {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 18px;
        }

        .page-conn-logs .pagination a,
        .page-conn-logs .pagination span {
            padding: 8px 12px;
            background: #fff;
            border-radius: 8px;
            text-decoration: none;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .page-conn-logs .pagination .current {
            background: #ff6b35;
            color: #fff;
            border-color: #ff6b35;
        }

        @media (max-width: 900px) {
            .page-conn-logs .cards,
            .page-conn-logs .filter-grid {
                grid-template-columns: 1fr;
            }

            .page-conn-logs .header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>

<div class="page page-conn-logs">

    <div class="header">
        <div>
            <h1>Internet Connection Logs</h1>
            <p>Monitor connected devices, active sessions, and connection history.</p>
        </div>

        <a class="btn green" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">
            Export CSV
        </a>
    </div>

    <div class="cards">
        <div class="card">
            <span>Total Connections</span>
            <strong><?= number_format($totalConnections) ?></strong>
        </div>

        <div class="card">
            <span>Today Connections</span>
            <strong><?= number_format($todayConnections) ?></strong>
        </div>

        <div class="card">
            <span>Unique Devices</span>
            <strong><?= number_format($uniqueDevices) ?></strong>
        </div>
    </div>

    <form method="get" class="filters">
        <div class="filter-grid">
            <div>
                <label>Search</label>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search MAC, IP, member, plan...">
            </div>

            <div>
                <label>Date From</label>
                <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
            </div>

            <div>
                <label>Date To</label>
                <input type="date" name="date_to" value="<?= h($dateTo) ?>">
            </div>

            <button class="btn" type="submit">Apply</button>

            <a href="internet_connection_logs" class="btn secondary">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Connected At</th>
                    <th>Member</th>
                    <th>Membership No.</th>
                    <th>MAC Address</th>
                    <th>IP Address</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Remaining</th>
                    <th>User Agent</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="10" class="muted">No connection logs found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $index => $log): ?>
                        <?php
                            $remaining = remainingTime($log['expires_at'] ?? null);
                            $isActive = strtolower((string)($log['subscription_status'] ?? '')) === 'active'
                                && $remaining !== 'Expired';
                        ?>
                        <tr>
                            <td><?= $offset + $index + 1 ?></td>
                            <td><?= h($log['connected_at'] ?? '') ?></td>
                            <td><?= h($log['full_name'] ?? '-') ?></td>
                            <td><?= h($log['membership_number'] ?? '-') ?></td>
                            <td><?= h($log['mac_address'] ?? '-') ?></td>
                            <td><?= h($log['ip_address'] ?? '-') ?></td>
                            <td><?= h($log['plan_name'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= $isActive ? 'active' : 'expired' ?>">
                                    <?= $isActive ? 'Active' : 'Expired' ?>
                                </span>
                            </td>
                            <td><?= h($remaining) ?></td>
                            <td class="muted"><?= h(substr((string)($log['user_agent'] ?? '-'), 0, 80)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= h($baseLink . 'page=' . ($page - 1)) ?>">Prev</a>
        <?php endif; ?>

        <span class="current">
            Page <?= $page ?> of <?= $totalPages ?>
        </span>

        <?php if ($page < $totalPages): ?>
            <a href="<?= h($baseLink . 'page=' . ($page + 1)) ?>">Next</a>
        <?php endif; ?>
    </div>

</div><!-- /.page-conn-logs -->

<?php include 'includes/footer.php'; ?>
