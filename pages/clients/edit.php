<?php
// pages/clients/edit.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/includes/client_functions.php';

$page_title = 'Edit Client - ' . htmlspecialchars($client['company_name'] ?? 'Client');

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
$success = false;
$client = null;
$locations = [];
$contracts = [];

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
if (!hasClientPermission('edit')) {
    $_SESSION['error'] = "You don't have permission to edit this client.";
    header("Location: " . route('clients.view') . "?id=$client_id");
    exit();
}

// Tanzania regions
$tanzania_regions = [
    'Dar es Salaam',
    'Arusha',
    'Dodoma',
    'Mwanza',
    'Mbeya',
    'Morogoro',
    'Tanga',
    'Kagera',
    'Mtwara',
    'Rukwa',
    'Kigoma',
    'Shinyanga',
    'Tabora',
    'Singida',
    'Iringa',
    'Ruvuma',
    'Mara',
    'Manyara',
    'Pwani',
    'Lindi',
    'Geita',
    'Katavi',
    'Njombe',
    'Simiyu',
    'Songwe'
];

// Service types
$service_types = [
    'AMC' => 'Annual Maintenance Contract',
    'SLA' => 'Service Level Agreement',
    'Pay-per-use' => 'Pay-per-use',
    'General' => 'General Support'
];

// Industries
$industries = [
    'IT' => 'IT Services',
    'Healthcare' => 'Healthcare',
    'Finance' => 'Finance',
    'Retail' => 'Retail',
    'Education' => 'Education',
    'Manufacturing' => 'Manufacturing',
    'Government' => 'Government',
    'Hospitality' => 'Hospitality',
    'Construction' => 'Construction',
    'Transportation' => 'Transportation',
    'Telecommunications' => 'Telecommunications',
    'Energy' => 'Energy',
    'Other' => 'Other'
];

// Initialize form data with existing client data
$form_data = [
    'company_name' => $client['company_name'] ?? '',
    'contact_person' => $client['contact_person'] ?? '',
    'email' => $client['email'] ?? '',
    'phone' => $client['phone'] ?? '',
    'website' => $client['website'] ?? '',
    'industry' => $client['industry'] ?? '',
    'address' => $client['address'] ?? '',
    'city' => $client['city'] ?? '',
    'state' => $client['state'] ?? '',
    'country' => $client['country'] ?? 'Tanzania',
    'notes' => '', // Not in clients table, will be extracted from contracts
    'client_region' => '', // Will be extracted from notes
    'service_type' => '', // Will be extracted from notes
    'whatsapp_number' => '' // Will be extracted from notes
];

