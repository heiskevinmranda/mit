<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to view asset reports.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Default date range (last 30 days)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$client_id = $_GET['client_id'] ?? '';
$asset_type = $_GET['asset_type'] ?? '';
$status = $_GET['status'] ?? '';
$report_type = $_GET['report_type'] ?? 'summary';

// Get filter options
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$asset_types = $pdo->query("SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL ORDER BY asset_type")->fetchAll();
$statuses = $pdo->query("SELECT DISTINCT status FROM assets WHERE status IS NOT NULL ORDER BY status")->fetchAll();

// Build where clause for reports
$where_conditions = ['1=1'];
$params = [];
$types = [];

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

// Get overall statistics
$stats_sql = "SELECT 
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

$stmt = $pdo->prepare($stats_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$stats = $stmt->fetch();

// Get asset distribution by type
$type_distribution_sql = "SELECT 
    asset_type,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM assets WHERE $where_sql), 1) as percentage
FROM assets
WHERE $where_sql
GROUP BY asset_type
ORDER BY count DESC";

$stmt = $pdo->prepare($type_distribution_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$type_distribution = $stmt->fetchAll();

// Get status distribution
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM assets WHERE $where_sql), 1) as percentage
FROM assets
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Get assets by client - FIXED: Specify a.id instead of just id
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
WHERE ($where_sql) OR a.id IS NULL
GROUP BY c.id, c.company_name
HAVING COUNT(a.id) > 0
ORDER BY asset_count DESC
LIMIT 10";

// Need to modify where_sql for this query since it references 'a.' alias
$client_where_sql = str_replace('a.', '', $where_sql);
$stmt = $pdo->prepare(str_replace($where_sql, $client_where_sql, $client_assets_sql));
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$client_assets = $stmt->fetchAll();

// Get recently added assets
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

// Get asset value summary (if purchase price was tracked)
$asset_value_sql = "SELECT 
    a.asset_type,
    COUNT(a.id) as count,
    COALESCE(SUM(ct.monthly_amount), 0) as total_monthly_value
FROM assets a
LEFT JOIN contracts ct ON a.client_id = ct.client_id AND ct.status = 'Active'
WHERE $where_sql
GROUP BY a.asset_type
ORDER BY total_monthly_value DESC";

