<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

if (!hasPermission('manager') && !hasPermission('admin')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to manage services.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get clients and services catalog
$clients = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'Active' ORDER BY company_name")->fetchAll();
$services_catalog = $pdo->query("SELECT * FROM services_catalog WHERE is_active = true ORDER BY service_category, service_name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    $required_fields = ['client_id', 'service_name', 'expiry_date'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[$field] = "This field is required";
        }
    }
    
    // Validate dates
    if (!empty($_POST['expiry_date']) && !strtotime($_POST['expiry_date'])) {
        $errors['expiry_date'] = "Invalid date format";
    }
    if (!empty($_POST['start_date']) && !strtotime($_POST['start_date'])) {
        $errors['start_date'] = "Invalid date format";
    }
    if (!empty($_POST['renewal_date']) && !strtotime($_POST['renewal_date'])) {
        $errors['renewal_date'] = "Invalid date format";
    }
    
    // Validate domain name if provided
    if (!empty($_POST['domain_name']) && !preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', $_POST['domain_name'])) {
        $errors['domain_name'] = "Invalid domain name format";
    }
    
    // Check for duplicate domain if domain service
    if (!empty($_POST['domain_name']) && $_POST['service_category'] == 'Domain') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_services WHERE domain_name = ? AND status != 'Cancelled'");
        $stmt->execute([$_POST['domain_name']]);
        if ($stmt->fetchColumn() > 0) {
            $errors['domain_name'] = "This domain is already registered in the system";
        }
    }
    
    // Validate email accounts
    if (!empty($_POST['email_accounts']) && (!is_numeric($_POST['email_accounts']) || $_POST['email_accounts'] < 1)) {
        $errors['email_accounts'] = "Number of email accounts must be at least 1";
    }
    
    // Validate storage
    if (!empty($_POST['storage_gb']) && (!is_numeric($_POST['storage_gb']) || $_POST['storage_gb'] < 1)) {
        $errors['storage_gb'] = "Storage must be at least 1GB";
    }
    
    // Validate price
    if (!empty($_POST['monthly_price']) && (!is_numeric($_POST['monthly_price']) || $_POST['monthly_price'] < 0)) {
        $errors['monthly_price'] = "Price must be a positive number";
    }
    
    // If no errors, insert the service
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Prepare service details JSON
            $service_details = [];
            if (!empty($_POST['nameservers'])) {
                $service_details['nameservers'] = explode(',', $_POST['nameservers']);
            }
            if (!empty($_POST['admin_email'])) {
                $service_details['admin_email'] = $_POST['admin_email'];
            }
            if (!empty($_POST['registrar'])) {
                $service_details['registrar'] = $_POST['registrar'];
            }
            if (!empty($_POST['hosting_control_panel'])) {
                $service_details['control_panel'] = $_POST['hosting_control_panel'];
            }
            
            $sql = "INSERT INTO client_services (
                client_id, service_catalog_id, service_name, service_category,
                domain_name, hosting_plan, email_type, email_accounts, storage_gb,
                service_details, start_date, expiry_date, renewal_date, auto_renew,
                status, monthly_price, billing_cycle, payment_method, notes
            ) VALUES (
                :client_id, :service_catalog_id, :service_name, :service_category,
                :domain_name, :hosting_plan, :email_type, :email_accounts, :storage_gb,
                :service_details, :start_date, :expiry_date, :renewal_date, :auto_renew,
                :status, :monthly_price, :billing_cycle, :payment_method, :notes
            )";
            
            $stmt = $pdo->prepare($sql);
            
            // Calculate renewal date (30 days before expiry)
            $expiry_date = new DateTime($_POST['expiry_date']);
            $renewal_date = clone $expiry_date;
            $renewal_date->modify('-30 days');
            
            $params = [
                ':client_id' => $_POST['client_id'],
                ':service_catalog_id' => !empty($_POST['service_catalog_id']) ? $_POST['service_catalog_id'] : null,
                ':service_name' => $_POST['service_name'],
                ':service_category' => $_POST['service_category'] ?? 'Other',
                ':domain_name' => !empty($_POST['domain_name']) ? strtolower($_POST['domain_name']) : null,
                ':hosting_plan' => $_POST['hosting_plan'] ?? null,
                ':email_type' => $_POST['email_type'] ?? null,
                ':email_accounts' => !empty($_POST['email_accounts']) ? $_POST['email_accounts'] : 1,
                ':storage_gb' => !empty($_POST['storage_gb']) ? $_POST['storage_gb'] : null,
                ':service_details' => !empty($service_details) ? json_encode($service_details) : null,
                ':start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d'),
                ':expiry_date' => $_POST['expiry_date'],
                ':renewal_date' => $_POST['renewal_date'] ?? $renewal_date->format('Y-m-d'),
                ':auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
                ':status' => $_POST['status'] ?? 'Active',
                ':monthly_price' => !empty($_POST['monthly_price']) ? $_POST['monthly_price'] : 0,
                ':billing_cycle' => $_POST['billing_cycle'] ?? 'Monthly',
                ':payment_method' => $_POST['payment_method'] ?? null,
                ':notes' => !empty($_POST['notes']) ? $_POST['notes'] : null,
            ];
            
            $stmt->execute($params);
            $service_id = $pdo->lastInsertId();
            
            // Add DNS records for domain services
            if ($_POST['service_category'] == 'Domain' && !empty($_POST['domain_name'])) {
                $default_records = [
                    ['type' => 'A', 'host' => '@', 'value' => $_POST['ip_address'] ?? '192.168.1.1'],
                    ['type' => 'CNAME', 'host' => 'www', 'value' => $_POST['domain_name']],
                    ['type' => 'MX', 'host' => '@', 'value' => 'mail.' . $_POST['domain_name'], 'priority' => 10],
                    ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 a mx -all'],
                ];
                
                foreach ($default_records as $record) {
                    $dns_sql = "INSERT INTO dns_records 
                                (client_service_id, record_type, host, value, priority) 
                                VALUES (?, ?, ?, ?, ?)";
                    $dns_stmt = $pdo->prepare($dns_sql);
                    $dns_stmt->execute([
                        $service_id,
                        $record['type'],
                        $record['host'],
                        $record['value'],
                        $record['priority'] ?? null
                    ]);
                }
            }
            
            // Create audit log
            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                          VALUES (?, 'CREATE', 'SERVICE', ?, ?, ?, NOW())";
            $audit_stmt = $pdo->prepare($audit_sql);
            $audit_details = json_encode([
                'service_name' => $_POST['service_name'],
                'domain' => $_POST['domain_name'] ?? null,
                'client_id' => $_POST['client_id'],
                'expiry_date' => $_POST['expiry_date']
            ]);
            $audit_stmt->execute([
                $current_user['id'],
                $service_id,
                $audit_details,
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Service added successfully!'
            ];
            
            header('Location: ' . route('services.view', ['id' => $service_id]));
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "An error occurred: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Service | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .service-category {
            border-left: 4px solid #004E89;
            padding-left: 15px;
            margin-bottom: 20px;
        }
        .service-category.domain { border-color: #28a745; }
        .service-category.hosting { border-color: #007bff; }
        .service-category.email { border-color: #dc3545; }
        .service-category.security { border-color: #ffc107; }
        .service-category.subscription { border-color: #6f42c1; }
        
        .category-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .badge-domain { background: #28a745; color: white; }
        .badge-hosting { background: #007bff; color: white; }
        .badge-email { background: #dc3545; color: white; }
        .badge-security { background: #ffc107; color: black; }
        .badge-subscription { background: #6f42c1; color: white; }
        
        .expiry-warning {
            background: linear-gradient(135deg, #ff6b35, #ff8b6b);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .service-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 15px;
        }
        
        .dynamic-field {
            display: none;
        }
        .dynamic-field.active {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .pricing-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #dee2e6;
        }
        
        .renewal-reminder {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-plus-circle"></i> Add New Service</h1>
                    <p class="text-muted">Register domain, hosting, email, or security services for clients</p>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($current_user['staff_profile']['designation'] ?? ucfirst($current_user['user_type'])); ?></div>
                    </div>
                </div>
            </div>
            
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo route('dashboard'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo route('services.index'); ?>"><i class="fas fa-concierge-bell"></i> Services</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Add New Service</li>
                </ol>
            </nav>
            
            <?php if (isset($errors['database'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($errors['database']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Service Category Selection -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-layer-group"></i> Select Service Category</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-success category-selector w-100 h-100" data-category="Domain">
                                <i class="fas fa-globe fa-2x mb-2"></i><br>
                                Domain
                            </button>
                        </div>
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-primary category-selector w-100 h-100" data-category="Hosting">
                                <i class="fas fa-server fa-2x mb-2"></i><br>
                                Hosting
                            </button>
                        </div>
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-danger category-selector w-100 h-100" data-category="Email">
                                <i class="fas fa-envelope fa-2x mb-2"></i><br>
                                Email
                            </button>
                        </div>
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-warning category-selector w-100 h-100" data-category="Security">
                                <i class="fas fa-shield-alt fa-2x mb-2"></i><br>
                                Security
                            </button>
                        </div>
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-info category-selector w-100 h-100" data-category="Subscription">
                                <i class="fas fa-sync fa-2x mb-2"></i><br>
                                Subscription
                            </button>
                        </div>
                        <div class="col-md-2 col-4 mb-3">
                            <button type="button" class="btn btn-outline-secondary category-selector w-100 h-100" data-category="Other">
                                <i class="fas fa-cog fa-2x mb-2"></i><br>
                                Other
                            </button>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Selected Category:</strong> 
                            <span id="selected-category" class="badge bg-secondary">None</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Service Form -->
            <form method="POST" id="service-form" novalidate>
                <input type="hidden" id="service_category" name="service_category" value="">
                
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Basic Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Basic Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="client_id" class="required">Client *</label>
                                        <select class="form-select select2 <?php echo isset($errors['client_id']) ? 'is-invalid' : ''; ?>" 
                                                id="client_id" name="client_id" required>
                                            <option value="">Select client...</option>
                                            <?php foreach ($clients as $client): ?>
                                            <option value="<?php echo htmlspecialchars($client['id']); ?>"
                                                <?php echo isset($_POST['client_id']) && $_POST['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($client['company_name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (isset($errors['client_id'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['client_id']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="service_name" class="required">Service Name *</label>
                                        <input type="text" class="form-control <?php echo isset($errors['service_name']) ? 'is-invalid' : ''; ?>" 
                                               id="service_name" name="service_name" 
                                               value="<?php echo htmlspecialchars($_POST['service_name'] ?? ''); ?>" 
                                               placeholder="e.g., Domain Registration, Web Hosting, Office 365" required>
                                        <?php if (isset($errors['service_name'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['service_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="service_catalog_id">Service Template</label>
                                        <select class="form-select select2" id="service_catalog_id" name="service_catalog_id">
                                            <option value="">Select template (optional)...</option>
                                            <?php foreach ($services_catalog as $service): ?>
                                            <option value="<?php echo htmlspecialchars($service['id']); ?>"
                                                    data-category="<?php echo htmlspecialchars($service['service_category']); ?>"
                                                    data-price="<?php echo htmlspecialchars($service['default_price']); ?>"
                                                    data-cycle="<?php echo htmlspecialchars($service['billing_cycle']); ?>">
                                                <?php echo htmlspecialchars($service['service_name']); ?> 
                                                (<?php echo htmlspecialchars($service['service_category']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="status">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="Active" selected>Active</option>
                                            <option value="Pending">Pending</option>
                                            <option value="Suspended">Suspended</option>
                                            <option value="Cancelled">Cancelled</option>
                                            <option value="Expired">Expired</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Domain Service Fields (Dynamic) -->
                        <div id="domain-fields" class="dynamic-field card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-globe"></i> Domain Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="domain_name" class="required">Domain Name *</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control <?php echo isset($errors['domain_name']) ? 'is-invalid' : ''; ?>" 
                                                   id="domain_name" name="domain_name" 
                                                   value="<?php echo htmlspecialchars($_POST['domain_name'] ?? ''); ?>" 
                                                   placeholder="example.com">
                                            <span class="input-group-text">.com</span>
                                        </div>
                                        <?php if (isset($errors['domain_name'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['domain_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="registrar">Registrar</label>
                                        <input type="text" class="form-control" id="registrar" name="registrar" 
                                               value="<?php echo htmlspecialchars($_POST['registrar'] ?? 'GoDaddy'); ?>" 
                                               placeholder="GoDaddy, Namecheap, etc.">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="nameservers">Nameservers</label>
                                        <input type="text" class="form-control" id="nameservers" name="nameservers" 
                                               value="<?php echo htmlspecialchars($_POST['nameservers'] ?? 'ns1.hosting.com,ns2.hosting.com'); ?>"
                                               placeholder="ns1.hosting.com,ns2.hosting.com">
                                        <small class="text-muted">Separate multiple nameservers with commas</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="admin_email">Admin Email</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email" 
                                               value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>"
                                               placeholder="admin@example.com">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hosting Service Fields (Dynamic) -->
                        <div id="hosting-fields" class="dynamic-field card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-server"></i> Hosting Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="hosting_plan">Hosting Plan</label>
                                        <select class="form-select" id="hosting_plan" name="hosting_plan">
                                            <option value="Basic">Basic</option>
                                            <option value="Business" selected>Business</option>
                                            <option value="Enterprise">Enterprise</option>
                                            <option value="WordPress">WordPress Optimized</option>
                                            <option value="VPS">VPS</option>
                                            <option value="Dedicated">Dedicated Server</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="storage_gb">Storage (GB)</label>
                                        <input type="number" class="form-control" id="storage_gb" name="storage_gb" 
                                               value="<?php echo htmlspecialchars($_POST['storage_gb'] ?? '10'); ?>" 
                                               min="1" step="1">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="hosting_control_panel">Control Panel</label>
                                        <select class="form-select" id="hosting_control_panel" name="hosting_control_panel">
                                            <option value="cPanel">cPanel</option>
                                            <option value="Plesk">Plesk</option>
                                            <option value="DirectAdmin">DirectAdmin</option>
                                            <option value="Custom">Custom</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="ip_address">Server IP Address</label>
                                        <input type="text" class="form-control" id="ip_address" name="ip_address" 
                                               value="<?php echo htmlspecialchars($_POST['ip_address'] ?? ''); ?>"
                                               placeholder="192.168.1.1">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Email Service Fields (Dynamic) -->
                        <div id="email-fields" class="dynamic-field card mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-envelope"></i> Email Service Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email_type">Email Service Type</label>
                                        <select class="form-select" id="email_type" name="email_type">
                                            <option value="Zoho">Zoho Mail</option>
                                            <option value="GSuite">Google Workspace</option>
                                            <option value="Office365">Microsoft 365</option>
                                            <option value="Exchange">Microsoft Exchange</option>
                                            <option value="Custom">Custom/IMAP</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="email_accounts">Number of Accounts</label>
                                        <input type="number" class="form-control <?php echo isset($errors['email_accounts']) ? 'is-invalid' : ''; ?>" 
                                               id="email_accounts" name="email_accounts" 
                                               value="<?php echo htmlspecialchars($_POST['email_accounts'] ?? '5'); ?>" 
                                               min="1" step="1">
                                        <?php if (isset($errors['email_accounts'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['email_accounts']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="email_storage">Storage per Account (GB)</label>
                                        <input type="number" class="form-control" id="email_storage" name="email_storage" 
                                               value="<?php echo htmlspecialchars($_POST['email_storage'] ?? '15'); ?>" 
                                               min="1" step="1">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Service Fields (Dynamic) -->
                        <div id="security-fields" class="dynamic-field card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Security Service Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="security_type">Security Service Type</label>
                                        <select class="form-select" id="security_type" name="security_type">
                                            <option value="Firewall">Firewall Subscription</option>
                                            <option value="Antivirus">Antivirus</option>
                                            <option value="Backup">Cloud Backup</option>
                                            <option value="VPN">VPN Service</option>
                                            <option value="SSL">SSL Certificate</option>
                                            <option value="Monitoring">Security Monitoring</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="security_vendor">Vendor</label>
                                        <input type="text" class="form-control" id="security_vendor" name="security_vendor" 
                                               value="<?php echo htmlspecialchars($_POST['security_vendor'] ?? 'Fortinet'); ?>"
                                               placeholder="Fortinet, Sophos, Acronis, etc.">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="license_key">License Key</label>
                                        <input type="text" class="form-control" id="license_key" name="license_key" 
                                               value="<?php echo htmlspecialchars($_POST['license_key'] ?? ''); ?>"
                                               placeholder="XXXX-XXXX-XXXX-XXXX">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="seats">Number of Seats/Users</label>
                                        <input type="number" class="form-control" id="seats" name="seats" 
                                               value="<?php echo htmlspecialchars($_POST['seats'] ?? '1'); ?>" 
                                               min="1" step="1">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dates & Renewal -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Dates & Renewal</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="start_date">Start Date</label>
                                        <input type="text" class="form-control datepicker <?php echo isset($errors['start_date']) ? 'is-invalid' : ''; ?>" 
                                               id="start_date" name="start_date" 
                                               value="<?php echo htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')); ?>">
                                        <?php if (isset($errors['start_date'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['start_date']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="expiry_date" class="required">Expiry Date *</label>
                                        <input type="text" class="form-control datepicker <?php echo isset($errors['expiry_date']) ? 'is-invalid' : ''; ?>" 
                                               id="expiry_date" name="expiry_date" 
                                               value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>" required>
                                        <?php if (isset($errors['expiry_date'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['expiry_date']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="renewal_date">Renewal Date</label>
                                        <input type="text" class="form-control datepicker <?php echo isset($errors['renewal_date']) ? 'is-invalid' : ''; ?>" 
                                               id="renewal_date" name="renewal_date" 
                                               value="<?php echo htmlspecialchars($_POST['renewal_date'] ?? ''); ?>">
                                        <?php if (isset($errors['renewal_date'])): ?>
                                        <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['renewal_date']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="auto_renew" name="auto_renew" value="1" checked>
                                            <label class="form-check-label" for="auto_renew">
                                                <strong>Auto-renew service</strong>
                                            </label>
                                            <small class="d-block text-muted">Automatically renew this service before expiry</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="billing_cycle">Billing Cycle</label>
                                        <select class="form-select" id="billing_cycle" name="billing_cycle">
                                            <option value="Monthly">Monthly</option>
                                            <option value="Quarterly">Quarterly</option>
                                            <option value="Annual" selected>Annual</option>
                                            <option value="Biennial">Biennial (2 Years)</option>
                                            <option value="Triennial">Triennial (3 Years)</option>
                                            <option value="One-time">One-time</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="renewal-reminder">
                                    <i class="fas fa-bell"></i> 
                                    <strong>Renewal Reminder:</strong> 
                                    A reminder will be sent <span id="reminder-days">30</span> days before expiry.
                                    The service will be marked for renewal on: <span id="renewal-preview" class="fw-bold"></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Pricing -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-money-bill-wave"></i> Pricing & Billing</h5>
                            </div>
                            <div class="card-body">
                                <div class="pricing-card">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="monthly_price">Monthly Price ($)</label>
                                            <input type="number" class="form-control <?php echo isset($errors['monthly_price']) ? 'is-invalid' : ''; ?>" 
                                                   id="monthly_price" name="monthly_price" 
                                                   value="<?php echo htmlspecialchars($_POST['monthly_price'] ?? '0'); ?>" 
                                                   min="0" step="0.01">
                                            <?php if (isset($errors['monthly_price'])): ?>
                                            <div class="invalid-feedback d-block"><?php echo htmlspecialchars($errors['monthly_price']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="payment_method">Payment Method</label>
                                            <select class="form-select" id="payment_method" name="payment_method">
                                                <option value="">Select payment method...</option>
                                                <option value="Credit Card">Credit Card</option>
                                                <option value="Debit Card">Debit Card</option>
                                                <option value="Bank Transfer">Bank Transfer</option>
                                                <option value="PayPal">PayPal</option>
                                                <option value="Stripe">Stripe</option>
                                                <option value="Cash">Cash</option>
                                                <option value="Cheque">Cheque</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <strong>Monthly:</strong> <span id="price-monthly">$0.00</span>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <strong>Annual:</strong> <span id="price-annual">$0.00</span>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <strong>Total:</strong> <span id="price-total">$0.00</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notes -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-sticky-note"></i> Notes & Additional Information</h5>
                            </div>
                            <div class="card-body">
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Enter any additional information about this service..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                <div class="character-count mt-2" id="notes-count">0/2000 characters</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Service Preview -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-eye"></i> Service Preview</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <div class="service-icon mx-auto mb-2" id="preview-icon" 
                                         style="background: #6c757d; color: white;">
                                        <i class="fas fa-cog"></i>
                                    </div>
                                    <h4 id="preview-title">New Service</h4>
                                    <div id="preview-category" class="category-badge badge-secondary">Category</div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Client:</small>
                                    <div id="preview-client">-</div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Expiry:</small>
                                    <div id="preview-expiry" class="fw-bold">-</div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted">Status:</small>
                                    <div><span id="preview-status" class="badge bg-success">Active</span></div>
                                </div>
                                
                                <hr>
                                
                                <div class="mb-2">
                                    <small class="text-muted">Domain:</small>
                                    <div id="preview-domain" class="text-truncate">-</div>
                                </div>
                                
                                <div class="mb-2">
                                    <small class="text-muted">Type:</small>
                                    <div id="preview-type">-</div>
                                </div>
                                
                                <div class="mb-2">
                                    <small class="text-muted">Price:</small>
                                    <div id="preview-price">$0.00/month</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-primary" id="calculate-renewal">
                                        <i class="fas fa-calculator"></i> Calculate Renewal
                                    </button>
                                    <button type="button" class="btn btn-outline-info" id="copy-from-template">
                                        <i class="fas fa-copy"></i> Copy from Template
                                    </button>
                                    <button type="button" class="btn btn-outline-warning" id="set-reminder">
                                        <i class="fas fa-bell"></i> Set Reminder
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Common Services -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-star"></i> Popular Services</h5>
                            </div>
                            <div class="card-body">
                                <div class="list-group">
                                    <button type="button" class="list-group-item list-group-item-action service-template" 
                                            data-name=".COM Domain Registration" data-category="Domain" data-price="15" data-cycle="Annual">
                                        <div class="d-flex w-100 justify-content-between">
                                            <strong>.COM Domain</strong>
                                            <span class="badge bg-success">$15/yr</span>
                                        </div>
                                        <small>1-year registration with DNS</small>
                                    </button>
                                    
                                    <button type="button" class="list-group-item list-group-item-action service-template" 
                                            data-name="Business Web Hosting" data-category="Hosting" data-price="25" data-cycle="Monthly">
                                        <div class="d-flex w-100 justify-content-between">
                                            <strong>Business Hosting</strong>
                                            <span class="badge bg-primary">$25/mo</span>
                                        </div>
                                        <small>50GB SSD, cPanel, SSL</small>
                                    </button>
                                    
                                    <button type="button" class="list-group-item list-group-item-action service-template" 
                                            data-name="Office 365 Business" data-category="Email" data-price="12.50" data-cycle="Monthly">
                                        <div class="d-flex w-100 justify-content-between">
                                            <strong>Office 365</strong>
                                            <span class="badge bg-danger">$12.50/mo</span>
                                        </div>
                                        <small>Email, Office Apps, 1TB Storage</small>
                                    </button>
                                    
                                    <button type="button" class="list-group-item list-group-item-action service-template" 
                                            data-name="Fortinet Firewall" data-category="Security" data-price="50" data-cycle="Monthly">
                                        <div class="d-flex w-100 justify-content-between">
                                            <strong>Firewall Subscription</strong>
                                            <span class="badge bg-warning">$50/mo</span>
                                        </div>
                                        <small>UTM, VPN, 24/7 Support</small>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Expiry Alerts -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Expiry Alerts</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <h6><i class="fas fa-clock"></i> Alert Schedule</h6>
                                    <ul class="mb-0">
                                        <li>30 days before expiry</li>
                                        <li>15 days before expiry</li>
                                        <li>7 days before expiry</li>
                                        <li>1 day before expiry</li>
                                        <li>On expiry day</li>
                                    </ul>
                                </div>
                                
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-envelope"></i> Notifications</h6>
                                    <p class="mb-0">Alerts will be sent to:</p>
                                    <ul class="mb-0">
                                        <li>Service owner</li>
                                        <li>Account manager</li>
                                        <li>Technical team</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions bg-white p-3 border-top sticky-bottom">
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="<?php echo route('services.index'); ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="button" class="btn btn-outline-warning" id="save-draft">
                                <i class="fas fa-save"></i> Save Draft
                            </button>
                        </div>
                        <div>
                            <button type="reset" class="btn btn-outline-danger">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Create Service
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                width: '100%',
                placeholder: 'Select...'
            });
            
            // Initialize date pickers
            flatpickr('.datepicker', {
                dateFormat: 'Y-m-d',
                allowInput: true,
                minDate: 'today'
            });
            
            // Category to icon/color mapping
            const categoryInfo = {
                'Domain': { icon: 'fa-globe', color: '#28a745' },
                'Hosting': { icon: 'fa-server', color: '#007bff' },
                'Email': { icon: 'fa-envelope', color: '#dc3545' },
                'Security': { icon: 'fa-shield-alt', color: '#ffc107' },
                'Subscription': { icon: 'fa-sync', color: '#6f42c1' },
                'Other': { icon: 'fa-cog', color: '#6c757d' }
            };
            
            // Status to badge color mapping
            const statusColors = {
                'Active': 'success',
                'Pending': 'warning',
                'Suspended': 'danger',
                'Cancelled': 'secondary',
                'Expired': 'dark'
            };
            
            // Service category selection
            $('.category-selector').on('click', function() {
                const category = $(this).data('category');
                $('#service_category').val(category);
                $('#selected-category').text(category).removeClass().addClass(`badge badge-${category.toLowerCase()}`);
                
                // Update preview
                updatePreview();
                
                // Show/hide dynamic fields
                $('.dynamic-field').removeClass('active');
                $(`#${category.toLowerCase()}-fields`).addClass('active');
                
                // Update form based on category
                updateFormForCategory(category);
            });
            
            // Update form based on selected category
            function updateFormForCategory(category) {
                // Set default service name based on category
                if (!$('#service_name').val()) {
                    $('#service_name').val(category + ' Service');
                }
                
                // Set default pricing based on category
                const defaultPrices = {
                    'Domain': 15,
                    'Hosting': 25,
                    'Email': 12.50,
                    'Security': 50,
                    'Subscription': 10,
                    'Other': 20
                };
                
                if (!$('#monthly_price').val() || $('#monthly_price').val() == '0') {
                    $('#monthly_price').val(defaultPrices[category] || 20);
                    calculatePricing();
                }
            }
            
            // Update preview function
            function updatePreview() {
                const category = $('#service_category').val();
                const serviceName = $('#service_name').val();
                const clientSelect = $('#client_id option:selected');
                const expiryDate = $('#expiry_date').val();
                const status = $('#status').val();
                const price = $('#monthly_price').val();
                const billingCycle = $('#billing_cycle').val();
                
                // Update icon and color
                if (category && categoryInfo[category]) {
                    const info = categoryInfo[category];
                    $('#preview-icon').html(`<i class="fas ${info.icon}"></i>`);
                    $('#preview-icon').css('background', info.color);
                    $('#preview-category').text(category).removeClass().addClass(`category-badge badge-${category.toLowerCase()}`);
                }
                
                // Update title
                $('#preview-title').text(serviceName || 'New Service');
                
                // Update client
                if (clientSelect.val()) {
                    $('#preview-client').text(clientSelect.text());
                } else {
                    $('#preview-client').text('-');
                }
                
                // Update expiry
                $('#preview-expiry').text(expiryDate || '-');
                
                // Update status badge
                if (status && statusColors[status]) {
                    $('#preview-status').removeClass().addClass(`badge bg-${statusColors[status]}`).text(status);
                }
                
                // Update domain if available
                const domain = $('#domain_name').val();
                $('#preview-domain').text(domain || '-');
                
                // Update type
                let type = '-';
                if (category === 'Email') type = $('#email_type').val();
                else if (category === 'Hosting') type = $('#hosting_plan').val();
                else if (category === 'Security') type = $('#security_type').val();
                $('#preview-type').text(type);
                
                // Update price
                if (price) {
                    const cycle = billingCycle === 'Monthly' ? 'month' : 'year';
                    $('#preview-price').text(`$${parseFloat(price).toFixed(2)}/${cycle}`);
                }
                
                // Update renewal preview
                if (expiryDate) {
                    const renewalDate = calculateRenewalDate(expiryDate);
                    $('#renewal-preview').text(renewalDate);
                }
            }
            
            // Calculate renewal date (30 days before expiry)
            function calculateRenewalDate(expiryDate) {
                const date = new Date(expiryDate);
                date.setDate(date.getDate() - 30);
                return date.toISOString().split('T')[0];
            }
            
            // Calculate pricing
            function calculatePricing() {
                const monthlyPrice = parseFloat($('#monthly_price').val()) || 0;
                const billingCycle = $('#billing_cycle').val();
                
                let annualPrice = monthlyPrice * 12;
                let totalPrice = monthlyPrice;
                
                if (billingCycle === 'Annual') {
                    totalPrice = annualPrice;
                    annualPrice = monthlyPrice;
                } else if (billingCycle === 'Quarterly') {
                    totalPrice = monthlyPrice * 3;
                } else if (billingCycle === 'Biennial') {
                    totalPrice = monthlyPrice * 24;
                } else if (billingCycle === 'Triennial') {
                    totalPrice = monthlyPrice * 36;
                }
                
                $('#price-monthly').text(`$${monthlyPrice.toFixed(2)}`);
                $('#price-annual').text(`$${annualPrice.toFixed(2)}`);
                $('#price-total').text(`$${totalPrice.toFixed(2)}`);
            }
            
            // Bind change events
            $('#service_name, #client_id, #expiry_date, #status, #monthly_price, #billing_cycle, #domain_name, #email_type, #hosting_plan, #security_type').on('change input', updatePreview);
            
            // Pricing calculation
            $('#monthly_price, #billing_cycle').on('change input', calculatePricing);
            
            // Renewal date calculation
            $('#expiry_date').on('change', function() {
                if ($(this).val()) {
                    const renewalDate = calculateRenewalDate($(this).val());
                    $('#renewal_date').val(renewalDate);
                    updatePreview();
                }
            });
            
            // Service template selection
            $('.service-template').on('click', function() {
                const name = $(this).data('name');
                const category = $(this).data('category');
                const price = $(this).data('price');
                const cycle = $(this).data('cycle');
                
                // Select category
                $(`.category-selector[data-category="${category}"]`).click();
                
                // Fill form
                $('#service_name').val(name);
                $('#monthly_price').val(price);
                $('#billing_cycle').val(cycle);
                
                // Calculate pricing
                calculatePricing();
                updatePreview();
                
                // Show success message
                showToast('Template loaded', 'info');
            });
            
            // Service catalog selection
            $('#service_catalog_id').on('change', function() {
                const option = $(this).find('option:selected');
                if (option.val()) {
                    const category = option.data('category');
                    const price = option.data('price');
                    const cycle = option.data('cycle');
                    
                    // Select category
                    if (category) {
                        $(`.category-selector[data-category="${category}"]`).click();
                    }
                    
                    // Set price if not already set
                    if (price && (!$('#monthly_price').val() || $('#monthly_price').val() == '0')) {
                        $('#monthly_price').val(price);
                    }
                    
                    // Set billing cycle if not already set
                    if (cycle && !$('#billing_cycle').val()) {
                        $('#billing_cycle').val(cycle);
                    }
                    
                    calculatePricing();
                    updatePreview();
                }
            });
            
            // Calculate renewal button
            $('#calculate-renewal').on('click', function() {
                const expiryDate = $('#expiry_date').val();
                if (expiryDate) {
                    const renewalDate = calculateRenewalDate(expiryDate);
                    $('#renewal_date').val(renewalDate);
                    
                    // Calculate days until expiry
                    const today = new Date();
                    const expiry = new Date(expiryDate);
                    const diffTime = expiry - today;
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    showToast(`Renewal calculated: ${renewalDate}. ${diffDays} days until expiry.`, 'info');
                } else {
                    showToast('Please set an expiry date first', 'warning');
                }
            });
            
            // Copy from template button
            $('#copy-from-template').on('click', function() {
                const selectedTemplate = $('#service_catalog_id').val();
                if (selectedTemplate) {
                    // Already handled by change event
                    showToast('Template applied successfully', 'success');
                } else {
                    showToast('Please select a template first', 'warning');
                }
            });
            
            // Set reminder button
            $('#set-reminder').on('click', function() {
                const expiryDate = $('#expiry_date').val();
                const serviceName = $('#service_name').val();
                
                if (expiryDate && serviceName) {
                    if (confirm(`Set reminder for "${serviceName}" expiring on ${expiryDate}?`)) {
                        // Store reminder in localStorage
                        const reminder = {
                            service: serviceName,
                            expiry: expiryDate,
                            date: new Date().toISOString()
                        };
                        
                        let reminders = JSON.parse(localStorage.getItem('service_reminders') || '[]');
                        reminders.push(reminder);
                        localStorage.setItem('service_reminders', JSON.stringify(reminders));
                        
                        showToast('Reminder set successfully', 'success');
                    }
                } else {
                    showToast('Please fill in service name and expiry date', 'warning');
                }
            });
            
            // Save draft functionality
            $('#save-draft').on('click', function() {
                const formData = $('#service-form').serialize();
                localStorage.setItem('service_draft', formData);
                
                const draftInfo = {
                    timestamp: new Date().toISOString(),
                    service_name: $('#service_name').val() || 'Untitled Service',
                    client: $('#client_id option:selected').text()
                };
                localStorage.setItem('service_draft_info', JSON.stringify(draftInfo));
                
                showToast('Draft saved locally', 'info');
            });
            
            // Notes character count
            $('#notes').on('input', function() {
                const length = $(this).val().length;
                const max = 2000;
                const count = $('#notes-count');
                count.text(`${length}/${max} characters`);
                
                if (length > max * 0.9) {
                    count.removeClass('text-muted text-warning').addClass('text-danger');
                } else if (length > max * 0.75) {
                    count.removeClass('text-muted text-danger').addClass('text-warning');
                } else {
                    count.removeClass('text-warning text-danger').addClass('text-muted');
                }
            });
            
            // Form validation
            $('#service-form').on('submit', function(e) {
                let valid = true;
                const requiredFields = $('#client_id, #service_name, #expiry_date');
                
                // Clear previous errors
                $('.is-invalid').removeClass('is-invalid');
                $('.invalid-feedback').remove();
                
                // Check required fields
                requiredFields.each(function() {
                    if (!$(this).val()) {
                        $(this).addClass('is-invalid');
                        $(this).after(`<div class="invalid-feedback d-block">This field is required</div>`);
                        valid = false;
                    }
                });
                
                // Check category is selected
                if (!$('#service_category').val()) {
                    showToast('Please select a service category', 'error');
                    valid = false;
                }
                
                if (!valid) {
                    e.preventDefault();
                    // Scroll to first error
                    $('html, body').animate({
                        scrollTop: $('.is-invalid').first().offset().top - 100
                    }, 500);
                    
                    showToast('Please fill in all required fields', 'error');
                }
            });
            
            // Load draft on page load
            const draft = localStorage.getItem('service_draft');
            const draftInfo = JSON.parse(localStorage.getItem('service_draft_info') || 'null');
            
            if (draft && draftInfo) {
                const loadDraft = confirm(`You have a saved draft from ${new Date(draftInfo.timestamp).toLocaleString()} for "${draftInfo.service_name}". Load it?`);
                if (loadDraft) {
                    const params = new URLSearchParams(draft);
                    params.forEach((value, key) => {
                        const field = $(`[name="${key}"]`);
                        if (field.length) {
                            if (field.is('select')) {
                                field.val(value).trigger('change');
                            } else if (field.is(':checkbox')) {
                                field.prop('checked', value === '1');
                            } else {
                                field.val(value).trigger('input');
                            }
                        }
                    });
                    
                    // Trigger category selection if set
                    const category = $('#service_category').val();
                    if (category) {
                        $(`.category-selector[data-category="${category}"]`).click();
                    }
                    
                    updatePreview();
                    calculatePricing();
                    showToast('Draft loaded successfully', 'success');
                }
            }
            
            // Toast notification function
            function showToast(message, type = 'info') {
                const typeClass = {
                    'success': 'bg-success',
                    'error': 'bg-danger',
                    'warning': 'bg-warning',
                    'info': 'bg-info'
                }[type] || 'bg-info';
                
                const icon = {
                    'success': 'fa-check-circle',
                    'error': 'fa-exclamation-circle',
                    'warning': 'fa-exclamation-triangle',
                    'info': 'fa-info-circle'
                }[type] || 'fa-info-circle';
                
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header ${typeClass} text-white">
                                <i class="fas ${icon} me-2"></i>
                                <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                ${message}
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 5000);
            }
            
            // Initial calculations
            calculatePricing();
            
            // Auto-select "Other" category by default
            $('.category-selector[data-category="Other"]').click();
        });
    </script>
</body>
</html>