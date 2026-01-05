<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to preview assets.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();

// Get preview data from localStorage via POST
$form_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_data'])) {
    parse_str($_POST['preview_data'], $form_data);
} elseif (isset($_GET['draft'])) {
    // Load from draft parameter (for direct access)
    $draft_data = json_decode(base64_decode($_GET['draft']), true);
    if ($draft_data) {
        $form_data = $draft_data;
    }
}

// If no data, redirect back to create page
if (empty($form_data)) {
    $_SESSION['flash'] = [
        'type' => 'warning',
        'message' => 'No preview data found. Please fill out the asset form first.'
    ];
    header('Location: create.php');
    exit;
}

// Get database connection for dropdown values
$pdo = getDBConnection();

// Helper function to get display values
function getDisplayValue($value, $default = 'Not specified') {
    return !empty($value) ? htmlspecialchars($value) : '<span class="text-muted">' . $default . '</span>';
}

// Helper function to format dates
function formatDate($date, $format = 'F j, Y') {
    if (empty($date)) return '<span class="text-muted">Not set</span>';
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    return $date_obj ? $date_obj->format($format) : htmlspecialchars($date);
}

// Helper function to get asset icon
function getAssetIcon($type) {
    $icons = [
        'Firewall' => 'fas fa-shield-alt',
        'Switch' => 'fas fa-network-wired',
        'Server' => 'fas fa-server',
        'CCTV' => 'fas fa-video',
        'Biometric' => 'fas fa-fingerprint',
        'Gate Automation' => 'fas fa-door-open',
        'Router' => 'fas fa-wifi',
        'Access Point' => 'fas fa-wifi',
        'Desktop' => 'fas fa-desktop',
        'Laptop' => 'fas fa-laptop',
        'Printer' => 'fas fa-print',
        'Scanner' => 'fas fa-scanner',
        'Phone' => 'fas fa-phone',
        'Tablet' => 'fas fa-tablet-alt',
        'Mobile' => 'fas fa-mobile-alt',
    ];
    return $icons[$type] ?? 'fas fa-hdd';
}

// Helper function to get asset color
function getAssetColor($type) {
    $colors = [
        'Firewall' => '#dc3545',
        'Switch' => '#007bff',
        'Server' => '#28a745',
        'CCTV' => '#17a2b8',
        'Biometric' => '#6610f2',
        'Gate Automation' => '#fd7e14',
        'Router' => '#20c997',
        'Access Point' => '#e83e8c',
        'Desktop' => '#6f42c1',
        'Laptop' => '#20c997',
        'Printer' => '#6c757d',
        'Scanner' => '#ffc107',
        'Phone' => '#004E89',
        'Tablet' => '#FF6B35',
        'Mobile' => '#28a745'
    ];
    return $colors[$type] ?? '#004E89';
}

// Helper function to get status badge class
function getStatusBadge($status) {
    $classes = [
        'Active' => 'success',
        'Inactive' => 'danger',
        'Under Maintenance' => 'warning',
        'Retired' => 'secondary',
        'Spare' => 'info'
    ];
    return $classes[$status] ?? 'secondary';
}

// Get client and location names if IDs are provided
$client_name = 'Not assigned';
$location_name = 'Not assigned';
$assigned_to_name = 'Not assigned';

if (!empty($form_data['client_id'])) {
    $stmt = $pdo->prepare("SELECT company_name FROM clients WHERE id = ?");
    $stmt->execute([$form_data['client_id']]);
    $client = $stmt->fetch();
    if ($client) {
        $client_name = $client['company_name'];
    }
}

if (!empty($form_data['location_id'])) {
    $stmt = $pdo->prepare("SELECT location_name, city FROM client_locations WHERE id = ?");
    $stmt->execute([$form_data['location_id']]);
    $location = $stmt->fetch();
    if ($location) {
        $location_name = $location['location_name'] . ' (' . $location['city'] . ')';
    }
}

if (!empty($form_data['assigned_to'])) {
    $stmt = $pdo->prepare("SELECT full_name, designation FROM staff_profiles WHERE id = ?");
    $stmt->execute([$form_data['assigned_to']]);
    $staff = $stmt->fetch();
    if ($staff) {
        $assigned_to_name = $staff['full_name'] . ' (' . $staff['designation'] . ')';
    }
}