$stmt = $pdo->prepare($asset_value_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$asset_values = $stmt->fetchAll();

// Get monthly asset additions
$monthly_additions_sql = "SELECT 
    DATE_TRUNC('month', created_at) as month,
    COUNT(*) as asset_count
FROM assets
WHERE $where_sql AND created_at >= CURRENT_DATE - INTERVAL '6 months'
GROUP BY DATE_TRUNC('month', created_at)
ORDER BY month ASC";

$stmt = $pdo->prepare($monthly_additions_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$monthly_additions = $stmt->fetchAll();

// Get detailed client stats - FIXED: Specify a.id instead of just id
$client_details_sql = "SELECT 
    c.company_name,
    COUNT(a.id) as total_assets,
    COUNT(CASE WHEN a.status = 'Active' THEN 1 END) as active_count,
    COUNT(CASE WHEN a.status = 'Under Maintenance' THEN 1 END) as maintenance_count,
    COUNT(CASE WHEN a.warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' 
        OR a.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
        OR a.license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
        THEN 1 END) as expiring_soon,
    COUNT(DISTINCT a.asset_type) as asset_types_count
FROM clients c
LEFT JOIN assets a ON c.id = a.client_id
GROUP BY c.id, c.company_name
HAVING COUNT(a.id) > 0
ORDER BY total_assets DESC";

$client_details = $pdo->query($client_details_sql)->fetchAll();

// Helper function to format numbers
function formatNumber($number) {
    return number_format($number);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Reports | MSP Application</title>
    <link rel="stylesheet" href="../../css/style.css">
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
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #eaeaea;
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
        .report-type-tabs {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        .report-tab {
            padding: 12px 24px;
            border: none;
            background: none;
            color: #6c757d;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.3s;
        }
        .report-tab:hover {
            color: #004E89;
            background: #f8f9fa;
        }
        .report-tab.active {
            color: #004E89;
            border-bottom-color: #004E89;
            background: #e7f0ff;
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
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .trend-neutral { color: #6c757d; }
        .metric-change {
            font-size: 0.9rem;
            margin-left: 10px;
        }
        .report-section {
            display: none;
        }
        .report-section.active {
            display: block;
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-retired { background: #e2e3e5; color: #383d41; }
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
            .report-type-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            .report-tab {
                padding: 10px 15px;
                white-space: nowrap;
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
                    <h1><i class="fas fa-chart-bar"></i> Asset Reports & Analytics</h1>
                    <p class="text-muted">Comprehensive insights into your asset portfolio</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Asset Reports</li>
                </ol>
            </nav>
            
            <!-- Report Type Tabs -->
            <div class="report-type-tabs">
                <button class="report-tab active" data-report="summary">
                    <i class="fas fa-tachometer-alt"></i> Summary Dashboard
                </button>
                <button class="report-tab" data-report="distribution">
                    <i class="fas fa-chart-pie"></i> Distribution Analysis
                </button>
                <button class="report-tab" data-report="expiry">
                    <i class="fas fa-clock"></i> Expiry Management
                </button>
                <button class="report-tab" data-report="clients">
                    <i class="fas fa-building"></i> Client Assets
                </button>
                <button class="report-tab" data-report="performance">
                    <i class="fas fa-chart-line"></i> Performance Metrics
                </button>
            </div>
            
            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
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
                                <a href="reports.php" class="btn btn-outline-secondary">
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
            
            <!-- Summary Dashboard (Default) -->
            <div class="report-section active" id="summary-report">
                <!-- Key Insights -->
                <div class="insight-card">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="text-white"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                            <p class="text-white-50 mb-0">
                                <?php if ($stats['warranty_expiring_soon'] > 0): ?>
                                • <?php echo $stats['warranty_expiring_soon']; ?> assets have warranties expiring soon<br>
                                <?php endif; ?>
                                <?php if ($stats['active_assets'] > 0): ?>
                                • <?php echo round(($stats['active_assets'] / $stats['total_assets']) * 100, 1); ?>% of assets are active and operational<br>
                                <?php endif; ?>
                                • Assets distributed across <?php echo $stats['asset_types_count']; ?> different types<br>
                                • Serving <?php echo $stats['total_clients']; ?> clients with managed assets
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
                                if ($stats['total_assets'] > 0) {
                                    $health_score = round((
                                        ($stats['active_assets'] * 100) +
                                        ($stats['maintenance_assets'] * 70) +
                                        ($stats['inactive_assets'] * 30) -
                                        ($stats['warranty_expired'] * 20) -
                                        ($stats['amc_expired'] * 15)
                                    ) / $stats['total_assets'], 0);
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
                        <div class="stat-number"><?php echo formatNumber($stats['total_assets']); ?></div>
                        <div class="stat-label">Managed Assets</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-chart-line"></i> Across <?php echo $stats['asset_types_count']; ?> asset types
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-check-circle"></i> Active Assets</h3>
                        <div class="stat-number"><?php echo formatNumber($stats['active_assets']); ?></div>
                        <div class="stat-label">Operational</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-percentage"></i> 
                                <?php echo $stats['total_assets'] > 0 ? round(($stats['active_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-tools"></i> Under Maintenance</h3>
                        <div class="stat-number"><?php echo formatNumber($stats['maintenance_assets']); ?></div>
                        <div class="stat-label">Requiring Attention</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <?php echo $stats['total_assets'] > 0 ? round(($stats['maintenance_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-clock"></i> Expiring Soon</h3>
                        <div class="stat-number">
                            <?php echo formatNumber($stats['warranty_expiring_soon'] + $stats['amc_expiring_soon'] + $stats['license_expiring_soon']); ?>
                        </div>
                        <div class="stat-label">Within 30 Days</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                Warranty: <?php echo $stats['warranty_expiring_soon']; ?> • 
                                AMC: <?php echo $stats['amc_expiring_soon']; ?> • 
                                License: <?php echo $stats['license_expiring_soon']; ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Asset Type Distribution Chart -->
                <div class="report-card">
                    <h3><i class="fas fa-chart-pie"></i> Asset Type Distribution</h3>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <canvas id="typeDistributionChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($type_distribution as $item): ?>
                                <div class="distribution-item">
                                    <div class="distribution-label">
                                        <i class="<?php echo getAssetIcon($item['asset_type']); ?>"></i>
                                        <span><?php echo htmlspecialchars($item['asset_type']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <div class="distribution-percentage"><?php echo $item['percentage']; ?>%</div>
                                        <div class="distribution-count"><?php echo formatNumber($item['count']); ?> assets</div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Assets Added -->
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
            </div>
            
            <!-- Distribution Analysis -->
            <div class="report-section" id="distribution-report">
                <div class="report-card">
                    <h3><i class="fas fa-chart-bar"></i> Status Distribution Analysis</h3>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="chart-container">
                                <canvas id="statusPieChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h4>Status Breakdown</h4>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($status_distribution as $item): ?>
                                <div class="distribution-item">
                                    <div class="distribution-label">
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $item['status'])); ?>">
                                            <?php echo htmlspecialchars($item['status']); ?>
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <div class="distribution-percentage"><?php echo $item['percentage']; ?>%</div>
                                        <div class="distribution-count"><?php echo formatNumber($item['count']); ?> assets</div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h4>Health Metrics</h4>
                            <div class="mt-3">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Operational Efficiency</span>
                                        <span><?php echo $stats['total_assets'] > 0 ? round(($stats['active_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $stats['total_assets'] > 0 ? ($stats['active_assets'] / $stats['total_assets']) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Maintenance Load</span>
                                        <span><?php echo $stats['total_assets'] > 0 ? round(($stats['maintenance_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo $stats['total_assets'] > 0 ? ($stats['maintenance_assets'] / $stats['total_assets']) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Retirement Rate</span>
                                        <span><?php echo $stats['total_assets'] > 0 ? round(($stats['retired_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-secondary" style="width: <?php echo $stats['total_assets'] > 0 ? ($stats['retired_assets'] / $stats['total_assets']) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Expiry Management -->
            <div class="report-section" id="expiry-report">
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
                                    <th>Serial/Tag</th>
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
                                    <td>
                                        <div><?php echo htmlspecialchars($asset['serial_number'] ?? 'N/A'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($asset['asset_tag'] ?? ''); ?></small>
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
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>Total expiring assets:</strong> <?php echo count($expiring_assets); ?> 
                        • <strong>Critical (≤7 days):</strong> <?php 
                            $critical_count = 0;
                            foreach ($expiring_assets as $asset) {
                                $expiry_date = new DateTime($asset['next_expiry']);
                                $today = new DateTime();
                                if ($today->diff($expiry_date)->days <= 7) {
                                    $critical_count++;
                                }
                            }
                            echo $critical_count;
                        ?>
                        • <strong>Warning (8-14 days):</strong> <?php 
                            $warning_count = 0;
                            foreach ($expiring_assets as $asset) {
                                $expiry_date = new DateTime($asset['next_expiry']);
                                $today = new DateTime();
                                $days = $today->diff($expiry_date)->days;
                                if ($days > 7 && $days <= 14) {
                                    $warning_count++;
                                }
                            }
                            echo $warning_count;
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Expiry Summary -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="report-card">
                            <h3><i class="fas fa-shield-alt"></i> Warranty Status</h3>
                            <div class="stat-number"><?php echo formatNumber($stats['warranty_expiring_soon']); ?></div>
                            <div class="stat-label">Expiring Soon</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-times-circle text-danger"></i> 
                                    <?php echo formatNumber($stats['warranty_expired']); ?> expired
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="report-card">
                            <h3><i class="fas fa-tools"></i> AMC Status</h3>
                            <div class="stat-number"><?php echo formatNumber($stats['amc_expiring_soon']); ?></div>
                            <div class="stat-label">Expiring Soon</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-times-circle text-danger"></i> 
                                    <?php echo formatNumber($stats['amc_expired']); ?> expired
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="report-card">
                            <h3><i class="fas fa-certificate"></i> License Status</h3>
                            <div class="stat-number"><?php echo formatNumber($stats['license_expiring_soon']); ?></div>
                            <div class="stat-label">Expiring Soon</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-times-circle text-danger"></i> 
                                    <?php echo formatNumber($stats['license_expired']); ?> expired
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Client Assets -->
            <div class="report-section" id="clients-report">
                <div class="report-card">
                    <h3><i class="fas fa-building"></i> Asset Distribution by Client (Top 10)</h3>
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <canvas id="clientAssetsChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($client_assets as $client): ?>
                                <div class="distribution-item">
                                    <div class="distribution-label">
                                        <i class="fas fa-building"></i>
                                        <span><?php echo htmlspecialchars($client['company_name']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <div class="distribution-percentage"><?php echo $client['asset_count']; ?></div>
                                        <div class="distribution-count">
                                            <span class="text-success"><?php echo $client['active_count']; ?> active</span>
                                            <?php if ($client['expiring_soon'] > 0): ?>
                                            <br><small class="text-danger"><?php echo $client['expiring_soon']; ?> expiring</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Client Asset Details -->
                <div class="report-card">
                    <h3><i class="fas fa-list-alt"></i> Client Asset Details</h3>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Total Assets</th>
                                    <th>Active</th>
                                    <th>Under Maintenance</th>
                                    <th>Expiring Soon</th>
                                    <th>Asset Types</th>
                                    <th>Health Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($client_details as $client):
                                    $health_score = 0;
                                    if ($client['total_assets'] > 0) {
                                        $health_score = round((
                                            ($client['active_count'] * 100) +
                                            ($client['maintenance_count'] * 70)
                                        ) / $client['total_assets'], 0);
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($client['company_name']); ?></td>
                                    <td><?php echo formatNumber($client['total_assets']); ?></td>
                                    <td>
                                        <span class="text-success"><?php echo formatNumber($client['active_count']); ?></span>
                                    </td>
                                    <td>
                                        <span class="text-warning"><?php echo formatNumber($client['maintenance_count']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($client['expiring_soon'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $client['expiring_soon']; ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $client['asset_types_count']; ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo $health_score; ?>%">
                                                <?php echo $health_score; ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Performance Metrics -->
            <div class="report-section" id="performance-report">
                <div class="report-card">
                    <h3><i class="fas fa-chart-line"></i> Asset Growth Trend</h3>
                    <div class="chart-container">
                        <canvas id="growthChart"></canvas>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-money-bill-wave"></i> Asset Value Summary</h3>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($asset_values)): ?>
                                    <?php foreach ($asset_values as $value): ?>
                                    <div class="distribution-item">
                                        <div class="distribution-label">
                                            <i class="<?php echo getAssetIcon($value['asset_type']); ?>"></i>
                                            <span><?php echo htmlspecialchars($value['asset_type']); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <div class="distribution-percentage">
                                                $<?php echo number_format($value['total_monthly_value'], 2); ?>
                                            </div>
                                            <div class="distribution-count">
                                                <?php echo formatNumber($value['count']); ?> assets
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                                        <p>No contract value data available</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-calendar-alt"></i> Monthly Additions</h3>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($monthly_additions)): ?>
                                    <?php foreach ($monthly_additions as $addition): ?>
                                    <div class="distribution-item">
                                        <div class="distribution-label">
                                            <i class="fas fa-calendar"></i>
                                            <span><?php echo date('F Y', strtotime($addition['month'])); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <div class="distribution-percentage">+<?php echo $addition['asset_count']; ?></div>
                                            <div class="distribution-count">assets added</div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-calendar fa-3x mb-3"></i>
                                        <p>No addition data available</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Metrics -->
                <div class="report-card">
                    <h3><i class="fas fa-tachometer-alt"></i> Performance Metrics</h3>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #28a745; color: white;">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-number">
                                    <?php echo $stats['total_assets'] > 0 ? round(($stats['active_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>%
                                </div>
                                <div class="stat-label">Uptime Rate</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #ffc107; color: #212529;">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <div class="stat-number">
                                    <?php echo $stats['total_assets'] > 0 ? round(($stats['maintenance_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>%
                                </div>
                                <div class="stat-label">Maintenance Rate</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #dc3545; color: white;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="stat-number">
                                    <?php 
                                    $expired_total = $stats['warranty_expired'] + $stats['amc_expired'] + $stats['license_expired'];
                                    echo $stats['total_assets'] > 0 ? round(($expired_total / $stats['total_assets']) * 100, 1) : 0;
                                    ?>%
                                </div>
                                <div class="stat-label">Expired Rate</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-icon" style="background: #17a2b8; color: white;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-number">
                                    <?php echo $stats['total_clients'] > 0 ? round($stats['total_assets'] / $stats['total_clients'], 1) : 0; ?>
                                </div>
                                <div class="stat-label">Assets per Client</div>
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
            
            // Report type tabs
            $('.report-tab').on('click', function() {
                const reportType = $(this).data('report');
                
                // Update active tab
                $('.report-tab').removeClass('active');
                $(this).addClass('active');
                
                // Show corresponding report section
                $('.report-section').removeClass('active');
                $(`#${reportType}-report`).addClass('active');
                
                // Update URL without reloading
                const url = new URL(window.location);
                url.searchParams.set('report_type', reportType);
                window.history.replaceState({}, '', url);
            });
            
            // Initialize charts
            initializeCharts();
            
            function initializeCharts() {
                // Asset Type Distribution Chart
                const typeCtx = document.getElementById('typeDistributionChart').getContext('2d');
                const typeLabels = <?php echo json_encode(array_column($type_distribution, 'asset_type')); ?>;
                const typeData = <?php echo json_encode(array_column($type_distribution, 'count')); ?>;
                
                new Chart(typeCtx, {
                    type: 'bar',
                    data: {
                        labels: typeLabels,
                        datasets: [{
                            label: 'Asset Count',
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
                                    text: 'Asset Type'
                                }
                            }
                        }
                    }
                });
                
                // Status Distribution Chart
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
                                '#28a745', // Active
                                '#ffc107', // Under Maintenance
                                '#6c757d', // Retired
                                '#dc3545', // Inactive
                                '#17a2b8'  // Other
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
                
                // Status Pie Chart
                const pieCtx = document.getElementById('statusPieChart').getContext('2d');
                new Chart(pieCtx, {
                    type: 'pie',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            data: statusData,
                            backgroundColor: [
                                '#28a745', '#ffc107', '#6c757d', '#dc3545', '#17a2b8'
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
                
                // Client Assets Chart
                const clientCtx = document.getElementById('clientAssetsChart').getContext('2d');
                const clientLabels = <?php echo json_encode(array_column($client_assets, 'company_name')); ?>;
                const clientData = <?php echo json_encode(array_column($client_assets, 'asset_count')); ?>;
                
                new Chart(clientCtx, {
                    type: 'bar',
                    data: {
                        labels: clientLabels,
                        datasets: [{
                            label: 'Total Assets',
                            data: clientData,
                            backgroundColor: '#004E89',
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
                                    text: 'Client'
                                }
                            }
                        }
                    }
                });
                
                // Growth Chart
                const growthCtx = document.getElementById('growthChart').getContext('2d');
                const growthLabels = <?php echo json_encode(array_map(function($item) {
                    return date('M Y', strtotime($item['month']));
                }, $monthly_additions)); ?>;
                const growthData = <?php echo json_encode(array_column($monthly_additions, 'asset_count')); ?>;
                
                new Chart(growthCtx, {
                    type: 'line',
                    data: {
                        labels: growthLabels,
                        datasets: [{
                            label: 'Assets Added',
                            data: growthData,
                            borderColor: '#004E89',
                            backgroundColor: 'rgba(0, 78, 137, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Assets Added'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Month'
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
                let csvContent = "Asset Report - " + new Date().toLocaleDateString() + "\n\n";
                
                // Add summary data
                csvContent += "SUMMARY\n";
                csvContent += "Total Assets," + <?php echo $stats['total_assets']; ?> + "\n";
                csvContent += "Active Assets," + <?php echo $stats['active_assets']; ?> + "\n";
                csvContent += "Under Maintenance," + <?php echo $stats['maintenance_assets']; ?> + "\n";
                csvContent += "Expiring Soon," + <?php echo $stats['warranty_expiring_soon'] + $stats['amc_expiring_soon'] + $stats['license_expiring_soon']; ?> + "\n\n";
                
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
                const blob = new Blob([csvContent], { type: 'text/csv' });
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
            
            // Auto-refresh data every 5 minutes
            setInterval(function() {
                // In a real implementation, this would refresh charts/data
                console.log('Auto-refreshing report data...');
            }, 300000);
            
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl+P to print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    $('#print-report').click();
                }
                // Ctrl+E to export Excel
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    $('#export-excel').click();
                }
                // Number keys 1-5 to switch tabs
                if (e.key >= '1' && e.key <= '5') {
                    e.preventDefault();
                    const tabIndex = parseInt(e.key) - 1;
                    $('.report-tab').eq(tabIndex).click();
                }
            });
            
            // Initialize with correct tab based on URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const reportType = urlParams.get('report_type') || 'summary';
            $(`.report-tab[data-report="${reportType}"]`).click();
        });
    </script>
</body>
</html>