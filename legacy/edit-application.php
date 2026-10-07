<?php
// Frontend - Edit Application
$page_title = 'Edit Application';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if user is an applicant
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Applicant') {
    $_SESSION['error'] = "Access denied. This page is only for applicants.";
    header("Location: dashboard");
    exit();
}

// Get application ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Application ID not provided.";
    header("Location: my-applications");
    exit();
}

$application_id = intval($_GET['id']);

// Fetch application details
$query = "SELECT a.*, p.program_name, p.program_type, p.application_deadline
          FROM startup_applications a
          LEFT JOIN startup_programs p ON a.program_id = p.program_id
          WHERE a.application_id = $application_id AND a.user_id = $user_id";

$result = $conn->query($query);

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Application not found or access denied.";
    header("Location: my-applications");
    exit();
}

$application = $result->fetch_assoc();

// Check if application can be edited (only Pending status)
if ($application['application_status'] != 'Pending') {
    $_SESSION['error'] = "Only pending applications can be edited.";
    header("Location: view-application?id=$application_id");
    exit();
}

// Get applicant details
$user_query = "SELECT * FROM users WHERE user_id = $user_id";
$user_result = $conn->query($user_query);
$user = $user_result->fetch_assoc();
?>

<style>
.edit-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.edit-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.form-container {
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
    color: #667eea;
}

.alert-info-custom {
    background: #d1ecf1;
    border-left: 4px solid #17a2b8;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    color: #0c5460;
}

.char-counter {
    font-size: 12px;
    color: #7f8c8d;
    float: right;
    margin-top: 5px;
}

.file-upload-area {
    border: 2px dashed #667eea;
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    background: #f8f9fa;
    cursor: pointer;
    transition: all 0.3s;
}

.file-upload-area:hover {
    background: #e9ecef;
    border-color: #764ba2;
}

.file-upload-area i {
    font-size: 48px;
    color: #667eea;
    margin-bottom: 15px;
}

.uploaded-files {
    margin-top: 20px;
}

.file-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 10px;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.file-icon {
    font-size: 24px;
    color: #667eea;
}

.team-member-row {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 15px;
    position: relative;
}

.remove-member-btn {
    position: absolute;
    top: 10px;
    right: 10px;
}

.add-member-btn {
    margin-top: 15px;
}

.button-group {
    display: flex;
    gap: 15px;
    padding-top: 20px;
    border-top: 2px solid #ecf0f1;
    margin-top: 30px;
}

