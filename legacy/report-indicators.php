<?php
$page_title = 'Indicator Reports & Analytics';
$load_chartjs = true; // header.php loads Chart.js in <head> so inline chart scripts can run
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

check_role(['Administrator', 'Programs Lead', 'MEAL Lead', 'Project Officer']);

// Get filters
$project_filter = isset($_GET['project']) ? intval($_GET['project']) : 0;
$type_filter = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_input($_GET['date_from']) : date('Y-m-d', strtotime('-6 months'));
$date_to = isset($_GET['date_to']) ? sanitize_input($_GET['date_to']) : date('Y-m-d');

// Dates are interpolated into SQL below: accept strict YYYY-MM-DD only.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-6 months'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Build WHERE clause
$where = "1=1";
if ($project_filter) {
    $where .= " AND i.project_id = $project_filter";
}
if ($type_filter) {
    $where .= " AND i.indicator_type = '" . $conn->real_escape_string($type_filter) . "'";
}

// Fetch all indicators with achievement data
$indicators = [];
$query = "SELECT i.*, p.project_name, p.project_code, p.status as project_status,
    CASE WHEN i.target_value > 0 THEN (i.current_value / i.target_value * 100) ELSE 0 END as achievement_percentage,
    (SELECT COUNT(*) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id) as total_updates,
    (SELECT COUNT(*) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id 
     AND ip.recorded_date BETWEEN '$date_from' AND '$date_to') as period_updates,
    (SELECT MAX(recorded_date) FROM indicator_progress ip WHERE ip.indicator_id = i.indicator_id) as last_update_date
    FROM indicators i
    JOIN projects p ON i.project_id = p.project_id
    WHERE $where
    ORDER BY p.project_name, i.indicator_type, i.indicator_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $indicators[] = $row;
}

// Calculate statistics
$stats = [
    'total' => count($indicators),
    'outputs' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Output'; })),
    'outcomes' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Outcome'; })),
    'impacts' => count(array_filter($indicators, function($i) { return $i['indicator_type'] == 'Impact'; })),
    'achieved' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] >= 100; })),
    'on_track' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] >= 50 && $i['achievement_percentage'] < 100; })),
    'below_target' => count(array_filter($indicators, function($i) { return $i['achievement_percentage'] < 50; })),
    'no_progress' => count(array_filter($indicators, function($i) { return $i['current_value'] == $i['baseline_value']; }))
];

// Calculate average achievement by type
$avg_by_type = [];
foreach (['Output', 'Outcome', 'Impact'] as $type) {
    $type_indicators = array_filter($indicators, function($i) use ($type) { return $i['indicator_type'] == $type; });
    if (!empty($type_indicators)) {
        $avg_by_type[$type] = array_sum(array_column($type_indicators, 'achievement_percentage')) / count($type_indicators);
    } else {
        $avg_by_type[$type] = 0;
    }
}

// Get projects for filter
$projects = [];
$result = $conn->query("SELECT project_id, project_name, project_code FROM projects ORDER BY project_name");
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

// Get indicators with progress over time (for trend analysis)
$trend_data = [];
if (!empty($indicators)) {
    foreach (array_slice($indicators, 0, 5) as $indicator) {
        $progress = [];
        $query = "SELECT reporting_period, actual_value, achievement_percentage, recorded_date 
            FROM indicator_progress 
            WHERE indicator_id = {$indicator['indicator_id']} 
            AND recorded_date BETWEEN '$date_from' AND '$date_to'
            ORDER BY recorded_date";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        if (!empty($progress)) {
            $trend_data[] = [
                'indicator' => $indicator,
                'progress' => $progress
            ];
        }
    }
}

// Top and bottom performers
$sorted_by_achievement = $indicators;
usort($sorted_by_achievement, function($a, $b) {
    return $b['achievement_percentage'] <=> $a['achievement_percentage'];
});
$top_performers = array_slice($sorted_by_achievement, 0, 5);
$bottom_performers = array_slice(array_reverse($sorted_by_achievement), 0, 5);

// Indicators needing attention (no updates in last 30 days)
$stale_indicators = array_filter($indicators, function($i) {
    if (!$i['last_update_date']) return true;
    $days_since_update = (strtotime('now') - strtotime($i['last_update_date'])) / (60 * 60 * 24);
    return $days_since_update > 30;
});
?>

<style>
.analytics-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.metric-box {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    transition: all 0.3s;
}

