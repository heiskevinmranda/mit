<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission - reports should be accessible to managers and above
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to view reports.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get report type and date range
$report_type = $_GET['type'] ?? 'dashboard';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Additional filters
$client_id = $_GET['client_id'] ?? '';
$asset_type = $_GET['asset_type'] ?? '';
$status = $_GET['status'] ?? '';

// Get filter options
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$asset_types = $pdo->query("SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL ORDER BY asset_type")->fetchAll();
$statuses = $pdo->query("SELECT DISTINCT status FROM assets WHERE status IS NOT NULL ORDER BY status")->fetchAll();

// Build where clause for reports
$where_conditions = ['a.created_at BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = [PDO::PARAM_STR, PDO::PARAM_STR];

if ($client_id) {
    $where_conditions[] = "a.client_id = ?";
    $params[] = $client_id;
    $types[] = PDO::PARAM_STR;
}

if ($asset_type) {
    $where_conditions[] = "a.asset_type = ?";
    $params[] = $asset_type;
    $types[] = PDO::PARAM_STR;
}

if ($status) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

$where_sql = implode(' AND ', $where_conditions);

// Get asset statistics
$assets_sql = "SELECT 
    COUNT(*) as total_assets,
    COUNT(CASE WHEN status = 'Active' THEN 1 END) as active_assets,
    COUNT(CASE WHEN status = 'Under Maintenance' THEN 1 END) as maintenance_assets,
    COUNT(CASE WHEN status = 'Retired' THEN 1 END) as retired_assets,
    COUNT(CASE WHEN status = 'Inactive' THEN 1 END) as inactive_assets,
    COUNT(CASE WHEN warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 1 END) as warranty_expiring_soon,
    COUNT(CASE WHEN amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 1 END) as amc_expiring_soon,
    COUNT(CASE WHEN license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 1 END) as license_expiring_soon,
    COUNT(CASE WHEN warranty_expiry < CURRENT_DATE THEN 1 END) as warranty_expired,
    COUNT(CASE WHEN amc_expiry < CURRENT_DATE THEN 1 END) as amc_expired,
    COUNT(CASE WHEN license_expiry < CURRENT_DATE THEN 1 END) as license_expired,
    COUNT(DISTINCT client_id) as total_clients,
    COUNT(DISTINCT asset_type) as asset_types_count
FROM assets a
WHERE $where_sql";

$stmt = $pdo->prepare($assets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$assets_stats = $stmt->fetch();

// Get asset distribution by type
// Use a subquery to calculate the percentage
$type_distribution_sql = "SELECT 
    asset_type,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM assets a WHERE $where_sql), 1) as percentage
FROM assets a
WHERE $where_sql
GROUP BY asset_type
ORDER BY count DESC";

$stmt = $pdo->prepare($type_distribution_sql);
// We need to duplicate the parameters since they're used in both the main query and subquery
$duplicated_params = array_merge($params, $params);
$duplicated_types = array_merge($types, $types);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$type_distribution = $stmt->fetchAll();

// Get asset distribution by status
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM assets a WHERE $where_sql), 1) as percentage
FROM assets a
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
// We need to duplicate the parameters since they're used in both the main query and subquery
$duplicated_params = array_merge($params, $params);
$duplicated_types = array_merge($types, $types);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Get assets by client
$client_assets_sql = "SELECT 
    c.company_name,
    COUNT(a.id) as asset_count,
    COUNT(CASE WHEN a.status = 'Active' THEN 1 END) as active_count,
    COUNT(CASE WHEN a.warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' 
        OR a.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
        OR a.license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
        THEN 1 END) as expiring_soon
FROM clients c
LEFT JOIN assets a ON c.id = a.client_id
WHERE (a.created_at BETWEEN ? AND ?) OR a.id IS NULL
GROUP BY c.id, c.company_name
HAVING COUNT(a.id) > 0
ORDER BY asset_count DESC
LIMIT 10";

