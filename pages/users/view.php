<?php
// pages/users/view.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: " . route('login'));
    exit();
}

$current_user_role = $_SESSION['user_type'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Check what information can be viewed based on role
function canViewAllInfo($user_role) {
    // Super Admin and Manager can see all information
    return in_array($user_role, ['super_admin', 'manager']);
}

function canViewSensitiveInfo($viewer_role, $target_user_role) {
    // Super Admin can view anyone's sensitive info
    if ($viewer_role === 'super_admin') {
        return true;
    }
    // Manager can view sensitive info of non-admin users
    if ($viewer_role === 'manager') {
        return !in_array($target_user_role, ['super_admin', 'admin']);
    }
    // Admin can view sensitive info of non-super_admin users
    if ($viewer_role === 'admin') {
        return $target_user_role !== 'super_admin';
    }
    return false;
}

function canViewUser($viewer_role, $target_user_role) {
    // Everyone can view themselves
    // Super Admin can view everyone
    if ($viewer_role === 'super_admin') {
        return true;
    }
    // Admin can view everyone except super_admin
    if ($viewer_role === 'admin') {
        return $target_user_role !== 'super_admin';
    }
    // Manager can only view support_tech and client
    if ($viewer_role === 'manager') {
        return in_array($target_user_role, ['support_tech', 'client']);
    }
    // Support Tech can only view themselves and clients
    if ($viewer_role === 'support_tech') {
        return in_array($target_user_role, ['support_tech', 'client']);
    }
    // Client can only view themselves
    if ($viewer_role === 'client') {
        return $viewer_role === $target_user_role;
    }
    return false;
}

$pdo = getDBConnection();

// Get user ID from URL
$user_id = $_GET['id'] ?? null;

if (!$user_id) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: " . route('users.index'));
    exit();
}

// Fetch user data with related information
$userQuery = "SELECT 
                u.*, 
                ur.role_name,
                sp.*,
                (SELECT COUNT(*) FROM tickets WHERE created_by = u.id) as ticket_count,
                (SELECT COUNT(*) FROM tickets WHERE assigned_to = u.id) as assigned_tickets,
                (SELECT COUNT(*) FROM site_visits WHERE engineer_id = u.id) as site_visits_count
              FROM users u 
              LEFT JOIN user_roles ur ON u.role_id = ur.id 
              LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
              WHERE u.id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: " . route('users.index'));
    exit();
}

// Check if current user can view this user
$can_view = canViewUser($current_user_role, $user['user_type']);
$is_self = ($current_user_id == $user_id);

// Allow users to view their own profile
if (!$can_view && !$is_self) {
    $_SESSION['error'] = "You don't have permission to view this user.";
    header("Location: " . route('users.index'));
    exit();
}

// Determine what information can be shown
$show_all_info = canViewAllInfo($current_user_role);
$show_sensitive_info = canViewSensitiveInfo($current_user_role, $user['user_type']);
$can_edit = ($is_self || in_array($current_user_role, ['super_admin', 'admin']));

// Get user activity logs (only for super_admin and manager)
$activity_logs = [];
if ($show_sensitive_info) {
    try {
        $logsQuery = "SELECT * FROM audit_logs 
                      WHERE user_id = ? OR entity_id = ? 
                      ORDER BY created_at DESC 
                      LIMIT 10";
        $logsStmt = $pdo->prepare($logsQuery);
        $logsStmt->execute([$user_id, $user_id]);
        $activity_logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Audit logs might not exist
    }
}

