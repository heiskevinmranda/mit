<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission - only admin and managers can import
if (!hasPermission('admin') && !hasPermission('manager')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to import assets.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Define required fields
$required_fields = ['asset_type', 'manufacturer', 'model'];
$optional_fields = [
    'serial_number', 'asset_tag', 'ip_address', 'mac_address',
    'purchase_date', 'warranty_expiry', 'amc_expiry', 'license_expiry',
    'status', 'client_id', 'location_id', 'assigned_to', 'notes'
];

// Get mapping of column names to database fields
$field_mapping = [
    // Basic Information
    'Asset Type' => 'asset_type',
    'Type' => 'asset_type',
    'Device Type' => 'asset_type',
    'Manufacturer' => 'manufacturer',
    'Vendor' => 'manufacturer',
    'Brand' => 'manufacturer',
    'Model' => 'model',
    'Model Number' => 'model',
    'Serial' => 'serial_number',
    'Serial Number' => 'serial_number',
    'SN' => 'serial_number',
    'Asset Tag' => 'asset_tag',
    'Tag' => 'asset_tag',
    'Asset ID' => 'asset_tag',
    'Inventory Number' => 'asset_tag',
    
    // Network Information
    'IP Address' => 'ip_address',
    'IP' => 'ip_address',
    'MAC Address' => 'mac_address',
    'MAC' => 'mac_address',
    'Physical Address' => 'mac_address',
    
    // Dates
    'Purchase Date' => 'purchase_date',
    'Acquisition Date' => 'purchase_date',
    'Install Date' => 'purchase_date',
    'Warranty Expiry' => 'warranty_expiry',
    'Warranty End' => 'warranty_expiry',
    'AMC Expiry' => 'amc_expiry',
    'AMC End' => 'amc_expiry',
    'Maintenance Expiry' => 'amc_expiry',
    'License Expiry' => 'license_expiry',
    'License End' => 'license_expiry',
    
    // Status & Assignment
    'Status' => 'status',
    'Condition' => 'status',
    'Client' => 'client_id',
    'Customer' => 'client_id',
    'Location' => 'location_id',
    'Site' => 'location_id',
    'Assigned To' => 'assigned_to',
    'Owner' => 'assigned_to',
    'Responsible' => 'assigned_to',
    
    // Notes
    'Notes' => 'notes',
    'Description' => 'notes',
    'Comments' => 'notes',
    'Remarks' => 'notes'
];

// Get dropdown options for validation
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$client_map = [];
foreach ($clients as $client) {
    $client_map[$client['company_name']] = $client['id'];
}

// FIXED: Using official_email instead of email
$staff = $pdo->query("SELECT id, staff_id, full_name, official_email FROM staff_profiles WHERE employment_status = 'Active' ORDER BY full_name")->fetchAll();
$staff_map = [];
foreach ($staff as $person) {
    $staff_map[$person['full_name']] = $person['id'];
    if (!empty($person['official_email'])) {
        $staff_map[$person['official_email']] = $person['id'];
    }
    $staff_map[$person['staff_id']] = $person['id'];
}

$locations = $pdo->query("SELECT id, location_name, client_id FROM client_locations ORDER BY location_name")->fetchAll();
$location_map = [];
foreach ($locations as $location) {
    $location_map[$location['location_name']] = $location['id'];
}

// Process file upload
$errors = [];
$import_data = [];
$mapped_columns = [];
$total_rows = 0;
$successful_rows = 0;
$duplicate_rows = 0;
$skipped_rows = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['file'] = "File upload failed with error code: " . $file['error'];
    } elseif (!in_array($file['type'], ['text/csv', 'application/vnd.ms-excel', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])) {
        $errors['file'] = "Invalid file type. Please upload CSV or Excel files only.";
    } elseif ($file['size'] > 10485760) { // 10MB limit
        $errors['file'] = "File size too large. Maximum 10MB allowed.";
    }
    
    if (empty($errors)) {
        $file_path = $file['tmp_name'];
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        // Parse CSV file
        if ($file_extension === 'csv' || $file['type'] === 'text/csv') {
            $handle = fopen($file_path, 'r');
            if ($handle !== false) {
                // Detect delimiter
                $first_line = fgets($handle);
                rewind($handle);
                
                $delimiter = ',';
                if (strpos($first_line, ';') !== false && substr_count($first_line, ';') > substr_count($first_line, ',')) {
                    $delimiter = ';';
                } elseif (strpos($first_line, "\t") !== false) {
                    $delimiter = "\t";
                }
                
                // Read headers
                $headers = fgetcsv($handle, 0, $delimiter);
                if ($headers === false) {
                    $errors['file'] = "Failed to read CSV headers.";
                } else {
                    // Clean headers
                    $headers = array_map('trim', $headers);
                    $headers = array_map(function($h) {
                        return preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $h);
                    }, $headers);
                    
                    // Auto-map columns
                    $mapped_columns = [];
                    foreach ($headers as $index => $header) {
                        $normalized_header = strtolower(trim($header));
                        foreach ($field_mapping as $map_key => $db_field) {
                            if (strtolower($map_key) === $normalized_header) {
                                $mapped_columns[$index] = $db_field;
                                break;
                            }
                        }
                        if (!isset($mapped_columns[$index])) {
                            $mapped_columns[$index] = null; // Unmapped column
                        }
                    }
                    
                    // Check for required fields
                    $missing_required = [];
                    foreach ($required_fields as $field) {
                        if (!in_array($field, $mapped_columns)) {
                            $missing_required[] = $field;
                        }
                    }
                    
                    if (!empty($missing_required)) {
                        $errors['mapping'] = "Required columns missing: " . implode(', ', $missing_required);
                    } else {
                        // Read data rows
                        $row_number = 1;
                        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $row_number++;
                            $row_data = [];
                            
                            // Skip empty rows
                            if (empty(array_filter($row))) {
                                continue;
                            }
                            
                            // Map columns to fields
                            foreach ($row as $index => $value) {
                                $value = trim($value);
                                if (isset($mapped_columns[$index]) && $mapped_columns[$index] !== null) {
                                    $field = $mapped_columns[$index];
                                    $row_data[$field] = $value;
                                }
                            }
                            
                            // Validate row has required fields
                            $row_valid = true;
                            foreach ($required_fields as $field) {
                                if (empty($row_data[$field])) {
                                    $errors["row_{$row_number}"] = "Row $row_number: Missing required field '$field'";
                                    $row_valid = false;
                                    break;
                                }
                            }
                            
                            if ($row_valid) {
                                $import_data[] = [
                                    'row_number' => $row_number,
                                    'data' => $row_data,
                                    'status' => 'pending',
                                    'errors' => []
                                ];
                            }
                            
                            $total_rows++;
                        }
                        fclose($handle);
                    }
                }
            } else {
                $errors['file'] = "Failed to open CSV file.";
            }
        } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
            // For Excel files, we'd need PHPExcel or PhpSpreadsheet
            // For simplicity, we'll show an error and suggest CSV
            $errors['file'] = "Excel files require additional setup. Please convert to CSV first or contact your administrator.";
        } else {
            $errors['file'] = "Unsupported file format. Please upload CSV files only.";
        }
    }
    
    // Process preview if no errors
    if (empty($errors) && !empty($import_data)) {
        // Validate each row
        foreach ($import_data as &$row) {
            $row_data = $row['data'];
            $row_errors = [];
            
            // Validate asset type (basic check)
            if (!empty($row_data['asset_type']) && strlen($row_data['asset_type']) > 100) {
                $row_errors[] = "Asset type too long (max 100 chars)";
            }
            
            // Validate manufacturer/model
            if (!empty($row_data['manufacturer']) && strlen($row_data['manufacturer']) > 100) {
                $row_errors[] = "Manufacturer name too long (max 100 chars)";
            }
            if (!empty($row_data['model']) && strlen($row_data['model']) > 100) {
                $row_errors[] = "Model name too long (max 100 chars)";
            }
            
            // Validate serial number uniqueness
            if (!empty($row_data['serial_number'])) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE serial_number = ?");
                $stmt->execute([$row_data['serial_number']]);
                if ($stmt->fetchColumn() > 0) {
                    $row_errors[] = "Serial number already exists in database";
                    $duplicate_rows++;
                }
            }
            
            // Validate asset tag uniqueness
            if (!empty($row_data['asset_tag'])) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE asset_tag = ?");
                $stmt->execute([$row_data['asset_tag']]);
                if ($stmt->fetchColumn() > 0) {
                    $row_errors[] = "Asset tag already exists in database";
                    $duplicate_rows++;
                }
            }
            
            // Validate IP address
            if (!empty($row_data['ip_address']) && !filter_var($row_data['ip_address'], FILTER_VALIDATE_IP)) {
                $row_errors[] = "Invalid IP address format";
            }
            
            // Validate MAC address
            if (!empty($row_data['mac_address']) && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $row_data['mac_address'])) {
                $row_errors[] = "Invalid MAC address format";
            }
            
            // Validate dates
            $date_fields = ['purchase_date', 'warranty_expiry', 'amc_expiry', 'license_expiry'];
            foreach ($date_fields as $date_field) {
                if (!empty($row_data[$date_field])) {
                    $date = DateTime::createFromFormat('Y-m-d', $row_data[$date_field]);
                    if (!$date) {
                        // Try other formats
                        $date = DateTime::createFromFormat('d/m/Y', $row_data[$date_field]);
                        if (!$date) {
                            $date = DateTime::createFromFormat('m/d/Y', $row_data[$date_field]);
                        }
                        if ($date) {
                            $row_data[$date_field] = $date->format('Y-m-d');
                        } else {
                            $row_errors[] = "Invalid date format for $date_field (use YYYY-MM-DD)";
                        }
                    }
                }
            }
            
            // Map client name to ID
            if (!empty($row_data['client_id']) && !is_uuid($row_data['client_id'])) {
                $client_name = $row_data['client_id'];
                if (isset($client_map[$client_name])) {
                    $row_data['client_id'] = $client_map[$client_name];
                } else {
                    $row_errors[] = "Client not found: $client_name";
                }
            }
            
            // Map staff name to ID
            if (!empty($row_data['assigned_to']) && !is_uuid($row_data['assigned_to'])) {
                $staff_identifier = $row_data['assigned_to'];
                if (isset($staff_map[$staff_identifier])) {
                    $row_data['assigned_to'] = $staff_map[$staff_identifier];
                } else {
                    $row_errors[] = "Staff member not found: $staff_identifier";
                }
            }
            
            // Map location name to ID
            if (!empty($row_data['location_id']) && !is_uuid($row_data['location_id'])) {
                $location_name = $row_data['location_id'];
                if (isset($location_map[$location_name])) {
                    $row_data['location_id'] = $location_map[$location_name];
                } else {
                    $row_errors[] = "Location not found: $location_name";
                }
            }
            
            // Set default status if not provided
            if (empty($row_data['status'])) {
                $row_data['status'] = 'Active';
            }
            
            $row['data'] = $row_data;
            $row['errors'] = $row_errors;
            $row['status'] = empty($row_errors) ? 'valid' : 'invalid';
            
            if (!empty($row_errors)) {
                $skipped_rows++;
            }
        }
        unset($row);
        
        // Handle import confirmation
        if (isset($_POST['confirm_import']) && $_POST['confirm_import'] === '1') {
            $pdo->beginTransaction();
            
            try {
                $success_count = 0;
                $error_count = 0;
                
                foreach ($import_data as $row) {
                    if ($row['status'] === 'valid') {
                        try {
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
                            $params = [];
                            
                            foreach (array_merge($required_fields, $optional_fields) as $field) {
                                $params[":$field"] = !empty($row['data'][$field]) ? $row['data'][$field] : null;
                            }
                            
                            $stmt->execute($params);
                            $success_count++;
                            
                            // Create audit log
                            $asset_id = $pdo->lastInsertId();
                            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                                          VALUES (?, 'IMPORT', 'ASSET', ?, ?, ?, NOW())";
                            $audit_stmt = $pdo->prepare($audit_sql);
                            $audit_details = json_encode([
                                'asset_type' => $row['data']['asset_type'],
                                'manufacturer' => $row['data']['manufacturer'],
                                'model' => $row['data']['model'],
                                'import_batch' => true,
                                'row_number' => $row['row_number']
                            ]);
                            $audit_stmt->execute([
                                $current_user['id'],
                                $asset_id,
                                $audit_details,
                                $_SERVER['REMOTE_ADDR']
                            ]);
                            
                        } catch (Exception $e) {
                            $error_count++;
                            $import_data[$row['row_number'] - 2]['errors'][] = "Database error: " . $e->getMessage();
                            $import_data[$row['row_number'] - 2]['status'] = 'invalid';
                        }
                    }
                }
                
                $pdo->commit();
                
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => "Import completed! Successfully imported $success_count assets. Failed: $error_count"
                ];
                
                // Log the import
                $log_sql = "INSERT INTO import_logs (user_id, import_type, total_records, successful, failed, file_name, created_at)
                            VALUES (?, 'assets', ?, ?, ?, ?, NOW())";
                $log_stmt = $pdo->prepare($log_sql);
                $log_stmt->execute([
                    $current_user['id'],
                    count($import_data),
                    $success_count,
                    $error_count,
                    $file['name']
                ]);
                
                header('Location: index.php');
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors['import'] = "Import failed: " . $e->getMessage();
            }
        }
    }
}