.metric-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.metric-box h2 {
    font-size: 36px;
    margin: 10px 0;
    color: var(--primary-color);
}

.metric-box p {
    color: #7f8c8d;
    font-size: 14px;
    margin: 0;
}

.progress-mini {
    height: 8px;
    background: #ddd;
    border-radius: 4px;
    overflow: hidden;
    margin: 5px 0;
}

.progress-mini-bar {
    height: 100%;
    transition: width 0.3s;
}

@media print {
    .no-print { display: none !important; }
    .analytics-card { page-break-inside: avoid; }
}
</style>

<!-- Filters -->
<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Report Filters</h3>
        <div>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="filters-bar">
            <div class="form-group">
                <label>Project</label>
                <select name="project" class="form-control">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['project_id']; ?>" <?php echo $project_filter == $project['project_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['project_code'] . ' - ' . $project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Indicator Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="Output" <?php echo $type_filter == 'Output' ? 'selected' : ''; ?>>Output</option>
                    <option value="Outcome" <?php echo $type_filter == 'Outcome' ? 'selected' : ''; ?>>Outcome</option>
                    <option value="Impact" <?php echo $type_filter == 'Impact' ? 'selected' : ''; ?>>Impact</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="form-group">
                <label>Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="report-indicators" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Report Header -->
<div class="analytics-card" style="background: linear-gradient(135deg, var(--primary-color), #3498DB); color: white; text-align: center;">
    <h1 style="margin: 0; font-size: 32px; color: white;">Indicator Performance Analytics</h1>
    <p style="margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;">
        Period: <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
    </p>
    <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.8;">
        Generated: <?php echo date('d M Y H:i'); ?> by <?php echo htmlspecialchars($_SESSION['full_name']); ?>
    </p>
</div>

<!-- Key Performance Metrics -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
    <div class="metric-box">
        <i class="fas fa-chart-line" style="font-size: 24px; color: var(--primary-color);"></i>
        <h2><?php echo $stats['total']; ?></h2>
        <p>Total Indicators</p>
    </div>
    
    <div class="metric-box">
        <i class="fas fa-check-circle" style="font-size: 24px; color: #2ECC71;"></i>
        <h2><?php echo $stats['achieved']; ?></h2>
        <p>Achieved (≥100%)</p>
    </div>
    
    <div class="metric-box">
        <i class="fas fa-chart-bar" style="font-size: 24px; color: #F39C12;"></i>
        <h2><?php echo $stats['on_track']; ?></h2>
        <p>On Track (50-99%)</p>
    </div>
    
    <div class="metric-box">
        <i class="fas fa-exclamation-triangle" style="font-size: 24px; color: #E74C3C;"></i>
        <h2><?php echo $stats['below_target']; ?></h2>
        <p>Below Target (<50%)</p>
    </div>
    
    <div class="metric-box">
        <i class="fas fa-pause-circle" style="font-size: 24px; color: #95A5A6;"></i>
        <h2><?php echo $stats['no_progress']; ?></h2>
        <p>No Progress</p>
    </div>
</div>

<!-- Achievement by Type -->
<div class="analytics-card">
    <h3><i class="fas fa-layer-group"></i> Achievement by Indicator Type</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
        <?php foreach (['Output', 'Outcome', 'Impact'] as $type): ?>
            <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong><?php echo $type; ?>s</strong>
                    <span style="font-size: 24px; font-weight: bold; color: <?php 
                        echo $avg_by_type[$type] >= 75 ? '#2ECC71' : ($avg_by_type[$type] >= 50 ? '#F39C12' : '#E74C3C'); 
                    ?>;">
                        <?php echo number_format($avg_by_type[$type], 1); ?>%
                    </span>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo min($avg_by_type[$type], 100); ?>%">
                        <?php echo number_format($avg_by_type[$type], 1); ?>%
                    </div>
                </div>
                <div style="margin-top: 10px; color: #7f8c8d; font-size: 14px;">
                    <?php echo $stats[strtolower($type) . 's']; ?> indicators
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Achievement Distribution Chart -->
<div class="analytics-card">
    <h3><i class="fas fa-chart-pie"></i> Achievement Distribution</h3>
    <canvas id="achievementChart" style="max-height: 300px;"></canvas>
</div>