// Get assigned tickets (for staff users)
$assigned_tickets = [];
if (in_array($user['user_type'], ['admin', 'manager', 'support_tech'])) {
    $ticketsQuery = "SELECT t.*, c.company_name 
                     FROM tickets t
                     LEFT JOIN clients c ON t.client_id = c.id
                     WHERE t.assigned_to = ? 
                     OR t.primary_assignee = ?
                     ORDER BY t.created_at DESC 
                     LIMIT 5";
    $ticketsStmt = $pdo->prepare($ticketsQuery);
    $ticketsStmt->execute([$user_id, $user_id]);
    $assigned_tickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent logins (only for super_admin and manager)
$recent_logins = [];
if ($show_sensitive_info) {
    // We'll use audit logs for login tracking or create a separate table
    // For now, we'll show last_login timestamp
}

// Function to format date
function formatDate($date) {
    if (!$date) return 'Never';
    return date('M d, Y H:i', strtotime($date));
}

// Function to format phone number
function formatPhone($phone) {
    if (empty($phone)) return 'Not specified';
    // Remove all non-numeric characters
    $numbers = preg_replace('/[^0-9]/', '', $phone);
    
    // Format based on length
    if (strlen($numbers) == 10) {
        return '+1 (' . substr($numbers, 0, 3) . ') ' . substr($numbers, 3, 3) . '-' . substr($numbers, 6, 4);
    } elseif (strlen($numbers) > 10) {
        return '+' . substr($numbers, 0, strlen($numbers)-10) . ' (' . substr($numbers, -10, 3) . ') ' . 
               substr($numbers, -7, 3) . '-' . substr($numbers, -4);
    }
    return $phone;
}

// Function to get status badge
function getStatusBadge($status) {
    if ($status) {
        return '<span class="badge bg-success">Active</span>';
    } else {
        return '<span class="badge bg-danger">Inactive</span>';
    }
}

// Function to get verification badge
function getVerificationBadge($verified) {
    if ($verified) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Verified</span>';
    } else {
        return '<span class="badge bg-warning"><i class="fas fa-exclamation-circle"></i> Unverified</span>';
    }
}

