<?php
// pages/clients/add_contract.php
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

if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    $_SESSION['error'] = "Client ID is required.";
    header("Location: " . route('clients.index'));
    exit();
}

$client_id = $_GET['client_id'];
$pdo = getDBConnection();
$errors = [];
$success = false;
$client = null;
$form_data = [];

// Fetch client details
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        $_SESSION['error'] = "Client not found.";
        header("Location: " . route('clients.index'));
        exit();
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

// Check permissions
if (!hasClientPermission('edit', $client_id)) {
    $_SESSION['error'] = "You don't have permission to add contracts for this client.";
    header("Location: " . route('clients.view') . "?id=$client_id");
    exit();
}

// Contract types
$contract_types = [
    'AMC' => 'Annual Maintenance Contract',
    'SLA' => 'Service Level Agreement', 
    'Pay-per-use' => 'Pay-per-use',
    'Project-based' => 'Project-based',
    'Retainer' => 'Retainer',
    'Support' => 'Technical Support',
    'Consulting' => 'Consulting Services',
    'Other' => 'Other'
];

// Service scopes
$service_scopes = [
    'Full IT Support' => 'Full IT Support',
    'Network Management' => 'Network Management',
    'Server Maintenance' => 'Server Maintenance',
    'Cloud Services' => 'Cloud Services',
    'Security Services' => 'Security Services',
    'Backup Solutions' => 'Backup Solutions',
    'Help Desk Support' => 'Help Desk Support',
    'Software Support' => 'Software Support',
    'Hardware Support' => 'Hardware Support',
    'Custom' => 'Custom Scope'
];

