<?php
// pages/clients/view.php
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

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Client ID is required.";
    header("Location: " . route('clients.index'));
    exit();
}

$client_id = $_GET['id'];
$pdo = getDBConnection();
$errors = [];
$client = null;
$locations = [];
$contracts = [];

try {
    // Fetch client details
    $stmt = $pdo->prepare("
        SELECT * FROM clients 
        WHERE id = ?
    ");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        $_SESSION['error'] = "Client not found.";
        header("Location: " . route('clients.index'));
        exit();
    }
    
    // Fetch client locations
    $stmt = $pdo->prepare("
        SELECT * FROM client_locations 
        WHERE client_id = ? 
        ORDER BY is_primary DESC, created_at DESC
    ");
    $stmt->execute([$client_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch contracts
    $stmt = $pdo->prepare("
        SELECT * FROM contracts 
        WHERE client_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$client_id]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// Check permissions
if (!hasClientPermission('view')) {
    $_SESSION['error'] = "You don't have permission to view this client.";
    header("Location: " . route('clients.index'));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client['company_name'] ?? 'Client') ?> - MSP Application</title>
    
    <!-- Main CSS -->
    <link rel="stylesheet" href="/mit/css/style.css">
    
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
            --success-color: #27ae60;
            --warning-color: #f39c12;
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
            color: var(--secondary-color);
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title i {
            color: var(--primary-color);
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
            transition: transform 0.3s;
        }
        
        .card:hover {
            transform: translateY(-2px);
        }
        
        .card-header {
            background-color: var(--light-bg);
            color: var(--secondary-color);
            padding: 18px 25px;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header h5 {
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .card-header h5 i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .info-card h6 {
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .info-card h6 i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .info-item {
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
        }
        
        .info-label {
            font-weight: 500;
            color: var(--secondary-color);
            min-width: 140px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: var(--dark-text);
            flex: 1;
        }
        
        .info-value a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .info-value a:hover {
            text-decoration: underline;
        }
        
        /* Status Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .badge-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .badge-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .badge-warning {
            background-color: var(--warning-color);
            color: white;
        }
        
        .badge-secondary {
            background-color: var(--light-text);
            color: white;
        }
        
        /* Buttons */
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        .btn-sm {
            padding: 6px 15px;
            font-size: 13px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
            color: white;
        }
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
            transform: translateY(-2px);
            color: white;
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
            transform: translateY(-2px);
            color: white;
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
        
        .btn-whatsapp {
            background-color: #25D366;
            color: white;
        }
        
        .btn-whatsapp:hover {
            background-color: #1da851;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Alerts */
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
            border-left-color: var(--accent-color);
        }
        
        .alert-success {
            background-color: #d4efdf;
            color: #27ae60;
            border-left-color: var(--success-color);
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--light-text);
            font-weight: 500;
            padding: 12px 24px;
            border-radius: 6px 6px 0 0;
            margin-right: 5px;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background-color: white;
            border-bottom: 3px solid var(--primary-color);
        }
        
        /* Table */
        .table {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .table thead {
            background-color: var(--light-bg);
        }
        
        .table th {
            border-bottom: 2px solid var(--border-color);
            font-weight: 600;
            color: var(--secondary-color);
            padding: 15px;
        }
        
        .table td {
            padding: 15px;
            vertical-align: middle;
            border-top: 1px solid var(--border-color);
        }
        
        .table tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
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
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--light-text);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            .info-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .info-label {
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
            <h4><i class="fas fa-tools"></i> MSP Portal</h4>
            <div class="d-flex align-items-center">
                <span class="text-white me-3 d-none d-md-inline">
                    <i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['email'] ?? 'User') ?>
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
                    <li class="breadcrumb-item"><a href="<?= route('dashboard') ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= route('clients.index') ?>">Clients</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($client['company_name']) ?></li>
                </ol>
            </nav>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-building"></i> <?= htmlspecialchars($client['company_name']) ?>
                        <?php if ($client['industry']): ?>
                        <span class="badge badge-primary" style="font-size: 14px; margin-left: 10px;">
                            <?= htmlspecialchars($client['industry']) ?>
                        </span>
                        <?php endif; ?>
                    </h1>
                    <p class="text-muted">Client ID: <?= htmlspecialchars($client_id) ?></p>
                </div>
                <div class="action-buttons">
                    <a href="<?= route('clients.index') ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Clients
                    </a>
                    <a href="<?= route('clients.edit') . '?id=' . $client_id ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Client
                    </a>
                    <?php if ($client['phone']): 
                        $phone_number = $client['phone'];
                        $whatsapp_number = str_replace('+', '', $phone_number);
                        $message = urlencode("Hello " . $client['company_name'] . ",\n\nThis is a message from MSP Portal.");
                    ?>
                    <a href="https://wa.me/<?= $whatsapp_number ?>?text=<?= $message ?>" 
                       target="_blank" 
                       class="btn btn-whatsapp">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Error:</h5>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); endif; ?>
            
            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs" id="clientTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                        <i class="fas fa-info-circle me-2"></i>Overview
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="locations-tab" data-bs-toggle="tab" data-bs-target="#locations" type="button" role="tab">
                        <i class="fas fa-map-marker-alt me-2"></i>Locations
                        <span class="badge bg-secondary ms-2"><?= count($locations) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="contracts-tab" data-bs-toggle="tab" data-bs-target="#contracts" type="button" role="tab">
                        <i class="fas fa-file-contract me-2"></i>Contracts
                        <span class="badge bg-secondary ms-2"><?= count($contracts) ?></span>
                    </button>
                </li>
            </ul>
            
            <!-- Tabs Content -->
            <div class="tab-content" id="clientTabsContent">
                
                <!-- Overview Tab -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-card">
                                <h6><i class="fas fa-info-circle"></i> Company Information</h6>
                                <div class="info-item">
                                    <span class="info-label">Company Name:</span>
                                    <span class="info-value"><?= htmlspecialchars($client['company_name']) ?></span>
                                </div>
                                <?php if ($client['industry']): ?>
                                <div class="info-item">
                                    <span class="info-label">Industry:</span>
                                    <span class="info-value"><?= htmlspecialchars($client['industry']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($client['website']): ?>
                                <div class="info-item">
                                    <span class="info-label">Website:</span>
                                    <span class="info-value">
                                        <a href="<?= htmlspecialchars($client['website']) ?>" target="_blank">
                                            <?= htmlspecialchars($client['website']) ?>
                                        </a>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <span class="info-label">Created:</span>
                                    <span class="info-value">
                                        <?= date('F j, Y, g:i a', strtotime($client['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Updated:</span>
                                    <span class="info-value">
                                        <?= date('F j, Y, g:i a', strtotime($client['updated_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-card">
                                <h6><i class="fas fa-user"></i> Contact Information</h6>
                                <div class="info-item">
                                    <span class="info-label">Contact Person:</span>
                                    <span class="info-value"><?= htmlspecialchars($client['contact_person']) ?></span>
                                </div>
                                <?php if ($client['email']): ?>
                                <div class="info-item">
                                    <span class="info-label">Email:</span>
                                    <span class="info-value">
                                        <a href="mailto:<?= htmlspecialchars($client['email']) ?>">
                                            <?= htmlspecialchars($client['email']) ?>
                                        </a>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($client['phone']): ?>
                                <div class="info-item">
                                    <span class="info-label">Phone:</span>
                                    <span class="info-value"><?= htmlspecialchars($client['phone']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="info-card">
                                <h6><i class="fas fa-map-marker-alt"></i> Address Information</h6>
                                <div class="info-item">
                                    <span class="info-label">Address:</span>
                                    <span class="info-value"><?= htmlspecialchars($client['address'] ?: 'Not specified') ?></span>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <span class="info-label">City:</span>
                                            <span class="info-value"><?= htmlspecialchars($client['city'] ?: 'Not specified') ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <span class="info-label">State/Area:</span>
                                            <span class="info-value"><?= htmlspecialchars($client['state'] ?: 'Not specified') ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="info-item">
                                            <span class="info-label">Country:</span>
                                            <span class="info-value"><?= htmlspecialchars($client['country']) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Locations Tab -->
                <div class="tab-pane fade" id="locations" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-map-marker-alt"></i> Client Locations</h5>
                            <a href="<?= route('clients.add_location') . '?client_id=' . $client_id ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add Location
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($locations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-map-marker-alt"></i>
                                <h5>No Locations Found</h5>
                                <p>This client doesn't have any locations yet.</p>
                                <a href="<?= route('clients.add_location') . '?client_id=' . $client_id ?>" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Add First Location
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Location Name</th>
                                            <th>Type</th>
                                            <th>Address</th>
                                            <th>Contact</th>
                                            <th>Phone</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($locations as $location): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($location['location_name']) ?></strong>
                                                <?php if ($location['is_primary']): ?>
                                                <span class="badge badge-success ms-2">Primary</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($location['is_primary']): ?>
                                                <span class="badge badge-success">Primary</span>
                                                <?php else: ?>
                                                <span class="badge badge-secondary">Secondary</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($location['address']) ?><br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($location['city']) ?>, 
                                                    <?= htmlspecialchars($location['state']) ?>
                                                </small>
                                            </td>
                                            <td><?= htmlspecialchars($location['primary_contact']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($location['phone']) ?>
                                                <?php if ($location['phone']): ?>
                                                <br>
                                                <a href="https://wa.me/<?= str_replace('+', '', $location['phone']) ?>" 
                                                   target="_blank" 
                                                   class="btn btn-whatsapp btn-sm mt-1">
                                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="edit_location.php?id=<?= $location['id'] ?>&client_id=<?= $client_id ?>" 
                                                       class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if (!$location['is_primary']): ?>
                                                    <a href="delete_location.php?id=<?= $location['id'] ?>&client_id=<?= $client_id ?>" 
                                                       class="btn btn-danger btn-sm"
                                                       onclick="return confirm('Are you sure you want to delete this location?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Contracts Tab -->
                <div class="tab-pane fade" id="contracts" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-file-contract"></i> Client Contracts</h5>
                            <a href="<?= route('clients.add_contract') . '?client_id=' . $client_id ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add Contract
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($contracts)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-contract"></i>
                                <h5>No Contracts Found</h5>
                                <p>This client doesn't have any contracts yet.</p>
                                <a href="<?= route('clients.add_contract') . '?client_id=' . $client_id ?>" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Add First Contract
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Contract Number</th>
                                            <th>Type</th>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($contracts as $contract): 
                                            $start_date = new DateTime($contract['start_date']);
                                            $end_date = new DateTime($contract['end_date']);
                                            $now = new DateTime();
                                            $is_active = $contract['status'] === 'Active';
                                            $is_expired = $end_date < $now;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($contract['contract_number']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($contract['contract_type']) ?></td>
                                            <td><?= date('M j, Y', strtotime($contract['start_date'])) ?></td>
                                            <td>
                                                <?= date('M j, Y', strtotime($contract['end_date'])) ?>
                                                <?php if ($is_expired && $is_active): ?>
                                                <span class="badge badge-warning ms-2">Expired</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($contract['status'] === 'Active'): ?>
                                                <span class="badge badge-success">Active</span>
                                                <?php elseif ($contract['status'] === 'Expired'): ?>
                                                <span class="badge badge-warning">Expired</span>
                                                <?php else: ?>
                                                <span class="badge badge-secondary"><?= htmlspecialchars($contract['status']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="<?= route('contracts.view') . '?id=' . $contract['id'] ?>" 
                                                       class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="<?= route('contracts.edit') . '?id=' . $contract['id'] ?>" 
                                                       class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($is_expired && $is_active): ?>
                                                    <a href="<?= route('contracts.edit') . '?id=' . $contract['id'] ?>" 
                                                       class="btn btn-success btn-sm">
                                                        <i class="fas fa-redo"></i> Renew
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Card -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="<?= route('clients.add_ticket') . '?client_id=' . $client_id ?>" class="btn btn-primary w-100">
                                <i class="fas fa-ticket-alt"></i> Create Ticket
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="<?= route('clients.add_asset') . '?client_id=' . $client_id ?>" class="btn btn-success w-100">
                                <i class="fas fa-server"></i> Add Asset
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="<?= route('clients.add_contract') . '?client_id=' . $client_id ?>" class="btn btn-warning w-100">
                                <i class="fas fa-file-contract"></i> New Contract
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="#" class="btn btn-info w-100 disabled" onclick="alert('Email functionality not implemented yet'); return false;">
                                <i class="fas fa-envelope"></i> Send Email
                            </a>
                        </div>
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
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Tab persistence with URL hash
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (hash) {
                const tab = document.querySelector(`a[href="${hash}"]`);
                if (tab) {
                    const tabInstance = new bootstrap.Tab(tab);
                    tabInstance.show();
                }
            }
            
            // Update URL hash when tab changes
            const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
            tabEls.forEach(tabEl => {
                tabEl.addEventListener('shown.bs.tab', function(event) {
                    const hash = event.target.getAttribute('data-bs-target');
                    window.location.hash = hash;
                });
            });
        });
        
        // WhatsApp message template
        function sendWhatsApp() {
            const phone = '<?= $client["phone"] ?? "" ?>';
            if (!phone) {
                alert('No phone number available for this client.');
                return;
            }
            
            const companyName = '<?= addslashes($client["company_name"]) ?>';
            const message = `Hello ${companyName},

This is a message from MSP Portal.

How can we assist you today?`;
            const encodedMessage = encodeURIComponent(message);
            const whatsappNumber = phone.replace('+', '');
            
            window.open(`https://wa.me/${whatsappNumber}?text=${encodedMessage}`, '_blank');
        }
        
        // Copy client ID to clipboard
        function copyClientId() {
            const clientId = '<?= $client_id ?>';
            navigator.clipboard.writeText(clientId).then(() => {
                alert('Client ID copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }
        
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
    </script>
</body>
</html>