@media (max-width: 768px) {
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
<div class="edit-header">
    <h1><i class="fas fa-edit"></i> Edit Application</h1>
    <p style="margin: 0; opacity: 0.9;">
        Program: <?php echo htmlspecialchars($application['program_name']); ?>
    </p>
    <p style="margin: 5px 0 0 0; opacity: 0.9;">
        <small>Application #<?php echo htmlspecialchars($application['application_number']); ?></small>
    </p>
</div>

<div class="form-container">
    <div class="alert-info-custom">
        <i class="fas fa-info-circle"></i>
        <strong>Important:</strong> You can only edit pending applications. Once submitted, your changes will be reviewed by the program administrators.
    </div>

    <form method="POST" action="process-application.php" enctype="multipart/form-data" id="editApplicationForm">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">
        
        <!-- Contact Information -->
        <div class="form-card">
            <h3><i class="fas fa-user"></i> Contact Information</h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name" class="required">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" 
                           value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly>
                    <small style="color: #7f8c8d;">From your account profile</small>
                </div>
                
                <div class="form-group">
                    <label for="email" class="required">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    <small style="color: #7f8c8d;">From your account profile</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="phone_number" class="required">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" class="form-control" 
                           placeholder="+256 700 000 000"
                           value="<?php echo htmlspecialchars($application['phone_number']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" class="form-control" 
                           placeholder="City, Country"
                           value="<?php echo htmlspecialchars($application['location']); ?>">
                </div>
            </div>
        </div>
        
        <!-- Startup Information -->
        <div class="form-card">
            <h3><i class="fas fa-rocket"></i> Startup Information</h3>
            
            <div class="form-group">
                <label for="startup_name" class="required">Startup Name</label>
                <input type="text" id="startup_name" name="startup_name" class="form-control" 
                       placeholder="Your startup or business name"
                       value="<?php echo htmlspecialchars($application['startup_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="startup_description" class="required">Startup Description</label>
                <textarea id="startup_description" name="startup_description" class="form-control" 
                          rows="4" required 
                          placeholder="Describe your startup, what problem you're solving, and your target market..."
                          maxlength="1000" onkeyup="updateCharCount('startup_description', 1000)"><?php echo htmlspecialchars($application['startup_description']); ?></textarea>
                <span class="char-counter" id="startup_description_counter">0 / 1000</span>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="industry" class="required">Industry/Sector</label>
                    <select id="industry" name="industry" class="form-control" required>
                        <option value="">Select Industry</option>
                        <option value="Agriculture" <?php echo $application['industry'] == 'Agriculture' ? 'selected' : ''; ?>>Agriculture</option>
                        <option value="FinTech" <?php echo $application['industry'] == 'FinTech' ? 'selected' : ''; ?>>FinTech</option>
                        <option value="HealthTech" <?php echo $application['industry'] == 'HealthTech' ? 'selected' : ''; ?>>HealthTech</option>
                        <option value="EdTech" <?php echo $application['industry'] == 'EdTech' ? 'selected' : ''; ?>>EdTech</option>
                        <option value="E-commerce" <?php echo $application['industry'] == 'E-commerce' ? 'selected' : ''; ?>>E-commerce</option>
                        <option value="CleanTech" <?php echo $application['industry'] == 'CleanTech' ? 'selected' : ''; ?>>CleanTech</option>
                        <option value="LogisticsTech" <?php echo $application['industry'] == 'LogisticsTech' ? 'selected' : ''; ?>>LogisticsTech</option>
                        <option value="Manufacturing" <?php echo $application['industry'] == 'Manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                        <option value="Entertainment" <?php echo $application['industry'] == 'Entertainment' ? 'selected' : ''; ?>>Entertainment</option>
                        <option value="Other" <?php echo $application['industry'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="stage" class="required">Startup Stage</label>
                    <select id="stage" name="stage" class="form-control" required>
                        <option value="">Select Stage</option>
                        <option value="Idea" <?php echo $application['stage'] == 'Idea' ? 'selected' : ''; ?>>Idea Stage</option>
                        <option value="Prototype" <?php echo $application['stage'] == 'Prototype' ? 'selected' : ''; ?>>Prototype</option>
                        <option value="MVP" <?php echo $application['stage'] == 'MVP' ? 'selected' : ''; ?>>MVP (Minimum Viable Product)</option>
                        <option value="Early Revenue" <?php echo $application['stage'] == 'Early Revenue' ? 'selected' : ''; ?>>Early Revenue</option>
                        <option value="Growth" <?php echo $application['stage'] == 'Growth' ? 'selected' : ''; ?>>Growth Stage</option>
                        <option value="Scaling" <?php echo $application['stage'] == 'Scaling' ? 'selected' : ''; ?>>Scaling</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="team_size" class="required">Team Size</label>
                    <input type="number" id="team_size" name="team_size" class="form-control" 
                           min="1" max="100" 
                           value="<?php echo $application['team_size']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="founded_year">Year Founded</label>
                    <input type="number" id="founded_year" name="founded_year" class="form-control" 
                           min="2000" max="<?php echo date('Y'); ?>" 
                           placeholder="<?php echo date('Y'); ?>"
                           value="<?php echo $application['founded_year']; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label for="website">Website URL</label>
                <input type="url" id="website" name="website" class="form-control" 
                       placeholder="https://yourwebsite.com"
                       value="<?php echo htmlspecialchars($application['website']); ?>">
            </div>
        </div>
        
        <!-- Business Details -->
        <div class="form-card">
            <h3><i class="fas fa-chart-line"></i> Business Details</h3>
            
            <div class="form-group">
                <label for="problem_statement" class="required">Problem Statement</label>
                <textarea id="problem_statement" name="problem_statement" class="form-control" 
                          rows="4" required 
                          placeholder="What problem does your startup solve?"
                          maxlength="1000" onkeyup="updateCharCount('problem_statement', 1000)"><?php echo htmlspecialchars($application['problem_statement']); ?></textarea>
                <span class="char-counter" id="problem_statement_counter">0 / 1000</span>
            </div>
            
            <div class="form-group">
                <label for="solution" class="required">Your Solution</label>
                <textarea id="solution" name="solution" class="form-control" 
                          rows="4" required 
                          placeholder="How does your product/service solve the problem?"
                          maxlength="1000" onkeyup="updateCharCount('solution', 1000)"><?php echo htmlspecialchars($application['solution']); ?></textarea>
                <span class="char-counter" id="solution_counter">0 / 1000</span>
            </div>
            
            <div class="form-group">
                <label for="target_market">Target Market</label>
                <textarea id="target_market" name="target_market" class="form-control" 
                          rows="3" 
                          placeholder="Who are your target customers?"
                          maxlength="500" onkeyup="updateCharCount('target_market', 500)"><?php echo htmlspecialchars($application['target_market']); ?></textarea>
                <span class="char-counter" id="target_market_counter">0 / 500</span>
            </div>
            
            <div class="form-group">
                <label for="business_model">Business Model</label>
                <textarea id="business_model" name="business_model" class="form-control" 
                          rows="3" 
                          placeholder="How do you make money?"
                          maxlength="500" onkeyup="updateCharCount('business_model', 500)"><?php echo htmlspecialchars($application['business_model']); ?></textarea>
                <span class="char-counter" id="business_model_counter">0 / 500</span>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="current_revenue">Current Revenue (Annual)</label>
                    <input type="number" id="current_revenue" name="current_revenue" class="form-control" 
                           min="0" placeholder="0"
                           value="<?php echo $application['current_revenue']; ?>">
                    <small style="color: #7f8c8d;">Enter 0 if pre-revenue</small>
                </div>
                
                <div class="form-group">
                    <label for="funding_amount" class="required">Funding Requested</label>
                    <input type="number" id="funding_amount" name="funding_amount" class="form-control" 
                           min="0" required
                           value="<?php echo $application['funding_amount']; ?>">
                    <small style="color: #7f8c8d;">Amount in UGX</small>
                </div>
            </div>
        </div>
        
        <!-- Additional Information -->
        <div class="form-card">
            <h3><i class="fas fa-info-circle"></i> Additional Information</h3>
            
            <div class="form-group">
                <label for="why_join">Why do you want to join this program?</label>
                <textarea id="why_join" name="why_join" class="form-control" 
                          rows="4" 
                          placeholder="Tell us why this program is right for you..."
                          maxlength="1000" onkeyup="updateCharCount('why_join', 1000)"><?php echo htmlspecialchars($application['why_join']); ?></textarea>
                <span class="char-counter" id="why_join_counter">0 / 1000</span>
            </div>
            
            <div class="form-group">
                <label for="additional_info">Any other information?</label>
                <textarea id="additional_info" name="additional_info" class="form-control" 
                          rows="3" 
                          placeholder="Share any other relevant information..."
                          maxlength="500" onkeyup="updateCharCount('additional_info', 500)"><?php echo htmlspecialchars($application['additional_info']); ?></textarea>
                <span class="char-counter" id="additional_info_counter">0 / 500</span>
            </div>
        </div>
        
        <!-- Documents -->
        <div class="form-card">
            <h3><i class="fas fa-file-upload"></i> Supporting Documents</h3>
            
            <?php if ($application['business_plan_path']): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> 
                You have already uploaded a business plan. Upload a new file to replace it.
            </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="business_plan">Business Plan (Optional)</label>
                <input type="file" id="business_plan" name="business_plan" class="form-control" 
                       accept=".pdf,.doc,.docx">
                <small style="color: #7f8c8d;">Accepted formats: PDF, DOC, DOCX (Max 5MB)</small>
            </div>
            
            <?php if ($application['pitch_deck_path']): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;">
                <i class="fas fa-check-circle"></i> 
                You have already uploaded a pitch deck. Upload a new file to replace it.
            </div>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="pitch_deck">Pitch Deck (Optional)</label>
                <input type="file" id="pitch_deck" name="pitch_deck" class="form-control" 
                       accept=".pdf,.ppt,.pptx">
                <small style="color: #7f8c8d;">Accepted formats: PDF, PPT, PPTX (Max 10MB)</small>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="form-card">
            <div class="button-group">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                
                <a href="view-application?id=<?php echo $application_id; ?>" class="btn btn-secondary btn-lg">
                    <i class="fas fa-times"></i> Cancel
                </a>
                
                <a href="my-applications" class="btn btn-info btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to Applications
                </a>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Character counter
function updateCharCount(fieldId, maxLength) {
    const field = document.getElementById(fieldId);
    const counter = document.getElementById(fieldId + '_counter');
    const currentLength = field.value.length;
    counter.textContent = currentLength + ' / ' + maxLength;
    
    if (currentLength > maxLength * 0.9) {
        counter.style.color = '#E74C3C';
    } else {
        counter.style.color = '#7f8c8d';
    }
}

// Initialize character counters on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCharCount('startup_description', 1000);
    updateCharCount('problem_statement', 1000);
    updateCharCount('solution', 1000);
    updateCharCount('target_market', 500);
    updateCharCount('business_model', 500);
    updateCharCount('why_join', 1000);
    updateCharCount('additional_info', 500);
});

// Form validation
document.getElementById('editApplicationForm').addEventListener('submit', function(e) {
    const fundingAmount = parseFloat(document.getElementById('funding_amount').value);
    
    if (fundingAmount <= 0) {
        alert('Please enter a valid funding amount.');
        e.preventDefault();
        return false;
    }
    
    // Confirm submission
    if (!confirm('Are you sure you want to save these changes?\n\nYour updated application will be reviewed.')) {
        e.preventDefault();
        return false;
    }
    
    return true;
});

// File upload validation
document.getElementById('business_plan').addEventListener('change', function() {
    validateFileUpload(this, 5); // 5MB limit
});

document.getElementById('pitch_deck').addEventListener('change', function() {
    validateFileUpload(this, 10); // 10MB limit
});

function validateFileUpload(input, maxSizeMB) {
    if (input.files && input.files[0]) {
        const fileSize = input.files[0].size / 1024 / 1024; // Size in MB
        
        if (fileSize > maxSizeMB) {
            alert(`File size exceeds ${maxSizeMB}MB limit. Please choose a smaller file.`);
            input.value = '';
            return false;
        }
    }
    return true;
}
</script>