// Payment terms
$payment_terms = [
    'Monthly' => 'Monthly',
    'Quarterly' => 'Quarterly',
    'Semi-Annual' => 'Semi-Annual',
    'Annual' => 'Annual',
    'One-time' => 'One-time',
    'Milestone-based' => 'Milestone-based'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $form_data = [
        'contract_type' => trim($_POST['contract_type'] ?? ''),
        'service_scope' => trim($_POST['service_scope'] ?? ''),
        'custom_scope' => trim($_POST['custom_scope'] ?? ''),
        'start_date' => trim($_POST['start_date'] ?? ''),
        'end_date' => trim($_POST['end_date'] ?? ''),
        'contract_value' => trim($_POST['contract_value'] ?? ''),
        'payment_terms' => trim($_POST['payment_terms'] ?? ''),
        'status' => trim($_POST['status'] ?? 'Draft'),
        'notes' => trim($_POST['notes'] ?? ''),
        'renewal_reminder' => isset($_POST['renewal_reminder']) ? 1 : 0,
        'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0
    ];
    
    // Validation
    if (empty($form_data['contract_type'])) {
        $errors[] = "Contract type is required.";
    }
    
    if (empty($form_data['service_scope']) && empty($form_data['custom_scope'])) {
        $errors[] = "Service scope is required.";
    }
    
    if (empty($form_data['start_date'])) {
        $errors[] = "Start date is required.";
    }
    
    if (empty($form_data['end_date'])) {
        $errors[] = "End date is required.";
    }
    
    // Validate dates
    if (!empty($form_data['start_date']) && !empty($form_data['end_date'])) {
        $start_date = DateTime::createFromFormat('Y-m-d', $form_data['start_date']);
        $end_date = DateTime::createFromFormat('Y-m-d', $form_data['end_date']);
        
        if (!$start_date || !$end_date) {
            $errors[] = "Invalid date format. Use YYYY-MM-DD.";
        } elseif ($end_date <= $start_date) {
            $errors[] = "End date must be after start date.";
        }
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate contract number
            $contract_number = 'CON-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), 7, 6));
            
            // Use custom scope if provided, otherwise use selected scope
            $final_scope = !empty($form_data['custom_scope']) 
                ? $form_data['custom_scope'] 
                : $form_data['service_scope'];
            
            // Add notes to scope if provided
            if (!empty($form_data['notes'])) {
                $final_scope .= "\n\nAdditional Notes:\n" . $form_data['notes'];
            }
            
            // Insert contract
            $stmt = $pdo->prepare("
                INSERT INTO contracts (
                    id, client_id, contract_number, contract_type, start_date, end_date,
                    service_scope, contract_value, payment_terms, status, 
                    renewal_reminder, auto_renew, created_at, updated_at
                ) VALUES (
                    uuid_generate_v4(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                ) RETURNING id
            ");
            
            $stmt->execute([
                $client_id,
                $contract_number,
                $form_data['contract_type'],
                $form_data['start_date'],
                $form_data['end_date'],
                $final_scope,
                $form_data['contract_value'] ?: null,
                $form_data['payment_terms'],
                $form_data['status'],
                $form_data['renewal_reminder'],
                $form_data['auto_renew']
            ]);
            
            $contract_id = $stmt->fetchColumn();
            
            $pdo->commit();
            
            $_SESSION['success'] = "Contract '$contract_number' created successfully!";
            header("Location: " . route('contracts.view') . "?id=$contract_id");
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
    <title>Add Contract - <?= htmlspecialchars($client['company_name'] ?? 'Client') ?> - MSP Application</title>
    
    <!-- Main CSS -->
    <link rel="stylesheet" href="/mit/css/style.css">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Datepicker CSS -->
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
        
        .form-control, .form-select, .form-control-date {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
            background-color: white;
        }
        
        .form-control:focus, .form-select:focus, .form-control-date:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        textarea.form-control {
            min-height: 100px;
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
        
        /* Form Check */
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
        
        /* Currency Input */
        .input-group {
            position: relative;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border: 2px solid var(--border-color);
            border-right: none;
            border-radius: 6px 0 0 6px;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 6px 6px 0;
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
                <?php require_once '../../../includes/routes.php'; ?><a href="<?php echo route('logout'); ?>" class="btn btn-outline-light btn-sm">
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
                    <li class="breadcrumb-item"><a href="<?php echo route('dashboard'); ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo route('clients.index'); ?>">Clients</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo route('clients.view') . '?id=' . $client_id; ?>"><?php echo htmlspecialchars($client['company_name']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Add Contract</li>
                </ol>
            </nav>
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-file-contract"></i> Add New Contract
                    </h1>
                    <p class="text-muted">Create a new contract for <?= htmlspecialchars($client['company_name']) ?></p>
                </div>
                <a href="<?php echo route('clients.view') . '?id=' . $client_id; ?>" class="btn btn-outline-secondary">
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
            
            <!-- Contract Creation Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-contract"></i> Contract Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="contractForm" novalidate>
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle"></i> Basic Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contract_type" class="form-label required">Contract Type</label>
                                    <select class="form-select" id="contract_type" name="contract_type" required>
                                        <option value="">Select Contract Type</option>
                                        <?php foreach ($contract_types as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" 
                                            <?= ($form_data['contract_type'] ?? '') === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="error-message" id="contract_type_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="Draft" <?= ($form_data['status'] ?? 'Draft') === 'Draft' ? 'selected' : '' ?>>Draft</option>
                                        <option value="Active" <?= ($form_data['status'] ?? '') === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Pending" <?= ($form_data['status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Expired" <?= ($form_data['status'] ?? '') === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                        <option value="Cancelled" <?= ($form_data['status'] ?? '') === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="start_date" class="form-label required">Start Date</label>
                                    <input type="text" 
                                           class="form-control form-control-date" 
                                           id="start_date" 
                                           name="start_date" 
                                           value="<?= htmlspecialchars($form_data['start_date'] ?? '') ?>" 
                                           required
                                           placeholder="YYYY-MM-DD"
                                           data-date-format="Y-m-d">
                                    <div class="error-message" id="start_date_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="end_date" class="form-label required">End Date</label>
                                    <input type="text" 
                                           class="form-control form-control-date" 
                                           id="end_date" 
                                           name="end_date" 
                                           value="<?= htmlspecialchars($form_data['end_date'] ?? '') ?>" 
                                           required
                                           placeholder="YYYY-MM-DD"
                                           data-date-format="Y-m-d">
                                    <div class="error-message" id="end_date_error"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Service Scope Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-tasks"></i> Service Scope
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="service_scope" class="form-label">Predefined Service Scope</label>
                                    <select class="form-select" id="service_scope" name="service_scope">
                                        <option value="">Select Service Scope</option>
                                        <?php foreach ($service_scopes as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($label) ?>" 
                                            <?= ($form_data['service_scope'] ?? '') === $label ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Optional - Choose a predefined scope</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Or</label>
                                    <div class="form-text">Enter custom service scope below</div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="custom_scope" class="form-label">Custom Service Scope</label>
                                    <textarea class="form-control" 
                                              id="custom_scope" 
                                              name="custom_scope" 
                                              rows="4"
                                              placeholder="Describe the services to be provided under this contract..."><?= htmlspecialchars($form_data['custom_scope'] ?? '') ?></textarea>
                                    <small class="text-muted">Required if no predefined scope is selected</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Financial Details Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-money-bill-wave"></i> Financial Details
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contract_value" class="form-label">Contract Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text">TZS</span>
                                        <input type="number" 
                                               class="form-control" 
                                               id="contract_value" 
                                               name="contract_value" 
                                               value="<?= htmlspecialchars($form_data['contract_value'] ?? '') ?>"
                                               placeholder="0.00"
                                               step="0.01"
                                               min="0">
                                    </div>
                                    <small class="text-muted">Optional - Enter amount in Tanzanian Shillings</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <select class="form-select" id="payment_terms" name="payment_terms">
                                        <option value="">Select Payment Terms</option>
                                        <?php foreach ($payment_terms as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($label) ?>" 
                                            <?= ($form_data['payment_terms'] ?? '') === $label ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Optional</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Options Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-cog"></i> Additional Options
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="renewal_reminder" 
                                               name="renewal_reminder" 
                                               value="1"
                                               <?= isset($form_data['renewal_reminder']) && $form_data['renewal_reminder'] ? 'checked' : 'checked' ?>>
                                        <label class="form-check-label" for="renewal_reminder">
                                            Send renewal reminder 30 days before expiration
                                        </label>
                                    </div>
                                    
                                    <div class="form-check">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="auto_renew" 
                                               name="auto_renew" 
                                               value="1"
                                               <?= isset($form_data['auto_renew']) && $form_data['auto_renew'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="auto_renew">
                                            Auto-renew contract (requires client approval)
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" 
                                              id="notes" 
                                              name="notes" 
                                              rows="3"
                                              placeholder="Any additional notes or special terms for this contract..."><?= htmlspecialchars($form_data['notes'] ?? '') ?></textarea>
                                    <small class="text-muted">Optional - These notes will be included in the service scope</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <a href="<?php echo route('clients.view') . '?id=' . $client_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create Contract
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
    
    <!-- Datepicker JS -->
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
        
        // Initialize datepickers
        flatpickr('.form-control-date', {
            dateFormat: "Y-m-d",
            allowInput: true,
            minDate: "today",
            onChange: function(selectedDates, dateStr, instance) {
                // If this is the start date picker, update end date min date
                if (instance.input.id === 'start_date' && dateStr) {
                    const endDatePicker = document.getElementById('end_date')._flatpickr;
                    if (endDatePicker) {
                        endDatePicker.set('minDate', dateStr);
                    }
                }
            }
        });
        
        // Form validation
        document.getElementById('contractForm').addEventListener('submit', function(event) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            
            // Contract type validation
            const contractType = document.getElementById('contract_type');
            if (!contractType.value) {
                document.getElementById('contract_type_error').textContent = 'Contract type is required';
                contractType.focus();
                isValid = false;
            }
            
            // Start date validation
            const startDate = document.getElementById('start_date');
            if (!startDate.value) {
                document.getElementById('start_date_error').textContent = 'Start date is required';
                if (isValid) {
                    startDate.focus();
                    isValid = false;
                }
            }
            
            // End date validation
            const endDate = document.getElementById('end_date');
            if (!endDate.value) {
                document.getElementById('end_date_error').textContent = 'End date is required';
                if (isValid) {
                    endDate.focus();
                    isValid = false;
                }
            }
            
            // Service scope validation
            const serviceScope = document.getElementById('service_scope');
            const customScope = document.getElementById('custom_scope');
            if (!serviceScope.value && !customScope.value.trim()) {
                alert('Please select a service scope or enter a custom scope.');
                customScope.focus();
                isValid = false;
            }
            
            // Date validation
            if (startDate.value && endDate.value) {
                const start = new Date(startDate.value);
                const end = new Date(endDate.value);
                
                if (end <= start) {
                    alert('End date must be after start date.');
                    endDate.focus();
                    isValid = false;
                }
            }
            
            if (!isValid) {
                event.preventDefault();
                event.stopPropagation();
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
        
        // Auto-fill end date based on contract type
        document.getElementById('contract_type').addEventListener('change', function() {
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            
            if (!startDateInput.value) return;
            
            const startDate = new Date(startDateInput.value);
            const contractType = this.value;
            
            let endDate = new Date(startDate);
            
            switch(contractType) {
                case 'AMC':
                    endDate.setFullYear(endDate.getFullYear() + 1);
                    break;
                case 'SLA':
                    endDate.setFullYear(endDate.getFullYear() + 1);
                    break;
                case 'Retainer':
                    endDate.setMonth(endDate.getMonth() + 6);
                    break;
                case 'Support':
                    endDate.setMonth(endDate.getMonth() + 3);
                    break;
                default:
                    return;
            }
            
            // Format date as YYYY-MM-DD
            const formattedDate = endDate.toISOString().split('T')[0];
            endDateInput.value = formattedDate;
            
            // Update datepicker if initialized
            if (endDateInput._flatpickr) {
                endDateInput._flatpickr.setDate(formattedDate);
            }
        });
        
        // Format currency input
        document.getElementById('contract_value').addEventListener('blur', function() {
            if (this.value) {
                const value = parseFloat(this.value);
                if (!isNaN(value)) {
                    this.value = value.toFixed(2);
                }
            }
        });
    </script>
</body>
</html>