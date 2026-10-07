
<!-- Edit Permissions Modal -->
<div id="editPermissionsModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Edit User Permissions</h3>
            <span class="close" onclick="closeModal('editPermissionsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <?php if ($edit_user): ?>
                <form method="POST" action="user-permissions-process">
                    <input type="hidden" name="user_id" value="<?php echo $edit_user['user_id']; ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>User:</strong> <?php echo htmlspecialchars($edit_user['full_name']); ?> (<?php echo htmlspecialchars($edit_user['username']); ?>)
                    </div>
                    
                    <?php if ($edit_user['user_id'] == $_SESSION['user_id']): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning:</strong> You are editing your own permissions. Be careful not to lock yourself out!
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="edit_role" class="required">Role</label>
                        <select id="edit_role" name="role" class="form-control" required>
                            <option value="Administrator" <?php echo $edit_user['role'] == 'Administrator' ? 'selected' : ''; ?>>Administrator</option>
                            <option value="Programs Lead" <?php echo $edit_user['role'] == 'Programs Lead' ? 'selected' : ''; ?>>Programs Lead</option>
                            <option value="Program Director" <?php echo $edit_user['role'] == 'Program Director' ? 'selected' : ''; ?>>Program Director</option>
                             <option value="Reviewer" <?php echo $edit_user['role'] == 'Reviewer' ? 'selected' : ''; ?>>Reviewer</option>
                              <option value="Consultant" <?php echo $edit_user['role'] == 'Consultant' ? 'selected' : ''; ?>>Consultant</option>
                            <option value="MEAL Lead" <?php echo $edit_user['role'] == 'MEAL Lead' ? 'selected' : ''; ?>>MEAL Lead</option>
                            <option value="Project Officer" <?php echo $edit_user['role'] == 'Project Officer' ? 'selected' : ''; ?>>Project Officer</option>
                            <option value="Operations/Admin" <?php echo $edit_user['role'] == 'Operations/Admin' ? 'selected' : ''; ?>>Operations/Admin</option>
                            <option value="Donor/Partner" <?php echo $edit_user['role'] == 'Donor/Partner' ? 'selected' : ''; ?>>Donor/Partner</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_status" class="required">Status</label>
                        <select id="edit_status" name="status" class="form-control" required>
                            <option value="Active" <?php echo $edit_user['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $edit_user['status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Permissions:</label>
                        <div id="rolePermissions" style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px;">
                           
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editPermissionsModal')" class="btn btn-secondary">Cancel</button>
                        <button type="submit" name="update_permissions" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Permissions
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- User Details Modal -->
<div id="userDetailsModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-user"></i> User Details</h3>
            <span class="close" onclick="closeModal('userDetailsModal')">&times;</span>
        </div>
        <div class="modal-body">
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold; width: 40%;">Full Name:</td>
                    <td style="padding: 10px;" id="detailsUserName">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Username:</td>
                    <td style="padding: 10px;" id="detailsUsername">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Email:</td>
                    <td style="padding: 10px;" id="detailsEmail">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Phone:</td>
                    <td style="padding: 10px;" id="detailsPhone">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Role:</td>
                    <td style="padding: 10px;" id="detailsRole">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Status:</td>
                    <td style="padding: 10px;" id="detailsStatus">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Created:</td>
                    <td style="padding: 10px;" id="detailsCreated">-</td>
                </tr>
                <tr style="border-bottom: 1px solid #ecf0f1;">
                    <td style="padding: 10px; font-weight: bold;">Last Login:</td>
                    <td style="padding: 10px;" id="detailsLastLogin">-</td>
                </tr>
                <tr>
                    <td style="padding: 10px; font-weight: bold;">Total Actions:</td>
                    <td style="padding: 10px;" id="detailsActions">-</td>
                </tr>
            </table>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('userDetailsModal')" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Reset Password</h3>
            <span class="close" onclick="closeModal('resetPasswordModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST" action="user-permissions-process">
                <input type="hidden" name="user_id" id="resetUserId">
                
                <p style="margin-bottom: 20px;">
                    Reset password for user: <strong id="resetUsername"></strong>
                </p>
                
                <div class="form-group">
                    <label for="new_password" class="required">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                    <small style="color: #7f8c8d;">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="required">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i>
                    The user will be notified of their new password.
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('resetPasswordModal')" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-danger">
                        <i class="fas fa-key"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Role Descriptions Modal -->
<div id="rolesInfoModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3><i class="fas fa-info-circle"></i> Role Descriptions & Permissions</h3>
            <span class="close" onclick="closeModal('rolesInfoModal')">&times;</span>
        </div>
        <div class="modal-body">
            <div style="display: grid; gap: 20px;">
                <!-- Administrator -->
                <div style="border: 2px solid #E74C3C; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #E74C3C; margin: 0 0 10px 0;">
                        <i class="fas fa-user-shield"></i> Administrator
                    </h4>
                    <p><strong>Access Level:</strong> Full Access</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>System setup and configuration</li>
                        <li>User management (create, edit, delete users)</li>
                        <li>Role and permission management</li>
                        <li>Access to all modules and features</li>
                        <li>View and manage all projects, programs, and data</li>
                        <li>System logs and audit trails</li>
                        <li>Database backups and maintenance</li>
                    </ul>
                </div>
                
                <!-- Programs Lead -->
                <div style="border: 2px solid #F39C12; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #F39C12; margin: 0 0 10px 0;">
                        <i class="fas fa-user-tie"></i> Programs Lead
                    </h4>
                    <p><strong>Access Level:</strong> Program Level</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>Monitor progress across all programs</li>
                        <li>Generate program reports</li>
                        <li>Create and manage milestones</li>
                        <li>View all projects and beneficiaries</li>
                        <li>Approve project activities</li>
                        <li>Delete milestones and major records</li>
                    </ul>
                </div>
                
                <!-- MEAL Lead -->
                <div style="border: 2px solid #9b59b6; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #9b59b6; margin: 0 0 10px 0;">
                        <i class="fas fa-chart-line"></i> MEAL Lead
                    </h4>
                    <p><strong>Access Level:</strong> Cross Project</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>Data quality assurance</li>
                        <li>Indicator tracking and reporting</li>
                        <li>Generate analytical reports</li>
                        <li>Access all project data for analysis</li>
                        <li>Manage indicators and frameworks</li>
                        <li>Export data for external analysis</li>
                    </ul>
                </div>
                
                <!-- Project Officer -->
                <div style="border: 2px solid #3498DB; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #3498DB; margin: 0 0 10px 0;">
                        <i class="fas fa-user"></i> Project Officer
                    </h4>
                    <p><strong>Access Level:</strong> Project Level</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>Data entry for assigned projects</li>
                        <li>Beneficiary tracking and management</li>
                        <li>Activity logging</li>
                        <li>Create milestones and updates</li>
                        <li>Upload documents</li>
                        <li>View project dashboards</li>
                    </ul>
                </div>
                
                <!-- Operations/Admin -->
                <div style="border: 2px solid #95a5a6; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #95a5a6; margin: 0 0 10px 0;">
                        <i class="fas fa-building"></i> Operations/Admin
                    </h4>
                    <p><strong>Access Level:</strong> Hub Level</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>Track events and space utilization</li>
                        <li>Manage hub operations</li>
                        <li>Record daily visitors</li>
                        <li>Co-working membership management</li>
                        <li>Event attendance tracking</li>
                        <li>Facility usage reports</li>
                    </ul>
                </div>
                
                <!-- Donor/Partner -->
                <div style="border: 2px solid #34495e; border-radius: 8px; padding: 15px;">
                    <h4 style="color: #34495e; margin: 0 0 10px 0;">
                        <i class="fas fa-handshake"></i> Donor/Partner
                    </h4>
                    <p><strong>Access Level:</strong> Read Only</p>
                    <p><strong>Permissions:</strong></p>
                    <ul>
                        <li>View dashboards and reports</li>
                        <li>Access project progress data</li>
                        <li>View beneficiary statistics</li>
                        <li>Download reports</li>
                        <li>View indicator achievements</li>
                        <li>No edit or delete capabilities</li>
                    </ul>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('rolesInfoModal')" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for status toggle -->
<form id="statusForm" method="POST" action="user-permissions-process" style="display: none;">
    <input type="hidden" name="user_id" id="statusUserId">
    <input type="hidden" name="status" id="statusNewStatus">
    <input type="hidden" name="toggle_status" value="1">
</form>

<script>
// Update permissions display when role changes
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('edit_role');
    if (roleSelect) {
        roleSelect.addEventListener('change', updatePermissionsDisplay);
        updatePermissionsDisplay(); // Initial load
    }
});