// Function to get role badge
function getRoleBadge($role) {
    $badgeClasses = [
        'super_admin' => 'bg-danger',
        'admin' => 'bg-primary',
        'manager' => 'bg-success',
        'support_tech' => 'bg-info',
        'client' => 'bg-warning'
    ];
    
    $roleNames = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'support_tech' => 'Support Tech',
        'client' => 'Client'
    ];
    
    $class = $badgeClasses[$role] ?? 'bg-secondary';
    $name = $roleNames[$role] ?? ucfirst(str_replace('_', ' ', $role));
    
    return '<span class="badge ' . $class . '">' . $name . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View User - MSP Application</title>
    
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
        --accent-color: #43BCCD;
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
    
    /* Buttons */
    .btn-primary {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 6px;
        transition: all 0.2s;
    }
    
    .btn-primary:hover {
        background-color: #e85c2a;
        border-color: #e85c2a;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(255, 107, 53, 0.3);
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
    
    .alert-info {
        background-color: #e3f2fd;
        color: #1565c0;
        border-left: 4px solid #2196f3;
    }
    
    .alert-warning {
        background-color: #fff3cd;
        color: #856404;
        border-left: 4px solid #ffc107;
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
    
    /* User profile header */
    .profile-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        position: relative;
    }
    
    .profile-avatar {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: white;
        color: #764ba2;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        font-weight: bold;
        margin-right: 30px;
        border: 4px solid rgba(255, 255, 255, 0.3);
    }
    
    .profile-info h1 {
        margin-bottom: 5px;
        font-weight: 600;
    }
    
    .profile-info p {
        margin-bottom: 5px;
        opacity: 0.9;
    }
    
    /* Info cards */
    .info-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    
    .info-card h6 {
        color: var(--primary-color);
        border-bottom: 2px solid #f1f1f1;
        padding-bottom: 10px;
        margin-bottom: 15px;
        font-weight: 600;
    }
    
    .info-item {
        margin-bottom: 12px;
    }
    
    .info-label {
        font-weight: 500;
        color: #495057;
        margin-bottom: 3px;
        font-size: 14px;
    }
    
    .info-value {
        color: #6c757d;
        font-size: 15px;
    }
    
    /* Badge styling */
    .badge {
        font-weight: 500;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
    }
    
    /* Stats cards */
    .stats-card {
        text-align: center;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        color: white;
    }
    
    .stats-card i {
        font-size: 32px;
        margin-bottom: 10px;
        opacity: 0.9;
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
    
    /* Permission indicators */
    .permission-restricted {
        background-color: #f8f9fa;
        padding: 10px;
        border-radius: 6px;
        border-left: 4px solid #ffc107;
        margin-bottom: 15px;
    }
    
    .sensitive-info {
        background-color: #fff3cd;
        padding: 8px 12px;
        border-radius: 4px;
        border-left: 3px solid #ffc107;
        margin-top: 5px;
    }
    
    /* Activity log */
    .activity-log {
        max-height: 300px;
        overflow-y: auto;
    }
    
    .activity-item {
        padding: 10px 0;
        border-bottom: 1px solid #f1f1f1;
    }
    
    .activity-item:last-child {
        border-bottom: none;
    }
    
    .activity-time {
        font-size: 12px;
        color: #6c757d;
        margin-top: 3px;
    }
    
    /* Ticket list */
    .ticket-item {
        padding: 10px;
        border-radius: 6px;
        background-color: #f8f9fa;
        margin-bottom: 10px;
        border-left: 3px solid var(--primary-color);
    }
    
    .ticket-title {
        font-weight: 500;
        margin-bottom: 5px;
    }
    
    .ticket-meta {
        font-size: 12px;
        color: #6c757d;
    }
    
    /* Action buttons */
    .action-buttons {
        position: absolute;
        top: 30px;
        right: 30px;
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
    
    /* Section headers */
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .section-header h4 {
        color: var(--primary-color);
        margin: 0;
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
                <a href="<?php echo route('logout'); ?>" class="btn btn-sm btn-outline-light">
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
            <!-- Permission Warning -->
            <?php if (!$show_all_info && !$is_self): ?>
            <div class="alert alert-warning">
                <i class="fas fa-eye-slash me-2"></i>
                <strong>Limited View:</strong> You are viewing limited information based on your permissions. 
                Some sensitive information is hidden.
            </div>
            <?php endif; ?>
            
            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); endif; ?>
            
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="d-flex align-items-center">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($user['email'], 0, 1)) ?>
                    </div>
                    <div class="profile-info flex-grow-1">
                        <h1><?= htmlspecialchars($user['full_name'] ?? $user['email']) ?></h1>
                        <p class="mb-1">
                            <i class="fas fa-envelope me-2"></i> <?= htmlspecialchars($user['email']) ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-user-tag me-2"></i> 
                            <?= getRoleBadge($user['user_type']) ?>
                            <?php if ($user['staff_id']): ?>
                            <span class="ms-3"><i class="fas fa-id-card me-1"></i> <?= htmlspecialchars($user['staff_id']) ?></span>
                            <?php endif; ?>
                        </p>
                        <div class="d-flex align-items-center">
                            <?= getStatusBadge($user['is_active']) ?>
                            <span class="ms-3"><?= getVerificationBadge($user['email_verified']) ?></span>
                            <?php if ($user['two_factor_enabled']): ?>
                            <span class="badge bg-info ms-3"><i class="fas fa-shield-alt"></i> 2FA Enabled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <div class="btn-group">
                        <?php if ($is_self): ?>
                        <a href="<?php echo route('users.edit'); ?>?id=<?= $user_id ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                        <?php elseif ($can_edit): ?>
                        <a href="<?php echo route('users.edit'); ?>?id=<?= $user_id ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit User
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo route('users.index'); ?>" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- User Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <i class="fas fa-user-clock"></i>
                        <div class="stats-number"><?= $user['last_login'] ? 'Active' : 'Inactive' ?></div>
                        <div class="stats-label">Account Status</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="stats-number"><?= date('M d, Y', strtotime($user['created_at'])) ?></div>
                        <div class="stats-label">Member Since</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-ticket-alt"></i>
                        <div class="stats-number"><?= $user['ticket_count'] ?? 0 ?></div>
                        <div class="stats-label">Tickets Created</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="stats-number"><?= $user['site_visits_count'] ?? 0 ?></div>
                        <div class="stats-label">Site Visits</div>
                    </div>
                </div>
            </div>
            
            <!-- Basic Information (Visible to all) -->
            <div class="row">
                <div class="col-md-6">
                    <div class="info-card">
                        <h6><i class="fas fa-info-circle me-2"></i> Basic Information</h6>
                        
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?= htmlspecialchars($user['full_name'] ?? 'Not specified') ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?= htmlspecialchars($user['email']) ?></div>
                        </div>
                        
                        <?php if ($user['phone_number']): ?>
                        <div class="info-item">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?= formatPhone($user['phone_number']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($user['designation']): ?>
                        <div class="info-item">
                            <div class="info-label">Designation</div>
                            <div class="info-value"><?= htmlspecialchars($user['designation']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($user['department']): ?>
                        <div class="info-item">
                            <div class="info-label">Department</div>
                            <div class="info-value"><?= htmlspecialchars($user['department']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Account Information (Limited view for non-admins) -->
                <div class="col-md-6">
                    <div class="info-card">
                        <h6><i class="fas fa-user-shield me-2"></i> Account Information</h6>
                        
                        <div class="info-item">
                            <div class="info-label">User Role</div>
                            <div class="info-value"><?= getRoleBadge($user['user_type']) ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Account Status</div>
                            <div class="info-value"><?= getStatusBadge($user['is_active']) ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Email Verification</div>
                            <div class="info-value"><?= getVerificationBadge($user['email_verified']) ?></div>
                        </div>
                        
                        <?php if ($show_sensitive_info): ?>
                        <div class="info-item">
                            <div class="info-label">Two-Factor Authentication</div>
                            <div class="info-value">
                                <?php if ($user['two_factor_enabled']): ?>
                                <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Account Created</div>
                            <div class="info-value"><?= formatDate($user['created_at']) ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Last Updated</div>
                            <div class="info-value"><?= formatDate($user['updated_at']) ?></div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Last Login</div>
                            <div class="info-value"><?= formatDate($user['last_login']) ?></div>
                        </div>
                        <?php else: ?>
                        <div class="permission-restricted">
                            <i class="fas fa-lock text-warning me-2"></i>
                            <small>Sensitive account information is hidden due to permission restrictions.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Staff/Employee Information (Only for staff users) -->
            <?php if (in_array($user['user_type'], ['admin', 'manager', 'support_tech'])): ?>
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-briefcase me-2"></i> Employment Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php if ($user['staff_id']): ?>
                                <div class="col-md-3">
                                    <div class="info-item">
                                        <div class="info-label">Staff ID</div>
                                        <div class="info-value"><?= htmlspecialchars($user['staff_id']) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($user['employment_type']): ?>
                                <div class="col-md-3">
                                    <div class="info-item">
                                        <div class="info-label">Employment Type</div>
                                        <div class="info-value"><?= htmlspecialchars($user['employment_type']) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($user['date_of_joining']): ?>
                                <div class="col-md-3">
                                    <div class="info-item">
                                        <div class="info-label">Date of Joining</div>
                                        <div class="info-value"><?= date('M d, Y', strtotime($user['date_of_joining'])) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($user['employment_status']): ?>
                                <div class="col-md-3">
                                    <div class="info-item">
                                        <div class="info-label">Employment Status</div>
                                        <div class="info-value"><?= htmlspecialchars($user['employment_status']) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($user['skills']): ?>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="info-item">
                                        <div class="info-label">Skills</div>
                                        <div class="info-value"><?= nl2br(htmlspecialchars($user['skills'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($user['certifications']): ?>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="info-item">
                                        <div class="info-label">Certifications</div>
                                        <div class="info-value"><?= nl2br(htmlspecialchars($user['certifications'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Assigned Tickets (Only for staff users) -->
            <?php if (!empty($assigned_tickets) && $show_sensitive_info): ?>
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-tasks me-2"></i> Recent Assigned Tickets</h5>
                            <span class="badge bg-primary"><?= count($assigned_tickets) ?> tickets</span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($assigned_tickets as $ticket): ?>
                            <div class="ticket-item">
                                <div class="ticket-title">
                                    <a href="<?php echo route('tickets.view'); ?>?id=<?= $ticket['id'] ?>">
                                        <?= htmlspecialchars($ticket['title']) ?>
                                    </a>
                                </div>
                                <div class="ticket-meta">
                                    <span class="me-3">
                                        <i class="fas fa-building"></i> <?= htmlspecialchars($ticket['company_name'] ?? 'No Client') ?>
                                    </span>
                                    <span class="me-3">
                                        <i class="fas fa-flag"></i> <?= htmlspecialchars($ticket['priority']) ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-clock"></i> <?= formatDate($ticket['created_at']) ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($assigned_tickets) == 5): ?>
                            <div class="text-center mt-3">
                                <a href="<?php echo route('tickets.index'); ?>?assigned_to=<?= $user_id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-list"></i> View All Tickets
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Activity Log (Only for Super Admin and Manager) -->
            <?php if (!empty($activity_logs) && $show_sensitive_info): ?>
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i> Recent Activity</h5>
                        </div>
                        <div class="card-body">
                            <div class="activity-log">
                                <?php foreach ($activity_logs as $log): ?>
                                <div class="activity-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?= htmlspecialchars($log['action']) ?></strong> 
                                            <?= htmlspecialchars($log['entity_type']) ?>
                                            <?php if ($log['details']): ?>
                                            <span class="text-muted">- <?= htmlspecialchars($log['details']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted">
                                            <small><?= formatDate($log['created_at']) ?></small>
                                        </div>
                                    </div>
                                    <?php if ($log['ip_address']): ?>
                                    <div class="activity-time">
                                        <i class="fas fa-globe"></i> IP: <?= htmlspecialchars($log['ip_address']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Permission Info Box -->
            <?php if (!$show_all_info): ?>
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-info-circle fa-2x me-3 mt-1"></i>
                            <div>
                                <h6 class="mb-2">Permission Information</h6>
                                <p class="mb-1">
                                    <strong>Your Role:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $current_user_role))) ?>
                                </p>
                                <p class="mb-1">
                                    <strong>Viewing:</strong> 
                                    <?php if ($is_self): ?>
                                    Your own profile
                                    <?php else: ?>
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['user_type']))) ?> profile
                                    <?php endif; ?>
                                </p>
                                <p class="mb-0">
                                    <strong>Access Level:</strong> 
                                    <?php if ($show_sensitive_info): ?>
                                    <span class="badge bg-success">Full Access</span> - You can view all information
                                    <?php else: ?>
                                    <span class="badge bg-warning">Limited Access</span> - Sensitive information is hidden
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bolt me-2"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <a href="mailto:<?= htmlspecialchars($user['email']) ?>" class="btn btn-outline-primary w-100 mb-2">
                                        <i class="fas fa-envelope"></i> Send Email
                                    </a>
                                </div>
                                <?php if ($user['phone_number']): ?>
                                <div class="col-md-3">
                                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $user['phone_number'])) ?>" class="btn btn-outline-success w-100 mb-2">
                                        <i class="fas fa-phone"></i> Call User
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php if ($can_edit): ?>
                                <div class="col-md-3">
                                    <a href="<?php echo route('users.edit'); ?>?id=<?= $user_id ?>" class="btn btn-outline-warning w-100 mb-2">
                                        <i class="fas fa-edit"></i> Edit Profile
                                    </a>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-3">
                                    <a href="<?php echo route('users.index'); ?>" class="btn btn-outline-secondary w-100 mb-2">
                                        <i class="fas fa-users"></i> Back to Users
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
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
        
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Format dates on client side
        function formatClientDate(dateString) {
            if (!dateString) return 'Never';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: '2-digit', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Update all date elements
        document.querySelectorAll('.info-value').forEach(el => {
            const text = el.textContent.trim();
            // Check if it looks like a date
            if (text.match(/\d{4}-\d{2}-\d{2}/) && text.match(/\d{2}:\d{2}/)) {
                el.textContent = formatClientDate(text);
            }
        });
    </script>
</body>
</html>