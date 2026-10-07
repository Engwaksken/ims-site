<?php

$page_title = 'Make Payment';
include 'includes/header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get payment ID
if (!isset($_GET['payment_id']) || empty($_GET['payment_id'])) {
    $_SESSION['error'] = "Payment ID not provided.";
   // header("Location: my-subscription");
    exit();
}

$payment_id = intval($_GET['payment_id']);

// Get member details
$member_query = "SELECT member_id FROM members WHERE user_id = $user_id";
$member_result = $conn->query($member_query);

if ($member_result->num_rows == 0) {
    $_SESSION['error'] = "Member profile not found.";
    header("Location: my-subscription");
    exit();
}

$member_id = $member_result->fetch_assoc()['member_id'];

// Fetch payment details
$query = "SELECT sp.*, m.membership_number, u.full_name as member_name 
          FROM subscription_payments sp
          LEFT JOIN members m ON sp.member_id = m.member_id
          LEFT JOIN users u ON m.user_id = u.user_id
          WHERE sp.payment_id = $payment_id AND sp.member_id = $member_id";

$result = $conn->query($query);

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Payment not found or access denied.";
    header("Location: my-subscription");
    exit();
}

$payment = $result->fetch_assoc();

// Check if payment is already completed
if ($payment['payment_status'] == 'Paid') {
    $_SESSION['info'] = "This payment has already been completed.";
    header("Location: payment-history");
    exit();
}

// Hive Colab bank details (hardcoded - you can move to config)
$bank_details = [
    'bank_name' => 'Stanbic Bank Uganda',
    'account_name' => 'Hive Colab Limited',
    'account_number' => '9030012345678',
    'branch' => 'Kampala Road Branch',
    'swift_code' => 'SBICUGKX',
    'currency' => 'UGX',
    'mobile_money' => [
        'mtn' => '0772 123 456',
        'airtel' => '0756 789 012'
    ]
];
?>