function updatePermissionsDisplay() {
    const role = document.getElementById('edit_role').value;
    const permissionsDiv = document.getElementById('rolePermissions');
    
    const permissions = {
        'Administrator': [
            'Full system access',
            'User management',
            'All modules',
            'System configuration',
            'Manage Reviewers',
            'Audit logs'
        ],
        'Programs Lead': [
            'Monitor all programs',
            'Generate reports',
            'Manage milestones',
            'Approve activities',
            'Manage Reviewers',
            'Delete records'
        ],
         'Program Director': [
            'Monitor all programs',
            'Generate reports',
            'Manage milestones',
            'Approve activities',
            'Manage Reviewers',
            'Delete records'
        ],
        'MEAL Lead': [
            'Data quality assurance',
            'Indicator tracking',
            'Cross-project data access',
            'Analytical reports',
            'Manage Reviewers',
            'Data export'
        ],
        'Project Officer': [
            'Project data entry',
            'Participant tracking',
            'Activity logging',
            'Milestone updates',
            'Document uploads'
        ],
        'Operations/Admin': [
            'Hub operations',
            'Event tracking',
            'Space utilization',
            'Membership management',
            'Visitor logs'
        ],
        'Donor/Partner': [
            'View dashboards',
            'Access reports',
            'Download data',
            'No edit access',
            'No delete access'
        ]
    };
    
    const rolePermissions = permissions[role] || [];
    permissionsDiv.innerHTML = '<ul style="margin: 0; padding-left: 20px;">' +
        rolePermissions.map(p => `<li>${p}</li>`).join('') +
        '</ul>';
}
</script>