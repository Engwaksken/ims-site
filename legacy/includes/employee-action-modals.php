
<!-- Approve Employee Modal -->
<div id="approveEmployeeModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="color: #27AE60;"><i class="fas fa-check-circle"></i> Approve Employee Profile</h3>
            <span class="close" onclick="closeModal('approveEmployeeModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="employee-actions-process.php">
                <input type="hidden" name="employee_id" id="approveEmployeeId">
                
                <p style="margin-bottom: 20px;">
                    Are you sure you want to approve the employee profile for:
                </p>
                <p style="font-size: 18px; font-weight: 600; color: #2c3e50; margin-bottom: 20px;">
                    <span id="approveEmployeeName"></span>
                </p>
                
                <div class="alert alert-success">
                    <i class="fas fa-info-circle"></i>
                    Approving this profile will:
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Mark the profile as "Approved"</li>
                        <li>Lock the profile from further edits</li>
                        <li>Record your approval with timestamp</li>
                    </ul>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('approveEmployeeModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="approve_employee" class="btn btn-success">
                        <i class="fas fa-check"></i> Approve Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Employee Modal -->
<div id="rejectEmployeeModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="color: #E74C3C;"><i class="fas fa-times-circle"></i> Reject Employee Profile</h3>
            <span class="close" onclick="closeModal('rejectEmployeeModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="employee-actions-process.php">
                <input type="hidden" name="employee_id" id="rejectEmployeeId">
                
                <p style="margin-bottom: 20px;">
                    Reject the employee profile for:
                </p>
                <p style="font-size: 18px; font-weight: 600; color: #2c3e50; margin-bottom: 20px;">
                    <span id="rejectEmployeeName"></span>
                </p>
                
                <div class="form-group">
                    <label for="rejection_reason" class="required">Reason for Rejection</label>
                    <textarea id="rejection_reason" name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Please provide a clear reason for rejecting this profile..." required></textarea>
                    <small style="color: #7f8c8d;">The employee will see this reason and can make corrections.</small>
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Rejecting this profile will:
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Mark the profile as "Rejected"</li>
                        <li>Allow the employee to edit and resubmit</li>
                        <li>Send them your rejection reason</li>
                    </ul>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('rejectEmployeeModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="reject_employee" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>