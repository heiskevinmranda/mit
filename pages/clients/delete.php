<?php
// pages/clients/delete.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/includes/client_functions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

// Check if user is super admin
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['user_type'] ?? null;

// Only super admins can access this page
if ($user_type !== 'super_admin') {
    $_SESSION['error'] = "You don't have permission to delete clients. Only super administrators can perform this action.";
    header("Location: " . route('clients.index'));
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Client ID is required.";
    header("Location: " . route('clients.index'));
    exit();
}

$client_id = $_GET['id'];
$pdo = getDBConnection();
$client = null;
$locations = [];
$contracts = [];
$tickets = [];
$assets = [];

// Fetch client details for confirmation
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        $_SESSION['error'] = "Client not found.";
        header("Location: " . route('clients.index'));
        exit();
    }
    
    // Check for related records
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM client_locations WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $locations_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contracts WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $contracts_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $tickets_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM assets WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $assets_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: " . route('clients.view') . "?id=$client_id");
    exit();
}

// Handle form submission for deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Additional security check - verify user is still super admin
    if ($_SESSION['user_type'] !== 'super_admin') {
        $_SESSION['error'] = "Permission denied. Your session may have changed.";
        header("Location: " . route('clients.index'));
        exit();
    }
    
    $confirmation = $_POST['confirmation'] ?? '';
    $delete_related = isset($_POST['delete_related']) ? true : false;
    $archive_instead = isset($_POST['archive_instead']) ? true : false;
    
    if ($confirmation !== 'DELETE') {
        $_SESSION['error'] = "Please type 'DELETE' in the confirmation box.";
        header("Location: delete.php?id=$client_id");
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        if ($archive_instead) {
            // Archive the client instead of deleting
            $stmt = $pdo->prepare("
                UPDATE clients SET 
                    status = 'Archived',
                    archived_by = ?,
                    archived_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $client_id]);
            
            $_SESSION['success'] = "Client '{$client['company_name']}' has been archived successfully.";
        } else {
            // Delete related records if requested
            if ($delete_related) {
                // Delete tickets
                $stmt = $pdo->prepare("DELETE FROM tickets WHERE client_id = ?");
                $stmt->execute([$client_id]);
                
                // Delete assets
                $stmt = $pdo->prepare("DELETE FROM assets WHERE client_id = ?");
                $stmt->execute([$client_id]);
                
                // Delete contracts
                $stmt = $pdo->prepare("DELETE FROM contracts WHERE client_id = ?");
                $stmt->execute([$client_id]);
                
                // Delete locations
                $stmt = $pdo->prepare("DELETE FROM client_locations WHERE client_id = ?");
                $stmt->execute([$client_id]);
            } else {
                // Check if there are any related records that would prevent deletion
                $total_related = $locations_count + $contracts_count + $tickets_count + $assets_count;
                if ($total_related > 0) {
                    $_SESSION['error'] = "Cannot delete client. There are $total_related related records. Please delete related records first or select 'Delete all related records' option.";
                    header("Location: delete.php?id=$client_id");
                    exit();
                }
            }
            
            // Delete the client
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$client_id]);
            
            // Log the deletion in audit_logs table if exists
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                    VALUES (?, 'delete', 'client', ?, ?, ?, NOW())
                ");
                $user_email = $_SESSION['email'] ?? 'unknown';
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $details = "Client '{$client['company_name']}' (ID: $client_id) deleted by super admin: $user_email";
                $stmt->execute([$user_id, $client_id, $details, $ip_address]);
            } catch (Exception $e) {
                // Silently fail if audit_logs table doesn't exist
            }
            
            $_SESSION['success'] = "Client '{$client['company_name']}' has been permanently deleted.";
        }
        
        $pdo->commit();
        header("Location: " . route('clients.index'));
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: delete.php?id=$client_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Client - <?= htmlspecialchars($client['company_name']) ?> - MSP Application</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom Styles -->
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --success-color: #27ae60;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --light-text: #7f8c8d;
            --border-color: #e0e6ed;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-text);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Super Admin Badge */
        .super-admin-badge {
            background: linear-gradient(45deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            margin-left: 10px;
        }
        
        .super-admin-badge i {
            margin-right: 5px;
        }
        
        /* Header/Navbar */
        .navbar {
            background-color: var(--secondary-color);
            color: white;
            padding: 0.75rem 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        
        .navbar h4 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .navbar h4 i {
            margin-right: 10px;
        }
        
        /* Main Layout */
        .main-wrapper {
            display: flex;
            min-height: calc(100vh - 56px);
        }
        
        /* Sidebar */
        .sidebar {
            width: 250px;
            background-color: var(--secondary-color);
            color: white;
            transition: all 0.3s;
            overflow-y: auto;
            height: calc(100vh - 56px);
            position: sticky;
            top: 56px;
        }
        
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: -250px;
                z-index: 1040;
                box-shadow: 3px 0 10px rgba(0, 0, 0, 0.1);
            }
            .sidebar.active {
                left: 0;
            }
        }
        
        .sidebar-content {
            padding: 20px 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
        }
        
        .sidebar-menu li {
            padding: 0;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--primary-color);
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--accent-color);
        }
        
        .sidebar-menu a i {
            width: 24px;
            margin-right: 12px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px;
            background-color: #f5f7fa;
            overflow-y: auto;
            width: 100%;
        }
        
        @media (max-width: 992px) {
            .main-content {
                padding: 15px;
            }
        }
        
        /* Page Header */
        .page-title {
            color: var(--danger-color);
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--danger-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title i {
            color: var(--danger-color);
        }
        
        /* Super Admin Warning */
        .super-admin-warning {
            background: linear-gradient(45deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffd43b;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .super-admin-warning i {
            color: #e67e22;
            font-size: 24px;
            margin-right: 15px;
        }
        
        .super-admin-warning-content {
            flex: 1;
        }
        
        .super-admin-warning h6 {
            color: #e67e22;
            margin: 0 0 5px 0;
            font-weight: 600;
        }
        
        .super-admin-warning p {
            color: #b9770e;
            margin: 0;
            font-size: 14px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
        }
        
        .card.danger {
            border-left: 4px solid var(--danger-color);
        }
        
        .card.warning {
            border-left: 4px solid var(--warning-color);
        }
        
        .card-header {
            background-color: var(--light-bg);
            color: var(--secondary-color);
            padding: 18px 25px;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
            border-bottom: 1px solid var(--border-color);
        }
        
        .card-header.danger {
            background-color: #fde8e8;
            color: var(--danger-color);
            border-bottom: 1px solid #fad2d2;
        }
        
        .card-header h5 {
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .card-header h5 i {
            margin-right: 10px;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Alert Boxes */
        .alert {
            padding: 18px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: none;
            border-left: 4px solid;
        }
        
        .alert-danger {
            background-color: #fde8e8;
            color: #c0392b;
            border-left-color: var(--danger-color);
        }
        
        .alert-warning {
            background-color: #fef5e7;
            color: #b9770e;
            border-left-color: var(--warning-color);
        }
        
        .alert-success {
            background-color: #d4efdf;
            color: #27ae60;
            border-left-color: var(--success-color);
        }
        
        .alert-info {
            background-color: #e8f4fc;
            color: #2980b9;
            border-left-color: var(--primary-color);
        }
        
        /* Buttons */
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            background-color: transparent;
            border: 2px solid #95a5a6;
            color: #7f8c8d;
        }
        
        .btn-outline-secondary:hover {
            background-color: #95a5a6;
            color: white;
            border-color: #95a5a6;
        }
        
        /* Form Elements */
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
            background-color: white;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .form-check {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .form-check-input {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        
        .form-check-label {
            cursor: pointer;
            user-select: none;
        }
        
        /* Client Info Box */
        .client-info-box {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
        }
        
        .client-info-item {
            margin-bottom: 10px;
            display: flex;
        }
        
        .client-info-label {
            font-weight: 500;
            color: var(--secondary-color);
            min-width: 120px;
            flex-shrink: 0;
        }
        
        .client-info-value {
            color: var(--dark-text);
            flex: 1;
        }
        
        /* Related Records */
        .related-records {
            margin-top: 20px;
        }
        
        .related-record-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background-color: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid var(--warning-color);
        }
        
        .related-record-icon {
            width: 40px;
            height: 40px;
            background-color: #fef5e7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--warning-color);
        }
        
        .related-record-details {
            flex: 1;
        }
        
        .related-record-name {
            font-weight: 500;
            color: var(--secondary-color);
        }
        
        .related-record-count {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        /* Warning Box */
        .warning-box {
            background-color: #fef5e7;
            border: 2px solid #fad7a0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .warning-box h6 {
            color: #b9770e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .warning-box h6 i {
            margin-right: 10px;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            background-color: transparent;
            padding: 0;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: var(--light-text);
        }
        
        /* Mobile Menu Button */
        .mobile-menu-btn {
            position: fixed;
            bottom: 25px;
            right: 25px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            font-size: 22px;
            cursor: pointer;
            z-index: 1030;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            display: none;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .mobile-menu-btn:hover {
            background-color: #2980b9;
            transform: scale(1.05);
        }
        
        @media (max-width: 992px) {
            .mobile-menu-btn {
                display: flex;
            }
        }
        
        /* Backdrop for mobile sidebar */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1039;
        }
        
        @media (max-width: 992px) {
            .sidebar-backdrop.active {
                display: block;
            }
        }
        
        /* Responsive Grid */
        @media (max-width: 768px) {
            .row > div {
                width: 100% !important;
                margin-bottom: 15px;
            }
            .client-info-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .client-info-label {
                min-width: auto;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <h4><i class="fas fa-tools"></i> MSP Portal <span class="super-admin-badge"><i class="fas fa-user-shield"></i> SUPER ADMIN</span></h4>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-md-inline">
                    <i class="fas fa-user-shield me-1"></i> <?= htmlspecialchars($_SESSION['email'] ?? 'User') ?>
                </span>
                <a href="../../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Sidebar Backdrop (mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>
    
    <div class="main-wrapper">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Clients</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $client_id ?>"><?= htmlspecialchars($client['company_name']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Delete Client</li>
                </ol>
            </nav>
            
            <!-- Super Admin Warning -->
            <div class="super-admin-warning">
                <i class="fas fa-user-shield"></i>
                <div class="super-admin-warning-content">
                    <h6>Super Administrator Access</h6>
                    <p>You are performing a high-privilege action. All deletions are logged and cannot be undone.</p>
                </div>
            </div>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-trash-alt"></i> Delete Client: <?= htmlspecialchars($client['company_name']) ?>
                        <span class="super-admin-badge"><i class="fas fa-user-shield"></i> SUPER ADMIN ONLY</span>
                    </h1>
                    <p class="text-muted">This action cannot be undone and is restricted to super administrators only</p>
                </div>
                <a href="view.php?id=<?= $client_id ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Client
                </a>
            </div>
            
            <!-- Error Messages -->
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Error:</strong> <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); endif; ?>
            
            <!-- Success Message -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); endif; ?>
            
            <!-- Client Information -->
            <div class="card warning">
                <div class="card-header">
                    <h5><i class="fas fa-exclamation-triangle"></i> Client to be Deleted</h5>
                </div>
                <div class="card-body">
                    <div class="client-info-box">
                        <div class="client-info-item">
                            <span class="client-info-label">Company Name:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['company_name']) ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Contact Person:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['contact_person']) ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Email:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['email'] ?: 'Not specified') ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Phone:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['phone'] ?: 'Not specified') ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Industry:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['industry'] ?: 'Not specified') ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Client Since:</span>
                            <span class="client-info-value"><?= date('F j, Y', strtotime($client['created_at'])) ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Client ID:</span>
                            <span class="client-info-value"><code><?= $client_id ?></code></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Related Records Warning -->
            <?php if ($locations_count > 0 || $contracts_count > 0 || $tickets_count > 0 || $assets_count > 0): ?>
            <div class="card warning">
                <div class="card-header">
                    <h5><i class="fas fa-exclamation-circle"></i> Related Records Found</h5>
                </div>
                <div class="card-body">
                    <div class="warning-box">
                        <h6><i class="fas fa-exclamation-triangle"></i> Warning: This client has related records</h6>
                        <p>Deleting this client will also remove all related records. Consider archiving instead.</p>
                    </div>
                    
                    <div class="related-records">
                        <?php if ($locations_count > 0): ?>
                        <div class="related-record-item">
                            <div class="related-record-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="related-record-details">
                                <div class="related-record-name">Client Locations</div>
                                <div class="related-record-count"><?= $locations_count ?> location(s) will be deleted</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($contracts_count > 0): ?>
                        <div class="related-record-item">
                            <div class="related-record-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="related-record-details">
                                <div class="related-record-name">Contracts</div>
                                <div class="related-record-count"><?= $contracts_count ?> contract(s) will be deleted</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($tickets_count > 0): ?>
                        <div class="related-record-item">
                            <div class="related-record-icon">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <div class="related-record-details">
                                <div class="related-record-name">Support Tickets</div>
                                <div class="related-record-count"><?= $tickets_count ?> ticket(s) will be deleted</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($assets_count > 0): ?>
                        <div class="related-record-item">
                            <div class="related-record-icon">
                                <i class="fas fa-server"></i>
                            </div>
                            <div class="related-record-details">
                                <div class="related-record-name">Assets</div>
                                <div class="related-record-count"><?= $assets_count ?> asset(s) will be deleted</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Deletion Options -->
            <div class="card danger">
                <div class="card-header danger">
                    <h5><i class="fas fa-skull-crossbones"></i> Super Admin Deletion Options</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="deleteForm">
                        <!-- Archive Option -->
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-archive me-2"></i>Recommended: Archive Instead</h6>
                            <p>Archiving keeps the client record but marks it as inactive. This preserves history while removing the client from active lists.</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="archive_instead" name="archive_instead" value="1">
                                <label class="form-check-label" for="archive_instead">
                                    <strong>Archive this client instead of deleting</strong>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Permanent Deletion Option -->
                        <div class="alert alert-danger mb-4" id="permanentDeleteSection">
                            <h6><i class="fas fa-user-shield me-2"></i>Super Admin: Permanent Deletion</h6>
                            <p><strong>This action is logged and audited.</strong> This will permanently remove the client and all related data from the system.</p>
                            
                            <?php if ($locations_count > 0 || $contracts_count > 0 || $tickets_count > 0 || $assets_count > 0): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="delete_related" name="delete_related" value="1">
                                <label class="form-check-label" for="delete_related">
                                    <strong>Delete all <?= $locations_count + $contracts_count + $tickets_count + $assets_count ?> related records</strong>
                                    <br>
                                    <small>This includes locations, contracts, tickets, and assets associated with this client.</small>
                                </label>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="confirmation" class="form-label">
                                    <i class="fas fa-user-shield me-1"></i> Super Admin Confirmation:
                                    <br>
                                    Type <strong>DELETE</strong> to confirm permanent deletion:
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="confirmation" 
                                       name="confirmation" 
                                       placeholder="Type DELETE here"
                                       style="border-color: var(--danger-color);">
                                <small class="text-muted">Required for audit trail. Your action will be logged.</small>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <a href="view.php?id=<?= $client_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <div class="action-buttons">
                                <button type="submit" class="btn btn-danger" id="submitBtn">
                                    <i class="fas fa-trash-alt"></i> Proceed with Deletion
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Safety Information -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-shield-alt"></i> Super Admin Safety Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-check-circle text-success me-2"></i>What happens when you archive:</h6>
                            <ul>
                                <li>Client is marked as "Archived"</li>
                                <li>All related records are preserved</li>
                                <li>Client disappears from active lists</li>
                                <li>Can be restored if needed</li>
                                <li>Historical data remains intact</li>
                                <li><strong>Action is logged with your admin ID</strong></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-exclamation-triangle text-danger me-2"></i>What happens when you delete:</h6>
                            <ul>
                                <li>Client is permanently removed</li>
                                <li>All related data may be deleted</li>
                                <li>Action cannot be undone</li>
                                <li>No recovery possible</li>
                                <li>Affects reporting and analytics</li>
                                <li><strong>Audit log entry is created</strong></li>
                            </ul>
                        </div>
                    </div>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-user-shield me-2"></i>
                        <strong>Super Admin Responsibility:</strong> As a super administrator, your actions are not reversible and are permanently logged. Please exercise extreme caution when deleting client data.
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                backdrop.classList.remove('active');
            } else {
                sidebar.classList.add('active');
                backdrop.classList.add('active');
            }
        }
        
        // Close sidebar when clicking on a link (mobile)
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    toggleSidebar();
                }
            });
        });
        
        // Toggle permanent deletion section based on archive checkbox
        document.getElementById('archive_instead').addEventListener('change', function() {
            const permanentDeleteSection = document.getElementById('permanentDeleteSection');
            const submitBtn = document.getElementById('submitBtn');
            const confirmationInput = document.getElementById('confirmation');
            
            if (this.checked) {
                permanentDeleteSection.style.opacity = '0.5';
                permanentDeleteSection.style.pointerEvents = 'none';
                submitBtn.innerHTML = '<i class="fas fa-archive"></i> Archive Client';
                submitBtn.className = 'btn btn-warning';
                confirmationInput.required = false;
            } else {
                permanentDeleteSection.style.opacity = '1';
                permanentDeleteSection.style.pointerEvents = 'auto';
                submitBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Proceed with Deletion';
                submitBtn.className = 'btn btn-danger';
                confirmationInput.required = true;
            }
        });
        
        // Form validation
        document.getElementById('deleteForm').addEventListener('submit', function(event) {
            const archiveCheckbox = document.getElementById('archive_instead');
            const confirmationInput = document.getElementById('confirmation');
            
            if (!archiveCheckbox.checked) {
                // For permanent deletion, require "DELETE" confirmation
                if (confirmationInput.value !== 'DELETE') {
                    event.preventDefault();
                    alert('SUPER ADMIN REQUIRED: Please type "DELETE" in the confirmation box to proceed with permanent deletion.');
                    confirmationInput.focus();
                    confirmationInput.style.borderColor = '#e74c3c';
                    confirmationInput.style.boxShadow = '0 0 0 3px rgba(231, 76, 60, 0.2)';
                    return false;
                }
                
                // Additional warning for permanent deletion
                const deleteRelated = document.getElementById('delete_related');
                const hasRelatedRecords = <?= ($locations_count + $contracts_count + $tickets_count + $assets_count) > 0 ? 'true' : 'false' ?>;
                
                if (hasRelatedRecords && !deleteRelated.checked) {
                    const confirmMessage = "SUPER ADMIN WARNING:\n\nThis client has related records. If you don't select 'Delete all related records', the deletion will fail.\n\nContinue anyway?";
                    if (!confirm(confirmMessage)) {
                        event.preventDefault();
                        return false;
                    }
                }
                
                // Final warning for permanent deletion
                const finalWarning = "⚠️ SUPER ADMIN FINAL WARNING ⚠️\n\n" +
                    "You are about to PERMANENTLY DELETE a client.\n\n" +
                    "✅ This action is logged with your admin credentials\n" +
                    "✅ This action CANNOT BE UNDONE\n" +
                    "✅ All related data may be lost\n\n" +
                    "Type OK to confirm you understand the consequences.";
                
                if (!confirm(finalWarning)) {
                    event.preventDefault();
                    return false;
                }
            } else {
                // For archiving, show confirmation
                const archiveConfirm = "Super Admin: Archive Client\n\n" +
                    "This will archive the client. The client will be marked as inactive but can be restored later.\n\n" +
                    "This action will be logged with your admin credentials.\n\n" +
                    "Continue?";
                if (!confirm(archiveConfirm)) {
                    event.preventDefault();
                    return false;
                }
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Close sidebar when clicking outside (mobile)
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            const mobileBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth < 992 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target)) {
                toggleSidebar();
            }
        });
        
        // Initialize the page state
        document.addEventListener('DOMContentLoaded', function() {
            // Trigger the change event to set initial state
            document.getElementById('archive_instead').dispatchEvent(new Event('change'));
            
            // Add super admin visual indicator to page
            document.title = "🔐 " + document.title;
        });
    </script>
</body>
</html>