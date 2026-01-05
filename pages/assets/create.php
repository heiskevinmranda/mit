<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to create assets.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get clients for dropdown
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$staff = $pdo->query("SELECT id, staff_id, full_name, designation FROM staff_profiles WHERE employment_status = 'Active' ORDER BY full_name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    $required_fields = ['asset_type', 'manufacturer', 'model'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[$field] = "This field is required";
        }
    }
    
    // Validate dates
    $date_fields = ['purchase_date', 'warranty_expiry', 'amc_expiry', 'license_expiry'];
    foreach ($date_fields as $field) {
        if (!empty($_POST[$field]) && !strtotime($_POST[$field])) {
            $errors[$field] = "Invalid date format";
        }
    }
    
    // Validate IP address if provided
    if (!empty($_POST['ip_address']) && !filter_var($_POST['ip_address'], FILTER_VALIDATE_IP)) {
        $errors['ip_address'] = "Invalid IP address";
    }
    
    // Validate MAC address if provided
    if (!empty($_POST['mac_address']) && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $_POST['mac_address'])) {
        $errors['mac_address'] = "Invalid MAC address format (use 00:1A:2B:3C:4D:5E or 00-1A-2B-3C-4D-5E)";
    }
    
    // Check if serial number already exists
    if (!empty($_POST['serial_number'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE serial_number = ?");
        $stmt->execute([$_POST['serial_number']]);
        if ($stmt->fetchColumn() > 0) {
            $errors['serial_number'] = "An asset with this serial number already exists";
        }
    }
    
    // Check if asset tag already exists
    if (!empty($_POST['asset_tag'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE asset_tag = ?");
        $stmt->execute([$_POST['asset_tag']]);
        if ($stmt->fetchColumn() > 0) {
            $errors['asset_tag'] = "An asset with this asset tag already exists";
        }
    }
    
    // If no errors, insert the asset
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $sql = "INSERT INTO assets (
                client_id, location_id, asset_type, manufacturer, model, 
                serial_number, asset_tag, ip_address, mac_address, 
                purchase_date, warranty_expiry, amc_expiry, license_expiry, 
                status, assigned_to, notes, created_at, updated_at
            ) VALUES (
                :client_id, :location_id, :asset_type, :manufacturer, :model,
                :serial_number, :asset_tag, :ip_address, :mac_address,
                :purchase_date, :warranty_expiry, :amc_expiry, :license_expiry,
                :status, :assigned_to, :notes, NOW(), NOW()
            )";
            
            $stmt = $pdo->prepare($sql);
            
            $params = [
                ':client_id' => !empty($_POST['client_id']) ? $_POST['client_id'] : null,
                ':location_id' => !empty($_POST['location_id']) ? $_POST['location_id'] : null,
                ':asset_type' => $_POST['asset_type'],
                ':manufacturer' => $_POST['manufacturer'],
                ':model' => $_POST['model'],
                ':serial_number' => !empty($_POST['serial_number']) ? $_POST['serial_number'] : null,
                ':asset_tag' => !empty($_POST['asset_tag']) ? $_POST['asset_tag'] : null,
                ':ip_address' => !empty($_POST['ip_address']) ? $_POST['ip_address'] : null,
                ':mac_address' => !empty($_POST['mac_address']) ? $_POST['mac_address'] : null,
                ':purchase_date' => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null,
                ':warranty_expiry' => !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null,
                ':amc_expiry' => !empty($_POST['amc_expiry']) ? $_POST['amc_expiry'] : null,
                ':license_expiry' => !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null,
                ':status' => $_POST['status'] ?? 'Active',
                ':assigned_to' => !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null,
                ':notes' => !empty($_POST['notes']) ? $_POST['notes'] : null,
            ];
            
            $stmt->execute($params);
            $asset_id = $pdo->lastInsertId();
            
            // Create audit log
            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                          VALUES (?, 'CREATE', 'ASSET', ?, ?, ?, NOW())";
            $audit_stmt = $pdo->prepare($audit_sql);
            $audit_details = json_encode([
                'asset_type' => $_POST['asset_type'],
                'manufacturer' => $_POST['manufacturer'],
                'model' => $_POST['model'],
                'serial_number' => $_POST['serial_number'] ?? null,
                'created_by' => $current_user['id']
            ]);
            $audit_stmt->execute([
                $current_user['id'],
                $asset_id,
                $audit_details,
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Asset created successfully!'
            ];
            
            // Redirect to view page or index
            header('Location: view.php?id=' . urlencode($asset_id));
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['database'] = "An error occurred while saving the asset: " . $e->getMessage();
        }
    }
}

// Get asset types from existing assets for suggestions
$asset_types = $pdo->query("SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL ORDER BY asset_type")->fetchAll(PDO::FETCH_COLUMN);
$manufacturers = $pdo->query("SELECT DISTINCT manufacturer FROM assets WHERE manufacturer IS NOT NULL ORDER BY manufacturer")->fetchAll(PDO::FETCH_COLUMN);
$models = $pdo->query("SELECT DISTINCT model FROM assets WHERE model IS NOT NULL ORDER BY model")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Asset | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #eaeaea;
        }
        .form-section h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.2rem;
        }
        .required:after {
            content: " *";
            color: #dc3545;
        }
        .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        .asset-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 2px dashed #dee2e6;
        }
        .preview-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-actions {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 15px 0;
            border-top: 1px solid #dee2e6;
            margin-top: 30px;
        }
        .asset-type-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.5rem;
        }
        .suggestion-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
        }
        .suggestion-item:hover {
            background: #f8f9fa;
        }
        .suggestion-item:last-child {
            border-bottom: none;
        }
        .character-count {
            font-size: 0.8rem;
            color: #6c757d;
            text-align: right;
        }
        .character-count.warning {
            color: #ffc107;
        }
        .character-count.danger {
            color: #dc3545;
        }
        .field-group {
            margin-bottom: 20px;
        }
        .field-group label {
            font-weight: 500;
            margin-bottom: 5px;
            display: block;
        }
        @media (max-width: 768px) {
            .form-section {
                padding: 15px;
            }
            .col-md-6 {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-plus-circle"></i> Add New Asset</h1>
                    <p class="text-muted">Register new IT infrastructure or hardware asset</p>
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
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-server"></i> Assets</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Add New Asset</li>
                </ol>
            </nav>
            
            <?php if (isset($errors['database'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($errors['database']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Quick Add Options -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-bolt text-warning"></i> Quick Templates</h5>
                            <p class="card-text">Use predefined templates for common assets:</p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm template-btn" data-type="Firewall" data-manufacturer="Fortinet" data-model="FortiGate 60F">
                                    <i class="fas fa-shield-alt"></i> Firewall Template
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm template-btn" data-type="Switch" data-manufacturer="Cisco" data-model="Catalyst 2960">
                                    <i class="fas fa-network-wired"></i> Switch Template
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm template-btn" data-type="Server" data-manufacturer="Dell" data-model="PowerEdge R740">
                                    <i class="fas fa-server"></i> Server Template
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm template-btn" data-type="CCTV" data-manufacturer="Hikvision" data-model="DS-2CD2143G0">
                                    <i class="fas fa-video"></i> CCTV Template
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="asset-preview">
                        <div class="preview-card">
                            <div class="d-flex align-items-center mb-3">
                                <div class="asset-type-icon" id="preview-icon" style="background: #004E89; color: white;">
                                    <i class="fas fa-hdd"></i>
                                </div>
                                <div>
                                    <h4 id="preview-title">New Asset</h4>
                                    <span class="badge bg-success" id="preview-status">Active</span>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted">Manufacturer:</small>
                                    <div id="preview-manufacturer">-</div>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Model:</small>
                                    <div id="preview-model">-</div>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">Serial:</small>
                                    <div id="preview-serial">-</div>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">Asset Tag:</small>
                                    <div id="preview-tag">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Asset Form -->
            <form method="POST" id="asset-form" novalidate>
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                            <div class="row">
                                <div class="col-md-6 field-group">
                                    <label for="asset_type" class="required">Asset Type</label>
                                    <select class="form-select <?php echo isset($errors['asset_type']) ? 'is-invalid' : ''; ?>" 
                                            id="asset_type" name="asset_type" required>
                                        <option value="">Select asset type...</option>
                                        <option value="Firewall" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Firewall' ? 'selected' : ''; ?>>Firewall</option>
                                        <option value="Switch" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Switch' ? 'selected' : ''; ?>>Switch</option>
                                        <option value="Server" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Server' ? 'selected' : ''; ?>>Server</option>
                                        <option value="CCTV" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'CCTV' ? 'selected' : ''; ?>>CCTV Camera</option>
                                        <option value="Biometric" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Biometric' ? 'selected' : ''; ?>>Biometric Device</option>
                                        <option value="Gate Automation" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Gate Automation' ? 'selected' : ''; ?>>Gate Automation</option>
                                        <option value="Router" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Router' ? 'selected' : ''; ?>>Router</option>
                                        <option value="Access Point" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Access Point' ? 'selected' : ''; ?>>Access Point</option>
                                        <option value="Desktop" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Desktop' ? 'selected' : ''; ?>>Desktop Computer</option>
                                        <option value="Laptop" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Laptop' ? 'selected' : ''; ?>>Laptop</option>
                                        <option value="Printer" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Printer' ? 'selected' : ''; ?>>Printer</option>
                                        <option value="Scanner" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Scanner' ? 'selected' : ''; ?>>Scanner</option>
                                        <option value="Phone" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Phone' ? 'selected' : ''; ?>>Phone</option>
                                        <option value="Tablet" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Tablet' ? 'selected' : ''; ?>>Tablet</option>
                                        <option value="Mobile" <?php echo isset($_POST['asset_type']) && $_POST['asset_type'] == 'Mobile' ? 'selected' : ''; ?>>Mobile Device</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <?php if (isset($errors['asset_type'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['asset_type']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Select the type of hardware or infrastructure</div>
                                    
                                    <!-- Suggestions -->
                                    <?php if (!empty($asset_types)): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Common types:</small>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <?php foreach ($asset_types as $type): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary suggestion-btn" 
                                                    data-field="asset_type" data-value="<?php echo htmlspecialchars($type); ?>">
                                                <?php echo htmlspecialchars($type); ?>
                                            </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="status" class="required">Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Active" <?php echo isset($_POST['status']) && $_POST['status'] == 'Active' ? 'selected' : 'selected'; ?>>Active</option>
                                        <option value="Inactive" <?php echo isset($_POST['status']) && $_POST['status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Under Maintenance" <?php echo isset($_POST['status']) && $_POST['status'] == 'Under Maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                                        <option value="Retired" <?php echo isset($_POST['status']) && $_POST['status'] == 'Retired' ? 'selected' : ''; ?>>Retired</option>
                                        <option value="Spare" <?php echo isset($_POST['status']) && $_POST['status'] == 'Spare' ? 'selected' : ''; ?>>Spare</option>
                                    </select>
                                    <div class="help-text">Current operational status of the asset</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="manufacturer" class="required">Manufacturer</label>
                                    <input type="text" class="form-control <?php echo isset($errors['manufacturer']) ? 'is-invalid' : ''; ?>" 
                                           id="manufacturer" name="manufacturer" 
                                           value="<?php echo htmlspecialchars($_POST['manufacturer'] ?? ''); ?>" 
                                           required
                                           list="manufacturer-suggestions">
                                    <datalist id="manufacturer-suggestions">
                                        <?php foreach ($manufacturers as $mfg): ?>
                                        <option value="<?php echo htmlspecialchars($mfg); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                    <?php if (isset($errors['manufacturer'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['manufacturer']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="model" class="required">Model</label>
                                    <input type="text" class="form-control <?php echo isset($errors['model']) ? 'is-invalid' : ''; ?>" 
                                           id="model" name="model" 
                                           value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>" 
                                           required
                                           list="model-suggestions">
                                    <datalist id="model-suggestions">
                                        <?php foreach ($models as $model): ?>
                                        <option value="<?php echo htmlspecialchars($model); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                    <?php if (isset($errors['model'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['model']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="serial_number">Serial Number</label>
                                    <input type="text" class="form-control <?php echo isset($errors['serial_number']) ? 'is-invalid' : ''; ?>" 
                                           id="serial_number" name="serial_number" 
                                           value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>">
                                    <?php if (isset($errors['serial_number'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['serial_number']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Unique serial number (case-sensitive)</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="asset_tag">Asset Tag</label>
                                    <input type="text" class="form-control <?php echo isset($errors['asset_tag']) ? 'is-invalid' : ''; ?>" 
                                           id="asset_tag" name="asset_tag" 
                                           value="<?php echo htmlspecialchars($_POST['asset_tag'] ?? ''); ?>">
                                    <?php if (isset($errors['asset_tag'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['asset_tag']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Company asset tag (e.g., MSP-IT-001)</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Network Information -->
                        <div class="form-section">
                            <h3><i class="fas fa-network-wired"></i> Network Information</h3>
                            <div class="row">
                                <div class="col-md-6 field-group">
                                    <label for="ip_address">IP Address</label>
                                    <input type="text" class="form-control <?php echo isset($errors['ip_address']) ? 'is-invalid' : ''; ?>" 
                                           id="ip_address" name="ip_address" 
                                           value="<?php echo htmlspecialchars($_POST['ip_address'] ?? ''); ?>"
                                           placeholder="192.168.1.1">
                                    <?php if (isset($errors['ip_address'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['ip_address']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="mac_address">MAC Address</label>
                                    <input type="text" class="form-control <?php echo isset($errors['mac_address']) ? 'is-invalid' : ''; ?>" 
                                           id="mac_address" name="mac_address" 
                                           value="<?php echo htmlspecialchars($_POST['mac_address'] ?? ''); ?>"
                                           placeholder="00:1A:2B:3C:4D:5E">
                                    <?php if (isset($errors['mac_address'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['mac_address']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Format: 00:1A:2B:3C:4D:5E or 00-1A-2B-3C-4D-5E</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Assignment & Location -->
                        <div class="form-section">
                            <h3><i class="fas fa-map-marker-alt"></i> Assignment & Location</h3>
                            <div class="row">
                                <div class="col-md-6 field-group">
                                    <label for="client_id">Client</label>
                                    <select class="form-select select2" id="client_id" name="client_id">
                                        <option value="">Select client...</option>
                                        <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo htmlspecialchars($client['id']); ?>"
                                            <?php echo isset($_POST['client_id']) && $_POST['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">Optional - leave blank for internal assets</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="location_id">Location</label>
                                    <select class="form-select select2" id="location_id" name="location_id">
                                        <option value="">Select location...</option>
                                        <!-- Locations will be populated via JavaScript based on client selection -->
                                    </select>
                                    <div class="help-text">Client location (populated after selecting client)</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="assigned_to">Assigned To</label>
                                    <select class="form-select select2" id="assigned_to" name="assigned_to">
                                        <option value="">Select staff member...</option>
                                        <?php foreach ($staff as $person): ?>
                                        <option value="<?php echo htmlspecialchars($person['id']); ?>"
                                            <?php echo isset($_POST['assigned_to']) && $_POST['assigned_to'] == $person['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($person['full_name']); ?> (<?php echo htmlspecialchars($person['designation']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text">Staff member responsible for this asset</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dates & Expiry -->
                        <div class="form-section">
                            <h3><i class="fas fa-calendar-alt"></i> Dates & Expiry Information</h3>
                            <div class="row">
                                <div class="col-md-6 field-group">
                                    <label for="purchase_date">Purchase Date</label>
                                    <input type="text" class="form-control datepicker <?php echo isset($errors['purchase_date']) ? 'is-invalid' : ''; ?>" 
                                           id="purchase_date" name="purchase_date" 
                                           value="<?php echo htmlspecialchars($_POST['purchase_date'] ?? ''); ?>">
                                    <?php if (isset($errors['purchase_date'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['purchase_date']); ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="warranty_expiry">Warranty Expiry</label>
                                    <input type="text" class="form-control datepicker <?php echo isset($errors['warranty_expiry']) ? 'is-invalid' : ''; ?>" 
                                           id="warranty_expiry" name="warranty_expiry" 
                                           value="<?php echo htmlspecialchars($_POST['warranty_expiry'] ?? ''); ?>">
                                    <?php if (isset($errors['warranty_expiry'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['warranty_expiry']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Hardware warranty expiration date</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="amc_expiry">AMC Expiry</label>
                                    <input type="text" class="form-control datepicker <?php echo isset($errors['amc_expiry']) ? 'is-invalid' : ''; ?>" 
                                           id="amc_expiry" name="amc_expiry" 
                                           value="<?php echo htmlspecialchars($_POST['amc_expiry'] ?? ''); ?>">
                                    <?php if (isset($errors['amc_expiry'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['amc_expiry']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Annual Maintenance Contract expiration date</div>
                                </div>
                                
                                <div class="col-md-6 field-group">
                                    <label for="license_expiry">License Expiry</label>
                                    <input type="text" class="form-control datepicker <?php echo isset($errors['license_expiry']) ? 'is-invalid' : ''; ?>" 
                                           id="license_expiry" name="license_expiry" 
                                           value="<?php echo htmlspecialchars($_POST['license_expiry'] ?? ''); ?>">
                                    <?php if (isset($errors['license_expiry'])): ?>
                                    <div class="error-message"><?php echo htmlspecialchars($errors['license_expiry']); ?></div>
                                    <?php endif; ?>
                                    <div class="help-text">Software license expiration date</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notes & Additional Information -->
                        <div class="form-section">
                            <h3><i class="fas fa-sticky-note"></i> Notes & Additional Information</h3>
                            <div class="field-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Enter any additional information about this asset..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                <div class="character-count" id="notes-count">0/2000 characters</div>
                                <div class="help-text">Configuration details, special instructions, maintenance history, etc.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <!-- Quick Tips -->
                        <div class="form-section">
                            <h3><i class="fas fa-lightbulb"></i> Quick Tips</h3>
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Required Fields</h6>
                                <p class="mb-2">Fields marked with <span class="text-danger">*</span> are required.</p>
                                
                                <h6 class="mt-3"><i class="fas fa-qrcode"></i> Asset Tag Best Practices</h6>
                                <ul class="mb-2">
                                    <li>Use consistent naming: MSP-IT-001</li>
                                    <li>Include location code: NY-SRV-01</li>
                                    <li>Make it scannable for inventory</li>
                                </ul>
                                
                                <h6 class="mt-3"><i class="fas fa-exclamation-triangle"></i> Important</h6>
                                <p class="mb-0">Serial numbers must be unique. The system will check for duplicates.</p>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-clock"></i> Expiry Alerts</h6>
                                <p class="mb-0">Assets with warranty/AMC/license expiring within 30 days will be flagged in the dashboard.</p>
                            </div>
                        </div>
                        
                        <!-- Asset Icon Preview -->
                        <div class="form-section">
                            <h3><i class="fas fa-palette"></i> Asset Preview</h3>
                            <div class="text-center">
                                <div class="asset-type-icon mx-auto mb-3" id="full-preview-icon" 
                                     style="width: 80px; height: 80px; font-size: 2rem; background: #004E89; color: white;">
                                    <i class="fas fa-hdd"></i>
                                </div>
                                <h5 id="full-preview-title">New Asset</h5>
                                <div class="text-muted mb-3">
                                    <div>Type: <span id="full-preview-type">-</span></div>
                                    <div>Status: <span id="full-preview-status" class="badge bg-success">Active</span></div>
                                </div>
                                <hr>
                                <small class="text-muted">This preview updates as you fill the form</small>
                            </div>
                        </div>
                        
                        <!-- Recent Assets -->
                        <div class="form-section">
                            <h3><i class="fas fa-history"></i> Recent Assets</h3>
                            <?php
                            $recent_assets = $pdo->query("SELECT asset_type, manufacturer, model, serial_number, created_at 
                                                           FROM assets ORDER BY created_at DESC LIMIT 5")->fetchAll();
                            if (!empty($recent_assets)):
                            ?>
                            <div class="list-group">
                                <?php foreach ($recent_assets as $asset): ?>
                                <div class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($asset['asset_type']); ?></h6>
                                        <small><?php echo date('M d', strtotime($asset['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <?php echo htmlspecialchars($asset['manufacturer']); ?> 
                                        <?php echo htmlspecialchars($asset['model']); ?>
                                    </p>
                                    <small class="text-muted">Serial: <?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-3 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p>No assets found</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="button" class="btn btn-outline-warning" id="save-draft">
                                <i class="fas fa-save"></i> Save Draft
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-outline-info" id="preview-btn">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Create Asset
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
                maxDate: 'today'
            });
            
            // Asset type to icon/color mapping
            const assetIcons = {
                'Firewall': { icon: 'fa-shield-alt', color: '#dc3545' },
                'Switch': { icon: 'fa-network-wired', color: '#007bff' },
                'Server': { icon: 'fa-server', color: '#28a745' },
                'CCTV': { icon: 'fa-video', color: '#17a2b8' },
                'Biometric': { icon: 'fa-fingerprint', color: '#6610f2' },
                'Gate Automation': { icon: 'fa-door-open', color: '#fd7e14' },
                'Router': { icon: 'fa-wifi', color: '#20c997' },
                'Access Point': { icon: 'fa-wifi', color: '#e83e8c' },
                'Desktop': { icon: 'fa-desktop', color: '#6f42c1' },
                'Laptop': { icon: 'fa-laptop', color: '#20c997' },
                'Printer': { icon: 'fa-print', color: '#6c757d' },
                'Scanner': { icon: 'fa-scanner', color: '#ffc107' },
                'Phone': { icon: 'fa-phone', color: '#004E89' },
                'Tablet': { icon: 'fa-tablet-alt', color: '#FF6B35' },
                'Mobile': { icon: 'fa-mobile-alt', color: '#28a745' }
            };
            
            // Status to badge color mapping
            const statusColors = {
                'Active': 'success',
                'Inactive': 'danger',
                'Under Maintenance': 'warning',
                'Retired': 'secondary',
                'Spare': 'info'
            };
            
            // Update preview on form changes
            function updatePreview() {
                const assetType = $('#asset_type').val();
                const manufacturer = $('#manufacturer').val();
                const model = $('#model').val();
                const serial = $('#serial_number').val();
                const tag = $('#asset_tag').val();
                const status = $('#status').val();
                
                // Update icon and color
                if (assetType && assetIcons[assetType]) {
                    const assetInfo = assetIcons[assetType];
                    $('#preview-icon').html(`<i class="fas ${assetInfo.icon}"></i>`);
                    $('#preview-icon').css('background', assetInfo.color);
                    $('#full-preview-icon').html(`<i class="fas ${assetInfo.icon}"></i>`);
                    $('#full-preview-icon').css('background', assetInfo.color);
                    $('#preview-title').text(assetType);
                    $('#full-preview-title').text(assetType);
                } else {
                    $('#preview-icon').html('<i class="fas fa-hdd"></i>');
                    $('#preview-icon').css('background', '#004E89');
                    $('#full-preview-icon').html('<i class="fas fa-hdd"></i>');
                    $('#full-preview-icon').css('background', '#004E89');
                    $('#preview-title').text(assetType || 'New Asset');
                    $('#full-preview-title').text(assetType || 'New Asset');
                }
                
                // Update status badge
                if (status && statusColors[status]) {
                    const badgeClass = `badge bg-${statusColors[status]}`;
                    $('#preview-status').removeClass().addClass(badgeClass).text(status);
                    $('#full-preview-status').removeClass().addClass(badgeClass).text(status);
                }
                
                // Update details
                $('#preview-manufacturer').text(manufacturer || '-');
                $('#preview-model').text(model || '-');
                $('#preview-serial').text(serial || '-');
                $('#preview-tag').text(tag || '-');
                $('#full-preview-type').text(assetType || '-');
            }
            
            // Bind change events
            $('#asset_type, #manufacturer, #model, #serial_number, #asset_tag, #status').on('change input', updatePreview);
            
            // Initial preview update
            updatePreview();
            
            // Template buttons
            $('.template-btn').on('click', function() {
                $('#asset_type').val($(this).data('type')).trigger('change');
                $('#manufacturer').val($(this).data('manufacturer')).trigger('input');
                $('#model').val($(this).data('model')).trigger('input');
                
                // Show success message
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header bg-success text-white">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong class="me-auto">Template Applied</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                ${$(this).data('type')} template loaded. Fill in the remaining details.
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 3000);
            });
            
            // Suggestion buttons
            $('.suggestion-btn').on('click', function() {
                const field = $(this).data('field');
                const value = $(this).data('value');
                $('#' + field).val(value).trigger('change');
            });
            
            // Notes character count
            $('#notes').on('input', function() {
                const length = $(this).val().length;
                const max = 2000;
                const count = $('#notes-count');
                count.text(`${length}/${max} characters`);
                
                if (length > max * 0.9) {
                    count.removeClass('warning danger').addClass('danger');
                } else if (length > max * 0.75) {
                    count.removeClass('warning danger').addClass('warning');
                } else {
                    count.removeClass('warning danger');
                }
            });
            
            // Load locations based on client selection
            $('#client_id').on('change', function() {
                const clientId = $(this).val();
                const locationSelect = $('#location_id');
                
                if (clientId) {
                    $.ajax({
                        url: '../../api/get_locations.php',
                        method: 'GET',
                        data: { client_id: clientId },
                        success: function(data) {
                            locationSelect.empty().append('<option value="">Select location...</option>');
                            if (data.locations && data.locations.length > 0) {
                                data.locations.forEach(function(location) {
                                    locationSelect.append(
                                        `<option value="${location.id}">${location.location_name} (${location.city})</option>`
                                    );
                                });
                            }
                            locationSelect.trigger('change');
                        }
                    });
                } else {
                    locationSelect.empty().append('<option value="">Select location...</option>').trigger('change');
                }
            });
            
            // Save draft functionality
            $('#save-draft').on('click', function() {
                const formData = $('#asset-form').serialize();
                localStorage.setItem('asset_draft', formData);
                
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header bg-info text-white">
                                <i class="fas fa-save me-2"></i>
                                <strong class="me-auto">Draft Saved</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                Asset draft saved locally. It will be available for 7 days.
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 3000);
            });
            
            // Preview button
            $('#preview-btn').on('click', function() {
                // Validate required fields first
                const requiredFields = $('#asset_type, #manufacturer, #model');
                let valid = true;
                
                requiredFields.each(function() {
                    if (!$(this).val()) {
                        $(this).addClass('is-invalid');
                        valid = false;
                    }
                });
                
                if (valid) {
                    const formData = $('#asset-form').serialize();
                    localStorage.setItem('asset_preview', formData);
                    window.open('preview.php', '_blank');
                } else {
                    const toast = $(`
                        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                            <div class="toast show" role="alert">
                                <div class="toast-header bg-danger text-white">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong class="me-auto">Validation Error</strong>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                                </div>
                                <div class="toast-body">
                                    Please fill in all required fields before previewing.
                                </div>
                            </div>
                        </div>
                    `);
                    $('body').append(toast);
                    setTimeout(() => toast.remove(), 3000);
                }
            });
            
            // Load draft on page load if exists
            const draft = localStorage.getItem('asset_draft');
            if (draft) {
                if (confirm('You have a saved draft. Would you like to load it?')) {
                    const params = new URLSearchParams(draft);
                    params.forEach((value, key) => {
                        const field = $(`[name="${key}"]`);
                        if (field.length) {
                            if (field.is('select')) {
                                field.val(value).trigger('change');
                            } else {
                                field.val(value).trigger('input');
                            }
                        }
                    });
                    updatePreview();
                }
            }
            
            // Auto-generate asset tag
            $('#manufacturer, #model').on('change', function() {
                if (!$('#asset_tag').val()) {
                    const mfg = $('#manufacturer').val().substring(0, 3).toUpperCase();
                    const model = $('#model').val().replace(/\s+/g, '').substring(0, 4).toUpperCase();
                    if (mfg && model) {
                        $.ajax({
                            url: '../../api/get_next_asset_number.php',
                            method: 'GET',
                            data: { prefix: `${mfg}-${model}-` },
                            success: function(data) {
                                if (data.next_number) {
                                    $('#asset_tag').val(data.next_number);
                                }
                            }
                        });
                    }
                }
            });
            
            // Form validation
            $('#asset-form').on('submit', function(e) {
                let valid = true;
                const requiredFields = $('#asset_type, #manufacturer, #model');
                
                // Clear previous errors
                $('.is-invalid').removeClass('is-invalid');
                $('.error-message').remove();
                
                // Check required fields
                requiredFields.each(function() {
                    if (!$(this).val()) {
                        $(this).addClass('is-invalid');
                        $(this).after(`<div class="error-message">This field is required</div>`);
                        valid = false;
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    // Scroll to first error
                    $('html, body').animate({
                        scrollTop: $('.is-invalid').first().offset().top - 100
                    }, 500);
                    
                    // Show error toast
                    const toast = $(`
                        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                            <div class="toast show" role="alert">
                                <div class="toast-header bg-danger text-white">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong class="me-auto">Validation Error</strong>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                                </div>
                                <div class="toast-body">
                                    Please fill in all required fields marked with *.
                                </div>
                            </div>
                        </div>
                    `);
                    $('body').append(toast);
                    setTimeout(() => toast.remove(), 5000);
                }
            });
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl+S to save draft
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    $('#save-draft').click();
                }
                // Ctrl+P to preview
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    $('#preview-btn').click();
                }
                // Ctrl+Enter to submit
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    $('#asset-form').submit();
                }
            });
        });
    </script>
</body>
</html>