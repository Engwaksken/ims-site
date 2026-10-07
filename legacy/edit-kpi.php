<?php
/*
 * Edit KPI
 *
 * The kpis table uses the employee-KPI schema (fiscal_year, category_id,
 * quarterly targets). The form further below was written for an older
 * project-KPI schema (project_id, owner, kpi_name) whose columns no longer
 * exist, so every edit failed. Editing is now handled by the create-kpi
 * form in edit mode, which saves through kpi-process.php (action=update).
 */
$kpi_edit_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($kpi_edit_id > 0) {
    require __DIR__ . '/create-kpi.php';
    exit();
}

header('Location: my-kpis');
exit();

// ---------------------------------------------------------------------
// Legacy project-KPI editor (unreachable, kept for reference).
// ---------------------------------------------------------------------
$page_title = 'Edit KPI';
include 'includes/header.php';

// Check if user is logged in and has appropriate access
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to access this page.";
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Check if user has permission to edit KPIs
$allowed_roles = ['Administrator', 'Programs Lead', 'MEAL Lead','staff'];
if (!in_array($user_role, $allowed_roles)) {
    $_SESSION['error'] = "Access denied. You don't have permission to edit KPIs.";
    header("Location: dashboard");
    exit();
}

// Get KPI ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "KPI ID not provided.";
    header("Location: kpi-management");
    exit();
}

$kpi_id = intval($_GET['id']);

// Fetch KPI details
$query = "SELECT k.*, 
          p.project_name,
          owner.full_name as owner_name
          FROM kpis k
          LEFT JOIN projects p ON k.project_id = p.project_id
          LEFT JOIN users owner ON k.owner = owner.user_id
          WHERE k.kpi_id = $kpi_id";

$result = $conn->query($query);

if ($result->num_rows == 0) {
    $_SESSION['error'] = "KPI not found.";
    header("Location: kpi-management");
    exit();
}

$kpi = $result->fetch_assoc();

// Fetch all projects for dropdown
$projects = [];
$projects_query = "SELECT project_id, project_name, project_status FROM projects ORDER BY project_name";
$projects_result = $conn->query($projects_query);
while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}

// Fetch all staff for owner dropdown
$staff = [];
$staff_query = "SELECT user_id, full_name, role FROM users WHERE is_active = '1' ORDER BY full_name";
$staff_result = $conn->query($staff_query);
while ($row = $staff_result->fetch_assoc()) {
    $staff[] = $row;
}

// Calculate progress
$progress_percentage = 0;
if ($kpi['target_value'] > 0) {
    $progress_percentage = min(100, ($kpi['current_value'] / $kpi['target_value']) * 100);
}
?>

<style>
.kpi-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.kpi-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.edit-form-container {
    max-width: 1200px;
    margin: 0 auto;
}

.form-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 25px;
}

.form-card h3 {
    color: #2c3e50;
    margin-bottom: 25px;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid #ecf0f1;
}

.form-card h3 i {
    color: #ff6b35;
}

