<?php
// pages/clients/create.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/includes/client_functions.php';

if (!isLoggedIn()) {
    header("Location: " . route('login'));
    exit();
}

$user_role = $_SESSION['user_type'] ?? null;
if (!hasClientPermission('create')) {
    $_SESSION['error'] = "You don't have permission to create clients.";
    header("Location: " . route('clients.index'));
    exit();
}

$pdo = getDBConnection();
$errors = [];
$success = false;
$form_data = [];

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
        'country' => 'Tanzania', // Always Tanzania
        'notes' => trim($_POST['notes'] ?? ''),
        'client_region' => trim($_POST['client_region'] ?? ''), // Will store in client_locations
        'service_type' => trim($_POST['service_type'] ?? ''), // Will store in contracts or notes
        'whatsapp_number' => trim($_POST['whatsapp_number'] ?? ''),
        'logo_path' => null
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
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Generate UUID for client ID
            $client_id = $pdo->query("SELECT uuid_generate_v4()")->fetchColumn();
            
            // Create notes string with additional info
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
            
            // Insert client
            $stmt = $pdo->prepare(
                "INSERT INTO clients (
                    id, company_name, contact_person, email, phone, address, 
                    city, state, country, website, industry, logo_path, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");
                        
            $stmt->execute([
                $client_id,
                $form_data['company_name'],
                $form_data['contact_person'],
                $form_data['email'],
                $form_data['phone'],
                $form_data['address'],
                $form_data['city'],
                $form_data['state'],
                $form_data['country'],
                $form_data['website'],
                $form_data['industry'],
                $form_data['logo_path']
            ]);
            
            // Always create a primary location
            $primary_location = $_POST['primary_location'] ?? 'yes';
            $location_name = $form_data['company_name'] . ' - Main Office';
            
            if ($primary_location === 'yes') {
                $location_data = [
                    'address' => trim($_POST['location_address'] ?? $form_data['address']),
                    'city' => trim($_POST['location_city'] ?? $form_data['city']),
                    'state' => trim($_POST['location_state'] ?? $form_data['state']),
                    'country' => 'Tanzania',
                    'primary_contact' => trim($_POST['location_contact_person'] ?? $form_data['contact_person']),
                    'phone' => trim($_POST['location_phone'] ?? $form_data['phone']),
                    'email' => trim($_POST['location_email'] ?? $form_data['email'])
                ];
                
                // Insert primary location
                $stmt = $pdo->prepare("
                    INSERT INTO client_locations (
                        client_id, location_name, address, city, state, country,
                        primary_contact, phone, email, is_primary, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, true, NOW())
                ");
                
                $stmt->execute([
                    $client_id,
                    $location_name,
                    $location_data['address'],
                    $location_data['city'],
                    $location_data['state'],
                    $location_data['country'],
                    $location_data['primary_contact'],
                    $location_data['phone'],
                    $location_data['email']
                ]);
            }
            
            // Create a default contract if service type is specified
            if (!empty($form_data['service_type'])) {
                $contract_id = $pdo->query("SELECT uuid_generate_v4()")->fetchColumn();
                $contract_number = 'CON-' . date('Ymd') . '-' . substr($client_id, 0, 8);
                
                // FIXED: Use PostgreSQL syntax for date addition
                $stmt = $pdo->prepare("
                    INSERT INTO contracts (
                        id, client_id, contract_number, contract_type, start_date, end_date,
                        service_scope, status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL '1 year', ?, 'Active', NOW(), NOW())
                ");
                
                // Include notes content in service scope
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
            
            $pdo->commit();
            
            $_SESSION['success'] = "Client '{$form_data['company_name']}' created successfully!";
            header("Location: " . route('clients.view') . "?id=$client_id");
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
];?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Client - MSP Application</title>
    
    <!-- Main CSS - Using the same as dashboard for consistency -->
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-plus-circle"></i> Create New Client</h1>
                    <p class="text-muted">Add a new client company to the system</p>
                </div>
                <a href="<?php echo route('clients.index'); ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Clients
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
            
            <!-- Client Creation Form -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-building"></i> Client Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="clientForm" enctype="multipart/form-data" novalidate>
                    
                    <!-- First Row: Company Information, Primary Contact, Company Address -->
                    <div class="row mb-4">
                        <div class="col-lg-4">
                            <div class="card h-100 shadow-sm">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-info-circle text-primary"></i> Company Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="company_name" class="form-label required">Company Name</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="company_name" 
                                                   name="company_name" 
                                                   value="<?= htmlspecialchars($form_data['company_name'] ?? '') ?>" 
                                                   required
                                                   placeholder="Enter company name">
                                            <div class="error-message" id="company_name_error"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="industry" class="form-label">Industry</label>
                                            <select class="form-select" id="industry" name="industry">
                                                <option value="">Select Industry</option>
                                                <option value="IT" <?= ($form_data['industry'] ?? '') === 'IT' ? 'selected' : '' ?>>IT Services</option>
                                                <option value="Healthcare" <?= ($form_data['industry'] ?? '') === 'Healthcare' ? 'selected' : '' ?>>Healthcare</option>
                                                <option value="Finance" <?= ($form_data['industry'] ?? '') === 'Finance' ? 'selected' : '' ?>>Finance</option>
                                                <option value="Retail" <?= ($form_data['industry'] ?? '') === 'Retail' ? 'selected' : '' ?>>Retail</option>
                                                <option value="Education" <?= ($form_data['industry'] ?? '') === 'Education' ? 'selected' : '' ?>>Education</option>
                                                <option value="Manufacturing" <?= ($form_data['industry'] ?? '') === 'Manufacturing' ? 'selected' : '' ?>>Manufacturing</option>
                                                <option value="Government" <?= ($form_data['industry'] ?? '') === 'Government' ? 'selected' : '' ?>>Government</option>
                                                <option value="Other" <?= ($form_data['industry'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="website" class="form-label">Website</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="website" 
                                                   name="website" 
                                                   value="<?= htmlspecialchars($form_data['website'] ?? '') ?>"
                                                   placeholder="https://example.com">
                                            <small class="text-muted">Optional</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="client_region" class="form-label">Client Region (Tanzania)</label>
                                            <select class="form-select" id="client_region" name="client_region">
                                                <option value="">Select Region in Tanzania</option>
                                                <?php foreach ($tanzania_regions as $region): ?>
                                                <option value="<?= htmlspecialchars($region) ?>" 
                                                    <?= ($form_data['client_region'] ?? '') === $region ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($region) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Optional</small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="service_type" class="form-label">Service Type</label>
                                            <select class="form-select" id="service_type" name="service_type">
                                                <option value="">Select Service Type</option>
                                                <?php foreach ($service_types as $key => $label): ?>
                                                <option value="<?= htmlspecialchars($key) ?>" 
                                                    <?= ($form_data['service_type'] ?? '') === $key ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Optional - Will be stored in contract</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="notes" class="form-label">Notes</label>
                                            <textarea class="form-control" 
                                                      id="notes" 
                                                      name="notes" 
                                                      rows="2"
                                                      placeholder="Any additional information about this client..."><?= htmlspecialchars($form_data['notes'] ?? '') ?></textarea>
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
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Contact Information Section -->
                            <div class="card h-100 shadow-sm">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-user text-primary"></i> Primary Contact Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="contact_person" class="form-label required">Contact Person</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="contact_person" 
                                                   name="contact_person" 
                                                   value="<?= htmlspecialchars($form_data['contact_person'] ?? '') ?>" 
                                                   required
                                                   placeholder="Full name of contact person">
                                            <div class="error-message" id="contact_person_error"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="email" 
                                                   name="email" 
                                                   value="<?= htmlspecialchars($form_data['email'] ?? '') ?>"
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
                                                   value="<?= htmlspecialchars($form_data['phone'] ?? '') ?>"
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
                                                       value="<?= htmlspecialchars($form_data['whatsapp_number'] ?? '') ?>"
                                                       placeholder="+255712345678">
                                                <button type="button" class="btn btn-success" id="test_whatsapp" disabled>
                                                    <i class="fab fa-whatsapp"></i> Test
                                                </button>
                                            </div>
                                            <small class="phone-format">Format: +255712345678 or 255712345678</small>
                                            <small class="text-muted">Optional - Will be stored in contract service scope</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Company Address Section -->
                            <div class="card h-100 shadow-sm">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-map-marker-alt text-primary"></i> Company Address
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="address" class="form-label">Street Address</label>
                                            <textarea class="form-control" 
                                                      id="address" 
                                                      name="address" 
                                                      rows="2"
                                                      placeholder="123 Main Street, Kinondoni"><?= htmlspecialchars($form_data['address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="city" class="form-label">City/District</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="city" 
                                                   name="city" 
                                                   value="<?= htmlspecialchars($form_data['city'] ?? '') ?>"
                                                   placeholder="Dar es Salaam">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label for="state" class="form-label">Ward/Area</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="state" 
                                                   name="state" 
                                                   value="<?= htmlspecialchars($form_data['state'] ?? '') ?>"
                                                   placeholder="Kinondoni">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label for="country" class="form-label">Country</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="country" 
                                                   name="country" 
                                                   value="Tanzania"
                                                   readonly
                                                   style="background-color: #f8f9fa;">
                                            <small class="text-muted">All clients are in Tanzania</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Primary Service Location Section -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-home text-primary"></i> Primary Service Location
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="add_location" 
                                           name="primary_location" 
                                           value="yes"
                                           <?= isset($_POST['primary_location']) && $_POST['primary_location'] === 'yes' ? 'checked' : 'checked' ?>>
                                    <label class="form-check-label" for="add_location">
                                        Add primary service location
                                    </label>
                                    <small class="text-muted d-block">This is where services will be delivered</small>
                                </div>
                            </div>
                            
                            <div id="location_fields">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               id="same_as_company" 
                                               name="same_as_company" 
                                               value="1"
                                               checked>
                                        <label class="form-check-label" for="same_as_company">
                                            Same as company address and contact information
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="custom_location" style="display: none;">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="location_address" class="form-label">Location Address</label>
                                            <textarea class="form-control" 
                                                      id="location_address" 
                                                      name="location_address" 
                                                      rows="2"
                                                      placeholder="Service location address"><?= htmlspecialchars($_POST['location_address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="location_city" class="form-label">City/District</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_city" 
                                                   name="location_city" 
                                                   value="<?= htmlspecialchars($_POST['location_city'] ?? '') ?>"
                                                   placeholder="City">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label for="location_state" class="form-label">Ward/Area</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_state" 
                                                   name="location_state" 
                                                   value="<?= htmlspecialchars($_POST['location_state'] ?? '') ?>"
                                                   placeholder="Ward/Area">
                                        </div>
                                        
                                        <div class="col-md-4 mb-3">
                                            <label for="location_country" class="form-label">Country</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_country" 
                                                   name="location_country" 
                                                   value="Tanzania"
                                                   readonly
                                                   style="background-color: #f8f9fa;">
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="location_contact_person" class="form-label">Location Contact</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_contact_person" 
                                                   name="location_contact_person" 
                                                   value="<?= htmlspecialchars($_POST['location_contact_person'] ?? '') ?>"
                                                   placeholder="Contact person at this location">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="location_phone" class="form-label">Location Phone</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_phone" 
                                                   name="location_phone" 
                                                   value="<?= htmlspecialchars($_POST['location_phone'] ?? '') ?>"
                                                   placeholder="+255712345678">
                                            <small class="phone-format">Format: +255712345678 or 255712345678</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="location_email" class="form-label">Location Email</label>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="location_email" 
                                                   name="location_email" 
                                                   value="<?= htmlspecialchars($_POST['location_email'] ?? '') ?>"
                                                   placeholder="location@example.com">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="location_whatsapp" class="form-label">Location WhatsApp</label>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="location_whatsapp" 
                                                   name="location_whatsapp" 
                                                   value="<?= htmlspecialchars($_POST['location_whatsapp'] ?? '') ?>"
                                                   placeholder="+255712345678">
                                            <small class="phone-format">Format: +255712345678 or 255712345678</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                        <!-- Form Actions -->
                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?php echo route('clients.index'); ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Client
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/main.js"></script>
    <script>
        // Same as company address toggle
        document.getElementById('same_as_company').addEventListener('change', function() {
            const customLocation = document.getElementById('custom_location');
            if (this.checked) {
                customLocation.style.display = 'none';
            } else {
                customLocation.style.display = 'block';
            }
        });
        
        // Form validation
        document.getElementById('clientForm').addEventListener('submit', function(event) {
            let isValid = true;
            
            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            
            // Company name validation
            const companyName = document.getElementById('company_name');
            if (!companyName.value.trim()) {
                document.getElementById('company_name_error').textContent = 'Company name is required';
                companyName.focus();
                isValid = false;
            }
            
            // Contact person validation
            const contactPerson = document.getElementById('contact_person');
            if (!contactPerson.value.trim()) {
                document.getElementById('contact_person_error').textContent = 'Contact person is required';
                if (isValid) {
                    contactPerson.focus();
                    isValid = false;
                }
            }
            
            // Email validation
            const email = document.getElementById('email');
            if (email.value.trim() && !isValidEmail(email.value)) {
                alert('Please enter a valid email address');
                email.focus();
                isValid = false;
            }
            
            // Phone validation - accepts + at start
            const phone = document.getElementById('phone');
            if (phone.value.trim() && !isValidInternationalPhone(phone.value)) {
                alert('Please enter a valid phone number with country code (e.g., +255712345678 or 255712345678)');
                phone.focus();
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
        
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        function isValidInternationalPhone(phone) {
            // Accepts + at the beginning, then digits only
            // Minimum: + and 7 digits (e.g., +2551234)
            // Maximum: + and 15 digits (typical max for international numbers)
            const cleanPhone = phone.replace(/\s+/g, ''); // Remove spaces
            // Check if starts with + followed by digits, or just digits
            return /^\+?\d{7,15}$/.test(cleanPhone);
        }
        
        // Phone number formatting - allows + at start
        ['phone', 'whatsapp_number', 'location_phone', 'location_whatsapp'].forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', function(e) {
                    let value = e.target.value;
                    
                    // Allow + at the beginning, then only digits
                    if (value.startsWith('+')) {
                        // Keep the + and allow digits only after it
                        const plus = '+';
                        const digits = value.substring(1).replace(/\D/g, '');
                        value = plus + digits;
                    } else {
                        // No + at start, allow digits only
                        value = value.replace(/\D/g, '');
                    }
                    
                    // Limit total length to 16 characters (+ plus 15 digits max)
                    if (value.length > 16) {
                        value = value.substring(0, 16);
                    }
                    
                    e.target.value = value;
                });
            }
        });
        
        // Copy company address to location if same
        document.getElementById('same_as_company').addEventListener('change', function() {
            if (this.checked) {
                // Copy all values
                document.getElementById('location_address').value = document.getElementById('address').value;
                document.getElementById('location_city').value = document.getElementById('city').value;
                document.getElementById('location_state').value = document.getElementById('state').value;
                document.getElementById('location_contact_person').value = document.getElementById('contact_person').value;
                document.getElementById('location_phone').value = document.getElementById('phone').value;
                document.getElementById('location_email').value = document.getElementById('email').value;
                document.getElementById('location_whatsapp').value = document.getElementById('whatsapp_number').value;
            }
        });
        
        // Auto-update location fields when company fields change
        ['address', 'city', 'state', 'contact_person', 'phone', 'email', 'whatsapp_number'].forEach(field => {
            const fieldElement = document.getElementById(field);
            if (fieldElement) {
                fieldElement.addEventListener('input', function() {
                    if (document.getElementById('same_as_company').checked) {
                        const locationField = 'location_' + field;
                        const locationElement = document.getElementById(locationField);
                        if (locationElement) {
                            locationElement.value = this.value;
                        }
                    }
                });
            }
        });
        
        // WhatsApp test button functionality
        const whatsappField = document.getElementById('whatsapp_number');
        const testWhatsappBtn = document.getElementById('test_whatsapp');
        
        if (whatsappField && testWhatsappBtn) {
            whatsappField.addEventListener('input', function() {
                const value = this.value.replace(/\s+/g, '');
                // Enable button if we have at least 7 digits after +
                const cleanValue = value.replace(/[\D]/g, '');
                testWhatsappBtn.disabled = cleanValue.length < 7;
            });
            
            testWhatsappBtn.addEventListener('click', function() {
                let whatsappNumber = whatsappField.value.replace(/\s+/g, '');
                // Remove + for WhatsApp URL
                if (whatsappNumber.startsWith('+')) {
                    whatsappNumber = whatsappNumber.substring(1);
                }
                
                if (whatsappNumber) {
                    const companyName = document.getElementById('company_name').value || 'New Client';
                    const message = encodeURIComponent(
                        `Hello ${companyName},

This is a test message from MSP Portal.

Best regards,
MSP Support Team`
                    );
                    window.open(`https://wa.me/${whatsappNumber}?text=${message}`, '_blank');
                }
            });
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>