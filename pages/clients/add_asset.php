<?php
// pages/clients/add_asset.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/client_functions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

// Get client ID from query parameter
if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    $_SESSION['error'] = "Client ID is required.";
    header("Location: index.php");
    exit();
}

$client_id = $_GET['client_id'];
$pdo = getDBConnection();
$errors = [];
$success = false;
$client = null;

// Fetch client details
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        $_SESSION['error'] = "Client not found.";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// Check permissions
if (!hasClientPermission('edit', $client_id)) {
    $_SESSION['error'] = "You don't have permission to add assets for this client.";
    header("Location: view.php?id=" . $client_id);
    exit();
}

// Fetch client locations for dropdown
$locations = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, location_name, is_primary 
        FROM client_locations 
        WHERE client_id = ? 
        ORDER BY is_primary DESC, location_name
    ");
    $stmt->execute([$client_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error fetching locations: " . $e->getMessage();
}

// Asset types
$asset_types = [
    'Server' => [
        'label' => 'Server',
        'icon' => 'fa-server',
        'description' => 'Physical or virtual servers'
    ],
    'Workstation' => [
        'label' => 'Workstation',
        'icon' => 'fa-desktop',
        'description' => 'Desktop computers'
    ],
    'Laptop' => [
        'label' => 'Laptop',
        'icon' => 'fa-laptop',
        'description' => 'Portable computers'
    ],
    'Network Device' => [
        'label' => 'Network Device',
        'icon' => 'fa-network-wired',
        'description' => 'Switches, routers, firewalls'
    ],
    'Printer' => [
        'label' => 'Printer',
        'icon' => 'fa-print',
        'description' => 'Printers and scanners'
    ],
    'Mobile Device' => [
        'label' => 'Mobile Device',
        'icon' => 'fa-mobile-alt',
        'description' => 'Smartphones, tablets'
    ],
    'Monitor' => [
        'label' => 'Monitor',
        'icon' => 'fa-tv',
        'description' => 'Computer monitors'
    ],
    'Storage' => [
        'label' => 'Storage',
        'icon' => 'fa-hdd',
        'description' => 'NAS, SAN, external drives'
    ],
    'UPS' => [
        'label' => 'UPS',
        'icon' => 'fa-bolt',
        'description' => 'Uninterruptible power supply'
    ],
    'Other' => [
        'label' => 'Other',
        'icon' => 'fa-cube',
        'description' => 'Other equipment'
    ]
];

// Status options
$status_options = [
    'Active' => [
        'label' => 'Active',
        'color' => '#27ae60',
        'description' => 'Asset is in use'
    ],
    'Inactive' => [
        'label' => 'Inactive',
        'color' => '#95a5a6',
        'description' => 'Asset is not in use'
    ],
    'Maintenance' => [
        'label' => 'Maintenance',
        'color' => '#f39c12',
        'description' => 'Asset is under maintenance'
    ],
    'Retired' => [
        'label' => 'Retired',
        'color' => '#7f8c8d',
        'description' => 'Asset is retired/disposed'
    ],
    'Lost/Stolen' => [
        'label' => 'Lost/Stolen',
        'color' => '#e74c3c',
        'description' => 'Asset is lost or stolen'
    ]
];

// Initialize form data
$form_data = [
    'asset_name' => '',
    'asset_type' => 'Workstation',
    'serial_number' => '',
    'model' => '',
    'manufacturer' => '',
    'purchase_date' => '',
    'warranty_expiry' => '',
    'location_id' => '',
    'status' => 'Active',
    'notes' => '',
    'ip_address' => '',
    'mac_address' => '',
    'asset_tag' => '',
    'value' => '',
    'assigned_to' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $form_data = [
        'asset_name' => trim($_POST['asset_name'] ?? ''),
        'asset_type' => trim($_POST['asset_type'] ?? 'Workstation'),
        'serial_number' => trim($_POST['serial_number'] ?? ''),
        'model' => trim($_POST['model'] ?? ''),
        'manufacturer' => trim($_POST['manufacturer'] ?? ''),
        'purchase_date' => $_POST['purchase_date'] ?? null,
        'warranty_expiry' => $_POST['warranty_expiry'] ?? null,
        'location_id' => $_POST['location_id'] ?? null,
        'status' => $_POST['status'] ?? 'Active',
        'notes' => trim($_POST['notes'] ?? ''),
        'ip_address' => trim($_POST['ip_address'] ?? ''),
        'mac_address' => trim($_POST['mac_address'] ?? ''),
        'asset_tag' => trim($_POST['asset_tag'] ?? ''),
        'value' => $_POST['value'] ?? null,
        'assigned_to' => trim($_POST['assigned_to'] ?? '')
    ];
    
    // Validation
    if (empty($form_data['asset_name'])) {
        $errors[] = "Asset name is required.";
    }
    
    if (empty($form_data['asset_type'])) {
        $errors[] = "Asset type is required.";
    }
    
    if ($form_data['purchase_date'] && !strtotime($form_data['purchase_date'])) {
        $errors[] = "Invalid purchase date format.";
    }
    
    if ($form_data['warranty_expiry'] && !strtotime($form_data['warranty_expiry'])) {
        $errors[] = "Invalid warranty expiry date format.";
    }
    
    if ($form_data['value'] !== null && !is_numeric($form_data['value'])) {
        $errors[] = "Value must be a number.";
    }
    
    // Validate IP address format if provided
    if ($form_data['ip_address'] && !filter_var($form_data['ip_address'], FILTER_VALIDATE_IP)) {
        $errors[] = "Invalid IP address format.";
    }
    
    // Validate MAC address format if provided
    if ($form_data['mac_address'] && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $form_data['mac_address'])) {
        $errors[] = "Invalid MAC address format. Use format: 00:1A:2B:3C:4D:5E";
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate unique asset ID
            $asset_id = bin2hex(random_bytes(8));
            
            // Prepare SQL statement
            $stmt = $pdo->prepare("
                INSERT INTO assets (
                    id, client_id, location_id, asset_name, asset_type, 
                    serial_number, model, manufacturer, purchase_date, 
                    warranty_expiry, status, notes, ip_address, mac_address, 
                    asset_tag, value, assigned_to, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");
            
            // Execute with parameters
            $stmt->execute([
                $asset_id,
                $client_id,
                $form_data['location_id'] ?: null,
                $form_data['asset_name'],
                $form_data['asset_type'],
                $form_data['serial_number'] ?: null,
                $form_data['model'] ?: null,
                $form_data['manufacturer'] ?: null,
                $form_data['purchase_date'] ? date('Y-m-d', strtotime($form_data['purchase_date'])) : null,
                $form_data['warranty_expiry'] ? date('Y-m-d', strtotime($form_data['warranty_expiry'])) : null,
                $form_data['status'],
                $form_data['notes'] ?: null,
                $form_data['ip_address'] ?: null,
                $form_data['mac_address'] ?: null,
                $form_data['asset_tag'] ?: null,
                $form_data['value'] ? floatval($form_data['value']) : null,
                $form_data['assigned_to'] ?: null
            ]);
            
            // Add to audit log
            $user_id = $_SESSION['user_id'] ?? null;
            if ($user_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                    VALUES (?, 'CREATE', 'ASSET', ?, ?, ?, NOW())
                ");
                
                $details = json_encode([
                    'client_id' => $client_id,
                    'asset_name' => $form_data['asset_name'],
                    'asset_type' => $form_data['asset_type'],
                    'location_id' => $form_data['location_id']
                ]);
                
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $stmt->execute([$user_id, $asset_id, $details, $ip_address]);
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Asset '{$form_data['asset_name']}' has been added successfully!";
            header("Location: view.php?id=" . $client_id);
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Asset - <?= htmlspecialchars($client['company_name'] ?? 'Client') ?> - MSP Application</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Flatpickr for date picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        /* Asset Type Badges */
        .asset-type-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-right: 5px;
        }
        
        .badge-server { background-color: #3498db; color: white; }
        .badge-workstation { background-color: #2ecc71; color: white; }
        .badge-laptop { background-color: #9b59b6; color: white; }
        .badge-network { background-color: #e67e22; color: white; }
        .badge-printer { background-color: #e74c3c; color: white; }
        .badge-phone { background-color: #1abc9c; color: white; }
        .badge-other { background-color: #95a5a6; color: white; }
        
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
        }
        
        .card-header {
            background-color: var(--light-bg);
            color: var(--secondary-color);
            padding: 18px 25px;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
            border-bottom: 1px solid var(--border-color);
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
        
        /* Form Elements */
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-label.required::after {
            content: " *";
            color: var(--accent-color);
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
            background-color: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        /* Form Sections */
        .form-section {
            background-color: var(--light-bg);
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary-color);
        }
        
        .section-title {
            color: var(--secondary-color);
            font-weight: 600;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        /* Asset Type Selector */
        .asset-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .asset-type-option {
            text-align: center;
        }
        
        .asset-type-option input[type="radio"] {
            display: none;
        }
        
        .asset-type-option label {
            display: block;
            padding: 20px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            height: 100%;
        }
        
        .asset-type-option label i {
            font-size: 24px;
            margin-bottom: 10px;
            display: block;
            color: var(--primary-color);
        }
        
        .asset-type-option input[type="radio"]:checked + label {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .asset-type-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .asset-type-desc {
            font-size: 12px;
            color: var(--light-text);
        }
        
        /* Status Selector */
        .status-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .status-option {
            flex: 1;
            min-width: 120px;
        }
        
        .status-option input[type="radio"] {
            display: none;
        }
        
        .status-option label {
            display: block;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .status-option input[type="radio"]:checked + label {
            border-color: var(--primary-color);
            background-color: rgba(52, 152, 219, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
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
        
        /* Client Info Box */
        .client-info-box {
            background-color: var(--light-bg);
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
        
        /* Helper Text */
        .text-muted {
            color: var(--light-text) !important;
            font-size: 13px;
            margin-top: 5px;
            display: block;
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
        
        /* Error Messages */
        .error-message {
            color: var(--accent-color);
            font-size: 13px;
            margin-top: 5px;
            display: block;
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
            .asset-type-selector {
                grid-template-columns: repeat(2, 1fr);
            }
            .status-selector {
                flex-direction: column;
            }
            .status-option {
                min-width: 100%;
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
                    <li class="breadcrumb-item active" aria-current="page">Add Asset</li>
                </ol>
            </nav>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-plus-circle"></i> Add New Asset
                    </h1>
                    <p class="text-muted">Add a new asset for <?= htmlspecialchars($client['company_name']) ?></p>
                </div>
                <a href="view.php?id=<?= $client_id ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Client
                </a>
            </div>
            
            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following errors:</h5>
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
            
            <!-- Client Information -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-building"></i> Client Information</h5>
                </div>
                <div class="card-body">
                    <div class="client-info-box">
                        <div class="client-info-item">
                            <span class="client-info-label">Company:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['company_name']) ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Contact Person:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['contact_person']) ?></span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Email:</span>
                            <span class="client-info-value">
                                <?php if ($client['email']): ?>
                                <a href="mailto:<?= htmlspecialchars($client['email']) ?>">
                                    <?= htmlspecialchars($client['email']) ?>
                                </a>
                                <?php else: ?>
                                Not specified
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="client-info-item">
                            <span class="client-info-label">Phone:</span>
                            <span class="client-info-value"><?= htmlspecialchars($client['phone'] ?: 'Not specified') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Asset Creation Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-server"></i> Asset Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="assetForm" novalidate>
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle"></i> Basic Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="asset_name" class="form-label required">Asset Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="asset_name" 
                                           name="asset_name" 
                                           value="<?= htmlspecialchars($form_data['asset_name']) ?>" 
                                           required
                                           placeholder="e.g., John's Workstation, Main Server, Conference Room Printer">
                                    <small class="text-muted">A descriptive name for this asset</small>
                                    <div class="error-message" id="asset_name_error"></div>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="asset_tag" class="form-label">Asset Tag</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="asset_tag" 
                                           name="asset_tag" 
                                           value="<?= htmlspecialchars($form_data['asset_tag']) ?>" 
                                           placeholder="e.g., ASSET-001">
                                    <small class="text-muted">Optional - Unique asset identifier</small>
                                </div>
                            </div>
                            
                            <!-- Asset Type Selector -->
                            <div class="mb-4">
                                <label class="form-label required">Asset Type</label>
                                <div class="asset-type-selector">
                                    <?php foreach ($asset_types as $type => $details): ?>
                                    <div class="asset-type-option">
                                        <input type="radio" 
                                               id="type_<?= strtolower(str_replace(' ', '_', $type)) ?>" 
                                               name="asset_type" 
                                               value="<?= htmlspecialchars($type) ?>"
                                               <?= $form_data['asset_type'] === $type ? 'checked' : '' ?>
                                               style="display: none;">
                                        <label for="type_<?= strtolower(str_replace(' ', '_', $type)) ?>">
                                            <i class="fas <?= $details['icon'] ?>"></i>
                                            <div class="asset-type-name"><?= htmlspecialchars($details['label']) ?></div>
                                            <div class="asset-type-desc"><?= htmlspecialchars($details['description']) ?></div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Manufacturer & Model -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="manufacturer" class="form-label">Manufacturer</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="manufacturer" 
                                           name="manufacturer" 
                                           value="<?= htmlspecialchars($form_data['manufacturer']) ?>" 
                                           placeholder="e.g., Dell, HP, Cisco, Apple">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="model" class="form-label">Model</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="model" 
                                           name="model" 
                                           value="<?= htmlspecialchars($form_data['model']) ?>" 
                                           placeholder="e.g., PowerEdge R740, MacBook Pro M2, ThinkPad X1">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="serial_number" class="form-label">Serial Number</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="serial_number" 
                                           name="serial_number" 
                                           value="<?= htmlspecialchars($form_data['serial_number']) ?>" 
                                           placeholder="e.g., SN123456789">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Location & Status Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-map-marker-alt"></i> Location & Status
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="location_id" class="form-label">Location</label>
                                    <select class="form-select" id="location_id" name="location_id">
                                        <option value="">Select Location (Optional)</option>
                                        <?php foreach ($locations as $location): ?>
                                        <option value="<?= htmlspecialchars($location['id']) ?>" 
                                            <?= $form_data['location_id'] == $location['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($location['location_name']) ?>
                                            <?php if ($location['is_primary']): ?> (Primary)<?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Which client location this asset is at</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="assigned_to" class="form-label">Assigned To</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="assigned_to" 
                                           name="assigned_to" 
                                           value="<?= htmlspecialchars($form_data['assigned_to']) ?>" 
                                           placeholder="e.g., John Smith, Marketing Department">
                                    <small class="text-muted">Person or department using this asset</small>
                                </div>
                            </div>
                            
                            <!-- Status Selector -->
                            <div class="mb-3">
                                <label class="form-label required">Status</label>
                                <div class="status-selector">
                                    <?php foreach ($status_options as $status => $details): ?>
                                    <div class="status-option">
                                        <input type="radio" 
                                               id="status_<?= strtolower($status) ?>" 
                                               name="status" 
                                               value="<?= $status ?>"
                                               <?= $form_data['status'] === $status ? 'checked' : '' ?>
                                               style="display: none;">
                                        <label for="status_<?= strtolower($status) ?>" 
                                               style="border-left: 4px solid <?= $details['color'] ?>;">
                                            <strong><?= $details['label'] ?></strong>
                                            <br>
                                            <small><?= $details['description'] ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dates & Value Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-calendar-alt"></i> Dates & Financial Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="purchase_date" class="form-label">Purchase Date</label>
                                    <input type="text" 
                                           class="form-control datepicker" 
                                           id="purchase_date" 
                                           name="purchase_date" 
                                           value="<?= htmlspecialchars($form_data['purchase_date']) ?>" 
                                           placeholder="YYYY-MM-DD">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="warranty_expiry" class="form-label">Warranty Expiry</label>
                                    <input type="text" 
                                           class="form-control datepicker" 
                                           id="warranty_expiry" 
                                           name="warranty_expiry" 
                                           value="<?= htmlspecialchars($form_data['warranty_expiry']) ?>" 
                                           placeholder="YYYY-MM-DD">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="value" class="form-label">Value ($)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" 
                                               class="form-control" 
                                               id="value" 
                                               name="value" 
                                               value="<?= htmlspecialchars($form_data['value']) ?>" 
                                               placeholder="e.g., 1500.00" 
                                               step="0.01" 
                                               min="0">
                                    </div>
                                    <small class="text-muted">Optional - Purchase or replacement value</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Network Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-network-wired"></i> Network Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="ip_address" class="form-label">IP Address</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="ip_address" 
                                           name="ip_address" 
                                           value="<?= htmlspecialchars($form_data['ip_address']) ?>" 
                                           placeholder="e.g., 192.168.1.100">
                                    <small class="text-muted">For network-connected devices</small>
                                    <div class="error-message" id="ip_address_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="mac_address" class="form-label">MAC Address</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="mac_address" 
                                           name="mac_address" 
                                           value="<?= htmlspecialchars($form_data['mac_address']) ?>" 
                                           placeholder="e.g., 00:1A:2B:3C:4D:5E">
                                    <small class="text-muted">Format: 00:1A:2B:3C:4D:5E or 00-1A-2B-3C-4D-5E</small>
                                    <div class="error-message" id="mac_address_error"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Notes Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-sticky-note"></i> Additional Notes
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="notes" class="form-label">Notes (Optional)</label>
                                    <textarea class="form-control" 
                                              id="notes" 
                                              name="notes" 
                                              rows="4"
                                              placeholder="Any additional information about this asset..."><?= htmlspecialchars($form_data['notes']) ?></textarea>
                                    <small class="text-muted">Configuration details, special instructions, or other notes</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <a href="view.php?id=<?= $client_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Asset
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quick Tips Card -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Quick Tips</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Best Practices:</h6>
                            <ul>
                                <li>Include serial numbers for warranty tracking</li>
                                <li>Use consistent naming conventions</li>
                                <li>Update warranty expiry dates</li>
                                <li>Record network information for IT management</li>
                                <li>Assign assets to specific locations/users</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Common Asset Types:</h6>
                            <div class="mb-3">
                                <span class="asset-type-badge badge-server">Server</span>
                                <span class="asset-type-badge badge-workstation">Workstation</span>
                                <span class="asset-type-badge badge-laptop">Laptop</span>
                                <span class="asset-type-badge badge-network">Network</span>
                                <span class="asset-type-badge badge-printer">Printer</span>
                                <span class="asset-type-badge badge-phone">Phone</span>
                            </div>
                            <p class="mb-0"><small class="text-muted">Complete asset information helps with support and inventory management.</small></p>
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
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
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
        
        // Initialize date pickers
        flatpickr('.datepicker', {
            dateFormat: "Y-m-d",
            allowInput: true
        });
        
        // Form validation
        document.getElementById('assetForm').addEventListener('submit', function(event) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            
            // Asset name validation
            const assetName = document.getElementById('asset_name');
            if (!assetName.value.trim()) {
                document.getElementById('asset_name_error').textContent = 'Asset name is required';
                assetName.focus();
                isValid = false;
            }
            
            // IP address validation
            const ipAddress = document.getElementById('ip_address');
            if (ipAddress.value.trim()) {
                if (!isValidIP(ipAddress.value)) {
                    document.getElementById('ip_address_error').textContent = 'Invalid IP address format';
                    if (isValid) {
                        ipAddress.focus();
                        isValid = false;
                    }
                }
            }
            
            // MAC address validation
            const macAddress = document.getElementById('mac_address');
            if (macAddress.value.trim()) {
                if (!isValidMAC(macAddress.value)) {
                    document.getElementById('mac_address_error').textContent = 'Invalid MAC address format. Use: 00:1A:2B:3C:4D:5E';
                    if (isValid) {
                        macAddress.focus();
                        isValid = false;
                    }
                }
            }
            
            // Value validation
            const valueField = document.getElementById('value');
            if (valueField.value && (!isNumeric(valueField.value) || parseFloat(valueField.value) < 0)) {
                alert('Value must be a positive number.');
                valueField.focus();
                isValid = false;
            }
            
            // Date validation
            const purchaseDate = document.getElementById('purchase_date');
            if (purchaseDate.value && !isValidDate(purchaseDate.value)) {
                alert('Invalid purchase date format. Use YYYY-MM-DD');
                purchaseDate.focus();
                isValid = false;
            }
            
            const warrantyExpiry = document.getElementById('warranty_expiry');
            if (warrantyExpiry.value && !isValidDate(warrantyExpiry.value)) {
                alert('Invalid warranty expiry date format. Use YYYY-MM-DD');
                warrantyExpiry.focus();
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
        
        // Helper functions
        function isValidIP(ip) {
            const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
            return ipPattern.test(ip);
        }
        
        function isValidMAC(mac) {
            const macPattern = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/;
            return macPattern.test(mac);
        }
        
        function isNumeric(value) {
            return /^-?\d+(\.\d+)?$/.test(value);
        }
        
        function isValidDate(dateString) {
            const regex = /^\d{4}-\d{2}-\d{2}$/;
            if (!regex.test(dateString)) return false;
            const date = new Date(dateString);
            return date instanceof Date && !isNaN(date);
        }
        
        // Auto-generate asset tag based on type
        const assetTypeInputs = document.querySelectorAll('input[name="asset_type"]');
        assetTypeInputs.forEach(input => {
            input.addEventListener('change', function() {
                const assetTag = document.getElementById('asset_tag');
                const assetName = document.getElementById('asset_name');
                
                if (!assetTag.value && !assetName.value) {
                    const type = this.value.substring(0, 3).toUpperCase();
                    const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                    assetTag.value = type + '-' + randomNum;
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
        
        // Auto-focus asset name field
        document.addEventListener('DOMContentLoaded', function() {
            const assetNameField = document.getElementById('asset_name');
            if (assetNameField && !assetNameField.value) {
                assetNameField.focus();
            }
        });
        
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
        
        // Character counter for notes
        const notesField = document.getElementById('notes');
        if (notesField) {
            const charCount = document.createElement('small');
            charCount.className = 'text-muted float-end';
            charCount.id = 'charCount';
            charCount.textContent = '0/1000 characters';
            notesField.parentNode.appendChild(charCount);
            
            notesField.addEventListener('input', function() {
                const count = this.value.length;
                charCount.textContent = `${count}/1000 characters`;
                if (count > 1000) {
                    charCount.style.color = '#e74c3c';
                } else if (count > 800) {
                    charCount.style.color = '#f39c12';
                } else {
                    charCount.style.color = '#7f8c8d';
                }
            });
            
            // Trigger initial count
            notesField.dispatchEvent(new Event('input'));
        }
        
        // Auto-format MAC address
        document.getElementById('mac_address').addEventListener('blur', function() {
            let mac = this.value.trim().toUpperCase();
            if (mac) {
                // Remove all non-alphanumeric characters
                mac = mac.replace(/[^0-9A-F]/g, '');
                // Format as 00:1A:2B:3C:4D:5E
                if (mac.length === 12) {
                    this.value = mac.match(/.{2}/g).join(':');
                }
            }
        });
    </script>
</body>
</html>