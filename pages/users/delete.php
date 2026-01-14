<?php
// pages/users/delete.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$current_user_role = $_SESSION['user_type'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Only Super Admin can delete users
if ($current_user_role !== 'super_admin') {
    $_SESSION['error'] = "Only Super Administrators can delete users.";
    header("Location: index.php");
    exit();
}

$pdo = getDBConnection();

// Get user ID from URL
$user_id = $_GET['id'] ?? null;
$confirm = $_GET['confirm'] ?? false;
$archive_mode = true; // Set to true for archiving instead of permanent deletion

if (!$user_id) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: index.php");
    exit();
}

// Fetch user data
$userQuery = "SELECT u.id, u.email, u.user_type, u.is_active, u.email_verified, u.two_factor_enabled, u.last_login, u.created_at as user_created_at, u.updated_at as user_updated_at, u.role_id,
              ur.role_name, sp.full_name as staff_full_name
              FROM users u 
              LEFT JOIN user_roles ur ON u.role_id = ur.id 
              LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
              WHERE u.id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: index.php");
    exit();
}

// Prevent deleting super_admin users (except for special cases)
if ($user['user_type'] === 'super_admin') {
    $_SESSION['error'] = "Cannot delete Super Administrator accounts.";
    header("Location: index.php");
    exit();
}

// Check if user is trying to delete themselves
if ($current_user_id == $user_id) {
    $_SESSION['error'] = "You cannot delete your own account.";
    header("Location: index.php");
    exit();
}

// Handle deletion/archiving
$deleted = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $delete_type = $_POST['delete_type'] ?? 'archive';
    $reason = trim($_POST['reason'] ?? '');
    $transfer_tickets = isset($_POST['transfer_tickets']);
    $transfer_to = $_POST['transfer_to'] ?? null;
    $notify_user = isset($_POST['notify_user']);
    
    // Validate reason
    if (empty($reason)) {
        $error = "Please provide a reason for deletion.";
    } else {
        try {
            $pdo->beginTransaction();
            
            if ($archive_mode && $delete_type === 'archive') {
                // Archive user (soft delete)
                $archiveQuery = "UPDATE users SET 
                                is_active = false,
                                email = CONCAT(email, '_archived_', UUID()),
                                updated_at = NOW()
                                WHERE id = ?";
                $archiveStmt = $pdo->prepare($archiveQuery);
                $archiveStmt->execute([$user_id]);
                
                // Archive staff profile if exists
                $archiveStaffQuery = "UPDATE staff_profiles SET 
                                     employment_status = 'Terminated',
                                     updated_at = NOW()
                                     WHERE user_id = ?";
                $archiveStaffStmt = $pdo->prepare($archiveStaffQuery);
                $archiveStaffStmt->execute([$user_id]);
                
                $action_type = 'ARCHIVE';
            } else {
                // Permanent deletion
                // First, handle foreign key constraints
                
                // 1. Update tickets created by this user
                $updateTicketsQuery = "UPDATE tickets SET created_by = NULL WHERE created_by = ?";
                $updateTicketsStmt = $pdo->prepare($updateTicketsQuery);
                $updateTicketsStmt->execute([$user_id]);
                
                // 2. Remove user from ticket assignees
                $deleteAssigneesQuery = "DELETE FROM ticket_assignees WHERE staff_id = ?";
                $deleteAssigneesStmt = $pdo->prepare($deleteAssigneesQuery);
                $deleteAssigneesStmt->execute([$user_id]);
                
                // 3. Transfer assigned tickets if requested
                if ($transfer_tickets && $transfer_to) {
                    $transferQuery = "UPDATE tickets SET assigned_to = ? WHERE assigned_to = ?";
                    $transferStmt = $pdo->prepare($transferQuery);
                    $transferStmt->execute([$transfer_to, $user_id]);
                } else {
                    // Unassign tickets
                    $unassignQuery = "UPDATE tickets SET assigned_to = NULL WHERE assigned_to = ?";
                    $unassignStmt = $pdo->prepare($unassignQuery);
                    $unassignStmt->execute([$user_id]);
                }
                
                // 4. Delete staff profile
                $deleteStaffQuery = "DELETE FROM staff_profiles WHERE user_id = ?";
                $deleteStaffStmt = $pdo->prepare($deleteStaffQuery);
                $deleteStaffStmt->execute([$user_id]);
                
                // 5. Finally delete the user
                $deleteUserQuery = "DELETE FROM users WHERE id = ?";
                $deleteUserStmt = $pdo->prepare($deleteUserQuery);
                $deleteUserStmt->execute([$user_id]);
                
                $action_type = 'DELETE';
            }
            
            // Audit log
            try {
                $auditQuery = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $auditStmt = $pdo->prepare($auditQuery);
                $auditStmt->execute([
                    $current_user_id,
                    $action_type,
                    'user',
                    $user_id,
                    "Reason: $reason" . ($transfer_tickets ? " | Tickets transferred to: $transfer_to" : ""),
                    $_SERVER['REMOTE_ADDR']
                ]);
            } catch (PDOException $e) {
                // Audit table might not exist
                error_log("Audit log error: " . $e->getMessage());
            }
            
            // Send notification email if requested
            if ($notify_user && $user['email']) {
                // TODO: Implement email notification
                // mail($user['email'], "Account Deletion Notice", "Your account has been deleted/archived. Reason: $reason");
            }
            
            $pdo->commit();
            $deleted = true;
            
            // Set success message
            if ($archive_mode && $delete_type === 'archive') {
                $_SESSION['success'] = "User '{$user['email']}' has been archived successfully.";
            } else {
                $_SESSION['success'] = "User '{$user['email']}' has been permanently deleted.";
            }
            
            // Redirect to user list
            header("Location: index.php");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
            error_log("User deletion error: " . $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
            error_log("User deletion error: " . $e->getMessage());
        }
    }
}