// Check for expiring dates
$warnings = [];
$today = new DateTime();
$critical_dates = [];

if (!empty($form_data['warranty_expiry'])) {
    $warranty_date = new DateTime($form_data['warranty_expiry']);
    $interval = $today->diff($warranty_date);
    if ($warranty_date < $today) {
        $warnings[] = 'Warranty has expired';
    } elseif ($interval->days <= 30) {
        $warnings[] = 'Warranty expiring in ' . $interval->days . ' days';
        $critical_dates['warranty'] = $warranty_date->format('M d, Y');
    }
}

if (!empty($form_data['amc_expiry'])) {
    $amc_date = new DateTime($form_data['amc_expiry']);
    $interval = $today->diff($amc_date);
    if ($amc_date < $today) {
        $warnings[] = 'AMC has expired';
    } elseif ($interval->days <= 30) {
        $warnings[] = 'AMC expiring in ' . $interval->days . ' days';
        $critical_dates['amc'] = $amc_date->format('M d, Y');
    }
}

if (!empty($form_data['license_expiry'])) {
    $license_date = new DateTime($form_data['license_expiry']);
    $interval = $today->diff($license_date);
    if ($license_date < $today) {
        $warnings[] = 'License has expired';
    } elseif ($interval->days <= 30) {
        $warnings[] = 'License expiring in ' . $interval->days . ' days';
        $critical_dates['license'] = $license_date->format('M d, Y');
    }
}