<!-- Top Performers -->
<?php if (!empty($top_performers)): ?>
<div class="analytics-card">
    <h3><i class="fas fa-trophy"></i> Top Performing Indicators</h3>
    <div style="margin-top: 20px;">
        <?php foreach ($top_performers as $indicator): ?>
            <div style="padding: 15px; margin-bottom: 10px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #2ECC71;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <div style="flex: 1;">
                        <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                        <br>
                        <small style="color: #7f8c8d;">
                            <?php echo htmlspecialchars($indicator['project_code']); ?> | 
                            <?php echo $indicator['indicator_type']; ?>
                            <?php if ($indicator['unit_of_measure']): ?>
                                | <?php echo htmlspecialchars($indicator['unit_of_measure']); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div style="text-align: right; min-width: 120px;">
                        <div style="font-size: 24px; font-weight: bold; color: #2ECC71;">
                            <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                        </div>
                        <small style="color: #7f8c8d;">
                            <?php echo number_format($indicator['current_value'], 2); ?> / 
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </small>
                    </div>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo min($indicator['achievement_percentage'], 100); ?>%">
                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Indicators Needing Attention -->
<?php if (!empty($bottom_performers)): ?>
<div class="analytics-card">
    <h3><i class="fas fa-exclamation-circle"></i> Indicators Needing Attention</h3>
    <div style="margin-top: 20px;">
        <?php foreach ($bottom_performers as $indicator): ?>
            <div style="padding: 15px; margin-bottom: 10px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #E74C3C;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <div style="flex: 1;">
                        <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                        <br>
                        <small style="color: #7f8c8d;">
                            <?php echo htmlspecialchars($indicator['project_code']); ?> | 
                            <?php echo $indicator['indicator_type']; ?>
                            <?php if ($indicator['responsible_person']): ?>
                                | Responsible: <?php echo htmlspecialchars($indicator['responsible_person']); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div style="text-align: right; min-width: 120px;">
                        <div style="font-size: 24px; font-weight: bold; color: #E74C3C;">
                            <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                        </div>
                        <small style="color: #7f8c8d;">
                            <?php echo number_format($indicator['current_value'], 2); ?> / 
                            <?php echo number_format($indicator['target_value'], 2); ?>
                        </small>
                    </div>
                </div>
                <div class="progress">
                    <div class="progress-bar" style="width: <?php echo $indicator['achievement_percentage']; ?>%; background: #E74C3C;">
                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Stale Indicators -->
<?php if (!empty($stale_indicators)): ?>
<div class="analytics-card no-print">
    <h3><i class="fas fa-clock"></i> Indicators Without Recent Updates (30+ days)</h3>
    <div class="alert alert-warning">
        <i class="fas fa-info-circle"></i>
        <strong><?php echo count($stale_indicators); ?></strong> indicator(s) haven't been updated in the last 30 days. 
        Regular updates are essential for accurate progress tracking.
    </div>
    <div style="margin-top: 20px;">
        <?php foreach (array_slice($stale_indicators, 0, 10) as $indicator): ?>
            <div style="padding: 10px; margin-bottom: 8px; background: #fff3cd; border-radius: 5px; display: flex; justify-content: space-between;">
                <div>
                    <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                    <br>
                    <small style="color: #856404;">
                        <?php echo htmlspecialchars($indicator['project_code']); ?>
                        <?php if ($indicator['last_update_date']): ?>
                            | Last updated: <?php echo date('d M Y', strtotime($indicator['last_update_date'])); ?>
                        <?php else: ?>
                            | Never updated
                        <?php endif; ?>
                    </small>
                </div>
                <a href="indicators?progress=<?php echo $indicator['indicator_id']; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Update
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Progress Trends -->
<?php if (!empty($trend_data)): ?>
<div class="analytics-card">
    <h3><i class="fas fa-chart-area"></i> Progress Trends (Top 5 Most Active)</h3>
    <?php foreach ($trend_data as $trend): ?>
        <div style="margin-bottom: 40px;">
            <h4><?php echo htmlspecialchars($trend['indicator']['indicator_name']); ?></h4>
            <small style="color: #7f8c8d;">
                <?php echo htmlspecialchars($trend['indicator']['project_name']); ?> | 
                <?php echo $trend['indicator']['indicator_type']; ?>
            </small>
            <canvas id="trendChart<?php echo $trend['indicator']['indicator_id']; ?>" style="max-height: 200px; margin-top: 15px;"></canvas>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Detailed Indicator List -->