<style>
.payment-header {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 40px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.payment-header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.payment-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.payment-card {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.payment-card h3 {
    color: #2c3e50;
    margin-bottom: 25px;
    font-size: 22px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.payment-card h3 i {
    color: #ff6b35;
}

.amount-display {
    background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 30px;
}

.amount-display .label {
    font-size: 16px;
    opacity: 0.9;
    margin-bottom: 10px;
}

.amount-display .amount {
    font-size: 48px;
    font-weight: 700;
    margin: 15px 0;
}

.payment-info {
    display: grid;
    gap: 15px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    align-items: center;
}

.info-row .label {
    font-size: 14px;
    color: #7f8c8d;
    font-weight: 500;
}

.info-row .value {
    font-size: 16px;
    color: #2c3e50;
    font-weight: 600;
    text-align: right;
}

.bank-details-box {
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.bank-detail-item {
    display: flex;
    justify-content: space-between;
    padding: 15px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    align-items: center;
}

.bank-detail-item:last-child {
    border-bottom: none;
}

.bank-detail-item .label {
    font-size: 14px;
    opacity: 0.9;
    font-weight: 500;
}

.bank-detail-item .value {
    font-size: 18px;
    font-weight: 700;
    text-align: right;
    font-family: monospace;
    display: flex;
    align-items: center;
    gap: 10px;
}

.copy-btn {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    padding: 5px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s;
}

.copy-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.mobile-money-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 12px;
    margin-top: 20px;
}

.mobile-money-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-top: 15px;
}

.mm-option {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    border: 2px solid #ecf0f1;
}

.mm-option .provider {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 10px;
    font-size: 16px;
}

.mm-option .number {
    font-size: 20px;
    font-weight: 700;
    color: #ff6b35;
    font-family: monospace;
}

.upload-section {
    background: white;
    border: 2px dashed #ff6b35;
    border-radius: 12px;
    padding: 40px;
    text-align: center;
    margin-bottom: 25px;
    transition: all 0.3s;
}

.upload-section:hover {
    border-color: #ff9800;
    background: #f8f9fa;
}

.upload-section.dragover {
    background: #e8eaf6;
    border-color: #ff6b35;
}

.upload-icon {
    font-size: 64px;
    color: #ff6b35;
    margin-bottom: 20px;
}

.upload-section input[type="file"] {
    display: none;
}

.upload-label {
    display: inline-block;
    padding: 15px 30px;
    background: linear-gradient(135deg, #ff6b35 0%, #ff9800 100%);
    color: white;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
}

.upload-label:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.file-preview {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
    display: none;
}

.file-preview.show {
    display: block;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.file-info i {
    font-size: 48px;
    color: #ff6b35;
}

.file-details {
    flex: 1;
}

.file-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
}

.file-size {
    color: #7f8c8d;
    font-size: 14px;
}

.remove-file {
    background: #E74C3C;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
}

.instructions {
    background: #fff3cd;
    border-left: 4px solid #F39C12;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.instructions h4 {
    color: #856404;
    margin-bottom: 15px;
}

.instructions ol {
    margin: 0;
    padding-left: 20px;
    color: #856404;
}

.instructions ol li {
    margin-bottom: 10px;
}

.alert-info {
    background: #d1ecf1;
    border-left: 4px solid #17a2b8;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: #0c5460;
}

@media (max-width: 968px) {
    .payment-content {
        grid-template-columns: 1fr;
    }
    
    .mobile-money-options {
        grid-template-columns: 1fr;
    }
    
    .amount-display .amount {
        font-size: 36px;
    }
}
</style>

<!-- Header -->
<div class="payment-header">
    <h1><i class="fas fa-credit-card"></i> Make Payment</h1>
    <p style="margin: 0; opacity: 0.9;">Complete your payment using bank transfer</p>
</div>

<!-- Payment Content -->
<div class="payment-content">
    <!-- Left Column - Payment Details -->
    <div>
        <!-- Amount Display -->
        <div class="amount-display">
            <div class="label">Amount to Pay</div>
            <div class="amount"><?php echo format_currency($payment['amount']); ?></div>
            <div class="label">Invoice: <?php echo htmlspecialchars($payment['invoice_number']); ?></div>
        </div>
        
        <!-- Payment Information -->
        <div class="payment-card">
            <h3><i class="fas fa-info-circle"></i> Payment Information</h3>
            <div class="payment-info">
                <div class="info-row">
                    <span class="label">Subscription Plan:</span>
                    <span class="value"><?php echo htmlspecialchars($payment['subscription_plan']); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="label">Period:</span>
                    <span class="value">
                        <?php echo date('d M', strtotime($payment['payment_period_start'])); ?> - 
                        <?php echo date('d M Y', strtotime($payment['payment_period_end'])); ?>
                    </span>
                </div>
                
                <div class="info-row">
                    <span class="label">Member:</span>
                    <span class="value"><?php echo htmlspecialchars($payment['member_name']); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="label">Membership #:</span>
                    <span class="value"><code><?php echo htmlspecialchars($payment['membership_number']); ?></code></span>
                </div>
                
                <div class="info-row">
                    <span class="label">Status:</span>
                    <span class="value">
                        <span class="badge badge-warning"><?php echo $payment['payment_status']; ?></span>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Payment Instructions -->
        <div class="instructions">
            <h4><i class="fas fa-lightbulb"></i> Payment Instructions</h4>
            <ol>
                <li>Use the bank details below to make your payment via bank transfer</li>
               <!-- <li>Alternatively, use Mobile Money (MTN or Airtel)</li> -->
                <li>Use your <strong>Invoice Number (<?php echo $payment['invoice_number']; ?>)</strong> as payment reference</li>
                <li>After making payment, upload your receipt using the form on the right</li>
                <li>Our team will verify your payment within 24 hours</li>
                <li>You will receive a confirmation once payment is verified</li>
            </ol>
        </div>
    </div>
    
    <!-- Right Column - Bank Details & Upload -->
    <div>
        <!-- Bank Details -->
        <div class="payment-card">
            <h3><i class="fas fa-university"></i> Bank Transfer Details</h3>
            
            <div class="bank-details-box">
                <div class="bank-detail-item">
                    <span class="label">Bank Name:</span>
                    <span class="value">
                        <?php echo $bank_details['bank_name']; ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo $bank_details['bank_name']; ?>', this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
                
                <div class="bank-detail-item">
                    <span class="label">Account Name:</span>
                    <span class="value">
                        <?php echo $bank_details['account_name']; ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo $bank_details['account_name']; ?>', this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
                
                <div class="bank-detail-item">
                    <span class="label">Account Number:</span>
                    <span class="value">
                        <?php echo $bank_details['account_number']; ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo $bank_details['account_number']; ?>', this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
                
                <div class="bank-detail-item">
                    <span class="label">Branch:</span>
                    <span class="value"><?php echo $bank_details['branch']; ?></span>
                </div>
                
                <div class="bank-detail-item">
                    <span class="label">SWIFT Code:</span>
                    <span class="value">
                        <?php echo $bank_details['swift_code']; ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo $bank_details['swift_code']; ?>', this)">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
                
                <div class="bank-detail-item">
                    <span class="label">Currency:</span>
                    <span class="value"><?php echo $bank_details['currency']; ?></span>
                </div>
            </div>
            
            <!-- Mobile Money -->
           <!-- <div class="mobile-money-section">
                <h4 style="margin-bottom: 10px; color: #2c3e50;">
                    <i class="fas fa-mobile-alt"></i> Mobile Money
                </h4>
                <div class="mobile-money-options">
                    <div class="mm-option">
                        <div class="provider">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/MTN_Logo.svg/320px-MTN_Logo.svg.png" 
                                 alt="MTN" style="height: 30px; margin-bottom: 10px;">
                        </div>
                        <div class="number"><?php echo $bank_details['mobile_money']['mtn']; ?></div>
                        <button class="copy-btn" style="margin-top: 10px; background: #FFCC00; color: #000;" 
                                onclick="copyToClipboard('<?php echo str_replace(' ', '', $bank_details['mobile_money']['mtn']); ?>', this)">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div> 
                    
                    <div class="mm-option">
                        <div class="provider">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/54/Airtel_Networks_Limited_Logo.svg/320px-Airtel_Networks_Limited_Logo.svg.png" 
                                 alt="Airtel" style="height: 30px; margin-bottom: 10px;">
                        </div>
                        <div class="number"><?php echo $bank_details['mobile_money']['airtel']; ?></div>
                        <button class="copy-btn" style="margin-top: 10px; background: #ED1C24; color: white;" 
                                onclick="copyToClipboard('<?php echo str_replace(' ', '', $bank_details['mobile_money']['airtel']); ?>', this)">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
        </div>-->
        
        <!-- Upload Receipt -->
      <!--  <div class="payment-card">
            <h3><i class="fas fa-upload"></i> Upload Payment Receipt</h3>
            
            <div class="alert-info">
                <i class="fas fa-info-circle"></i> 
                Upload your payment receipt or transaction confirmation. Accepted formats: PDF, JPG, PNG (Max 5MB)
            </div>
            
            <form method="POST" action="includes/process-receipt-upload.php" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="payment_id" value="<?php echo $payment_id; ?>">
                <input type="hidden" name="invoice_number" value="<?php echo $payment['invoice_number']; ?>">
                
                <div class="upload-section" id="uploadSection">
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <h4 style="color: #2c3e50; margin-bottom: 15px;">Upload Your Receipt</h4>
                    <p style="color: #7f8c8d; margin-bottom: 20px;">
                        Drag and drop your file here or click to browse
                    </p>
                    <input type="file" name="receipt_file" id="receiptFile" accept=".pdf,.jpg,.jpeg,.png" required>
                    <label for="receiptFile" class="upload-label">
                        <i class="fas fa-folder-open"></i> Choose File
                    </label>
                </div>
                
                <div class="file-preview" id="filePreview">
                    <div class="file-info">
                        <i class="fas fa-file-invoice"></i>
                        <div class="file-details">
                            <div class="file-name" id="fileName"></div>
                            <div class="file-size" id="fileSize"></div>
                        </div>
                        <button type="button" class="remove-file" onclick="removeFile()">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label for="payment_method">Payment Method</label>
                    <select name="payment_method" id="payment_method" class="form-control" required>
                        <option value="">Select Payment Method</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Mobile Money">Mobile Money - MTN</option>
                        <option value="Mobile Money">Mobile Money - Airtel</option>
                        <option value="Cash">Cash Deposit</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="payment_reference">Transaction Reference/ID</label>
                    <input type="text" name="payment_reference" id="payment_reference" 
                           class="form-control" placeholder="e.g., TXN123456789" required>
                    <small style="color: #7f8c8d;">Enter the transaction ID from your bank receipt</small>
                </div>
                
                <div class="form-group">
                    <label for="notes">Additional Notes (Optional)</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" 
                              placeholder="Any additional information about your payment..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-success btn-lg" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Submit Receipt for Verification
                </button>
            </form>
        </div>
    </div>-->
</div>

<?php include 'includes/footer.php'; ?>

<script>
// File upload handling
const uploadSection = document.getElementById('uploadSection');
const receiptFile = document.getElementById('receiptFile');
const filePreview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const fileSize = document.getElementById('fileSize');

// Handle file selection
receiptFile.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        displayFilePreview(this.files[0]);
    }
});

// Drag and drop
uploadSection.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('dragover');
});

uploadSection.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
});

uploadSection.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        receiptFile.files = files;
        displayFilePreview(files[0]);
    }
});

function displayFilePreview(file) {
    // Validate file size (5MB max)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('File size exceeds 5MB limit. Please choose a smaller file.');
        receiptFile.value = '';
        return;
    }
    
    // Display file info
    fileName.textContent = file.name;
    fileSize.textContent = formatFileSize(file.size);
    filePreview.classList.add('show');
}

function removeFile() {
    receiptFile.value = '';
    filePreview.classList.remove('show');
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

// Copy to clipboard
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(function() {
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function() {
            button.innerHTML = originalHTML;
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy: ' + err);
    });
}

// Form validation
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    if (!receiptFile.files || receiptFile.files.length === 0) {
        e.preventDefault();
        alert('Please select a receipt file to upload.');
        return false;
    }
    
    const paymentMethod = document.getElementById('payment_method').value;
    const paymentReference = document.getElementById('payment_reference').value;
    
    if (!paymentMethod || !paymentReference) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    return true;
});
</script>