// Generate QR code data
$qr_data = [
    'type' => $form_data['asset_type'] ?? 'Asset',
    'manufacturer' => $form_data['manufacturer'] ?? '',
    'model' => $form_data['model'] ?? '',
    'serial' => $form_data['serial_number'] ?? '',
    'tag' => $form_data['asset_tag'] ?? '',
    'created' => date('Y-m-d H:i:s'),
    'preview' => true
];
$qr_json = json_encode($qr_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Asset | MSP Application</title>
    <link rel="stylesheet" href="../../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .preview-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .preview-header {
            background: linear-gradient(135deg, #004E89, #1a6cb0);
            color: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        .preview-header:before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
        }
        .asset-icon-large {
            width: 80px;
            height: 80px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-right: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .preview-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #eaeaea;
        }
        .preview-card h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 1.1rem;
            color: #333;
        }
        .warning-badge {
            background: #fff3cd;
            color: #856404;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #eaeaea;
        }
        .qr-placeholder {
            width: 200px;
            height: 200px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: #6c757d;
        }
        .action-buttons {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 15px;
            border-top: 1px solid #dee2e6;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .stamp {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .expiry-warning {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        .print-only {
            display: none;
        }
        @media print {
            .action-buttons,
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .preview-container {
                padding: 0;
                margin: 0;
            }
            .preview-card {
                box-shadow: none;
                border: 1px solid #ddd;
                page-break-inside: avoid;
            }
            .preview-header {
                background: #004E89 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        @media (max-width: 768px) {
            .preview-header {
                padding: 20px;
            }
            .asset-icon-large {
                width: 60px;
                height: 60px;
                font-size: 2rem;
                margin-right: 15px;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                padding: 10px;
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
                    <h1><i class="fas fa-eye"></i> Asset Preview</h1>
                    <p class="text-muted">Review asset details before creation</p>
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
            <nav aria-label="breadcrumb" class="mb-4 no-print">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-server"></i> Assets</a></li>
                    <li class="breadcrumb-item"><a href="create.php"><i class="fas fa-plus-circle"></i> Create Asset</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Preview</li>
                </ol>
            </nav>
            
            <!-- Warning Messages -->
            <?php if (!empty($warnings)): ?>
            <div class="expiry-warning no-print">
                <h5><i class="fas fa-exclamation-triangle"></i> Important Notices</h5>
                <ul class="mb-0">
                    <?php foreach ($warnings as $warning): ?>
                    <li><?php echo htmlspecialchars($warning); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Preview Header -->
            <div class="preview-header">
                <div class="stamp">PREVIEW</div>
                <div class="d-flex align-items-center">
                    <div class="asset-icon-large" style="background: <?php echo getAssetColor($form_data['asset_type'] ?? ''); ?>; color: white;">
                        <i class="<?php echo getAssetIcon($form_data['asset_type'] ?? ''); ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h2 class="mb-2"><?php echo getDisplayValue($form_data['asset_type'] ?? '', 'New Asset'); ?></h2>
                        <div class="d-flex align-items-center flex-wrap">
                            <span class="badge bg-<?php echo getStatusBadge($form_data['status'] ?? 'Active'); ?> me-3 mb-2">
                                <?php echo getDisplayValue($form_data['status'] ?? 'Active'); ?>
                            </span>
                            <?php if (!empty($form_data['manufacturer'])): ?>
                            <span class="me-3 mb-2">
                                <i class="fas fa-industry me-1"></i>
                                <?php echo htmlspecialchars($form_data['manufacturer']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($form_data['model'])): ?>
                            <span class="me-3 mb-2">
                                <i class="fas fa-cube me-1"></i>
                                <?php echo htmlspecialchars($form_data['model']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Basic Information -->
                    <div class="preview-card">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Asset Type</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['asset_type'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status</span>
                                <span class="info-value">
                                    <span class="badge bg-<?php echo getStatusBadge($form_data['status'] ?? 'Active'); ?>">
                                        <?php echo getDisplayValue($form_data['status'] ?? 'Active'); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Manufacturer</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['manufacturer'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Model</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['model'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Serial Number</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['serial_number'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Asset Tag</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['asset_tag'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Network Information -->
                    <div class="preview-card">
                        <h3><i class="fas fa-network-wired"></i> Network Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">IP Address</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['ip_address'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">MAC Address</span>
                                <span class="info-value"><?php echo getDisplayValue($form_data['mac_address'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assignment & Location -->
                    <div class="preview-card">
                        <h3><i class="fas fa-map-marker-alt"></i> Assignment & Location</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Client</span>
                                <span class="info-value"><?php echo $client_name; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?php echo $location_name; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Assigned To</span>
                                <span class="info-value"><?php echo $assigned_to_name; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dates & Expiry -->
                    <div class="preview-card">
                        <h3><i class="fas fa-calendar-alt"></i> Dates & Expiry Information</h3>
                        <?php if (!empty($critical_dates)): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important Dates:</strong>
                            <?php 
                            $date_list = [];
                            foreach ($critical_dates as $type => $date) {
                                $date_list[] = ucfirst($type) . ': ' . $date;
                            }
                            echo implode(', ', $date_list);
                            ?>
                        </div>
                        <?php endif; ?>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Purchase Date</span>
                                <span class="info-value"><?php echo formatDate($form_data['purchase_date'] ?? ''); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Warranty Expiry</span>
                                <span class="info-value">
                                    <?php echo formatDate($form_data['warranty_expiry'] ?? ''); ?>
                                    <?php if (!empty($form_data['warranty_expiry']) && isset($critical_dates['warranty'])): ?>
                                    <span class="badge bg-danger ms-2">Expiring Soon</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">AMC Expiry</span>
                                <span class="info-value">
                                    <?php echo formatDate($form_data['amc_expiry'] ?? ''); ?>
                                    <?php if (!empty($form_data['amc_expiry']) && isset($critical_dates['amc'])): ?>
                                    <span class="badge bg-danger ms-2">Expiring Soon</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">License Expiry</span>
                                <span class="info-value">
                                    <?php echo formatDate($form_data['license_expiry'] ?? ''); ?>
                                    <?php if (!empty($form_data['license_expiry']) && isset($critical_dates['license'])): ?>
                                    <span class="badge bg-danger ms-2">Expiring Soon</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes & Additional Information -->
                    <?php if (!empty($form_data['notes'])): ?>
                    <div class="preview-card">
                        <h3><i class="fas fa-sticky-note"></i> Notes & Additional Information</h3>
                        <div class="notes-content">
                            <?php echo nl2br(htmlspecialchars($form_data['notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Print Header (Only shows when printing) -->
                    <div class="print-only">
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h1>Asset Preview - <?php echo htmlspecialchars($form_data['asset_type'] ?? 'New Asset'); ?></h1>
                            <p>Generated on <?php echo date('F j, Y \a\t g:i A'); ?> by <?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? 'System'); ?></p>
                            <hr>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- QR Code & Barcode -->
                    <div class="qr-container mb-4">
                        <h4><i class="fas fa-qrcode"></i> Asset Identification</h4>
                        <div class="qr-placeholder">
                            <div>
                                <i class="fas fa-qrcode fa-4x mb-3"></i>
                                <div>QR Code Preview</div>
                                <small class="text-muted">(Will be generated after saving)</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <strong>Asset Tag:</strong>
                            <div class="font-monospace" style="font-size: 1.2rem;">
                                <?php echo getDisplayValue($form_data['asset_tag'] ?? '', 'N/A'); ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <strong>Serial Number:</strong>
                            <div class="font-monospace">
                                <?php echo getDisplayValue($form_data['serial_number'] ?? '', 'N/A'); ?>
                            </div>
                        </div>
                        <div class="text-muted small">
                            <i class="fas fa-info-circle"></i>
                            QR code will contain asset details for easy scanning
                        </div>
                    </div>
                    
                    <!-- Summary Card -->
                    <div class="preview-card">
                        <h3><i class="fas fa-clipboard-check"></i> Summary</h3>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Asset Type:</strong> <?php echo getDisplayValue($form_data['asset_type'] ?? ''); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Manufacturer:</strong> <?php echo getDisplayValue($form_data['manufacturer'] ?? ''); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Model:</strong> <?php echo getDisplayValue($form_data['model'] ?? ''); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Status:</strong> <?php echo getDisplayValue($form_data['status'] ?? 'Active'); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-<?php echo !empty($form_data['client_id']) ? 'check-circle text-success' : 'minus-circle text-secondary'; ?> me-2"></i>
                                <strong>Client:</strong> <?php echo !empty($form_data['client_id']) ? 'Assigned' : 'Not assigned'; ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-<?php echo !empty($form_data['serial_number']) ? 'check-circle text-success' : 'exclamation-circle text-warning'; ?> me-2"></i>
                                <strong>Serial Number:</strong> <?php echo !empty($form_data['serial_number']) ? 'Provided' : 'Not provided'; ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-<?php echo !empty($form_data['asset_tag']) ? 'check-circle text-success' : 'exclamation-circle text-warning'; ?> me-2"></i>
                                <strong>Asset Tag:</strong> <?php echo !empty($form_data['asset_tag']) ? 'Provided' : 'Not provided'; ?>
                            </li>
                        </ul>
                        
                        <?php if (!empty($warnings)): ?>
                        <div class="alert alert-warning mt-3">
                            <h6><i class="fas fa-exclamation-triangle"></i> Warnings</h6>
                            <ul class="mb-0 small">
                                <?php foreach ($warnings as $warning): ?>
                                <li><?php echo htmlspecialchars($warning); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-lightbulb"></i> Recommendations</h6>
                            <ul class="mb-0 small">
                                <?php if (empty($form_data['serial_number'])): ?>
                                <li>Consider adding a serial number for better tracking</li>
                                <?php endif; ?>
                                <?php if (empty($form_data['asset_tag'])): ?>
                                <li>Add an asset tag for easy identification</li>
                                <?php endif; ?>
                                <?php if (empty($form_data['purchase_date'])): ?>
                                <li>Purchase date helps with warranty calculations</li>
                                <?php endif; ?>
                                <?php if (empty($form_data['notes'])): ?>
                                <li>Add notes for maintenance history and configuration</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Share Preview -->
                    <div class="preview-card no-print">
                        <h3><i class="fas fa-share-alt"></i> Share Preview</h3>
                        <p class="small text-muted mb-3">Generate a shareable link for team review</p>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="share-link" 
                                   value="Preview only - Link will be available after creation" readonly>
                            <button class="btn btn-outline-secondary" type="button" id="copy-link">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-outline-primary" id="email-preview">
                                <i class="fas fa-envelope"></i> Email Preview
                            </button>
                            <button class="btn btn-outline-success" id="download-pdf">
                                <i class="fas fa-file-pdf"></i> Download as PDF
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons no-print">
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <i class="fas fa-info-circle text-primary"></i>
                                </div>
                                <div>
                                    <small class="text-muted">This is a preview. No changes have been saved yet.</small>
                                    <div class="small">
                                        Last updated: <?php echo date('g:i A'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary" id="back-btn">
                                    <i class="fas fa-arrow-left"></i> Back to Form
                                </button>
                                <button type="button" class="btn btn-outline-info" id="print-btn">
                                    <i class="fas fa-print"></i> Print Preview
                                </button>
                                <button type="button" class="btn btn-primary" id="create-asset">
                                    <i class="fas fa-check-circle"></i> Create Asset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Back to form button
            $('#back-btn').on('click', function() {
                window.history.back();
            });
            
            // Print button
            $('#print-btn').on('click', function() {
                window.print();
            });
            
            // Create Asset button
            $('#create-asset').on('click', function() {
                // Submit the form data back to create.php
                const form = $('<form>', {
                    method: 'POST',
                    action: 'create.php'
                });
                
                <?php 
                // Serialize form data for submission
                foreach ($form_data as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $subvalue) {
                            echo "form.append($('<input>', { type: 'hidden', name: '{$key}[]', value: '" . addslashes($subvalue) . "' }));";
                        }
                    } else {
                        echo "form.append($('<input>', { type: 'hidden', name: '{$key}', value: '" . addslashes($value) . "' }));";
                    }
                }
                ?>
                
                form.appendTo('body').submit();
            });
            
            // Copy link button
            $('#copy-link').on('click', function() {
                const shareLink = $('#share-link');
                shareLink.select();
                document.execCommand('copy');
                
                // Show success message
                const originalText = $(this).html();
                $(this).html('<i class="fas fa-check"></i>');
                setTimeout(() => {
                    $(this).html(originalText);
                }, 2000);
            });
            
            // Email preview button
            $('#email-preview').on('click', function() {
                const subject = encodeURIComponent('Asset Preview: <?php echo htmlspecialchars($form_data['asset_type'] ?? 'New Asset'); ?>');
                const body = encodeURIComponent(`Please review this asset preview:

Asset Type: <?php echo htmlspecialchars($form_data['asset_type'] ?? ''); ?>
Manufacturer: <?php echo htmlspecialchars($form_data['manufacturer'] ?? ''); ?>
Model: <?php echo htmlspecialchars($form_data['model'] ?? ''); ?>

This is a preview only. The asset has not been created yet.

-- 
Generated by MSP Application`);
                
                window.location.href = `mailto:?subject=${subject}&body=${body}`;
            });
            
            // Download PDF button
            $('#download-pdf').on('click', function() {
                $(this).html('<i class="fas fa-spinner fa-spin"></i> Generating PDF...');
                $(this).prop('disabled', true);
                
                // In a real implementation, you would call an API to generate PDF
                setTimeout(() => {
                    const toast = $(`
                        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                            <div class="toast show" role="alert">
                                <div class="toast-header bg-info text-white">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong class="me-auto">PDF Generation</strong>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                                </div>
                                <div class="toast-body">
                                    PDF download will be available after asset creation.
                                </div>
                            </div>
                        </div>
                    `);
                    $('body').append(toast);
                    setTimeout(() => toast.remove(), 3000);
                    
                    $(this).html('<i class="fas fa-file-pdf"></i> Download as PDF');
                    $(this).prop('disabled', false);
                }, 1000);
            });
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Escape to go back
                if (e.key === 'Escape') {
                    e.preventDefault();
                    $('#back-btn').click();
                }
                // Ctrl+P to print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    $('#print-btn').click();
                }
                // Ctrl+S to create
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    $('#create-asset').click();
                }
            });
            
            // Auto-scroll to top when printing
            window.addEventListener('beforeprint', function() {
                window.scrollTo(0, 0);
            });
            
            // Show print confirmation
            window.addEventListener('afterprint', function() {
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header bg-success text-white">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong class="me-auto">Print Complete</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                Preview printed successfully.
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 3000);
            });
            
            // Add animation to warning badges
            $('.badge.bg-danger').each(function() {
                $(this).css('animation', 'pulse 1.5s infinite');
            });
            
            // Generate shareable link (in real implementation)
            function generateShareLink() {
                const data = <?php echo json_encode($form_data); ?>;
                const encoded = btoa(JSON.stringify(data));
                const url = new URL(window.location.href);
                url.searchParams.set('draft', encoded);
                return url.toString();
            }
            
            // Update share link (uncomment when implementing properly)
            // $('#share-link').val(generateShareLink());
        });
    </script>
</body>
</html>