// Try to extract additional info from contracts
try {
    $stmt = $pdo->prepare("
        SELECT service_scope FROM contracts 
        WHERE client_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$client_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($contract && !empty($contract['service_scope'])) {
        $scope = $contract['service_scope'];
        
        // Extract notes (everything after "Additional Notes:")
        if (strpos($scope, 'Additional Notes:') !== false) {
            $notes_part = explode('Additional Notes:', $scope, 2)[1];
            $form_data['notes'] = trim($notes_part);
            
            // Extract region, service type, and whatsapp from notes
            $lines = explode("\n", $form_data['notes']);
            foreach ($lines as $line) {
                if (strpos($line, 'Region:') === 0) {
                    $form_data['client_region'] = trim(str_replace('Region:', '', $line));
                }
                if (strpos($line, 'Service Type:') === 0) {
                    $form_data['service_type'] = trim(str_replace('Service Type:', '', $line));
                }
                if (strpos($line, 'WhatsApp:') === 0) {
                    $form_data['whatsapp_number'] = trim(str_replace('WhatsApp:', '', $line));
                }
            }
        }
    }
} catch (PDOException $e) {
    // Silently fail - these fields are optional
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $form_data = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'industry' => trim($_POST['industry'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'country' => 'Tanzania',
        'notes' => trim($_POST['notes'] ?? ''),
        'client_region' => trim($_POST['client_region'] ?? ''),
        'service_type' => trim($_POST['service_type'] ?? ''),
        'whatsapp_number' => trim($_POST['whatsapp_number'] ?? '')
    ];
    
    // Handle logo upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Check if mime_content_type function exists
        if (function_exists('mime_content_type')) {
            $fileType = mime_content_type($_FILES['logo']['tmp_name']);
        } else {
            // Fallback: check file extension
            $fileExtension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $mimeTypeMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif'
            ];
            $fileType = $mimeTypeMap[$fileExtension] ?? '';
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        
        if (in_array($fileType, $allowedTypes)) {
            $fileName = uniqid('logo_') . '_' . basename($_FILES['logo']['name']);
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $filePath)) {
                $form_data['logo_path'] = 'uploads/logos/' . $fileName;
            }
        } else {
            $errors[] = "Invalid file type. Please upload a JPG, PNG, or GIF image.";
        }
    } else {
        // Keep existing logo if no new upload
        $stmt = $pdo->prepare("SELECT logo_path FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $existingClient = $stmt->fetch(PDO::FETCH_ASSOC);
        $form_data['logo_path'] = $existingClient['logo_path'] ?? null;
    }
    
    // Validation
    if (empty($form_data['company_name'])) {
        $errors[] = "Company name is required.";
    }
    
    if (empty($form_data['contact_person'])) {
        $errors[] = "Contact person is required.";
    }
    
    if (!empty($form_data['email']) && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    
    if (!empty($form_data['website']) && !filter_var($form_data['website'], FILTER_VALIDATE_URL)) {
        $errors[] = "Invalid website URL.";
    }
    
    // If no errors, update database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Update client information
            $stmt = $pdo->prepare(
                "UPDATE clients SET
                    company_name = ?,
                    contact_person = ?,
                    email = ?,
                    phone = ?,
                    website = ?,
                    industry = ?,
                    address = ?,
                    city = ?,
                    state = ?,
                    country = ?,
                    logo_path = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
                        
            $stmt->execute([
                $form_data['company_name'],
                $form_data['contact_person'],
                $form_data['email'],
                $form_data['phone'],
                $form_data['website'],
                $form_data['industry'],
                $form_data['address'],
                $form_data['city'],
                $form_data['state'],
                $form_data['country'],
                $form_data['logo_path'],
                $client_id
            ]);
            
            // Update or create contract with additional information
            // First, check if client has any contracts
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE client_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$client_id]);
            $existing_contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Build notes content
            $notes_content = $form_data['notes'];
            if (!empty($form_data['client_region'])) {
                $notes_content .= (!empty($notes_content) ? "\n" : "") . "Region: " . $form_data['client_region'];
            }
            if (!empty($form_data['service_type'])) {
                $notes_content .= (!empty($notes_content) ? "\n" : "") . "Service Type: " . $form_data['service_type'];
            }
            if (!empty($form_data['whatsapp_number'])) {
                $notes_content .= (!empty($notes_content) ? "\n" : "") . "WhatsApp: " . $form_data['whatsapp_number'];
            }
            
            if ($existing_contract) {
                // Update existing contract's service scope
                $stmt = $pdo->prepare("
                    UPDATE contracts SET
                        service_scope = CONCAT(
                            SUBSTRING(service_scope FROM '^[^\\n]*'), 
                            CASE 
                                WHEN service_scope LIKE '%\\nAdditional Notes:%' 
                                THEN SUBSTRING(service_scope FROM '\\nAdditional Notes:.*$')
                                ELSE ''
                            END
                        ),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                // Get the original service type from contract
                $stmt2 = $pdo->prepare("SELECT contract_type FROM contracts WHERE id = ?");
                $stmt2->execute([$existing_contract['id']]);
                $contract_type_row = $stmt2->fetch(PDO::FETCH_ASSOC);
                $contract_type = $contract_type_row['contract_type'] ?? '';
                
                // Build new service scope
                $service_scope = "Service type: " . ($form_data['service_type'] ?: $contract_type);
                if (!empty($notes_content)) {
                    $service_scope .= "\n\nAdditional Notes:\n" . $notes_content;
                }
                
                $stmt->execute([$existing_contract['id']]);
                
                // Actually update with new service scope
                $stmt3 = $pdo->prepare("UPDATE contracts SET service_scope = ?, updated_at = NOW() WHERE id = ?");
                $stmt3->execute([$service_scope, $existing_contract['id']]);
            } else {
                // Create a new contract if service type is specified
                if (!empty($form_data['service_type'])) {
                    $contract_id = $pdo->query("SELECT uuid_generate_v4()")->fetchColumn();
                    $contract_number = 'CON-' . date('Ymd') . '-' . substr($client_id, 0, 8);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO contracts (
                            id, client_id, contract_number, contract_type, start_date, end_date,
                            service_scope, status, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL '1 year', ?, 'Active', NOW(), NOW())
                    ");
                    
                    $service_scope = "Service type: " . $form_data['service_type'];
                    if (!empty($notes_content)) {
                        $service_scope .= "\n\nAdditional Notes:\n" . $notes_content;
                    }
                    
                    $stmt->execute([
                        $contract_id,
                        $client_id,
                        $contract_number,
                        $form_data['service_type'],
                        $service_scope
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success'] = "Client '{$form_data['company_name']}' updated successfully!";
            header('Location: ' . route('clients.view', ['id' => $client_id]));
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

require_once __DIR__ . '/../../includes/header.php';
?>

<style>

    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #f5f7fa;
        color: #333;
        line-height: 1.6;
        min-height: 100vh;
    }
    
    /* Main Content */
    .main-content {
        flex: 1;
        padding: 25px;
        background-color: #f5f5f5;
        overflow-y: auto;
        width: calc(100vw - 250px);
        margin-left: 250px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
        box-sizing: border-box;
    }
    
    /* Adjust for mobile */
    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
            width: 100vw;
        }
    }
    
    /* Override any conflicting styles */
    .main-content > * {
        width: 100%;
        max-width: 100%;
    }
    
    @media (max-width: 992px) {
        .main-content {
            padding: 15px;
        }
    }
    
    /* Page Header */
    .page-title {
        color: #004E89;
        font-weight: 700;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 3px solid #FF6B35;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-title i {
        color: #FF6B35;
    }
    
    /* Cards */
    .card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        margin-bottom: 25px;
        border: 1px solid #dee2e6;
    }
    
    .card-header {
        background-color: #f8f9fa;
        color: #004E89;
        padding: 18px 25px;
        font-weight: 600;
        border-radius: 10px 10px 0 0 !important;
        border-bottom: 1px solid #dee2e6;
    }
    
    .card-header h5 {
        margin: 0;
        display: flex;
        align-items: center;
    }
    
    .card-header h5 i {
        margin-right: 10px;
        color: #FF6B35;
    }
    
    .card-body {
        padding: 25px;
    }
    
    /* Form Elements */
    .form-label {
        font-weight: 500;
        color: #004E89;
        margin-bottom: 8px;
        display: block;
    }
    
    .form-label.required::after {
        content: " *";
        color: #DC3545;
    }
    
    .form-control, .form-select {
        width: 100%;
        padding: 10px 15px;
        border: 2px solid #ddd;
        border-radius: 6px;
        font-size: 15px;
        transition: all 0.3s;
        background-color: white;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #FF6B35;
        outline: none;
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.2);
    }
    
    .form-control[readonly] {
        background-color: #f8f9fa;
        cursor: not-allowed;
    }
    
    /* Form Sections */
    .form-section {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 25px;
        border-left: 4px solid #FF6B35;
    }
    
    .section-title {
        color: #004E89;
        font-weight: 600;
        margin: 0 0 20px 0;
        padding-bottom: 12px;
        border-bottom: 2px solid #dee2e6;
        display: flex;
        align-items: center;
    }
    
    .section-title i {
        margin-right: 10px;
        color: #FF6B35;
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
    
    .btn-warning {
        background-color: #FFC107;
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
    
    /* Alerts */
    .alert {
        padding: 18px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: none;
        border-left: 4px solid;
    }
    
    .alert-danger {
        background-color: #f8d7da;
        color: #721c24;
        border-left-color: #DC3545;
    }
    
    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border-left-color: #28A745;
    }
    
    /* Form Check */
    .form-check {
        margin-bottom: 10px;
    }
    
    .form-check-input {
        margin-right: 10px;
        cursor: pointer;
    }
    
    .form-check-label {
        cursor: pointer;
        user-select: none;
    }
    
    /* Helper Text */
    .text-muted {
        color: #6c757d !important;
        font-size: 13px;
        margin-top: 5px;
        display: block;
    }
    
    .phone-format {
        font-size: 12px;
        color: #6c757d;
        margin-top: 3px;
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
        color: #FF6B35;
        text-decoration: none;
    }
    
    .breadcrumb-item.active {
        color: #6c757d;
    }
    
    /* Mobile Menu Button */
    .mobile-menu-btn {
        position: fixed;
        bottom: 25px;
        right: 25px;
        background-color: #FF6B35;
        color: white;
        border: none;
        border-radius: 50%;
        width: 60px;
        height: 60px;
        font-size: 22px;
        cursor: pointer;
        z-index: 1030;
        box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        display: none;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }
    
    .mobile-menu-btn:hover {
        background-color: #e55a2b;
        transform: scale(1.05);
    }
    
    @media (max-width: 992px) {
        .mobile-menu-btn {
            display: flex;
        }
    }
    
    /* Error Messages */
    .error-message {
        color: #DC3545;
        font-size: 13px;
        margin-top: 5px;
        display: block;
    }
    
    /* Input Group */
    .input-group {
        display: flex;
    }
    
    .input-group .form-control {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    
    .input-group .btn {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
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

<div class="dashboard-container">
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="main-content">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= route('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= route('clients.index') ?>">Clients</a></li>
            <li class="breadcrumb-item"><a href="<?= route('clients.view', ['id' => $client_id]) ?>"><?= htmlspecialchars($client['company_name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Edit Client</li>
        </ol>
    </nav>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title">
                <i class="fas fa-edit"></i> Edit Client: <?= htmlspecialchars($client['company_name']) ?>
            </h1>
            <p class="text-muted">Update client information</p>
        </div>
        <a href="<?= route('clients.view', ['id' => $client_id]) ?>" class="btn btn-outline-secondary">
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
            
            <!-- Client Edit Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-building"></i> Client Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="clientForm" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        
                        <!-- Company Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-info-circle"></i> Company Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="company_name" class="form-label required">Company Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="company_name" 
                                           name="company_name" 
                                           value="<?= htmlspecialchars($form_data['company_name']) ?>" 
                                           required
                                           placeholder="Enter company name">
                                    <div class="error-message" id="company_name_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="industry" class="form-label">Industry</label>
                                    <select class="form-select" id="industry" name="industry">
                                        <option value="">Select Industry</option>
                                        <?php foreach ($industries as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" 
                                            <?= $form_data['industry'] === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="website" class="form-label">Website</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="website" 
                                           name="website" 
                                           value="<?= htmlspecialchars($form_data['website']) ?>"
                                           placeholder="https://example.com">
                                    <small class="text-muted">Optional</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="client_region" class="form-label">Client Region (Tanzania)</label>
                                    <select class="form-select" id="client_region" name="client_region">
                                        <option value="">Select Region in Tanzania</option>
                                        <?php foreach ($tanzania_regions as $region): ?>
                                        <option value="<?= htmlspecialchars($region) ?>" 
                                            <?= $form_data['client_region'] === $region ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($region) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Optional</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="service_type" class="form-label">Service Type</label>
                                    <select class="form-select" id="service_type" name="service_type">
                                        <option value="">Select Service Type</option>
                                        <?php foreach ($service_types as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" 
                                            <?= $form_data['service_type'] === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Optional - Will be stored in contract</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" 
                                              id="notes" 
                                              name="notes" 
                                              rows="2"
                                              placeholder="Any additional information about this client..."><?= htmlspecialchars($form_data['notes']) ?></textarea>
                                    <small class="text-muted">Will be stored in contract service scope</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="logo" class="form-label">Company Logo</label>
                                    <input type="file" 
                                           class="form-control" 
                                           id="logo" 
                                           name="logo" 
                                           accept="image/*">
                                    <small class="text-muted">Upload company logo (optional, PNG/JPG/GIF)</small>
                                    <?php if (!empty($client['logo_path'])): ?>
                                        <div class="mt-2">
                                            <label class="form-label">Current Logo:</label><br>
                                            <img src="../../<?= htmlspecialchars($client['logo_path']) ?>" alt="Current Logo" style="max-width: 150px; max-height: 100px;">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-user"></i> Primary Contact Information
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_person" class="form-label required">Contact Person</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="contact_person" 
                                           name="contact_person" 
                                           value="<?= htmlspecialchars($form_data['contact_person']) ?>" 
                                           required
                                           placeholder="Full name of contact person">
                                    <div class="error-message" id="contact_person_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email" 
                                           value="<?= htmlspecialchars($form_data['email']) ?>"
                                           placeholder="contact@example.com">
                                    <small class="text-muted">Optional</small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="phone" 
                                           name="phone" 
                                           value="<?= htmlspecialchars($form_data['phone']) ?>"
                                           placeholder="+255712345678">
                                    <small class="phone-format">Format: +255712345678 or 255712345678</small>
                                    <small class="text-muted">Any country code with + prefix is accepted</small>
                                    <div class="error-message" id="phone_error"></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="whatsapp_number" class="form-label">WhatsApp Number</label>
                                    <div class="input-group">
                                        <input type="text" 
                                               class="form-control" 
                                               id="whatsapp_number" 
                                               name="whatsapp_number" 
                                               value="<?= htmlspecialchars($form_data['whatsapp_number']) ?>"
                                               placeholder="+255712345678">
                                        <button type="button" class="btn btn-success" id="test_whatsapp" <?= empty($form_data['whatsapp_number']) ? 'disabled' : '' ?>>
                                            <i class="fab fa-whatsapp"></i> Test
                                        </button>
                                    </div>
                                    <small class="phone-format">Format: +255712345678 or 255712345678</small>
                                    <small class="text-muted">Optional - Will be stored in contract service scope</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Company Address Section -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="fas fa-map-marker-alt"></i> Company Address
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="address" class="form-label">Street Address</label>
                                    <textarea class="form-control" 
                                              id="address" 
                                              name="address" 
                                              rows="2"
                                              placeholder="123 Main Street, Kinondoni"><?= htmlspecialchars($form_data['address']) ?></textarea>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="city" class="form-label">City/District</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="city" 
                                           name="city" 
                                           value="<?= htmlspecialchars($form_data['city']) ?>"
                                           placeholder="Dar es Salaam">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="state" class="form-label">Ward/Area</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="state" 
                                           name="state" 
                                           value="<?= htmlspecialchars($form_data['state']) ?>"
                                           placeholder="Kinondoni">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="country" 
                                           name="country" 
                                           value="<?= htmlspecialchars($form_data['country']) ?>"
                                           readonly
                                           style="background-color: #f8f9fa;">
                                    <small class="text-muted">All clients are in Tanzania</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <a href="<?= route('clients.view') . '?id=' . $client_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
    </div> <!-- End main-content -->
        
    </div> <!-- End main-content -->
    
    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const backdrop = document.querySelector('.sidebar-backdrop');
            
            if (sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                if(backdrop) backdrop.classList.remove('active');
            } else {
                sidebar.classList.add('active');
                if(backdrop) backdrop.classList.add('active');
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
        
        // Close sidebar when clicking outside (mobile)
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const backdrop = document.querySelector('.sidebar-backdrop');
            const mobileBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth < 992 && 
                sidebar && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && 
                (!mobileBtn || !mobileBtn.contains(event.target))) {
                toggleSidebar();
            }
        });
    </script>
    
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>