// Get other staff for ticket transfer
$other_staff = [];
try {
    $staffQuery = "SELECT u.id, sp.full_name, sp.designation 
                   FROM users u 
                   LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
                   WHERE u.id != ? 
                   AND u.user_type IN ('admin', 'manager', 'support_tech')
                   AND u.is_active = true
                   ORDER BY sp.full_name";
    $staffStmt = $pdo->prepare($staffQuery);
    $staffStmt->execute([$user_id]);
    $other_staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Staff query error: " . $e->getMessage());
}

// Get user statistics
$ticket_count = 0;
$assigned_tickets = 0;
$site_visits = 0;

try {
    // Count tickets created by user
    $ticketQuery = "SELECT COUNT(*) FROM tickets WHERE created_by = ?";
    $ticketStmt = $pdo->prepare($ticketQuery);
    $ticketStmt->execute([$user_id]);
    $ticket_count = $ticketStmt->fetchColumn();
    
    // Count tickets assigned to user
    $assignedQuery = "SELECT COUNT(*) FROM tickets WHERE assigned_to = ?";
    $assignedStmt = $pdo->prepare($assignedQuery);
    $assignedStmt->execute([$user_id]);
    $assigned_tickets = $assignedStmt->fetchColumn();
    
    // Count site visits
    $visitsQuery = "SELECT COUNT(*) FROM site_visits WHERE engineer_id = ?";
    $visitsStmt = $pdo->prepare($visitsQuery);
    $visitsStmt->execute([$user_id]);
    $site_visits = $visitsStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Statistics query error: " . $e->getMessage());
}