<div class="analytics-card">
    <h3><i class="fas fa-list"></i> All Indicators (<?php echo count($indicators); ?>)</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Indicator</th>
                    <th>Type</th>
                    <th>Target</th>
                    <th>Current</th>
                    <th>Achievement</th>
                    <th>Updates</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($indicators)): ?>
                    <tr>
                        <td colspan="8" class="text-center">No indicators found matching the criteria</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($indicators as $indicator): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($indicator['project_code']); ?></strong>
                                <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars($indicator['project_name']); ?></small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($indicator['indicator_name']); ?></strong>
                                <?php if ($indicator['unit_of_measure']): ?>
                                    <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars($indicator['unit_of_measure']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php 
                                    echo $indicator['indicator_type'] == 'Output' ? 'info' : 
                                        ($indicator['indicator_type'] == 'Outcome' ? 'warning' : 'secondary'); 
                                ?>">
                                    <?php echo $indicator['indicator_type']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($indicator['target_value'], 2); ?></td>
                            <td><strong><?php echo number_format($indicator['current_value'], 2); ?></strong></td>
                            <td>
                                <div style="min-width: 100px;">
                                    <span style="font-weight: bold; color: <?php 
                                        echo $indicator['achievement_percentage'] >= 100 ? '#2ECC71' : 
                                            ($indicator['achievement_percentage'] >= 50 ? '#F39C12' : '#E74C3C'); 
                                    ?>;">
                                        <?php echo number_format($indicator['achievement_percentage'], 1); ?>%
                                    </span>
                                    <div class="progress-mini">
                                        <div class="progress-mini-bar" style="width: <?php echo min($indicator['achievement_percentage'], 100); ?>%; background: <?php 
                                            echo $indicator['achievement_percentage'] >= 100 ? '#2ECC71' : 
                                                ($indicator['achievement_percentage'] >= 50 ? '#F39C12' : '#E74C3C'); 
                                        ?>;"></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $indicator['total_updates']; ?></td>
                            <td>
                                <?php if ($indicator['achievement_percentage'] >= 100): ?>
                                    <i class="fas fa-check-circle" style="color: #2ECC71;"></i> Achieved
                                <?php elseif ($indicator['achievement_percentage'] >= 50): ?>
                                    <i class="fas fa-chart-line" style="color: #F39C12;"></i> On Track
                                <?php else: ?>
                                    <i class="fas fa-exclamation-triangle" style="color: #E74C3C;"></i> Behind
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Achievement Distribution Chart
const achievementCtx = document.getElementById('achievementChart');
if (achievementCtx) {
    new Chart(achievementCtx, {
        type: 'doughnut',
        data: {
            labels: ['Achieved (≥100%)', 'On Track (50-99%)', 'Below Target (<50%)', 'No Progress'],
            datasets: [{
                data: [<?php echo $stats['achieved']; ?>, <?php echo $stats['on_track']; ?>, <?php echo $stats['below_target']; ?>, <?php echo $stats['no_progress']; ?>],
                backgroundColor: ['#2ECC71', '#F39C12', '#E74C3C', '#95A5A6']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Progress Trend Charts
<?php foreach ($trend_data as $trend): ?>
    const trendCtx<?php echo $trend['indicator']['indicator_id']; ?> = document.getElementById('trendChart<?php echo $trend['indicator']['indicator_id']; ?>');
    if (trendCtx<?php echo $trend['indicator']['indicator_id']; ?>) {
        new Chart(trendCtx<?php echo $trend['indicator']['indicator_id']; ?>, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($trend['progress'], 'reporting_period')); ?>,
                datasets: [{
                    label: 'Actual Value',
                    data: <?php echo json_encode(array_column($trend['progress'], 'actual_value')); ?>,
                    borderColor: '#3498DB',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Target',
                    data: <?php echo json_encode(array_fill(0, count($trend['progress']), $trend['indicator']['target_value'])); ?>,
                    borderColor: '#2ECC71',
                    borderDash: [5, 5],
                    tension: 0,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
<?php endforeach; ?>

function exportToExcel() {
    window.location.href = 'export?type=indicators<?php echo $project_filter ? "&project=" . (int)$project_filter : ""; ?><?php echo $type_filter ? "&indicator_type=" . h(rawurlencode($type_filter)) : ""; ?>';
}
</script>

<?php include 'includes/footer.php'; ?>