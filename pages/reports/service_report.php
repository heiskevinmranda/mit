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
$service_type = $_GET['service_type'] ?? '';
$status = $_GET['status'] ?? '';

// Get filter options
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$service_types = $pdo->query("SELECT DISTINCT service_type FROM service_contracts WHERE service_type IS NOT NULL ORDER BY service_type")->fetchAll();
$statuses = ['Active', 'Expiring Soon', 'Expired', 'Inactive'];

// Build where clause for reports
$where_conditions = ['sc.created_at BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = [PDO::PARAM_STR, PDO::PARAM_STR];

if ($client_id) {
    $where_conditions[] = "sc.client_id = ?";
    $params[] = $client_id;
    $types[] = PDO::PARAM_STR;
}

if ($service_type) {
    $where_conditions[] = "sc.service_type = ?";
    $params[] = $service_type;
    $types[] = PDO::PARAM_STR;
}

if ($status) {
    $where_conditions[] = "sc.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

$where_sql = implode(' AND ', $where_conditions);

// Get service contract statistics
$contracts_sql = "SELECT 
    COUNT(*) as total_contracts,
    COUNT(CASE WHEN status = 'Active' THEN 1 END) as active_contracts,
    COUNT(CASE WHEN status = 'Expiring Soon' THEN 1 END) as expiring_contracts,
    COUNT(CASE WHEN status = 'Expired' THEN 1 END) as expired_contracts,
    COUNT(CASE WHEN status = 'Inactive' THEN 1 END) as inactive_contracts,
    SUM(CASE WHEN status = 'Active' THEN monthly_amount ELSE 0 END) as total_monthly_revenue,
    SUM(CASE WHEN status = 'Active' THEN annual_amount ELSE 0 END) as total_annual_revenue,
    COUNT(DISTINCT client_id) as total_clients,
    COUNT(DISTINCT service_type) as service_types_count
FROM service_contracts sc
WHERE $where_sql";

$stmt = $pdo->prepare($contracts_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$contracts_stats = $stmt->fetch();

// Get contract distribution by type
$type_distribution_sql = "SELECT 
    service_type,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM service_contracts sc WHERE $where_sql), 1) as percentage
FROM service_contracts sc
WHERE $where_sql
GROUP BY service_type
ORDER BY count DESC";

$stmt = $pdo->prepare($type_distribution_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$type_distribution = $stmt->fetchAll();

// Get contract distribution by status
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM service_contracts sc WHERE $where_sql), 1) as percentage
FROM service_contracts sc
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Get contracts by client
$client_contracts_sql = "SELECT 
    c.company_name,
    COUNT(sc.id) as contract_count,
    COUNT(CASE WHEN sc.status = 'Active' THEN 1 END) as active_count,
    SUM(CASE WHEN sc.status = 'Active' THEN sc.monthly_amount ELSE 0 END) as monthly_revenue
FROM clients c
LEFT JOIN service_contracts sc ON c.id = sc.client_id
WHERE ($where_sql) OR sc.id IS NULL
GROUP BY c.id, c.company_name
HAVING COUNT(sc.id) > 0
ORDER BY contract_count DESC
LIMIT 10";

// Need to modify where_sql for this query since it references 'sc.' alias
$client_where_sql = str_replace('sc.', '', $where_sql);
$stmt = $pdo->prepare(str_replace($where_sql, $client_where_sql, $client_contracts_sql));
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$client_contracts = $stmt->fetchAll();

// Get recent contracts
$recent_contracts_sql = "SELECT 
    sc.*,
    c.company_name
FROM service_contracts sc
LEFT JOIN clients c ON sc.client_id = c.id
WHERE $where_sql
ORDER BY sc.created_at DESC
LIMIT 10";