// Helper function to check if string is UUID
function is_uuid($string) {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Assets | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .import-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .import-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #eaeaea;
        }
        .import-card h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            counter-reset: step;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step:before {
            counter-increment: step;
            content: counter(step);
            width: 40px;
            height: 40px;
            border: 2px solid #dee2e6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            background: white;
            font-weight: bold;
            color: #6c757d;
            transition: all 0.3s;
        }
        .step.active:before {
            background: #004E89;
            color: white;
            border-color: #004E89;
        }
        .step.completed:before {
            background: #28a745;
            color: white;
            border-color: #28a745;
            content: '✓';
        }
        .step .step-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .step.active .step-label {
            color: #004E89;
            font-weight: 500;
        }
        .step.completed .step-label {
            color: #28a745;
        }
        .step:not(:last-child):after {
            content: '';
            position: absolute;
            top: 20px;
            left: 60%;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: -1;
        }
        .step.completed:not(:last-child):after {
            background: #28a745;
        }
        .file-drop-zone {
            border: 3px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-drop-zone:hover, .file-drop-zone.dragover {
            border-color: #004E89;
            background: #e7f0ff;
        }
        .file-drop-zone i {
            font-size: 3rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        .file-drop-zone:hover i {
            color: #004E89;
        }
        .sample-file {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .field-mapping {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .mapping-row {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eaeaea;
        }
        .mapping-row:last-child {
            border-bottom: none;
        }
        .csv-header {
            flex: 1;
            font-weight: 500;
            color: #333;
        }
        .mapping-arrow {
            margin: 0 15px;
            color: #6c757d;
        }
        .db-field {
            flex: 1;
        }
        .preview-table-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .validation-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-right: 5px;
        }
        .badge-valid { background: #d4edda; color: #155724; }
        .badge-invalid { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        .import-summary {
            background: linear-gradient(135deg, #004E89, #1a6cb0);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .template-download {
            text-decoration: none;
            color: #004E89;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            padding: 8px 15px;
            border: 2px solid #004E89;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .template-download:hover {
            background: #004E89;
            color: white;
            text-decoration: none;
        }
        .progress-container {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            height: 100%;
            background: #004E89;
            transition: width 0.3s;
        }
        @media (max-width: 768px) {
            .step-indicator {
                flex-direction: column;
                gap: 20px;
            }
            .step:not(:last-child):after {
                display: none;
            }
            .mapping-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .mapping-arrow {
                display: none;
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
                    <h1><i class="fas fa-file-import"></i> Import Assets</h1>
                    <p class="text-muted">Bulk import assets from CSV file</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Import Assets</li>
                </ol>
            </nav>
            
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo empty($import_data) ? 'active' : 'completed'; ?>">
                    <div class="step-label">Upload File</div>
                </div>
                <div class="step <?php echo !empty($import_data) && !isset($_POST['confirm_import']) ? 'active' : (!empty($import_data) ? 'completed' : ''); ?>">
                    <div class="step-label">Preview & Map</div>
                </div>
                <div class="step <?php echo isset($_POST['confirm_import']) ? 'active' : ''; ?>">
                    <div class="step-label">Confirm Import</div>
                </div>
            </div>
            
            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <h5><i class="fas fa-exclamation-triangle"></i> Import Errors</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Flash Messages -->
            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <i class="fas fa-<?php echo $flash['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Upload Form (Step 1) -->
            <?php if (empty($import_data)): ?>
            <div class="import-card">
                <h3><i class="fas fa-upload"></i> Step 1: Upload CSV File</h3>
                <p class="text-muted mb-4">Upload a CSV file containing your asset data. The file should include headers in the first row.</p>
                
                <form method="POST" enctype="multipart/form-data" id="upload-form">
                    <div class="file-drop-zone" id="drop-zone">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h4>Drag & Drop your CSV file here</h4>
                        <p class="text-muted">or click to browse</p>
                        <input type="file" name="import_file" id="import_file" accept=".csv,.txt" class="d-none" required>
                        <div class="mt-3">
                            <button type="button" class="btn btn-primary" onclick="document.getElementById('import_file').click()">
                                <i class="fas fa-folder-open"></i> Browse Files
                            </button>
                        </div>
                        <div id="file-info" class="mt-3" style="display: none;">
                            <div class="alert alert-info">
                                <i class="fas fa-file-alt"></i>
                                <span id="file-name"></span>
                                (<span id="file-size"></span>)
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="sample-file">
                                <h5><i class="fas fa-download"></i> Download Sample Template</h5>
                                <p class="small text-muted">Use this template to ensure correct formatting</p>
                                <a href="javascript:void(0);" onclick="downloadTemplate()" class="template-download">
                                    <i class="fas fa-file-excel me-2"></i> Download CSV Template
                                </a>
                                <div class="mt-3">
                                    <small class="text-muted">Includes all required fields with example data</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="sample-file">
                                <h5><i class="fas fa-info-circle"></i> File Requirements</h5>
                                <ul class="small">
                                    <li>File must be in CSV format</li>
                                    <li>Maximum file size: 10MB</li>
                                    <li>First row must contain headers</li>
                                    <li>Required fields: Asset Type, Manufacturer, Model</li>
                                    <li>Date format: YYYY-MM-DD (recommended)</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="field-mapping mt-4">
                        <h5><i class="fas fa-exchange-alt"></i> Supported Column Headers</h5>
                        <p class="small text-muted">Your CSV can use any of these header names (case insensitive)</p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Basic Information</h6>
                                <ul class="small">
                                    <li><code>Asset Type</code>, <code>Type</code>, <code>Device Type</code></li>
                                    <li><code>Manufacturer</code>, <code>Vendor</code>, <code>Brand</code></li>
                                    <li><code>Model</code>, <code>Model Number</code></li>
                                    <li><code>Serial Number</code>, <code>Serial</code>, <code>SN</code></li>
                                    <li><code>Asset Tag</code>, <code>Tag</code>, <code>Asset ID</code></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Additional Fields</h6>
                                <ul class="small">
                                    <li><code>IP Address</code>, <code>IP</code></li>
                                    <li><code>MAC Address</code>, <code>MAC</code></li>
                                    <li><code>Purchase Date</code>, <code>Acquisition Date</code></li>
                                    <li><code>Warranty Expiry</code>, <code>AMC Expiry</code></li>
                                    <li><code>Status</code>, <code>Client</code>, <code>Location</code></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Assets
                        </a>
                        <button type="submit" class="btn btn-primary" id="upload-btn">
                            <i class="fas fa-arrow-right"></i> Next: Preview & Map
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Preview & Mapping (Step 2) -->
            <?php if (!empty($import_data) && !isset($_POST['confirm_import'])): ?>
            <div class="import-card">
                <h3><i class="fas fa-search"></i> Step 2: Preview & Validate</h3>
                <p class="text-muted mb-4">Review the imported data and fix any validation errors before proceeding.</p>
                
                <!-- Import Summary -->
                <div class="import-summary">
                    <h4 class="text-white"><i class="fas fa-chart-bar"></i> Import Summary</h4>
                    <div class="summary-item">
                        <span>Total Rows Found:</span>
                        <span class="font-weight-bold"><?php echo $total_rows; ?></span>
                    </div>
                    <div class="summary-item">
                        <span>Valid Rows:</span>
                        <span class="font-weight-bold" style="color: #90ee90;"><?php echo count(array_filter($import_data, fn($row) => $row['status'] === 'valid')); ?></span>
                    </div>
                    <div class="summary-item">
                        <span>Invalid Rows:</span>
                        <span class="font-weight-bold" style="color: #ffcccb;"><?php echo count(array_filter($import_data, fn($row) => $row['status'] === 'invalid')); ?></span>
                    </div>
                    <div class="summary-item">
                        <span>Duplicate Serial/Tag:</span>
                        <span class="font-weight-bold" style="color: #ffcccb;"><?php echo $duplicate_rows; ?></span>
                    </div>
                </div>
                
                <!-- Column Mapping -->
                <div class="field-mapping mb-4">
                    <h5><i class="fas fa-project-diagram"></i> Column Mapping</h5>
                    <p class="small text-muted">The following columns were automatically mapped from your CSV:</p>
                    
                    <?php 
                    $mapped_count = count(array_filter($mapped_columns));
                    $unmapped_count = count($mapped_columns) - $mapped_count;
                    ?>
                    
                    <div class="progress-container">
                        <div class="progress-bar" style="width: <?php echo ($mapped_count / count($mapped_columns)) * 100; ?>%;"></div>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span>Mapped: <?php echo $mapped_count; ?> columns</span>
                        <span>Unmapped: <?php echo $unmapped_count; ?> columns</span>
                    </div>
                    
                    <div class="mt-3">
                        <?php 
                        foreach ($mapped_columns as $index => $field) {
                            $header = $headers[$index] ?? "Column $index";
                            if ($field !== null) {
                                echo '<div class="mapping-row">
                                    <span class="csv-header">' . htmlspecialchars($header) . '</span>
                                    <span class="mapping-arrow"><i class="fas fa-arrow-right"></i></span>
                                    <span class="db-field">
                                        <span class="badge bg-success">' . htmlspecialchars($field) . '</span>
                                    </span>
                                </div>';
                            } else {
                                echo '<div class="mapping-row">
                                    <span class="csv-header">' . htmlspecialchars($header) . '</span>
                                    <span class="mapping-arrow"><i class="fas fa-arrow-right"></i></span>
                                    <span class="db-field">
                                        <span class="badge bg-secondary">Not mapped</span>
                                        <small class="text-muted ms-2">(Will be ignored)</small>
                                    </span>
                                </div>';
                            }
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Data Preview Table -->
                <h5><i class="fas fa-table"></i> Data Preview (First 10 rows)</h5>
                <div class="preview-table-container">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th>Row</th>
                                <th>Status</th>
                                <th>Asset Type</th>
                                <th>Manufacturer</th>
                                <th>Model</th>
                                <th>Serial Number</th>
                                <th>Asset Tag</th>
                                <th>Client</th>
                                <th>Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $preview_limit = min(10, count($import_data));
                            for ($i = 0; $i < $preview_limit; $i++):
                                $row = $import_data[$i];
                            ?>
                            <tr class="<?php echo $row['status'] === 'invalid' ? 'table-danger' : ''; ?>">
                                <td><?php echo $row['row_number']; ?></td>
                                <td>
                                    <span class="validation-badge badge-<?php echo $row['status']; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['data']['asset_type'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['data']['manufacturer'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['data']['model'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['data']['serial_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['data']['asset_tag'] ?? ''); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($row['data']['client_id']) && isset($client_map)) {
                                        $client_name = array_search($row['data']['client_id'], $client_map);
                                        echo htmlspecialchars($client_name ?: $row['data']['client_id']);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['errors'])): ?>
                                    <ul class="mb-0 small" style="color: #dc3545;">
                                        <?php foreach ($row['errors'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    Showing <?php echo $preview_limit; ?> of <?php echo count($import_data); ?> rows. 
                    Invalid rows (highlighted in red) will be skipped during import.
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex justify-content-between mt-4">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="cancel" value="1" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel Import
                        </button>
                    </form>
                    
                    <div>
                        <button type="button" class="btn btn-outline-warning" id="download-errors" 
                                <?php echo count(array_filter($import_data, fn($row) => !empty($row['errors']))) == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-download"></i> Download Error Report
                        </button>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="confirm_import" value="1">
                            <?php foreach ($_FILES as $key => $file): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($file['name']); ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary" 
                                    <?php echo count(array_filter($import_data, fn($row) => $row['status'] === 'valid')) == 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-check-circle"></i> Confirm Import
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Import Progress (Step 3) -->
            <?php if (isset($_POST['confirm_import']) && empty($errors)): ?>
            <div class="import-card">
                <h3><i class="fas fa-sync-alt fa-spin"></i> Importing Assets...</h3>
                <p class="text-muted mb-4">Please wait while we import your assets. Do not close this window.</p>
                
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h4 class="mt-4">Importing <?php echo count($import_data); ?> assets</h4>
                    <p class="text-muted">This may take a few moments...</p>
                    
                    <div class="progress-container mt-4" style="max-width: 500px; margin: 0 auto;">
                        <div class="progress-bar" id="import-progress" style="width: 0%;"></div>
                    </div>
                    <div class="mt-2" id="progress-text">Starting import...</div>
                </div>
                
                <div class="alert alert-warning mt-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Do not close this window</strong> while the import is in progress. 
                    You will be redirected automatically when complete.
                </div>
            </div>
            
            <script>
                // Simulate progress bar
                let progress = 0;
                const progressBar = document.getElementById('import-progress');
                const progressText = document.getElementById('progress-text');
                
                function updateProgress() {
                    progress += 10;
                    if (progress > 100) progress = 100;
                    
                    progressBar.style.width = progress + '%';
                    progressText.textContent = 'Importing... ' + progress + '% complete';
                    
                    if (progress < 100) {
                        setTimeout(updateProgress, 300);
                    } else {
                        progressText.textContent = 'Import complete! Redirecting...';
                        setTimeout(() => {
                            window.location.href = 'index.php';
                        }, 1000);
                    }
                }
                
                // Start progress after page loads
                setTimeout(updateProgress, 500);
            </script>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // File upload handling
            const dropZone = document.getElementById('drop-zone');
            const fileInput = document.getElementById('import_file');
            const fileInfo = document.getElementById('file-info');
            const fileName = document.getElementById('file-name');
            const fileSize = document.getElementById('file-size');
            
            // Click on drop zone
            dropZone.addEventListener('click', () => {
                fileInput.click();
            });
            
            // Drag and drop
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });
            
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileInfo(e.dataTransfer.files[0]);
                }
            });
            
            // File input change
            fileInput.addEventListener('change', (e) => {
                if (fileInput.files.length) {
                    updateFileInfo(fileInput.files[0]);
                }
            });
            
            function updateFileInfo(file) {
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                fileInfo.style.display = 'block';
                
                // Validate file type
                const validTypes = ['text/csv', 'application/vnd.ms-excel', 'text/plain'];
                if (!validTypes.includes(file.type) && !file.name.toLowerCase().endsWith('.csv')) {
                    alert('Please upload a CSV file only.');
                    fileInput.value = '';
                    fileInfo.style.display = 'none';
                }
            }
            
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            // Download template
            function downloadTemplate() {
                const csvContent = "Asset Type,Manufacturer,Model,Serial Number,Asset Tag,IP Address,MAC Address,Purchase Date,Warranty Expiry,AMC Expiry,License Expiry,Status,Client,Location,Assigned To,Notes\n" +
                                 "Firewall,Fortinet,FortiGate 60F,FG60FTK12345678,MSP-FW-001,192.168.1.1,00:1A:2B:3C:4D:5E,2023-01-15,2025-01-15,2024-01-15,2024-12-31,Active,ABC Corporation,Head Office,John Doe (Engineer),Primary firewall for network\n" +
                                 "Switch,Cisco,Catalyst 2960,CAT2960X123456,MSP-SW-001,192.168.1.2,00:1B:2C:3D:4E:5F,2023-02-20,2025-02-20,,,Active,ABC Corporation,Branch Office,Jane Smith (Technician),48-port switch\n" +
                                 "Server,Dell,PowerEdge R740,CN123456789012,MSP-SRV-001,192.168.1.10,00:1C:2D:3E:4F:60,2023-03-10,2026-03-10,2024-03-10,2024-06-30,Active,XYZ Ltd,Data Center,Robert Johnson (Admin),VMware host server\n" +
                                 "CCTV,Hikvision,DS-2CD2143G0,DS1234567890,MSP-CCTV-001,,,2023-04-05,2024-10-05,,,,Active,XYZ Ltd,Reception,,Entrance camera\n" +
                                 "Laptop,HP,EliteBook 840 G6,5CG123456M,MSP-LT-001,,34:64:A9:12:34:56,2023-05-12,2024-11-12,,,Active,,,Sarah Wilson (Manager),CEO's laptop";
                
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'asset_import_template.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }
            
            // Download error report
            $('#download-errors').on('click', function() {
                const errorRows = <?php echo json_encode(array_filter($import_data, fn($row) => !empty($row['errors']))); ?>;
                
                if (errorRows.length === 0) {
                    alert('No errors to download.');
                    return;
                }
                
                // Create CSV content
                let csvContent = "Row Number,Asset Type,Manufacturer,Model,Serial Number,Asset Tag,Errors\n";
                
                errorRows.forEach(row => {
                    const errors = row.errors.join('; ');
                    csvContent += `"${row.row_number}","${row.data.asset_type || ''}","${row.data.manufacturer || ''}","${row.data.model || ''}","${row.data.serial_number || ''}","${row.data.asset_tag || ''}","${errors}"\n`;
                });
                
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'import_errors_' + new Date().toISOString().split('T')[0] + '.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            });
            
            // Form validation
            $('#upload-form').on('submit', function(e) {
                if (!fileInput.files.length) {
                    e.preventDefault();
                    alert('Please select a file to upload.');
                    return false;
                }
                
                $('#upload-btn').html('<i class="fas fa-spinner fa-spin"></i> Uploading...');
                $('#upload-btn').prop('disabled', true);
            });
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl+U to upload file
                if (e.ctrlKey && e.key === 'u') {
                    e.preventDefault();
                    fileInput.click();
                }
                
                // Ctrl+S to download template
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    downloadTemplate();
                }
            });
            
            // Auto-submit on file select (optional)
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    // Optional: auto-submit after short delay
                    // setTimeout(() => {
                    //     $('#upload-form').submit();
                    // }, 500);
                }
            });
        });
    </script>
</body>
</html>