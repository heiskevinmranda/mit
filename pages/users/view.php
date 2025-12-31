<?php
require_once '../../includes/header.php';
requirePermission('admin');

$pdo = getDBConnection();

// Get user ID
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: index.php');
    exit();
}

// Get user details
$query = "SELECT u.*, sp.*, ur.role_name 
          FROM users u 
          LEFT JOIN staff_profiles sp ON u.id = sp.user_id
          LEFT JOIN user_roles ur ON u.user_type = ur.role_name
          WHERE u.id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = "User not found";
    header('Location: index.php');
    exit();
}

// Get user activity logs
$activity_query = "SELECT * FROM ticket_logs WHERE staff_id = ? ORDER BY created_at DESC LIMIT 10";
$activity_stmt = $pdo->prepare($activity_query);
$activity_stmt->execute([$id]);
$activities = $activity_stmt->fetchAll();

// Get assigned tickets
$tickets_query = "SELECT * FROM tickets WHERE assigned_to = ? ORDER BY created_at DESC LIMIT 10";
$tickets_stmt = $pdo->prepare($tickets_query);
$tickets_stmt->execute([$id]);
$tickets = $tickets_stmt->fetchAll();
?>

<div class="main-content">
    <div class="header">
        <h1><i class="fas fa-user"></i> User Details</h1>
        <div class="btn-group">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit User
            </a>
            <?php if ($user['is_active']): ?>
            <a href="deactivate.php?id=<?php echo $id; ?>" class="btn btn-danger" 
               onclick="return confirm('Are you sure you want to deactivate this user?')">
                <i class="fas fa-user-slash"></i> Deactivate
            </a>
            <?php else: ?>
            <a href="activate.php?id=<?php echo $id; ?>" class="btn btn-success"
               onclick="return confirm('Are you sure you want to activate this user?')">
                <i class="fas fa-user-check"></i> Activate
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- User Profile Card -->
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div class="avatar-circle-large mb-3">
                        <?php echo strtoupper(substr($user['email'], 0, 1)); ?>
                    </div>
                    <h4><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($user['designation'] ?? 'No designation'); ?></p>
                    
                    <div class="mb-3">
                        <span class="badge bg-<?php 
                            echo $user['user_type'] == 'super_admin' ? 'danger' : 
                                 ($user['user_type'] == 'admin' ? 'warning' : 
                                 ($user['user_type'] == 'manager' ? 'info' : 'secondary')); 
                        ?> fs-6">
                            <?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                        </span>
                        
                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?> fs-6">
                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between">
                            <span>Staff ID:</span>
                            <strong><?php echo htmlspecialchars($user['staff_id'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="list-group-item d-flex justify-content-between">
                            <span>Department:</span>
                            <span><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between">
                            <span>Employment Type:</span>
                            <span><?php echo htmlspecialchars($user['employment_type'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between">
                            <span>Date of Joining:</span>
                            <span><?php echo $user['date_of_joining'] ? date('M d, Y', strtotime($user['date_of_joining'])) : 'N/A'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <!-- Basic Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Email:</strong><br>
                            <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </a>
                            <?php if ($user['email_verified']): ?>
                                <span class="badge bg-success ms-2">Verified</span>
                            <?php else: ?>
                                <span class="badge bg-warning ms-2">Not Verified</span>
                            <?php endif; ?>
                            </p>
                            
                            <p><strong>Phone:</strong><br>
                            <?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?>
                            <?php if ($user['alternate_phone']): ?>
                                <br><small class="text-muted">Alt: <?php echo htmlspecialchars($user['alternate_phone']); ?></small>
                            <?php endif; ?>
                            </p>
                            
                            <p><strong>Date of Birth:</strong><br>
                            <?php echo $user['date_of_birth'] ? date('M d, Y', strtotime($user['date_of_birth'])) : 'N/A'; ?>
                            </p>
                            
                            <p><strong>Gender:</strong><br>
                            <?php echo htmlspecialchars($user['gender'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        
                        <div class="col-md-6">
                            <p><strong>Last Login:</strong><br>
                            <?php echo $user['last_login'] ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'Never'; ?>
                            </p>
                            
                            <p><strong>Account Created:</strong><br>
                            <?php echo date('M d, Y h:i A', strtotime($user['created_at'])); ?>
                            </p>
                            
                            <p><strong>Last Updated:</strong><br>
                            <?php echo date('M d, Y h:i A', strtotime($user['updated_at'])); ?>
                            </p>
                            
                            <p><strong>Two-Factor Auth:</strong><br>
                            <span class="badge bg-<?php echo $user['two_factor_enabled'] ? 'success' : 'secondary'; ?>">
                                <?php echo $user['two_factor_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact & Emergency Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-address-book"></i> Contact Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Current Address</h6>
                            <p><?php echo nl2br(htmlspecialchars($user['current_address'] ?? 'Not provided')); ?></p>
                            
                            <h6>Permanent Address</h6>
                            <p><?php echo nl2br(htmlspecialchars($user['permanent_address'] ?? 'Not provided')); ?></p>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>Emergency Contact</h6>
                            <?php if ($user['emergency_contact_name']): ?>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($user['emergency_contact_name']); ?></p>
                                <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['emergency_contact_number'] ?? 'N/A'); ?></p>
                            <?php else: ?>
                                <p class="text-muted">No emergency contact provided</p>
                            <?php endif; ?>
                            
                            <h6>System Access</h6>
                            <p><strong>VPN Access:</strong>
                                <span class="badge bg-<?php echo $user['vpn_access'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $user['vpn_access'] ? 'Yes' : 'No'; ?>
                                </span>
                            </p>
                            <p><strong>Company Laptop:</strong>
                                <span class="badge bg-<?php echo $user['company_laptop_issued'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $user['company_laptop_issued'] ? 'Issued' : 'Not Issued'; ?>
                                </span>
                                <?php if ($user['asset_serial_number']): ?>
                                    <br><small>Serial: <?php echo htmlspecialchars($user['asset_serial_number']); ?></small>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Information Tabs -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="userTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="skills-tab" data-bs-toggle="tab" data-bs-target="#skills" type="button">
                                <i class="fas fa-tools"></i> Skills & Experience
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button">
                                <i class="fas fa-history"></i> Recent Activity
                                <?php if ($activities): ?>
                                <span class="badge bg-primary ms-1"><?php echo count($activities); ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tickets-tab" data-bs-toggle="tab" data-bs-target="#tickets" type="button">
                                <i class="fas fa-ticket-alt"></i> Assigned Tickets
                                <?php if ($tickets): ?>
                                <span class="badge bg-info ms-1"><?php echo count($tickets); ?></span>
                                <?php endif; ?>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button">
                                <i class="fas fa-sticky-note"></i> Notes
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="userTabsContent">
                        <!-- Skills & Experience Tab -->
                        <div class="tab-pane fade show active" id="skills" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Skills</h6>
                                    <?php if ($user['skills']): ?>
                                        <p><?php echo nl2br(htmlspecialchars($user['skills'])); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">No skills listed</p>
                                    <?php endif; ?>
                                    
                                    <h6>Certifications</h6>
                                    <?php if ($user['certifications']): ?>
                                        <p><?php echo nl2br(htmlspecialchars($user['certifications'])); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">No certifications</p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <h6>Experience</h6>
                                    <p><strong>Years of Experience:</strong> <?php echo $user['experience_years'] ?? '0'; ?> years</p>
                                    
                                    <h6>Service Area</h6>
                                    <p><?php echo htmlspecialchars($user['service_area'] ?? 'Not specified'); ?></p>
                                    
                                    <h6>Assigned Clients</h6>
                                    <?php if ($user['assigned_clients']): ?>
                                        <p><?php echo nl2br(htmlspecialchars($user['assigned_clients'])); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">No assigned clients</p>
                                    <?php endif; ?>
                                    
                                    <h6>Shift Timing</h6>
                                    <p><?php echo htmlspecialchars($user['shift_timing'] ?? 'Regular'); ?></p>
                                    
                                    <p><strong>On-Call Support:</strong>
                                        <span class="badge bg-<?php echo $user['on_call_support'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $user['on_call_support'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Tab -->
                        <div class="tab-pane fade" id="activity" role="tabpanel">
                            <?php if ($activities): ?>
                                <div class="timeline">
                                    <?php foreach ($activities as $activity): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker"></div>
                                        <div class="timeline-content">
                                            <h6><?php echo htmlspecialchars($activity['action']); ?></h6>
                                            <p class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></p>
                                            <p><?php echo htmlspecialchars($activity['description']); ?></p>
                                            <?php if ($activity['time_spent_minutes']): ?>
                                                <span class="badge bg-info">
                                                    <i class="fas fa-clock"></i> <?php echo $activity['time_spent_minutes']; ?> mins
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                    <p>No recent activity found</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tickets Tab -->
                        <div class="tab-pane fade" id="tickets" role="tabpanel">
                            <?php if ($tickets): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Ticket #</th>
                                                <th>Title</th>
                                                <th>Priority</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tickets as $ticket): ?>
                                            <tr>
                                                <td><a href="../tickets/view.php?id=<?php echo $ticket['id']; ?>"><?php echo htmlspecialchars($ticket['ticket_number']); ?></a></td>
                                                <td><?php echo htmlspecialchars(substr($ticket['title'], 0, 50)) . (strlen($ticket['title']) > 50 ? '...' : ''); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $ticket['priority'] == 'High' ? 'danger' : 
                                                             ($ticket['priority'] == 'Medium' ? 'warning' : 'info'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($ticket['priority']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $ticket['status'] == 'Closed' ? 'success' : 
                                                             ($ticket['status'] == 'Open' ? 'primary' : 'warning'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($ticket['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="../tickets/index.php?assigned_to=<?php echo $id; ?>" class="btn btn-sm btn-outline-primary">
                                        View All Assigned Tickets
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                                    <p>No assigned tickets</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Notes Tab -->
                        <div class="tab-pane fade" id="notes" role="tabpanel">
                            <?php if ($user['remarks']): ?>
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-sticky-note"></i> Remarks</h6>
                                    <p><?php echo nl2br(htmlspecialchars($user['remarks'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="adminNotes" class="form-label">Add Admin Notes</label>
                                <textarea class="form-control" id="adminNotes" rows="3" placeholder="Add private notes about this user..."></textarea>
                            </div>
                            <button class="btn btn-primary" onclick="addNote()">
                                <i class="fas fa-save"></i> Save Note
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-circle-large {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #FF6B35 0%, #004E89 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 40px;
    margin: 0 auto;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
    border-left: 2px solid #dee2e6;
    padding-left: 20px;
}

.timeline-item:last-child {
    border-left: 2px solid transparent;
}

.timeline-marker {
    position: absolute;
    left: -30px;
    top: 0;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #0d6efd;
    border: 3px solid white;
    box-shadow: 0 0 0 3px #0d6efd;
}

.timeline-content {
    padding: 5px 0;
}

.nav-tabs .nav-link {
    color: #495057;
}

.nav-tabs .nav-link.active {
    font-weight: 600;
}
</style>

<script>
function addNote() {
    const note = document.getElementById('adminNotes').value;
    if (note.trim()) {
        // Here you would typically send the note to the server via AJAX
        alert('Note would be saved to the database. This feature needs backend implementation.');
        // Example AJAX call:
        // fetch('save_note.php', {
        //     method: 'POST',
        //     headers: {'Content-Type': 'application/json'},
        //     body: JSON.stringify({userId: <?php echo $id; ?>, note: note})
        // }).then(response => {
        //     if (response.ok) {
        //         alert('Note saved successfully');
        //         document.getElementById('adminNotes').value = '';
        //     }
        // });
    } else {
        alert('Please enter a note');
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>