// Function to format date
function formatDate($date) {
    if (!$date) return 'Never';
    return date('M d, Y H:i', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete User - MSP Application</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    
    <!-- Load CSS files with fallback -->
    <link rel="stylesheet" href="/mit/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
<!-- Add this CSS after the Bootstrap CDN link -->
<style>
    /* Override Bootstrap and custom styles to match the image */
    :root {
        --primary-color: #004E89;
        --secondary-color: #FF6B35;
        --danger-color: #dc3545;
        --warning-color: #ffc107;
        --light-bg: #f8f9fa;
        --border-color: #dee2e6;
    }
    
    body {
        background-color: #f0f2f5;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    /* Navbar styling */
    .navbar {
        background: linear-gradient(135deg, var(--primary-color) 0%, #002D62 100%);
        padding: 12px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .navbar h4 {
        color: white;
        margin: 0;
        font-weight: 600;
    }
    
    /* Main layout */
    .main-wrapper {
        display: flex;
        min-height: calc(100vh - 56px);
    }
    
    /* Sidebar styling - exactly like image */
    .sidebar {
        width: 220px;
        background: white;
        border-right: 1px solid var(--border-color);
        padding: 0;
        box-shadow: 2px 0 5px rgba(0,0,0,0.05);
    }
    
    .sidebar-content {
        padding: 20px 0;
    }
    
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-menu li {
        margin: 0;
    }
    
    .sidebar-menu a {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #495057;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: all 0.2s;
    }
    
    .sidebar-menu a:hover {
        background-color: var(--light-bg);
        color: var(--primary-color);
        border-left-color: var(--primary-color);
    }
    
    .sidebar-menu a.active {
        background-color: #e3f2fd;
        color: var(--primary-color);
        border-left-color: var(--primary-color);
        font-weight: 500;
    }
    
    .sidebar-menu i {
        width: 24px;
        margin-right: 12px;
        font-size: 16px;
        text-align: center;
    }
    
    /* Main content area */
    .main-content {
        flex: 1;
        padding: 25px;
        background-color: #f0f2f5;
        min-height: calc(100vh - 56px);
    }
    
    /* Page header */
    .main-content .h2 {
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .main-content .text-muted {
        color: #6c757d !important;
    }
    
    /* Card styling - match image */
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        margin-bottom: 24px;
        overflow: hidden;
    }
    
    .card-header {
        background-color: var(--primary-color);
        color: white;
        border-bottom: none;
        padding: 16px 20px;
        font-weight: 600;
    }
    
    .card-header h5 {
        margin: 0;
        font-weight: 600;
    }
    
    .card-body {
        padding: 25px;
    }
    
    /* Danger card */
    .card-danger {
        border-left: 4px solid var(--danger-color);
    }
    
    .card-warning {
        border-left: 4px solid var(--warning-color);
    }
    
    /* Buttons */
    .btn-danger {
        background-color: var(--danger-color);
        border-color: var(--danger-color);
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 6px;
        transition: all 0.2s;
    }
    
    .btn-danger:hover {
        background-color: #c82333;
        border-color: #bd2130;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
    }
    
    .btn-warning {
        background-color: var(--warning-color);
        border-color: var(--warning-color);
        color: #212529;
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 6px;
        transition: all 0.2s;
    }
    
    .btn-warning:hover {
        background-color: #e0a800;
        border-color: #d39e00;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
    }
    
    .btn-outline-secondary {
        border-radius: 6px;
        padding: 6px 12px;
    }
    
    /* Alert styling */
    .alert {
        border: none;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }
    
    .alert-danger {
        background-color: #ffebee;
        color: #c62828;
        border-left: 4px solid #f44336;
    }
    
    .alert-warning {
        background-color: #fff3cd;
        color: #856404;
        border-left: 4px solid #ffc107;
    }
    
    .alert-info {
        background-color: #e3f2fd;
        color: #1565c0;
        border-left: 4px solid #2196f3;
    }
    
    .alert-success {
        background-color: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid #4caf50;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .sidebar {
            position: fixed;
            top: 56px;
            left: -250px;
            height: calc(100vh - 56px);
            z-index: 1000;
            transition: left 0.3s;
        }
        
        .sidebar.show {
            left: 0;
        }
        
        .main-content {
            padding: 15px;
        }
        
        .card-body {
            padding: 15px;
        }
    }
    
    /* Make sure everything is visible */
    * {
        box-sizing: border-box;
    }
    
    /* Warning header */
    .warning-header {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        text-align: center;
    }
    
    .warning-icon {
        font-size: 64px;
        margin-bottom: 20px;
        color: rgba(255, 255, 255, 0.9);
    }
    
    /* User info panel */
    .user-info-panel {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border-left: 4px solid var(--primary-color);
    }
    
    .user-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: bold;
        margin-right: 20px;
    }
    
    .user-details h3 {
        margin-bottom: 5px;
        color: #2c3e50;
    }
    
    .user-details p {
        margin-bottom: 5px;
        color: #6c757d;
    }
    
    /* Stats cards */
    .stats-card {
        text-align: center;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        color: white;
    }
    
    .stats-number {
        font-size: 28px;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .stats-label {
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Impact section */
    .impact-section {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        border-left: 4px solid var(--warning-color);
    }
    
    .impact-item {
        padding: 10px 0;
        border-bottom: 1px solid #e9ecef;
    }
    
    .impact-item:last-child {
        border-bottom: none;
    }
    
    /* Form controls */
    .form-control {
        border: 1px solid #ced4da;
        border-radius: 6px;
        padding: 10px 12px;
        transition: all 0.2s;
    }
    
    .form-control:focus {
        border-color: var(--danger-color);
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
    
    /* Radio buttons */
    .form-check-input:checked {
        background-color: var(--danger-color);
        border-color: var(--danger-color);
    }
    
    /* Action buttons */
    .action-buttons {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-top: 30px;
    }
    
    /* Confirmation box */
    .confirmation-box {
        background-color: #fff3cd;
        border: 2px solid #ffeaa7;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .confirmation-text {
        font-size: 18px;
        font-weight: 500;
        color: #856404;
        text-align: center;
        margin-bottom: 20px;
    }
    
    /* Back button */
    .btn-back {
        color: var(--primary-color);
        text-decoration: none;
        font-weight: 500;
    }
    
    .btn-back:hover {
        color: #002D62;
    }
</style>
</head>
<body>
    <!-- Simple Header -->
    <nav class="navbar">
        <div class="container-fluid">
            <h4><i class="fas fa-tools me-2"></i>MSP Portal</h4>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['email'] ?? 'User') ?>
                </span>
                <a href="/mit/logout.php" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <div class="main-wrapper">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Warning Header -->
            <div class="warning-header">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h1 class="mb-3">Delete User Account</h1>
                <p class="mb-0">This action is irreversible and will permanently remove the user from the system.</p>
                <p><strong>Only Super Administrators can perform this action.</strong></p>
            </div>
            
            <!-- Permission Info -->
            <div class="alert alert-info">
                <div class="d-flex align-items-start">
                    <i class="fas fa-user-shield fa-2x me-3 mt-1"></i>
                    <div>
                        <h6 class="mb-2">Super Administrator Action</h6>
                        <p class="mb-1">
                            <strong>Your Role:</strong> <span class="badge bg-danger">Super Admin</span>
                        </p>
                        <p class="mb-0">
                            <strong>Action Required:</strong> You are about to delete a user account. This requires careful consideration.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Error Messages -->
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <!-- User Information -->
            <div class="user-info-panel">
                <div class="d-flex align-items-center">
                    <div class="user-avatar">
                        <?= strtoupper(substr($user['email'], 0, 1)) ?>
                    </div>
                    <div class="user-details flex-grow-1">
                        <h3><?= htmlspecialchars($user['staff_full_name'] ?? $user['full_name'] ?? $user['email']) ?></h3>
                        <p class="mb-1">
                            <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($user['email']) ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-user-tag me-2"></i> 
                            <span class="badge bg-primary">
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['user_type']))) ?>
                            </span>
                            <?php if ($user['staff_id']): ?>
                            <span class="ms-3"><i class="fas fa-id-card me-1"></i> <?= htmlspecialchars($user['staff_id']) ?></span>
                            <?php endif; ?>
                        </p>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-<?= $user['is_active'] ? 'success' : 'danger' ?> me-3">
                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <span class="me-3">
                                <i class="fas fa-calendar me-1"></i> Joined: <?= date('M d, Y', strtotime($user['user_created_at'])) ?>
                            </span>
                            <span>
                                <i class="fas fa-sign-in-alt me-1"></i> Last login: <?= formatDate($user['last_login']) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Statistics -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="stats-number"><?= $ticket_count ?></div>
                        <div class="stats-label">Tickets Created</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stats-number"><?= $assigned_tickets ?></div>
                        <div class="stats-label">Assigned Tickets</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="stats-number"><?= $site_visits ?></div>
                        <div class="stats-label">Site Visits</div>
                    </div>
                </div>
            </div>
            
            <!-- Impact Analysis -->
            <div class="impact-section">
                <h5 class="text-warning mb-3"><i class="fas fa-exclamation-triangle me-2"></i> Impact Analysis</h5>
                
                <div class="impact-item">
                    <div class="d-flex justify-content-between">
                        <span><i class="fas fa-ticket-alt text-danger me-2"></i> Tickets Created</span>
                        <span class="badge bg-danger"><?= $ticket_count ?> tickets affected</span>
                    </div>
                    <small class="text-muted">These tickets will have their creator set to NULL</small>
                </div>
                
                <div class="impact-item">
                    <div class="d-flex justify-content-between">
                        <span><i class="fas fa-tasks text-warning me-2"></i> Assigned Tickets</span>
                        <span class="badge bg-warning"><?= $assigned_tickets ?> tickets affected</span>
                    </div>
                    <small class="text-muted">These tickets will need to be reassigned or unassigned</small>
                </div>
                
                <div class="impact-item">
                    <div class="d-flex justify-content-between">
                        <span><i class="fas fa-map-marker-alt text-info me-2"></i> Site Visits</span>
                        <span class="badge bg-info"><?= $site_visits ?> visits affected</span>
                    </div>
                    <small class="text-muted">Site visit records will be orphaned</small>
                </div>
                
                <div class="impact-item">
                    <div class="d-flex justify-content-between">
                        <span><i class="fas fa-database text-secondary me-2"></i> Staff Profile</span>
                        <span class="badge bg-secondary">1 profile affected</span>
                    </div>
                    <small class="text-muted">Staff profile data will be deleted</small>
                </div>
            </div>
            
            <!-- Delete Form -->
            <div class="card card-danger">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-trash-alt me-2"></i> Deletion Options</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="delete.php?id=<?= htmlspecialchars($user_id) ?>" id="deleteUserForm">
                        
                        <!-- Deletion Type -->
                        <div class="mb-4">
                            <h6 class="mb-3">Deletion Type</h6>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="delete_type" id="archive" value="archive" checked>
                                <label class="form-check-label" for="archive">
                                    <strong>Archive User (Recommended)</strong>
                                    <div class="text-muted">
                                        Deactivate account and archive data. Email will be modified to prevent login. 
                                        Data remains in database for reporting and compliance.
                                    </div>
                                </label>
                            </div>
                            
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="delete_type" id="permanent" value="permanent">
                                <label class="form-check-label" for="permanent">
                                    <strong>Permanent Deletion</strong>
                                    <div class="text-muted">
                                        Completely remove user and associated data from database. 
                                        <span class="text-danger">This action cannot be undone.</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Ticket Management (for permanent deletion) -->
                        <div id="ticketManagement" class="mb-4" style="display: none;">
                            <h6 class="mb-3">Ticket Management</h6>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="transfer_tickets" name="transfer_tickets">
                                <label class="form-check-label" for="transfer_tickets">
                                    <strong>Transfer Assigned Tickets</strong>
                                    <div class="text-muted">
                                        Reassign all tickets currently assigned to this user to another staff member.
                                    </div>
                                </label>
                            </div>
                            
                            <div class="row g-3" id="transferToSection" style="display: none;">
                                <div class="col-md-8">
                                    <label for="transfer_to" class="form-label">Transfer To</label>
                                    <select class="form-control" id="transfer_to" name="transfer_to">
                                        <option value="">Select Staff Member</option>
                                        <?php foreach ($other_staff as $staff): ?>
                                        <option value="<?= htmlspecialchars($staff['id']) ?>">
                                            <?= htmlspecialchars($staff['full_name']) ?> - <?= htmlspecialchars($staff['designation'] ?? 'Staff') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Select a staff member to receive all assigned tickets</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reason for Deletion -->
                        <div class="mb-4">
                            <h6 class="mb-3">Reason for Deletion</h6>
                            <div class="mb-3">
                                <label for="reason" class="form-label required">Please provide a detailed reason for this action:</label>
                                <textarea class="form-control" id="reason" name="reason" rows="4" 
                                          placeholder="Example: Employee has left the company, account inactive for 6+ months, security concerns, etc."
                                          required></textarea>
                                <small class="text-muted">This reason will be recorded in the audit logs.</small>
                            </div>
                        </div>
                        
                        <!-- Notification -->
                        <div class="mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="notify_user" name="notify_user">
                                <label class="form-check-label" for="notify_user">
                                    <strong>Notify User</strong>
                                    <div class="text-muted">
                                        Send email notification to user about account deletion (if email is valid).
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Final Confirmation -->
                        <div class="confirmation-box">
                            <div class="confirmation-text">
                                <i class="fas fa-exclamation-circle text-danger me-2"></i>
                                FINAL CONFIRMATION REQUIRED
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirm_delete" name="confirm_delete" required>
                                <label class="form-check-label" for="confirm_delete">
                                    <strong>I understand that this action is permanent and cannot be undone.</strong>
                                    <div class="text-muted">
                                        I confirm that I have verified the user information and understand the consequences of this deletion.
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <a href="view.php?id=<?= $user_id ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-danger btn-lg" id="deleteButton">
                                <i class="fas fa-trash-alt"></i> Delete User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Important Notes -->
            <div class="card card-warning mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i> Important Notes</h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li class="mb-2">
                            <strong>Data Retention:</strong> Archived users can be restored by Super Administrators. 
                            Permanently deleted users cannot be recovered.
                        </li>
                        <li class="mb-2">
                            <strong>Compliance:</strong> User deletion may affect compliance with data retention policies. 
                            Ensure you are following company policies and legal requirements.
                        </li>
                        <li class="mb-2">
                            <strong>Reporting:</strong> Archived users will still appear in historical reports. 
                            Permanently deleted users will be removed from all reports.
                        </li>
                        <li>
                            <strong>Backup:</strong> Consider taking a database backup before performing permanent deletion.
                        </li>
                    </ul>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Mobile Menu Toggle -->
    <button class="btn btn-primary d-md-none fixed-bottom m-3" style="z-index: 1000;" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple mobile menu toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('d-none');
        }
        
        // Show/hide ticket management based on deletion type
        const archiveRadio = document.getElementById('archive');
        const permanentRadio = document.getElementById('permanent');
        const ticketManagement = document.getElementById('ticketManagement');
        
        function toggleTicketManagement() {
            if (permanentRadio.checked) {
                ticketManagement.style.display = 'block';
            } else {
                ticketManagement.style.display = 'none';
            }
        }
        
        archiveRadio.addEventListener('change', toggleTicketManagement);
        permanentRadio.addEventListener('change', toggleTicketManagement);
        toggleTicketManagement(); // Initial check
        
        // Show/hide transfer to section
        const transferTickets = document.getElementById('transfer_tickets');
        const transferToSection = document.getElementById('transferToSection');
        
        function toggleTransferToSection() {
            if (transferTickets.checked) {
                transferToSection.style.display = 'block';
            } else {
                transferToSection.style.display = 'none';
            }
        }
        
        transferTickets.addEventListener('change', toggleTransferToSection);
        toggleTransferToSection(); // Initial check
        
        // Form validation and confirmation
        document.getElementById('deleteUserForm').addEventListener('submit', function(e) {
            const deleteType = document.querySelector('input[name="delete_type"]:checked').value;
            const reason = document.getElementById('reason').value.trim();
            const confirmDelete = document.getElementById('confirm_delete').checked;
            const transferTicketsChecked = document.getElementById('transfer_tickets').checked;
            const transferTo = document.getElementById('transfer_to');
            
            // Validate reason
            if (!reason) {
                e.preventDefault();
                alert('Please provide a reason for deletion.');
                return;
            }
            
            // Validate confirmation
            if (!confirmDelete) {
                e.preventDefault();
                alert('You must confirm that you understand this action is permanent.');
                return;
            }
            
            // Validate ticket transfer
            if (deleteType === 'permanent' && transferTicketsChecked && (!transferTo || !transferTo.value)) {
                e.preventDefault();
                alert('Please select a staff member to transfer tickets to.');
                return;
            }
            
            // Final warning based on deletion type
            let message;
            if (deleteType === 'archive') {
                message = 'Are you sure you want to ARCHIVE this user?\n\n' +
                         'The user will be deactivated and their email modified to prevent login.\n' +
                         'User data will remain in the database for reporting purposes.\n\n' +
                         'Reason: ' + reason;
            } else {
                message = '⚠️ FINAL WARNING: PERMANENT DELETION ⚠️\n\n' +
                         'Are you ABSOLUTELY SURE you want to PERMANENTLY DELETE this user?\n\n' +
                         '❌ This action CANNOT BE UNDONE\n' +
                         '❌ All user data will be permanently removed\n' +
                         '❌ Tickets and records will be affected\n\n' +
                         'Reason: ' + reason + '\n\n' +
                         'Type "DELETE" to confirm:';
            }
            
            if (deleteType === 'permanent') {
                const userInput = prompt(message);
                if (userInput !== 'DELETE') {
                    e.preventDefault();
                    alert('Deletion cancelled. User was NOT deleted.');
                    return;
                }
            } else {
                if (!confirm(message)) {
                    e.preventDefault();
                    alert('Deletion cancelled. User was NOT archived.');
                    return;
                }
            }
            
            // Show processing message
            const deleteButton = document.getElementById('deleteButton');
            deleteButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            deleteButton.disabled = true;
        });
        
        // Update delete button text based on selected option
        function updateDeleteButton() {
            const deleteButton = document.getElementById('deleteButton');
            const deleteType = document.querySelector('input[name="delete_type"]:checked').value;
            
            if (deleteType === 'archive') {
                deleteButton.innerHTML = '<i class="fas fa-archive"></i> Archive User';
            } else {
                deleteButton.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Permanently';
            }
        }
        
        archiveRadio.addEventListener('change', updateDeleteButton);
        permanentRadio.addEventListener('change', updateDeleteButton);
        updateDeleteButton(); // Initial update
        
        // Character counter for reason textarea
        const reasonTextarea = document.getElementById('reason');
        if (reasonTextarea) {
            reasonTextarea.addEventListener('input', function() {
                const charCount = this.value.length;
                const counter = document.getElementById('charCounter') || 
                               (function() {
                                   const counter = document.createElement('div');
                                   counter.id = 'charCounter';
                                   counter.className = 'text-muted small mt-1';
                                   this.parentNode.appendChild(counter);
                                   return counter;
                               }).call(this);
                
                counter.textContent = charCount + ' characters';
                
                if (charCount < 10) {
                    counter.className = 'text-danger small mt-1';
                } else if (charCount < 20) {
                    counter.className = 'text-warning small mt-1';
                } else {
                    counter.className = 'text-success small mt-1';
                }
            });
            
            // Trigger input event to show initial counter
            reasonTextarea.dispatchEvent(new Event('input'));
        }
        
        // Auto-hide alerts after 10 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 10000);
    </script>
</body>
</html>