// Update the client assets query to use asset's created_at specifically
$stmt = $pdo->prepare($client_assets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$client_assets = $stmt->fetchAll();

// Get recent assets
$recent_assets_sql = "SELECT 
    a.*,
    c.company_name,
    cl.location_name
FROM assets a
LEFT JOIN clients c ON a.client_id = c.id
LEFT JOIN client_locations cl ON a.location_id = cl.id
WHERE $where_sql
ORDER BY a.created_at DESC
LIMIT 10";

$stmt = $pdo->prepare($recent_assets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$recent_assets = $stmt->fetchAll();

// Get expiring assets
$expiring_assets_sql = "SELECT 
    a.*,
    c.company_name,
    CASE 
        WHEN a.warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'Warranty'
        WHEN a.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'AMC'
        WHEN a.license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'License'
    END as expiry_type,
    LEAST(
        COALESCE(a.warranty_expiry, '9999-12-31'),
        COALESCE(a.amc_expiry, '9999-12-31'),
        COALESCE(a.license_expiry, '9999-12-31')
    ) as next_expiry
FROM assets a
LEFT JOIN clients c ON a.client_id = c.id
WHERE $where_sql AND (
    (a.warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') OR
    (a.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') OR
    (a.license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days')
)
ORDER BY next_expiry ASC
LIMIT 10";

$stmt = $pdo->prepare($expiring_assets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$expiring_assets = $stmt->fetchAll();

// Helper function to format numbers
function formatNumber($number)
{
    return number_format($number);
}

// Helper function to get asset icon
function getAssetIcon($type)
{
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

// Get report title
$report_title = 'Asset Report';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $report_title; ?> | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .report-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #eaeaea;
            transition: transform 0.2s;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .report-card h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
            color: #333;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        .progress-ring {
            width: 120px;
            height: 120px;
        }

        .distribution-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .distribution-item:last-child {
            border-bottom: none;
        }

        .distribution-label {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .distribution-percentage {
            font-weight: bold;
            color: #004E89;
        }

        .distribution-count {
            color: #666;
            font-size: 0.9rem;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .insight-card {
            background: linear-gradient(135deg, #004E89, #1a6cb0);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .insight-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        .trend-up {
            color: #28a745;
        }

        .trend-down {
            color: #dc3545;
        }

        .trend-neutral {
            color: #6c757d;
        }

        .metric-change {
            font-size: 0.9rem;
            margin-left: 10px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }

        .data-table th {
            position: sticky;
            top: 0;
            background: #004E89;
            color: white;
            z-index: 10;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-maintenance {
            background: #fff3cd;
            color: #856404;
        }

        .status-retired {
            background: #e2e3e5;
            color: #383d41;
        }

        .expiry-badge {
            background: #dc3545;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 2rem;
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
                    <h1><i class="fas fa-server"></i> <?php echo $report_title; ?></h1>
                    <p class="text-muted">Comprehensive asset reports and analytics</p>
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
                    <li class="breadcrumb-item"><a href="index.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Asset Report</li>
                </ol>
            </nav>

            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="type" value="asset">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="text" class="form-control datepicker" name="start_date"
                            value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="text" class="form-control datepicker" name="end_date"
                            value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Client</label>
                        <select class="form-select select2" name="client_id">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo htmlspecialchars($client['id']); ?>"
                                    <?php echo $client_id == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['company_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Asset Type</label>
                        <select class="form-select select2" name="asset_type">
                            <option value="">All Types</option>
                            <?php foreach ($asset_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['asset_type']); ?>"
                                    <?php echo $asset_type == $type['asset_type'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['asset_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select select2" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statuses as $stat): ?>
                                <option value="<?php echo htmlspecialchars($stat['status']); ?>"
                                    <?php echo $status == $stat['status'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($stat['status']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between">
                            <div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="?type=asset" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Clear Filters
                                </a>
                            </div>
                            <div class="export-buttons">
                                <button type="button" class="btn btn-outline-success" id="export-pdf">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </button>
                                <button type="button" class="btn btn-outline-info" id="export-excel">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </button>
                                <button type="button" class="btn btn-outline-warning" id="print-report">
                                    <i class="fas fa-print"></i> Print Report
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Asset Report -->
            <div class="report-section active" id="asset-report">
                <!-- Key Insights -->
                <div class="insight-card">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="text-white"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                            <p class="text-white-50 mb-0">
                                <?php if ($assets_stats['warranty_expiring_soon'] > 0): ?>
                                    • <?php echo $assets_stats['warranty_expiring_soon']; ?> assets have warranties expiring soon<br>
                                <?php endif; ?>
                                <?php if ($assets_stats['active_assets'] > 0): ?>
                                    • <?php echo round(($assets_stats['active_assets'] / $assets_stats['total_assets']) * 100, 1); ?>% of assets are active and operational<br>
                                <?php endif; ?>
                                <?php if ($assets_stats['total_clients'] > 0): ?>
                                    • Assets distributed across <?php echo $assets_stats['total_clients']; ?> clients<br>
                                <?php endif; ?>
                                • Assets in <?php echo $assets_stats['asset_types_count']; ?> different types
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="insight-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4 class="mb-0">Asset Health Score</h4>
                            <div class="display-4">
                                <?php
                                $health_score = 100;
                                if ($assets_stats['total_assets'] > 0) {
                                    $health_score = round((
                                        ($assets_stats['active_assets'] * 100) +
                                        ($assets_stats['maintenance_assets'] * 70) +
                                        ($assets_stats['inactive_assets'] * 30) -
                                        ($assets_stats['warranty_expired'] * 20) -
                                        ($assets_stats['amc_expired'] * 15)
                                    ) / $assets_stats['total_assets'], 0);
                                    $health_score = max(0, min(100, $health_score));
                                }
                                echo $health_score;
                                ?>
                            </div>
                            <small>/100</small>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="dashboard-grid">
                    <div class="report-card">
                        <h3><i class="fas fa-server"></i> Total Assets</h3>
                        <div class="stat-number"><?php echo formatNumber($assets_stats['total_assets']); ?></div>
                        <div class="stat-label">Managed Assets</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                            </small>
                        </div>
                    </div>

                    <div class="report-card">
                        <h3><i class="fas fa-check-circle"></i> Active Assets</h3>
                        <div class="stat-number"><?php echo formatNumber($assets_stats['active_assets']); ?></div>
                        <div class="stat-label">Operational</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-percentage"></i>
                                <?php echo $assets_stats['total_assets'] > 0 ? round(($assets_stats['active_assets'] / $assets_stats['total_assets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>

                    <div class="report-card">
                        <h3><i class="fas fa-tools"></i> Under Maintenance</h3>
                        <div class="stat-number"><?php echo formatNumber($assets_stats['maintenance_assets']); ?></div>
                        <div class="stat-label">Requiring Attention</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?php echo $assets_stats['total_assets'] > 0 ? round(($assets_stats['maintenance_assets'] / $assets_stats['total_assets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>

                    <div class="report-card">
                        <h3><i class="fas fa-clock"></i> Expiring Soon</h3>
                        <div class="stat-number">
                            <?php echo formatNumber($assets_stats['warranty_expiring_soon'] + $assets_stats['amc_expiring_soon'] + $assets_stats['license_expiring_soon']); ?>
                        </div>
                        <div class="stat-label">Within 30 Days</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i>
                                Warranty: <?php echo $assets_stats['warranty_expiring_soon']; ?> •
                                AMC: <?php echo $assets_stats['amc_expiring_soon']; ?> •
                                License: <?php echo $assets_stats['license_expiring_soon']; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-pie"></i> Asset Type Distribution</h3>
                            <div class="chart-container">
                                <canvas id="typeDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-bar"></i> Asset Status</h3>
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Assets -->
                <div class="report-card">
                    <h3><i class="fas fa-history"></i> Recently Added Assets</h3>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Asset Type</th>
                                    <th>Manufacturer/Model</th>
                                    <th>Client</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Added On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_assets as $asset): ?>
                                    <tr>
                                        <td>
                                            <i class="<?php echo getAssetIcon($asset['asset_type']); ?> me-2"></i>
                                            <?php echo htmlspecialchars($asset['asset_type']); ?>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($asset['manufacturer']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($asset['model']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($asset['company_name'] ?? 'Internal'); ?></td>
                                        <td><?php echo htmlspecialchars($asset['location_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                                <?php echo htmlspecialchars($asset['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($asset['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Expiring Assets -->
                <div class="report-card">
                    <h3><i class="fas fa-exclamation-triangle"></i> Assets Expiring Soon (Next 30 Days)</h3>

                    <?php if (empty($expiring_assets)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4>No Expiring Assets</h4>
                            <p class="text-muted">Great! No assets are expiring in the next 30 days.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table data-table">
                                <thead>
                                    <tr>
                                        <th>Asset Type</th>
                                        <th>Manufacturer/Model</th>
                                        <th>Client</th>
                                        <th>Expiry Type</th>
                                        <th>Expiry Date</th>
                                        <th>Days Left</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiring_assets as $asset):
                                        $expiry_date = new DateTime($asset['next_expiry']);
                                        $today = new DateTime();
                                        $days_left = $today->diff($expiry_date)->days;
                                        $is_critical = $days_left <= 7;
                                    ?>
                                        <tr class="<?php echo $is_critical ? 'table-danger' : ''; ?>">
                                            <td>
                                                <i class="<?php echo getAssetIcon($asset['asset_type']); ?> me-2"></i>
                                                <?php echo htmlspecialchars($asset['asset_type']); ?>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($asset['manufacturer']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($asset['model']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($asset['company_name'] ?? 'Internal'); ?></td>
                                            <td>
                                                <span class="expiry-badge">
                                                    <?php echo htmlspecialchars($asset['expiry_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($asset['next_expiry'])); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $days_left <= 7 ? 'danger' : ($days_left <= 14 ? 'warning' : 'info'); ?>">
                                                    <?php echo $days_left; ?> days
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                                    <?php echo htmlspecialchars($asset['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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
                allowInput: true
            });

            // Initialize charts
            initializeCharts();

            function initializeCharts() {
                // Asset Type Distribution Chart
                const typeCtx = document.getElementById('typeDistributionChart').getContext('2d');
                const typeLabels = <?php echo json_encode(array_column($type_distribution, 'asset_type')); ?>;
                const typeData = <?php echo json_encode(array_column($type_distribution, 'count')); ?>;

                new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            data: typeData,
                            backgroundColor: [
                                '#004E89', '#FF6B35', '#28a745', '#17a2b8', '#6610f2',
                                '#fd7e14', '#20c997', '#e83e8c', '#6f42c1', '#ffc107'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });

                // Asset Status Distribution Chart
                const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
                const statusLabels = <?php echo json_encode(array_column($status_distribution, 'status')); ?>;
                const statusData = <?php echo json_encode(array_column($status_distribution, 'count')); ?>;

                new Chart(statusCtx, {
                    type: 'bar',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            label: 'Asset Count',
                            data: statusData,
                            backgroundColor: [
                                '#28a745', '#ffc107', '#6c757d', '#dc3545'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Assets'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Status'
                                }
                            }
                        }
                    }
                });
            }

            // Export functionality
            $('#export-pdf').on('click', function() {
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header bg-info text-white">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong class="me-auto">PDF Export</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                PDF export feature will be available in the next update.
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 3000);
            });

            $('#export-excel').on('click', function() {
                // Generate CSV data
                let csvContent = "Asset Report - " + new Date().toLocaleDateString() + "\n";
                csvContent += "Date Range," + "<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>" + "\n\n";

                // Add summary data
                csvContent += "SUMMARY\n";
                csvContent += "Total Assets," + <?php echo $assets_stats['total_assets']; ?> + "\n";
                csvContent += "Active Assets," + <?php echo $assets_stats['active_assets']; ?> + "\n";
                csvContent += "Under Maintenance," + <?php echo $assets_stats['maintenance_assets']; ?> + "\n";
                csvContent += "Expiring Soon," + <?php echo $assets_stats['warranty_expiring_soon'] + $assets_stats['amc_expiring_soon'] + $assets_stats['license_expiring_soon']; ?> + "\n\n";

                // Add type distribution
                csvContent += "ASSET TYPE DISTRIBUTION\n";
                csvContent += "Asset Type,Count,Percentage\n";
                <?php foreach ($type_distribution as $item): ?>
                    csvContent += "<?php echo $item['asset_type']; ?>,<?php echo $item['count']; ?>,<?php echo $item['percentage']; ?>%\n";
                <?php endforeach; ?>
                csvContent += "\n";

                // Add recent assets
                csvContent += "RECENTLY ADDED ASSETS\n";
                csvContent += "Asset Type,Manufacturer,Model,Client,Status,Added On\n";
                <?php foreach ($recent_assets as $asset): ?>
                    csvContent += "<?php echo $asset['asset_type']; ?>,<?php echo $asset['manufacturer']; ?>,<?php echo $asset['model']; ?>,<?php echo $asset['company_name'] ?? 'Internal'; ?>,<?php echo $asset['status']; ?>,<?php echo date('Y-m-d', strtotime($asset['created_at'])); ?>\n";
                <?php endforeach; ?>

                // Create and download CSV
                const blob = new Blob([csvContent], {
                    type: 'text/csv'
                });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'asset_report_' + new Date().toISOString().split('T')[0] + '.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            });

            $('#print-report').on('click', function() {
                window.print();
            });
        });
    </script>
</body>

</html>