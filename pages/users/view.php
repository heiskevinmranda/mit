<?php
// pages/users/view.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/../../includes/profile_picture_helper.php';

if (!isLoggedIn()) {
    header("Location: " . route('login'));
    exit();
}

$current_user_role = $_SESSION['user_type'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Get current user info for sidebar
$current_user = getCurrentUser();
$user_type = $current_user['user_type'] ?? 'client';

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
                u.id, u.email, u.user_type, u.is_active, u.email_verified, u.two_factor_enabled, u.last_login, u.created_at as user_created_at, u.updated_at as user_updated_at, u.role_id,
                ur.role_name,
                sp.id as staff_profile_id, sp.staff_id, sp.full_name as staff_full_name, sp.designation, sp.department, sp.employment_type, sp.date_of_joining, sp.reporting_manager_id, sp.official_email, sp.personal_email, sp.phone_number as staff_phone_number, sp.alternate_phone, sp.emergency_contact_name, sp.emergency_contact_number, sp.current_address, sp.permanent_address, sp.national_id, sp.passport_number, sp.date_of_birth, sp.gender, sp.nationality, sp.tax_id, sp.work_permit_details, sp.role_category, sp.skills, sp.certifications, sp.experience_years, sp.assigned_clients, sp.service_area, sp.shift_timing, sp.on_call_support, sp.username as staff_username, sp.role_level, sp.system_access, sp.company_laptop_issued, sp.asset_serial_number, sp.vpn_access, sp.bank_name, sp.account_number, sp.salary_type, sp.payment_method, sp.employment_status, sp.last_working_day, sp.remarks, sp.staff_signature_data, sp.hr_approval_date, sp.hr_manager_id, sp.created_at as staff_created_at, sp.updated_at as staff_updated_at,
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
    <title>User Details - MSP Portal</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
<style>
    :root {
        --primary-color: #FF6B35;
        --primary-dark: #e55a2b;
        --secondary-color: #004E89;
        --secondary-light: #0066b3;
        --bg-main: #F8FAFC;
        --bg-card: #FFFFFF;
        --text-primary: #1E293B;
        --text-secondary: #64748B;
        --text-muted: #94A3B8;
        --border-color: #E2E8F0;
        --success: #10B981;
        --warning: #F59E0B;
        --danger: #EF4444;
        --info: #3B82F6;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 16px;
        --radius-xl: 24px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    * {
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: var(--bg-main);
        color: var(--text-primary);
    }
    
    /* ===== DASHBOARD LAYOUT ===== */
    .dashboard-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* ===== MODERN SIDEBAR (override global) ===== */
    /* Using global sidebar from includes/sidebar.php */
    
    /* ===== MAIN CONTENT (override global) ===== */
    .main-content {
        flex: 1;
        margin-left: var(--sidebar-width);
        padding: 24px;
        background: var(--bg-main);
        min-height: calc(100vh - 60px);
        width: 100%;
    }
    
    /* ===== TOP HEADER (if needed) ===== */
    .top-header {
        background: white;
        padding: 16px 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: var(--shadow-sm);
        position: sticky;
        top: 0;
        z-index: 100;
    }
    
    .breadcrumb-nav {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.875rem;
    }
    
    .breadcrumb-nav a {
        color: var(--text-secondary);
        text-decoration: none;
        transition: var(--transition);
    }
    
    .breadcrumb-nav a:hover {
        color: var(--primary-color);
    }
    
    .breadcrumb-nav span {
        color: var(--text-muted);
    }
    
    .breadcrumb-nav .current {
        color: var(--text-primary);
        font-weight: 500;
    }
    
    .header-actions {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    
    .header-search {
        position: relative;
    }
    
    .header-search input {
        width: 240px;
        padding: 10px 16px 10px 40px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-lg);
        font-size: 0.875rem;
        transition: var(--transition);
        background: var(--bg-main);
    }
    
    .header-search input:focus {
        outline: none;
        border-color: var(--primary-color);
        width: 300px;
    }
    
    .header-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }
    
    .header-icon-btn {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-md);
        border: none;
        background: var(--bg-main);
        color: var(--text-secondary);
        cursor: pointer;
        transition: var(--transition);
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .header-icon-btn:hover {
        background: var(--primary-color);
        color: white;
        transform: translateY(-2px);
    }
    
    .header-icon-btn .badge {
        position: absolute;
        top: 4px;
        right: 4px;
        width: 8px;
        height: 8px;
        padding: 0;
        border-radius: 50%;
    }
    
    .user-dropdown {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 6px 12px 6px 6px;
        border-radius: var(--radius-lg);
        cursor: pointer;
        transition: var(--transition);
    }
    
    .user-dropdown:hover {
        background: var(--bg-main);
    }
    
    .user-dropdown-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), #ff8f5a);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
    }
    
    .user-dropdown-info {
        text-align: left;
    }
    
    .user-dropdown-name {
        font-weight: 600;
        font-size: 0.875rem;
        line-height: 1.2;
    }
    
    .user-dropdown-role {
        font-size: 0.75rem;
        color: var(--text-muted);
        text-transform: capitalize;
    }
    
    .page-header {
        background: white;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title-section h1 {
        color: var(--secondary-color);
        font-size: 1.5rem;
        margin: 0;
    }
    
    .page-title-section p {
        color: var(--text-secondary);
        margin: 0;
        font-size: 0.9rem;
    }
    
    .page-actions {
        display: flex;
        gap: 12px;
    }
    
    /* ===== BUTTONS ===== */
    .btn {
        padding: 10px 20px;
        border-radius: var(--radius-md);
        font-weight: 500;
        font-size: 0.875rem;
        transition: var(--transition);
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary {
        background: var(--primary-color);
        color: white;
    }
    
    .btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
    }
    
    .btn-secondary {
        background: var(--bg-main);
        color: var(--text-primary);
        border: 2px solid var(--border-color);
    }
    
    .btn-secondary:hover {
        background: var(--border-color);
        transform: translateY(-2px);
    }
    
    .btn-outline {
        background: transparent;
        color: var(--text-secondary);
        border: 2px solid var(--border-color);
    }
    
    .btn-outline:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }
    
    .btn-danger {
        background: var(--danger);
        color: white;
    }
    
    .btn-danger:hover {
        background: #dc2626;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    
    .btn-warning {
        background: var(--warning);
        color: white;
    }
    
    .btn-warning:hover {
        background: #d97706;
        transform: translateY(-2px);
    }
    
    .btn-success {
        background: var(--success);
        color: white;
    }
    
    .btn-success:hover {
        background: #059669;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 0.8rem;
    }
    
    .btn-icon {
        width: 36px;
        height: 36px;
        padding: 0;
        justify-content: center;
    }
    
    /* ===== HERO PROFILE CARD ===== */
    .hero-profile-card {
        background: white;
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-lg);
        overflow: hidden;
        margin-bottom: 32px;
        position: relative;
    }
    
    .hero-profile-bg {
        height: 140px;
        background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-light) 50%, #0080d4 100%);
        position: relative;
    }
    
    .hero-profile-bg::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 60px;
        background: linear-gradient(to top, rgba(255,255,255,0.1), transparent);
    }
    
    .hero-profile-content {
        padding: 0 32px 32px;
        position: relative;
    }
    
    .hero-profile-main {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        margin-top: -60px;
        position: relative;
        z-index: 1;
    }
    
    .hero-profile-left {
        display: flex;
        align-items: flex-end;
        gap: 24px;
    }
    
    .hero-avatar {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: white;
        padding: 4px;
        box-shadow: var(--shadow-lg);
    }
    
    .hero-avatar-inner {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), #ff8f5a);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 2.5rem;
        overflow: hidden;
    }
    
    .hero-avatar-img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        overflow: hidden;
        border: 4px solid white;
        box-shadow: var(--shadow-md);
    }
    
    .hero-avatar-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .hero-avatar-initials {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), #ff8f5a) !important;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white !important;
        font-weight: 700;
        font-size: 2.5rem;
        border: 4px solid white;
        box-shadow: var(--shadow-md);
    }
    
    /* Override profile picture helper styles in hero */
    .hero-avatar-initials .profile-picture-initials {
        width: 100% !important;
        height: 100% !important;
        border-radius: 50% !important;
        margin: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .hero-profile-info {
        padding-bottom: 8px;
    }
    
    .hero-profile-name {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0 0 8px 0;
        letter-spacing: -0.5px;
    }
    
    .hero-profile-email {
        color: var(--text-secondary);
        font-size: 0.95rem;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .hero-profile-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .profile-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .profile-badge-role {
        background: rgba(59, 130, 246, 0.1);
        color: #3B82F6;
    }
    
    .profile-badge-status-active {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .profile-badge-status-inactive {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }
    
    .profile-badge-verified {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .profile-badge-unverified {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning);
    }
    
    .profile-badge-2fa-enabled {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .profile-badge-2fa-disabled {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }
    
    .hero-profile-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    /* ===== STATS CARDS ===== */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .stat-card {
        background: white;
        border-radius: var(--radius-lg);
        padding: 24px;
        box-shadow: var(--shadow-md);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }
    
    .stat-card-tickets::before {
        background: linear-gradient(90deg, #6366f1, #8b5cf6);
    }
    
    .stat-card-visits::before {
        background: linear-gradient(90deg, #10b981, #34d399);
    }
    
    .stat-card-member::before {
        background: linear-gradient(90deg, #f59e0b, #fbbf24);
    }
    
    .stat-card-login::before {
        background: linear-gradient(90deg, #3b82f6, #60a5fa);
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-xl);
    }
    
    .stat-card-icon {
        width: 48px;
        height: 48px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-bottom: 16px;
    }
    
    .stat-card-tickets .stat-card-icon {
        background: rgba(99, 102, 241, 0.1);
        color: #6366f1;
    }
    
    .stat-card-visits .stat-card-icon {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .stat-card-member .stat-card-icon {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning);
    }
    
    .stat-card-login .stat-card-icon {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info);
    }
    
    .stat-card-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 4px;
        line-height: 1;
    }
    
    .stat-card-label {
        font-size: 0.875rem;
        color: var(--text-secondary);
    }
    
    /* ===== INFO CARDS ===== */
    .info-cards-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 32px;
    }
    
    .info-card {
        background: white;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        transition: var(--transition);
    }
    
    .info-card:hover {
        box-shadow: var(--shadow-lg);
    }
    
    .info-card-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .info-card-header i {
        font-size: 1.1rem;
        color: var(--primary-color);
    }
    
    .info-card-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }
    
    .info-card-body {
        padding: 24px;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 12px 0;
        border-bottom: 1px solid var(--border-color);
    }
    
    .info-row:last-child {
        border-bottom: none;
    }
    
    .info-label {
        font-size: 0.85rem;
        color: var(--text-muted);
        font-weight: 500;
    }
    
    .info-value {
        font-size: 0.9rem;
        color: var(--text-primary);
        font-weight: 600;
        text-align: right;
    }
    
    .info-value.badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    /* ===== ACTIVITY TIMELINE ===== */
    .timeline-section {
        background: white;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        margin-bottom: 32px;
    }
    
    .timeline-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .timeline-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .timeline-header i {
        color: var(--primary-color);
    }
    
    .timeline-body {
        padding: 24px;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .timeline-item {
        display: flex;
        gap: 16px;
        padding: 16px 0;
        position: relative;
    }
    
    .timeline-item:not(:last-child)::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 48px;
        bottom: -16px;
        width: 2px;
        background: var(--border-color);
    }
    
    .timeline-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.75rem;
    }
    
    .timeline-icon-create {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .timeline-icon-update {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info);
    }
    
    .timeline-icon-delete {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }
    
    .timeline-content {
        flex: 1;
    }
    
    .timeline-action {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 4px;
    }
    
    .timeline-details {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin-bottom: 8px;
    }
    
    .timeline-meta {
        display: flex;
        gap: 16px;
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    
    .timeline-meta i {
        margin-right: 4px;
    }
    
    /* ===== QUICK ACTIONS ===== */
    .quick-actions {
        background: white;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        overflow: hidden;
    }
    
    .quick-actions-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .quick-actions-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .quick-actions-header i {
        color: var(--primary-color);
    }
    
    .quick-actions-body {
        padding: 24px;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }
    
    .quick-action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 20px;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-md);
        background: white;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        color: var(--text-secondary);
    }
    
    .quick-action-btn:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    
    .quick-action-btn i {
        font-size: 1.25rem;
    }
    
    .quick-action-btn span {
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    /* ===== ALERTS ===== */
    .alert {
        padding: 16px 20px;
        border-radius: var(--radius-md);
        margin-bottom: 24px;
        border: none;
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }
    
    .alert-success {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
    }
    
    .alert-danger {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
    }
    
    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        color: var(--warning);
    }
    
    .alert-info {
        background: rgba(59, 130, 246, 0.1);
        color: var(--info);
    }
    
    /* ===== PERMISSION NOTICE ===== */
    .permission-notice {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(99, 102, 241, 0.05));
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        padding: 20px 24px;
        margin-bottom: 24px;
        display: flex;
        align-items: flex-start;
        gap: 16px;
    }
    
    .permission-notice-icon {
        width: 40px;
        height: 40px;
        border-radius: var(--radius-md);
        background: rgba(59, 130, 246, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--info);
        flex-shrink: 0;
    }
    
    .permission-notice-content h4 {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0 0 4px 0;
    }
    
    .permission-notice-content p {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin: 0;
    }
    
    /* ===== ASSIGNED TICKETS ===== */
    .assigned-tickets {
        background: white;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        overflow: hidden;
        margin-bottom: 32px;
    }
    
    .assigned-tickets-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .assigned-tickets-header h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .assigned-tickets-header i {
        color: var(--primary-color);
    }
    
    .ticket-item {
        padding: 16px 24px;
        border-bottom: 1px solid var(--border-color);
        transition: var(--transition);
    }
    
    .ticket-item:last-child {
        border-bottom: none;
    }
    
    .ticket-item:hover {
        background: var(--bg-main);
    }
    
    .ticket-title {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 8px;
    }
    
    .ticket-title a {
        color: var(--text-primary);
        text-decoration: none;
    }
    
    .ticket-title a:hover {
        color: var(--primary-color);
    }
    
    .ticket-meta {
        display: flex;
        gap: 16px;
        font-size: 0.8rem;
        color: var(--text-muted);
    }
    
    .ticket-meta i {
        margin-right: 4px;
    }
    
    /* ===== MOBILE MENU TOGGLE ===== */
    .mobile-menu-toggle {
        display: none;
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        border: none;
        box-shadow: var(--shadow-lg);
        cursor: pointer;
        z-index: 1001;
        transition: var(--transition);
    }
    
    .mobile-menu-toggle:hover {
        transform: scale(1.1);
    }
    
    /* ===== RESPONSIVE ===== */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .info-cards-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 992px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.mobile-open {
            transform: translateX(0);
        }
        
        .main-content {
            margin-left: 0;
        }
        
        .mobile-menu-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .hero-profile-main {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .hero-profile-actions {
            margin-top: 24px;
            width: 100%;
        }
        
        .hero-profile-actions .btn {
            flex: 1;
            justify-content: center;
        }
    }
    
    @media (max-width: 768px) {
        .top-header {
            padding: 12px 16px;
        }
        
        .header-search {
            display: none;
        }
        
        .main-content {
            padding: 16px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .info-cards-grid {
            grid-template-columns: 1fr;
        }
        
        .quick-actions-body {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .hero-profile-content {
            padding: 0 16px 16px;
        }
        
        .hero-avatar {
            width: 80px;
            height: 80px;
        }
        
        .hero-avatar-inner {
            font-size: 1.75rem;
        }
        
        .hero-profile-name {
            font-size: 1.25rem;
        }
    }
    
    /* ===== ANIMATIONS ===== */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .animate-fade-in {
        animation: fadeInUp 0.5s ease forwards;
    }
    
    .animate-delay-1 { animation-delay: 0.1s; }
    .animate-delay-2 { animation-delay: 0.2s; }
    .animate-delay-3 { animation-delay: 0.3s; }
    .animate-delay-4 { animation-delay: 0.4s; }
    
    /* ===== SCROLLBAR ===== */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: var(--bg-main);
    }
    
    ::-webkit-scrollbar-thumb {
        background: var(--border-color);
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: var(--text-muted);
    }
    
    /* ===== PROFILE PICTURE STYLES ===== */
    .profile-picture-initials {
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-weight: 600;
        color: white;
        background: linear-gradient(135deg, var(--primary-color), #ff8f5a);
    }
    
    .profile-picture-img {
        border-radius: 50%;
        object-fit: cover;
    }
</style>
</head>
<body>

<div class="dashboard-container">
    <!-- Sidebar -->
    <?php include '../../includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title-section">
                    <h1>User Details</h1>
                    <p>View and manage user information</p>
                </div>
                <div class="page-actions">
                    <a href="<?php echo route('users.edit', ['id' => $user_id]); ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit User
                    </a>
                    <a href="<?php echo route('users.index'); ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Permission Warning -->
            <?php if (!$show_all_info && !$is_self): ?>
            <div class="permission-notice animate-fade-in">
                <div class="permission-notice-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="permission-notice-content">
                    <h4>Limited View</h4>
                    <p>You are viewing limited information based on your permissions. Some sensitive information is hidden.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success animate-fade-in">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger animate-fade-in">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); endif; ?>
            
            <!-- Current User Info -->
            <div class="alert alert-info animate-fade-in" style="background: rgba(59, 130, 246, 0.08); border-left: 4px solid var(--info);">
                <i class="fas fa-info-circle"></i>
                <span>Logged in as <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong> (<?php echo ucfirst(str_replace('_', ' ', $current_user_role)); ?>)</span>
            </div>
            
            <!-- Hero Profile Card -->
            <div class="hero-profile-card animate-fade-in">
                <div class="hero-profile-bg"></div>
                <div class="hero-profile-content">
                    <div class="hero-profile-main">
                        <div class="hero-profile-left">
                            <div class="hero-avatar">
                                <?php 
                                $profilePicHtml = getProfilePictureHTML($user['id'], $user['email'], 'xl', '');
                                // Check if it contains an img tag
                                if (strpos($profilePicHtml, '<img') !== false) {
                                    echo '<div class="hero-avatar-img">' . $profilePicHtml . '</div>';
                                } else {
                                    // It's initials, show in styled div
                                    echo '<div class="hero-avatar-initials">' . $profilePicHtml . '</div>';
                                }
                                ?>
                            </div>
                            <div class="hero-profile-info">
                                <h1 class="hero-profile-name"><?= htmlspecialchars($user['staff_full_name'] ?? $user['full_name'] ?? $user['email']) ?></h1>
                                <p class="hero-profile-email">
                                    <i class="fas fa-envelope"></i>
                                    <?= htmlspecialchars($user['email']) ?>
                                </p>
                                <div class="hero-profile-badges">
                                    <?php
                                    $roleNames = [
                                        'super_admin' => 'Super Admin',
                                        'admin' => 'Admin',
                                        'manager' => 'Manager',
                                        'support_tech' => 'Support Tech',
                                        'client' => 'Client'
                                    ];
                                    $roleColors = [
                                        'super_admin' => 'bg-danger',
                                        'admin' => 'bg-primary',
                                        'manager' => 'bg-success',
                                        'support_tech' => 'bg-info',
                                        'client' => 'bg-warning'
                                    ];
                                    ?>
                                    <span class="profile-badge profile-badge-role">
                                        <i class="fas fa-user-tag"></i>
                                        <?php echo $roleNames[$user['user_type']] ?? ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                                    </span>
                                    <span class="profile-badge <?php echo $user['is_active'] ? 'profile-badge-status-active' : 'profile-badge-status-inactive'; ?>">
                                        <i class="fas fa-circle" style="font-size: 8px;"></i>
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <span class="profile-badge <?php echo $user['email_verified'] ? 'profile-badge-verified' : 'profile-badge-unverified'; ?>">
                                        <i class="fas fa-<?php echo $user['email_verified'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                        <?php echo $user['email_verified'] ? 'Verified' : 'Unverified'; ?>
                                    </span>
                                    <span class="profile-badge <?php echo $user['two_factor_enabled'] ? 'profile-badge-2fa-enabled' : 'profile-badge-2fa-disabled'; ?>">
                                        <i class="fas fa-shield-alt"></i>
                                        2FA <?php echo $user['two_factor_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                    <?php if ($user['staff_id']): ?>
                                    <span class="profile-badge" style="background: rgba(107, 114, 128, 0.1); color: #6b7280;">
                                        <i class="fas fa-id-card"></i>
                                        <?= htmlspecialchars($user['staff_id']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="hero-profile-actions">
                            <a href="<?php echo route('users.edit', ['id' => $user_id]); ?>" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit User
                            </a>
                            <button class="btn btn-secondary" onclick="alert('Reset password functionality coming soon')">
                                <i class="fas fa-key"></i> Reset Password
                            </button>
                            <button class="btn btn-<?php echo $user['is_active'] ? 'warning' : 'success'; ?>" onclick="alert('Toggle status functionality coming soon')">
                                <i class="fas fa-<?php echo $user['is_active'] ? 'ban' : 'check'; ?>"></i>
                                <?php echo $user['is_active'] ? 'Disable' : 'Enable'; ?>
                            </button>
                            <?php if (in_array($current_user_role, ['super_admin'])): ?>
                            <button class="btn btn-danger" onclick="confirmDelete()">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card stat-card-tickets animate-fade-in animate-delay-1">
                    <div class="stat-card-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-card-value"><?= $user['ticket_count'] ?? 0 ?></div>
                    <div class="stat-card-label">Tickets Created</div>
                </div>
                <div class="stat-card stat-card-visits animate-fade-in animate-delay-2">
                    <div class="stat-card-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="stat-card-value"><?= $user['site_visits_count'] ?? 0 ?></div>
                    <div class="stat-card-label">Site Visits</div>
                </div>
                <div class="stat-card stat-card-member animate-fade-in animate-delay-3">
                    <div class="stat-card-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-card-value" style="font-size: 1.25rem;">
                        <?= $user['user_created_at'] ? date('M d, Y', strtotime($user['user_created_at'])) : 'N/A' ?>
                    </div>
                    <div class="stat-card-label">Member Since</div>
                </div>
                <div class="stat-card stat-card-login animate-fade-in animate-delay-4">
                    <div class="stat-card-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-card-value" style="font-size: 1.25rem;">
                        <?= $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never' ?>
                    </div>
                    <div class="stat-card-label">Last Login</div>
                </div>
            </div>
            
            <!-- Information Cards Grid -->
            <div class="info-cards-grid">
                <!-- Basic Information Card -->
                <div class="info-card animate-fade-in">
                    <div class="info-card-header">
                        <i class="fas fa-user"></i>
                        <h3>Basic Information</h3>
                    </div>
                    <div class="info-card-body">
                        <div class="info-row">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= htmlspecialchars($user['staff_full_name'] ?? $user['full_name'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email Address</span>
                            <span class="info-value"><?= htmlspecialchars($user['email']) ?></span>
                        </div>
                        <?php if ($user['staff_phone_number']): ?>
                        <div class="info-row">
                            <span class="info-label">Phone Number</span>
                            <span class="info-value"><?= formatPhone($user['staff_phone_number']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['designation']): ?>
                        <div class="info-row">
                            <span class="info-label">Designation</span>
                            <span class="info-value"><?= htmlspecialchars($user['designation']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['department']): ?>
                        <div class="info-row">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?= htmlspecialchars($user['department']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Account Information Card -->
                <div class="info-card animate-fade-in">
                    <div class="info-card-header">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Account Information</h3>
                    </div>
                    <div class="info-card-body">
                        <div class="info-row">
                            <span class="info-label">User Role</span>
                            <span class="info-value badge bg-<?php echo isset($roleColors[$user['user_type']]) ? str_replace('bg-', '', $roleColors[$user['user_type']]) : 'secondary'; ?>">
                                <?php echo $roleNames[$user['user_type']] ?? ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Status</span>
                            <span class="info-value badge <?php echo $user['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email Verification</span>
                            <span class="info-value badge <?php echo $user['email_verified'] ? 'bg-success' : 'bg-warning'; ?>">
                                <?php echo $user['email_verified'] ? 'Verified' : 'Unverified'; ?>
                            </span>
                        </div>
                        <?php if ($show_sensitive_info): ?>
                        <div class="info-row">
                            <span class="info-label">Two-Factor Auth</span>
                            <span class="info-value badge <?php echo $user['two_factor_enabled'] ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $user['two_factor_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Created</span>
                            <span class="info-value"><?= formatDate($user['user_created_at']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Updated</span>
                            <span class="info-value"><?= formatDate($user['user_updated_at']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Login</span>
                            <span class="info-value"><?= formatDate($user['last_login']) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="info-row">
                            <span class="info-label" style="color: var(--warning);">
                                <i class="fas fa-lock"></i> Sensitive info hidden
                            </span>
                            <span class="info-value" style="font-size: 0.8rem;">
                                Permission required
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Employment Information Card -->
                <?php if (in_array($user['user_type'], ['admin', 'manager', 'support_tech'])): ?>
                <div class="info-card animate-fade-in">
                    <div class="info-card-header">
                        <i class="fas fa-briefcase"></i>
                        <h3>Employment Information</h3>
                    </div>
                    <div class="info-card-body">
                        <?php if ($user['staff_id']): ?>
                        <div class="info-row">
                            <span class="info-label">Staff ID</span>
                            <span class="info-value"><?= htmlspecialchars($user['staff_id']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['employment_type']): ?>
                        <div class="info-row">
                            <span class="info-label">Employment Type</span>
                            <span class="info-value"><?= htmlspecialchars($user['employment_type']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['date_of_joining']): ?>
                        <div class="info-row">
                            <span class="info-label">Date of Joining</span>
                            <span class="info-value"><?= $user['date_of_joining'] ? date('M d, Y', strtotime($user['date_of_joining'])) : 'Not specified' ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['employment_status']): ?>
                        <div class="info-row">
                            <span class="info-label">Employment Status</span>
                            <span class="info-value"><?= htmlspecialchars($user['employment_status']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['skills']): ?>
                        <div class="info-row" style="display: block;">
                            <span class="info-label">Skills</span>
                            <span class="info-value" style="text-align: left; margin-top: 8px;"><?= nl2br(htmlspecialchars($user['skills'])) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($user['certifications']): ?>
                        <div class="info-row" style="display: block;">
                            <span class="info-label">Certifications</span>
                            <span class="info-value" style="text-align: left; margin-top: 8px;"><?= nl2br(htmlspecialchars($user['certifications'])) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Assigned Tickets -->
            <?php if (!empty($assigned_tickets) && $show_sensitive_info): ?>
            <div class="assigned-tickets animate-fade-in">
                <div class="assigned-tickets-header">
                    <h3><i class="fas fa-tasks"></i> Recent Assigned Tickets</h3>
                    <span class="badge" style="background: var(--primary-color); color: white;"><?= count($assigned_tickets) ?> tickets</span>
                </div>
                <div class="ticket-list">
                    <?php foreach ($assigned_tickets as $ticket): ?>
                    <div class="ticket-item">
                        <div class="ticket-title">
                            <a href="<?php echo route('tickets.view'); ?>?id=<?= $ticket['id'] ?>">
                                <?= htmlspecialchars($ticket['title']) ?>
                            </a>
                        </div>
                        <div class="ticket-meta">
                            <span><i class="fas fa-building"></i> <?= htmlspecialchars($ticket['company_name'] ?? 'No Client') ?></span>
                            <span><i class="fas fa-flag"></i> <?= htmlspecialchars($ticket['priority']) ?></span>
                            <span><i class="fas fa-clock"></i> <?= formatDate($ticket['created_at']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($assigned_tickets) == 5): ?>
                <div style="padding: 16px 24px; text-align: center; border-top: 1px solid var(--border-color);">
                    <a href="<?php echo route('tickets.index'); ?>?assigned_to=<?= $user_id ?>" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-list"></i> View All Tickets
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Activity Timeline -->
            <?php if (!empty($activity_logs) && $show_sensitive_info): ?>
            <div class="timeline-section animate-fade-in">
                <div class="timeline-header">
                    <h3><i class="fas fa-history"></i> Recent Activity</h3>
                </div>
                <div class="timeline-body">
                    <?php foreach ($activity_logs as $log): ?>                    <div class="timeline-item">
                        <div class="timeline-icon <?php 
                            $action = strtolower($log['action'] ?? '');
                            if (strpos($action, 'create') !== false) echo 'timeline-icon-create';
                            elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) echo 'timeline-icon-update';
                            elseif (strpos($action, 'delete') !== false) echo 'timeline-icon-delete';
                            else echo 'timeline-icon-update';
                        ?>">
                            <i class="fas fa-<?php 
                                if (strpos($action, 'create') !== false) echo 'plus';
                                elseif (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) echo 'pen';
                                elseif (strpos($action, 'delete') !== false) echo 'trash';
                                else echo 'circle';
                            ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-action">
                                <?= htmlspecialchars(strtoupper($log['action'] ?? 'UNKNOWN')) ?>
                            </div>
                            <div class="timeline-details">
                                <?= htmlspecialchars($log['entity_type'] ?? 'User') ?>
                                <?php if ($log['details']): ?>
                                - <?= htmlspecialchars($log['details']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-meta">
                                <span><i class="fas fa-clock"></i> <?= formatDate($log['created_at']) ?></span>
                                <?php if ($log['ip_address']): ?>
                                <span><i class="fas fa-globe"></i> <?= htmlspecialchars($log['ip_address']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Permission Info Box -->
            <?php if (!$show_all_info): ?>
            <div class="permission-notice animate-fade-in">
                <div class="permission-notice-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="permission-notice-content">
                    <h4>Permission Information</h4>
                    <p>Your role: <strong><?php echo ucfirst(str_replace('_', ' ', $current_user_role)); ?></strong> | 
                    Viewing: <strong><?php echo $is_self ? 'Your own profile' : ucfirst(str_replace('_', ' ', $user['user_type'])) . ' profile'; ?></strong> | 
                    Access Level: <span class="badge <?php echo $show_sensitive_info ? 'bg-success' : 'bg-warning'; ?>">
                        <?php echo $show_sensitive_info ? 'Full Access' : 'Limited Access'; ?>
                    </span></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="quick-actions animate-fade-in">
                <div class="quick-actions-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="quick-actions-body">
                    <a href="mailto:<?= htmlspecialchars($user['email']) ?>" class="quick-action-btn">
                        <i class="fas fa-envelope"></i>
                        <span>Send Email</span>
                    </a>
                    <?php if ($user['staff_phone_number']): ?>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $user['staff_phone_number'])) ?>" class="quick-action-btn">
                        <i class="fas fa-phone"></i>
                        <span>Call User</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                    <a href="<?php echo route('users.edit', ['id' => $user_id]); ?>" class="quick-action-btn">
                        <i class="fas fa-edit"></i>
                        <span>Edit Profile</span>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo route('users.index'); ?>" class="quick-action-btn">
                        <i class="fas fa-users"></i>
                        <span>Back to Users</span>
                    </a>
                </div>
            </div>
        </main>
</div>

<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" onclick="toggleMobileSidebar()">
    <i class="fas fa-bars"></i>
</button>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle Function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.querySelector('.sidebar-toggle i');
            
            sidebar.classList.toggle('collapsed');
            
            // Toggle icon
            if (sidebar.classList.contains('collapsed')) {
                toggleBtn.classList.remove('fa-chevron-left');
                toggleBtn.classList.add('fa-chevron-right');
            } else {
                toggleBtn.classList.remove('fa-chevron-right');
                toggleBtn.classList.add('fa-chevron-left');
            }
            
            // Save state
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
        
        // Mobile Sidebar Toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }
        
        // Load saved sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const savedState = localStorage.getItem('sidebarCollapsed');
            
            if (savedState === 'true') {
                sidebar.classList.add('collapsed');
                const toggleBtn = document.querySelector('.sidebar-toggle i');
                toggleBtn.classList.remove('fa-chevron-left');
                toggleBtn.classList.add('fa-chevron-right');
            }
        });
        
        // Confirm Delete Function
        function confirmDelete() {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                alert('Delete functionality - redirect to delete handler');
                // In production, this would redirect to the delete endpoint
            }
        }
        
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() {
                    alert.remove();
                }, 300);
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
        
        // Close mobile sidebar when clicking outside
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            
            if (window.innerWidth <= 992 && 
                sidebar.classList.contains('mobile-open') &&
                !sidebar.contains(e.target) &&
                !mobileToggle.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
    </script>
</body>
</html>