.kpi-current-status {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.status-item {
    background: white;
    padding: 15px;
    border-radius: 8px;
    text-align: center;
}

.status-item .label {
    font-size: 12px;
    color: #7f8c8d;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.status-item .value {
    font-size: 24px;
    font-weight: 700;
    color: #2c3e50;
}

.progress-bar-container {
    background: #ecf0f1;
    height: 30px;
    border-radius: 15px;
    overflow: hidden;
    margin-top: 10px;
    position: relative;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2ECC71 0%, #27AE60 100%);
    transition: width 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 14px;
}

.progress-bar.low {
    background: linear-gradient(90deg, #E74C3C 0%, #C0392B 100%);
}

.progress-bar.medium {
    background: linear-gradient(90deg, #F39C12 0%, #E67E22 100%);
}

.form-section {
    margin-bottom: 30px;
}

.form-section:last-child {
    margin-bottom: 0;
}

.alert-info {
    background: #d1ecf1;
    border-left: 4px solid #17a2b8;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #0c5460;
}

.button-group {
    display: flex;
    gap: 15px;
    padding-top: 20px;
    border-top: 2px solid #ecf0f1;
    margin-top: 30px;
}

@media (max-width: 768px) {
    .status-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .button-group {
        flex-direction: column;
    }
    
    .button-group button,
    .button-group a {
        width: 100%;
    }
}
</style>

<!-- Header -->
<div class="kpi-header">
    <h1><i class="fas fa-chart-line"></i> Edit KPI</h1>
    <p style="margin: 0; opacity: 0.9;">Update Key Performance Indicator details and targets</p>
</div>

<div class="edit-form-container">
    <!-- Current Status -->
    <div class="kpi-current-status">
        <h4 style="margin-bottom: 15px; color: #2c3e50;">
            <i class="fas fa-info-circle"></i> Current KPI Status
        </h4>
        
        <div class="status-grid">
            <div class="status-item">
                <div class="label">Current Value</div>
                <div class="value" style="color: #3498DB;"><?php echo number_format($kpi['current_value'], 2); ?></div>
            </div>
            
            <div class="status-item">
                <div class="label">Target Value</div>
                <div class="value" style="color: #2ECC71;"><?php echo number_format($kpi['target_value'], 2); ?></div>
            </div>
            
            <div class="status-item">
                <div class="label">Progress</div>
                <div class="value" style="color: <?php echo $progress_percentage < 50 ? '#E74C3C' : ($progress_percentage < 80 ? '#F39C12' : '#2ECC71'); ?>;">
                    <?php echo number_format($progress_percentage, 1); ?>%
                </div>
            </div>
            
            <div class="status-item">
                <div class="label">Status</div>
                <div class="value">
                    <?php
                    $status_colors = [
                        'On Track' => '#2ECC71',
                        'At Risk' => '#F39C12',
                        'Off Track' => '#E74C3C',
                        'Achieved' => '#3498DB',
                        'Not Started' => '#95A5A6'
                    ];
                    $color = $status_colors[$kpi['status']] ?? '#95A5A6';
                    ?>
                    <span style="color: <?php echo $color; ?>; font-size: 18px;">
                        <?php echo htmlspecialchars($kpi['status']); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="progress-bar-container">
            <div class="progress-bar <?php echo $progress_percentage < 50 ? 'low' : ($progress_percentage < 80 ? 'medium' : ''); ?>" 
                 style="width: <?php echo $progress_percentage; ?>%;">
                <?php echo number_format($progress_percentage, 1); ?>%
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <form method="POST" action="process-kpi.php" id="editKpiForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="kpi_id" value="<?php echo $kpi_id; ?>">
        
        <!-- Basic Information -->
        <div class="form-card">
            <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
            
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label for="project_id" class="required">Project</label>
                        <select id="project_id" name="project_id" class="form-control" required>
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['project_id']; ?>" 
                                        <?php echo $kpi['project_id'] == $project['project_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['project_name']); ?>
                                    <?php if ($project['project_status'] != 'Active'): ?>
                                        (<?php echo $project['project_status']; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="kpi_name" class="required">KPI Name</label>
                        <input type="text" id="kpi_name" name="kpi_name" class="form-control" 
                               placeholder="e.g., Number of Youth Trained"
                               value="<?php echo htmlspecialchars($kpi['kpi_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3" 
                              placeholder="Detailed description of this KPI..."><?php echo htmlspecialchars($kpi['description']); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="kpi_category" class="required">Category</label>
                        <select id="kpi_category" name="kpi_category" class="form-control" required>
                            <option value="">Select Category</option>
                            <option value="Output" <?php echo $kpi['kpi_category'] == 'Output' ? 'selected' : ''; ?>>Output</option>
                            <option value="Outcome" <?php echo $kpi['kpi_category'] == 'Outcome' ? 'selected' : ''; ?>>Outcome</option>
                            <option value="Impact" <?php echo $kpi['kpi_category'] == 'Impact' ? 'selected' : ''; ?>>Impact</option>
                            <option value="Process" <?php echo $kpi['kpi_category'] == 'Process' ? 'selected' : ''; ?>>Process</option>
                            <option value="Financial" <?php echo $kpi['kpi_category'] == 'Financial' ? 'selected' : ''; ?>>Financial</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="measurement_unit" class="required">Measurement Unit</label>
                        <input type="text" id="measurement_unit" name="measurement_unit" class="form-control" 
                               placeholder="e.g., People, UGX, %, Sessions"
                               value="<?php echo htmlspecialchars($kpi['measurement_unit']); ?>" required>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Targets & Values -->
        <div class="form-card">
            <h3><i class="fas fa-bullseye"></i> Targets & Values</h3>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> 
                Update current value as progress is made. Target value should remain constant unless formally revised.
            </div>
            
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label for="baseline_value" class="required">Baseline Value</label>
                        <input type="number" id="baseline_value" name="baseline_value" 
                               class="form-control" step="0.01" 
                               placeholder="Starting value"
                               value="<?php echo $kpi['baseline_value']; ?>" required>
                        <small style="color: #7f8c8d;">Value at the start of measurement</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="target_value" class="required">Target Value</label>
                        <input type="number" id="target_value" name="target_value" 
                               class="form-control" step="0.01" 
                               placeholder="Goal to achieve"
                               value="<?php echo $kpi['target_value']; ?>" required>
                        <small style="color: #7f8c8d;">Value to be achieved</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="current_value" class="required">Current Value</label>
                        <input type="number" id="current_value" name="current_value" 
                               class="form-control" step="0.01" 
                               placeholder="Current progress"
                               value="<?php echo $kpi['current_value']; ?>" required>
                        <small style="color: #7f8c8d;">Current achievement level</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Timeline & Status -->
        <div class="form-card">
            <h3><i class="fas fa-calendar-alt"></i> Timeline & Status</h3>
            
            <div class="form-section">
                <div class="form-row">
                    <div class="form-group">
                        <label for="reporting_frequency" class="required">Reporting Frequency</label>
                        <select id="reporting_frequency" name="reporting_frequency" class="form-control" required>
                            <option value="">Select Frequency</option>
                            <option value="Weekly" <?php echo $kpi['reporting_frequency'] == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo $kpi['reporting_frequency'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Quarterly" <?php echo $kpi['reporting_frequency'] == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="Semi-Annual" <?php echo $kpi['reporting_frequency'] == 'Semi-Annual' ? 'selected' : ''; ?>>Semi-Annual</option>
                            <option value="Annual" <?php echo $kpi['reporting_frequency'] == 'Annual' ? 'selected' : ''; ?>>Annual</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="deadline" class="required">Deadline</label>
                        <input type="date" id="deadline" name="deadline" class="form-control" 
                               value="<?php echo $kpi['deadline']; ?>" required>
                        <small style="color: #7f8c8d;">Target achievement date</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status" class="required">Status</label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="Not Started" <?php echo $kpi['status'] == 'Not Started' ? 'selected' : ''; ?>>Not Started</option>
                            <option value="On Track" <?php echo $kpi['status'] == 'On Track' ? 'selected' : ''; ?>>On Track</option>
                            <option value="At Risk" <?php echo $kpi['status'] == 'At Risk' ? 'selected' : ''; ?>>At Risk</option>
                            <option value="Off Track" <?php echo $kpi['status'] == 'Off Track' ? 'selected' : ''; ?>>Off Track</option>
                            <option value="Achieved" <?php echo $kpi['status'] == 'Achieved' ? 'selected' : ''; ?>>Achieved</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="owner">KPI Owner</label>
                        <select id="owner" name="owner" class="form-control">
                            <option value="">Not Assigned</option>
                            <?php foreach ($staff as $person): ?>
                                <option value="<?php echo $person['user_id']; ?>" 
                                        <?php echo $kpi['owner'] == $person['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($person['full_name']); ?> 
                                    (<?php echo htmlspecialchars($person['role']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #7f8c8d;">Person responsible for this KPI</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Additional Information -->
        <div class="form-card">
            <h3><i class="fas fa-edit"></i> Additional Information</h3>
            
            <div class="form-section">
                <div class="form-group">
                    <label for="data_source">Data Source</label>
                    <input type="text" id="data_source" name="data_source" class="form-control" 
                           placeholder="e.g., Training Attendance Sheets, M&E Database"
                           value="<?php echo htmlspecialchars($kpi['data_source']); ?>">
                    <small style="color: #7f8c8d;">Where the data for this KPI comes from</small>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes / Comments</label>
                    <textarea id="notes" name="notes" class="form-control" rows="4" 
                              placeholder="Any additional notes, challenges, or observations about this KPI..."><?php echo htmlspecialchars($kpi['notes']); ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="form-card">
            <div class="button-group">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save"></i> Update KPI
                </button>
                
                <a href="view-kpi?id=<?php echo $kpi_id; ?>" class="btn btn-secondary btn-lg">
                    <i class="fas fa-times"></i> Cancel
                </a>
                
                <a href="kpi-management" class="btn btn-info btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to KPI List
                </a>
                
                <?php if ($user_role == 'Administrator'): ?>
                <button type="button" onclick="confirmDelete()" class="btn btn-danger btn-lg" style="margin-left: auto;">
                    <i class="fas fa-trash"></i> Delete KPI
                </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Form validation
document.getElementById('editKpiForm').addEventListener('submit', function(e) {
    const currentValue = parseFloat(document.getElementById('current_value').value);
    const targetValue = parseFloat(document.getElementById('target_value').value);
    const baselineValue = parseFloat(document.getElementById('baseline_value').value);
    
    // Validation warnings (not blocking)
    if (currentValue > targetValue) {
        if (!confirm('Current value exceeds target value. This indicates the target has been surpassed. Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    if (currentValue < baselineValue) {
        if (!confirm('Current value is less than baseline value. This indicates negative progress. Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    return true;
});

// Auto-calculate and suggest status based on progress
document.getElementById('current_value').addEventListener('change', function() {
    const current = parseFloat(this.value) || 0;
    const target = parseFloat(document.getElementById('target_value').value) || 1;
    const progress = (current / target) * 100;
    
    const statusSelect = document.getElementById('status');
    
    if (progress >= 100) {
        if (confirm('Current value has met or exceeded the target. Set status to "Achieved"?')) {
            statusSelect.value = 'Achieved';
        }
    } else if (progress >= 80) {
        if (statusSelect.value != 'Achieved' && confirm('Progress is at ' + progress.toFixed(1) + '%. Suggest setting status to "On Track"?')) {
            statusSelect.value = 'On Track';
        }
    } else if (progress >= 50) {
        if (statusSelect.value == 'Not Started' || statusSelect.value == '') {
            statusSelect.value = 'At Risk';
        }
    }
});

// Delete confirmation
function confirmDelete() {
    if (confirm('Are you sure you want to delete this KPI?\n\nThis action cannot be undone and will remove all associated data.')) {
        window.location.href = 'process-kpi.php?delete_kpi=<?php echo $kpi_id; ?>';
    }
}

// Deadline validation
document.getElementById('deadline').addEventListener('change', function() {
    const deadline = new Date(this.value);
    const today = new Date();
    
    if (deadline < today) {
        alert('Warning: The deadline is in the past. Consider updating the status or extending the deadline.');
    }
});
</script>