$stmt = $pdo->prepare($recent_contracts_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$recent_contracts = $stmt->fetchAll();

// Get expiring contracts
$expiring_contracts_sql = "SELECT 
    sc.*,
    c.company_name,
    CASE 
        WHEN sc.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'Contract'
        WHEN sc.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'AMC'
        WHEN sc.support_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 'Support'
    END as expiry_type,
    LEAST(
        COALESCE(sc.expiry_date, '9999-12-31'),
        COALESCE(sc.amc_expiry, '9999-12-31'),
        COALESCE(sc.support_expiry, '9999-12-31')
    ) as next_expiry
FROM service_contracts sc
LEFT JOIN clients c ON sc.client_id = c.id
WHERE $where_sql AND (
    (sc.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') OR
    (sc.amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') OR
    (sc.support_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days')
)
ORDER BY next_expiry ASC
LIMIT 10";

$stmt = $pdo->prepare($expiring_contracts_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$expiring_contracts = $stmt->fetchAll();

// Helper function to format numbers
function formatNumber($number) {
    return number_format($number);
}

function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

// Get report title
$report_title = 'Service Contract Report';
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
                    <h1><i class="fas fa-file-contract"></i> <?php echo $report_title; ?></h1>
                    <p class="text-muted">Comprehensive service contract reports and analytics</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Service Contract Report</li>
                </ol>
            </nav>
            
            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="type" value="service">
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
                        <label class="form-label">Service Type</label>
                        <select class="form-select select2" name="service_type">
                            <option value="">All Types</option>
                            <?php foreach ($service_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['service_type']); ?>"
                                <?php echo $service_type == $type['service_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['service_type']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select class="form-select select2" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statuses as $status_opt): ?>
                            <option value="<?php echo htmlspecialchars($status_opt); ?>"
                                <?php echo $status == $status_opt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_opt); ?>
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
                                <a href="?type=service" class="btn btn-outline-secondary">
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
            
            <!-- Service Contract Report -->
            <div class="report-section active" id="service-report">
                <!-- Key Insights -->
                <div class="insight-card">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="text-white"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                            <p class="text-white-50 mb-0">
                                <?php if ($contracts_stats['expiring_contracts'] > 0): ?>
                                • <?php echo $contracts_stats['expiring_contracts']; ?> contracts expiring soon<br>
                                <?php endif; ?>
                                <?php if ($contracts_stats['active_contracts'] > 0): ?>
                                • <?php echo formatCurrency($contracts_stats['total_monthly_revenue']); ?> monthly recurring revenue<br>
                                <?php endif; ?>
                                <?php if ($contracts_stats['total_clients'] > 0): ?>
                                • Contracts distributed across <?php echo $contracts_stats['total_clients']; ?> clients<br>
                                <?php endif; ?>
                                • <?php echo $contracts_stats['service_types_count']; ?> different service types
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="insight-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4 class="mb-0">Contract Health Score</h4>
                            <div class="display-4">
                                <?php 
                                $health_score = 100;
                                if ($contracts_stats['total_contracts'] > 0) {
                                    $active_rate = $contracts_stats['active_contracts'] / $contracts_stats['total_contracts'];
                                    $expiring_rate = $contracts_stats['expiring_contracts'] / $contracts_stats['total_contracts'];
                                    $expired_rate = $contracts_stats['expired_contracts'] / $contracts_stats['total_contracts'];
                                    
                                    $health_score = round((($active_rate * 100) - ($expiring_rate * 30) - ($expired_rate * 50)) * 2, 0);
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
                        <h3><i class="fas fa-file-contract"></i> Total Contracts</h3>
                        <div class="stat-number"><?php echo formatNumber($contracts_stats['total_contracts']); ?></div>
                        <div class="stat-label">Service Contracts</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-check-circle"></i> Active Contracts</h3>
                        <div class="stat-number"><?php echo formatNumber($contracts_stats['active_contracts']); ?></div>
                        <div class="stat-label">Generating Revenue</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-percentage"></i> 
                                <?php echo $contracts_stats['total_contracts'] > 0 ? round(($contracts_stats['active_contracts'] / $contracts_stats['total_contracts']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-dollar-sign"></i> Monthly Revenue</h3>
                        <div class="stat-number"><?php echo formatCurrency($contracts_stats['total_monthly_revenue']); ?></div>
                        <div class="stat-label">Recurring Income</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-arrow-up trend-up"></i> 
                                From active contracts
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-clock"></i> Expiring Soon</h3>
                        <div class="stat-number"><?php echo formatNumber($contracts_stats['expiring_contracts']); ?></div>
                        <div class="stat-label">Within 30 Days</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-exclamation-triangle text-warning"></i> 
                                Requires attention
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Charts -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-pie"></i> Service Type Distribution</h3>
                            <div class="chart-container">
                                <canvas id="typeDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-bar"></i> Contract Status</h3>
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Contracts -->
                <div class="report-card">
                    <h3><i class="fas fa-history"></i> Recent Service Contracts</h3>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Service Type</th>
                                    <th>Client</th>
                                    <th>Description</th>
                                    <th>Monthly Amount</th>
                                    <th>Status</th>
                                    <th>Start Date</th>
                                    <th>Expiry Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_contracts as $contract): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($contract['service_type']); ?></td>
                                    <td><?php echo htmlspecialchars($contract['company_name'] ?? 'Internal'); ?></td>
                                    <td><?php echo htmlspecialchars($contract['description'] ?? 'N/A'); ?></td>
                                    <td><?php echo formatCurrency($contract['monthly_amount']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $contract['status'])); ?>">
                                            <?php echo htmlspecialchars($contract['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $contract['start_date'] ? date('M d, Y', strtotime($contract['start_date'])) : 'N/A'; ?></td>
                                    <td><?php echo $contract['expiry_date'] ? date('M d, Y', strtotime($contract['expiry_date'])) : 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Expiring Contracts -->
                <div class="report-card">
                    <h3><i class="fas fa-exclamation-triangle"></i> Contracts Expiring Soon (Next 30 Days)</h3>
                    
                    <?php if (empty($expiring_contracts)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h4>No Expiring Contracts</h4>
                        <p class="text-muted">Great! No contracts are expiring in the next 30 days.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Service Type</th>
                                    <th>Client</th>
                                    <th>Monthly Amount</th>
                                    <th>Expiry Type</th>
                                    <th>Expiry Date</th>
                                    <th>Days Left</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expiring_contracts as $contract): 
                                    $expiry_date = new DateTime($contract['next_expiry']);
                                    $today = new DateTime();
                                    $days_left = $today->diff($expiry_date)->days;
                                    $is_critical = $days_left <= 7;
                                ?>
                                <tr class="<?php echo $is_critical ? 'table-danger' : ''; ?>">
                                    <td><?php echo htmlspecialchars($contract['service_type']); ?></td>
                                    <td><?php echo htmlspecialchars($contract['company_name'] ?? 'Internal'); ?></td>
                                    <td><?php echo formatCurrency($contract['monthly_amount']); ?></td>
                                    <td>
                                        <span class="expiry-badge">
                                            <?php echo htmlspecialchars($contract['expiry_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($contract['next_expiry'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $days_left <= 7 ? 'danger' : ($days_left <= 14 ? 'warning' : 'info'); ?>">
                                            <?php echo $days_left; ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $contract['status'])); ?>">
                                            <?php echo htmlspecialchars($contract['status']); ?>
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
                // Service Type Distribution Chart
                const typeCtx = document.getElementById('typeDistributionChart').getContext('2d');
                const typeLabels = <?php echo json_encode(array_column($type_distribution, 'service_type')); ?>;
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
                
                // Contract Status Distribution Chart
                const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
                const statusLabels = <?php echo json_encode(array_column($status_distribution, 'status')); ?>;
                const statusData = <?php echo json_encode(array_column($status_distribution, 'count')); ?>;
                
                new Chart(statusCtx, {
                    type: 'bar',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            label: 'Contract Count',
                            data: statusData,
                            backgroundColor: [
                                '#28a745', '#ffc107', '#dc3545', '#6c757d'
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
                                    text: 'Number of Contracts'
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
                let csvContent = "Service Contract Report - " + new Date().toLocaleDateString() + "\n";
                csvContent += "Date Range," + "<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>" + "\n\n";
                
                // Add summary data
                csvContent += "SUMMARY\n";
                csvContent += "Total Contracts," + <?php echo $contracts_stats['total_contracts']; ?> + "\n";
                csvContent += "Active Contracts," + <?php echo $contracts_stats['active_contracts']; ?> + "\n";
                csvContent += "Monthly Revenue," + <?php echo $contracts_stats['total_monthly_revenue']; ?> + "\n";
                csvContent += "Expiring Soon," + <?php echo $contracts_stats['expiring_contracts']; ?> + "\n\n";
                
                // Add type distribution
                csvContent += "SERVICE TYPE DISTRIBUTION\n";
                csvContent += "Service Type,Count,Percentage\n";
                <?php foreach ($type_distribution as $item): ?>
                csvContent += "<?php echo $item['service_type']; ?>,<?php echo $item['count']; ?>,<?php echo $item['percentage']; ?>%\n";
                <?php endforeach; ?>
                csvContent += "\n";
                
                // Add recent contracts
                csvContent += "RECENT SERVICE CONTRACTS\n";
                csvContent += "Service Type,Client,Description,Monthly Amount,Status,Start Date,Expiry Date\n";
                <?php foreach ($recent_contracts as $contract): ?>
                csvContent += "<?php echo $contract['service_type']; ?>,<?php echo $contract['company_name'] ?? 'Internal'; ?>,<?php echo $contract['description'] ?? 'N/A'; ?>,<?php echo $contract['monthly_amount']; ?>,<?php echo $contract['status']; ?>,<?php echo $contract['start_date'] ? date('Y-m-d', strtotime($contract['start_date'])) : 'N/A'; ?>,<?php echo $contract['expiry_date'] ? date('Y-m-d', strtotime($contract['expiry_date'])) : 'N/A'; ?>\n";
                <?php endforeach; ?>
                
                // Create and download CSV
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'service_report_' + new Date().toISOString().split('T')[0] + '.csv';
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