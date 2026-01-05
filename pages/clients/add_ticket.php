<?php
// pages/clients/add_ticket.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/includes/client_functions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    $_SESSION['error'] = "Client ID is required.";
    header("Location: index.php");
    exit();
}

$client_id = $_GET['client_id'];
$pdo = getDBConnection();
$errors = [];
$client = null;
$technicians = [];
$assets = [];

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

// Check permissions - technicians and above can create tickets
$user_role = $_SESSION['user_type'] ?? null;
if (!in_array($user_role, ['super_admin', 'admin', 'manager', 'technician'])) {
    $_SESSION['error'] = "You don't have permission to create tickets.";
    header("Location: view.php?id=$client_id");
    exit();
}

// Fetch available technicians
try {
    $stmt = $pdo->prepare("
        SELECT id, email, CONCAT(first_name, ' ', last_name) as full_name 
        FROM users 
        WHERE user_type IN ('technician', 'admin', 'super_admin', 'manager')
        AND status = 'active'
        ORDER BY first_name, last_name
    ");
    $stmt->execute();
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail - technicians list is optional
}

// Fetch client assets
try {
    $stmt = $pdo->prepare("
        SELECT id, asset_name, asset_type, serial_number 
        FROM assets 
        WHERE client_id = ? 
        AND status = 'active'
        ORDER BY asset_name
    ");
    $stmt->execute([$client_id]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail - assets list is optional
}

// Ticket categories
$ticket_categories = [
    'Hardware' => [
        'Desktop/Laptop' => 'Desktop/Laptop',
        'Server' => 'Server',
        'Network' => 'Network Equipment',
        'Printer/Scanner' => 'Printer/Scanner',
        'Peripheral' => 'Other Peripheral',
        'Mobile Device' => 'Mobile Device'
    ],
    'Software' => [
        'Operating System' => 'Operating System',
        'Application' => 'Application Software',
        'Database' => 'Database',
        'Security' => 'Security Software',
        'Cloud' => 'Cloud Services'
    ],
    'Network' => [
        'Connectivity' => 'Network Connectivity',
        'VPN' => 'VPN Access',
        'Wireless' => 'Wireless Network',
        'Firewall' => 'Firewall/Router',
        'Bandwidth' => 'Bandwidth Issues'
    ],
    'Email' => [
        'Email Access' => 'Email Access',
        'Email Client' => 'Email Client',
        'Spam' => 'Spam/Phishing',
        'Calendar' => 'Calendar Issues',
        'Attachment' => 'Attachment Problems'
    ],
    'Security' => [
        'Virus/Malware' => 'Virus/Malware',
        'Access Control' => 'Access Control',
        'Data Breach' => 'Data Breach',
        'Compliance' => 'Compliance Issues',
        'Audit' => 'Security Audit'
    ],
    'Other' => [
        'Training' => 'User Training',
        'Consultation' => 'Consultation',
        'Other' => 'Other Issues'
    ]
];

// Priority levels
$priority_levels = [
    'Critical' => [
        'label' => 'Critical',
        'color' => '#e74c3c',
        'description' => 'System down, business stopped'
    ],
    'High' => [
        'label' => 'High',
        'color' => '#e67e22',
        'description' => 'Major impact on business'
    ],
    'Medium' => [
        'label' => 'Medium',
        'color' => '#f39c12',
        'description' => 'Moderate business impact'
    ],
    'Low' => [
        'label' => 'Low',
        'color' => '#3498db',
        'description' => 'Minor issue, workaround exists'
    ]
];

// Ticket sources
$ticket_sources = [
    'Phone' => 'Phone Call',
    'Email' => 'Email',
    'Portal' => 'Client Portal',
    'Walk-in' => 'Walk-in',
    'Chat' => 'Live Chat',
    'Monitoring' => 'Monitoring Alert',
    'Other' => 'Other'
];

// Initialize form data
$form_data = [
    'title' => '',
    'category' => '',
    'subcategory' => '',
    'priority' => 'Medium',
    'description' => '',
    'assigned_to' => $_SESSION['user_id'] ?? '', // Default to current user
    'asset_id' => '',
    'source' => 'Portal',
    'estimated_hours' => '1',
    'notes' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $form_data = [
        'title' => trim($_POST['title'] ?? ''),
        'category' => trim($_POST['category'] ?? ''),
        'subcategory' => trim($_POST['subcategory'] ?? ''),
        'priority' => trim($_POST['priority'] ?? 'Medium'),
        'description' => trim($_POST['description'] ?? ''),
        'assigned_to' => trim($_POST['assigned_to'] ?? ''),
        'asset_id' => trim($_POST['asset_id'] ?? ''),
        'source' => trim($_POST['source'] ?? 'Portal'),
        'estimated_hours' => trim($_POST['estimated_hours'] ?? '1'),
        'notes' => trim($_POST['notes'] ?? '')
    ];
    
    // Validation
    if (empty($form_data['title'])) {
        $errors[] = "Ticket title is required.";
    }
    
    if (empty($form_data['category'])) {
        $errors[] = "Category is required.";
    }
    
    if (empty($form_data['description'])) {
        $errors[] = "Description is required.";
    }
    
    if (empty($form_data['assigned_to'])) {
        $errors[] = "Please assign the ticket to a technician.";
    }
    
    // Validate estimated hours
    if (!empty($form_data['estimated_hours']) && (!is_numeric($form_data['estimated_hours']) || $form_data['estimated_hours'] <= 0)) {
        $errors[] = "Estimated hours must be a positive number.";
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate ticket number
            $ticket_number = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), 7, 6));
            
            // Insert ticket
            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    id, client_id, ticket_number, title, category, subcategory, priority,
                    description, assigned_to, asset_id, source, estimated_hours, notes,
                    status, created_by, created_at, updated_at
                ) VALUES (
                    uuid_generate_v4(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, NOW(), NOW()
                ) RETURNING id
            ");
            
            $stmt->execute([
                $client_id,
                $ticket_number,
                $form_data['title'],
                $form_data['category'],
                $form_data['subcategory'],
                $form_data['priority'],
                $form_data['description'],
                $form_data['assigned_to'],
                $form_data['asset_id'] ?: null,
                $form_data['source'],
                $form_data['estimated_hours'] ?: null,
                $form_data['notes'],
                $_SESSION['user_id']
            ]);
            
            $ticket_id = $stmt->fetchColumn();
            
            // Add initial status update
            $stmt = $pdo->prepare("
                INSERT INTO ticket_updates (
                    id, ticket_id, update_type, description, created_by, created_at
                ) VALUES (
                    uuid_generate_v4(), ?, 'Status Change', 'Ticket created and set to Open status', ?, NOW()
                )
            ");
            $stmt->execute([$ticket_id, $_SESSION['user_id']]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Ticket #$ticket_number created successfully!";
            header("Location: ../../pages/tickets/view.php?id=$ticket_id");
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ticket - <?= htmlspecialchars($client['company_name'] ?? 'Client') ?> - MSP Application</title>
    
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
        
        /* Priority Badges */
        .priority-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-right: 5px;
        }
        
        .priority-critical { background-color: #e74c3c; color: white; }
        .priority-high { background-color: #e67e22; color: white; }
        .priority-medium { background-color: #f39c12; color: white; }
        .priority-low { background-color: #3498db; color: white; }
        
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
        
        /* Priority Selector */
        .priority-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .priority-option {
            flex: 1;
            min-width: 120px;
        }
        
        .priority-option input[type="radio"] {
            display: none;
        }
        
        .priority-option label {
            display: block;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .priority-option input[type="radio"]:checked + label {
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
            .priority-selector {
                flex-direction: column;
            }
            .priority-option {
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
        <div class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <ul class="sidebar-menu">
                    <li><a href="../../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../../pages/users/index.php"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-building"></i> Client Management</a></li>
                    <li><a href="../../pages/tickets/index.php"><i class="fas fa-ticket-alt"></i> Tickets</a></li>
                    <li><a href="../../pages/assets/index.php"><i class="fas fa-server"></i> Assets</a></li>
                    <li><a href="../../pages/reports/index.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="../../pages/staff/profile.php"><i class="fas fa-user-circle"></i> My Profile</a></li>
                    <li><a href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Clients</a></li>
                    <li class="breadcrumb-item"><a href="view.php?id=<?= $client_id ?>"><?= htmlspecialchars($client['company_name']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Create Ticket</li>
                </ol>
            </nav>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-ticket-alt"></i> Create Support Ticket
                    </h1>
                    <p class="text-muted">Create a new support ticket for <?= htmlspecialchars($client['company_name']) ?></p>
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
            
            <!-- Ticket Creation Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-plus-circle"></i> Ticket Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="ticketForm" novalidate>
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle"></i> Basic Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label for="title" class="form-label required">Ticket Title</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="title" 
                                           name="title" 
                                           value="<?= htmlspecialchars($form_data['title']) ?>" 
                                           required
                                           placeholder="Brief description of the issue">
                                    <small class="text-muted">Be specific about the problem</small>
                                    <div class="error-message" id="title_error"></div>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="source" class="form-label">Ticket Source</label>
                                    <select class="form-select" id="source" name="source">
                                        <?php foreach ($ticket_sources as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" 
                                            <?= $form_data['source'] === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="category" class="form-label required">Category</label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($ticket_categories as $category => $subcategories): ?>
                                        <option value="<?= htmlspecialchars($category) ?>" 
                                            <?= $form_data['category'] === $category ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-message" id="category_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="subcategory" class="form-label">Subcategory</label>
                                    <select class="form-select" id="subcategory" name="subcategory">
                                        <option value="">Select Subcategory</option>
                                        <!-- Subcategories will be populated by JavaScript -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Priority Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-exclamation-circle"></i> Priority Level
                            </h5>
                            
                            <div class="priority-selector">
                                <?php foreach ($priority_levels as $key => $priority): ?>
                                <div class="priority-option">
                                    <input type="radio" 
                                           id="priority_<?= strtolower($key) ?>" 
                                           name="priority" 
                                           value="<?= $key ?>"
                                           <?= $form_data['priority'] === $key ? 'checked' : '' ?>
                                           style="display: none;">
                                    <label for="priority_<?= strtolower($key) ?>" 
                                           style="border-left: 4px solid <?= $priority['color'] ?>;">
                                        <strong><?= $priority['label'] ?></strong>
                                        <br>
                                        <small><?= $priority['description'] ?></small>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Description Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-align-left"></i> Problem Description
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="description" class="form-label required">Detailed Description</label>
                                    <textarea class="form-control" 
                                              id="description" 
                                              name="description" 
                                              rows="5"
                                              required
                                              placeholder="Describe the issue in detail. Include error messages, steps to reproduce, and any troubleshooting already attempted."><?= htmlspecialchars($form_data['description']) ?></textarea>
                                    <small class="text-muted">Be as detailed as possible to help technicians resolve quickly</small>
                                    <div class="error-message" id="description_error"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Assignment & Assets Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user-cog"></i> Assignment & Related Assets
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="assigned_to" class="form-label required">Assign To</label>
                                    <select class="form-select" id="assigned_to" name="assigned_to" required>
                                        <option value="">Select Technician</option>
                                        <?php foreach ($technicians as $tech): ?>
                                        <option value="<?= htmlspecialchars($tech['id']) ?>" 
                                            <?= $form_data['assigned_to'] === $tech['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tech['full_name']) ?> (<?= htmlspecialchars($tech['email']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Select the technician responsible for this ticket</small>
                                    <div class="error-message" id="assigned_to_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="asset_id" class="form-label">Related Asset (Optional)</label>
                                    <select class="form-select" id="asset_id" name="asset_id">
                                        <option value="">Select Asset (Optional)</option>
                                        <?php foreach ($assets as $asset): ?>
                                        <option value="<?= htmlspecialchars($asset['id']) ?>" 
                                            <?= $form_data['asset_id'] === $asset['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($asset['asset_name']) ?> 
                                            (<?= htmlspecialchars($asset['asset_type']) ?>)
                                            <?php if ($asset['serial_number']): ?>
                                            - SN: <?= htmlspecialchars($asset['serial_number']) ?>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">If this ticket is related to a specific asset</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="estimated_hours" class="form-label">Estimated Resolution Time</label>
                                    <div class="input-group">
                                        <input type="number" 
                                               class="form-control" 
                                               id="estimated_hours" 
                                               name="estimated_hours" 
                                               value="<?= htmlspecialchars($form_data['estimated_hours']) ?>"
                                               min="0.5"
                                               max="100"
                                               step="0.5">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                    <small class="text-muted">Optional - Estimated time to resolve</small>
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
                                    <label for="notes" class="form-label">Internal Notes (Optional)</label>
                                    <textarea class="form-control" 
                                              id="notes" 
                                              name="notes" 
                                              rows="3"
                                              placeholder="Any internal notes, special instructions, or references..."><?= htmlspecialchars($form_data['notes']) ?></textarea>
                                    <small class="text-muted">These notes are only visible to staff, not the client</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <a href="view.php?id=<?= $client_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Create Ticket
                            </button>
                        </div>
                    </form>
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
        
        // Subcategory dynamic population
        const categories = <?= json_encode($ticket_categories) ?>;
        const categorySelect = document.getElementById('category');
        const subcategorySelect = document.getElementById('subcategory');
        
        function updateSubcategories() {
            const selectedCategory = categorySelect.value;
            subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
            
            if (selectedCategory && categories[selectedCategory]) {
                const subcategories = categories[selectedCategory];
                for (const [value, label] of Object.entries(subcategories)) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    subcategorySelect.appendChild(option);
                }
            }
        }
        
        // Initial update
        updateSubcategories();
        
        // Update subcategories when category changes
        categorySelect.addEventListener('change', updateSubcategories);
        
        // Form validation
        document.getElementById('ticketForm').addEventListener('submit', function(event) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            
            // Title validation
            const title = document.getElementById('title');
            if (!title.value.trim()) {
                document.getElementById('title_error').textContent = 'Ticket title is required';
                title.focus();
                isValid = false;
            }
            
            // Category validation
            const category = document.getElementById('category');
            if (!category.value) {
                document.getElementById('category_error').textContent = 'Category is required';
                if (isValid) {
                    category.focus();
                    isValid = false;
                }
            }
            
            // Description validation
            const description = document.getElementById('description');
            if (!description.value.trim()) {
                document.getElementById('description_error').textContent = 'Description is required';
                if (isValid) {
                    description.focus();
                    isValid = false;
                }
            }
            
            // Assigned to validation
            const assignedTo = document.getElementById('assigned_to');
            if (!assignedTo.value) {
                document.getElementById('assigned_to_error').textContent = 'Please assign the ticket to a technician';
                if (isValid) {
                    assignedTo.focus();
                    isValid = false;
                }
            }
            
            // Estimated hours validation
            const estimatedHours = document.getElementById('estimated_hours');
            if (estimatedHours.value && (!isNumeric(estimatedHours.value) || parseFloat(estimatedHours.value) <= 0)) {
                alert('Estimated hours must be a positive number.');
                estimatedHours.focus();
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
        
        function isNumeric(value) {
            return /^-?\d+(\.\d+)?$/.test(value);
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Auto-focus title field
        document.addEventListener('DOMContentLoaded', function() {
            const titleField = document.getElementById('title');
            if (titleField && !titleField.value) {
                titleField.focus();
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
        
        // Auto-suggest title based on category
        categorySelect.addEventListener('change', function() {
            const titleField = document.getElementById('title');
            if (!titleField.value.trim()) {
                const category = this.options[this.selectedIndex].text;
                titleField.placeholder = `Brief description of ${category} issue`;
            }
        });
        
        // Character counter for description
        const descriptionField = document.getElementById('description');
        if (descriptionField) {
            const charCount = document.createElement('small');
            charCount.className = 'text-muted float-end';
            charCount.id = 'charCount';
            charCount.textContent = '0/2000 characters';
            descriptionField.parentNode.appendChild(charCount);
            
            descriptionField.addEventListener('input', function() {
                const count = this.value.length;
                charCount.textContent = `${count}/2000 characters`;
                if (count > 2000) {
                    charCount.style.color = '#e74c3c';
                } else if (count > 1500) {
                    charCount.style.color = '#f39c12';
                } else {
                    charCount.style.color = '#7f8c8d';
                }
            });
            
            // Trigger initial count
            descriptionField.dispatchEvent(new Event('input'));
        }
    </script>
</body>
</html>