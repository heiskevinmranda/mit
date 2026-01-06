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
$priority = $_GET['priority'] ?? '';
$status = $_GET['status'] ?? '';

// Get filter options
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$priorities = ['Low', 'Medium', 'High', 'Critical'];
$statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];

// Build where clause for reports
$where_conditions = ['t.created_at BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = [PDO::PARAM_STR, PDO::PARAM_STR];

if ($client_id) {
    $where_conditions[] = "t.client_id = ?";
    $params[] = $client_id;
    $types[] = PDO::PARAM_STR;
}

if ($priority) {
    $where_conditions[] = "t.priority = ?";
    $params[] = $priority;
    $types[] = PDO::PARAM_STR;
}

if ($status) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

$where_sql = implode(' AND ', $where_conditions);

// Get ticket statistics
$tickets_sql = "SELECT 
    COUNT(*) as total_tickets,
    COUNT(CASE WHEN status = 'Open' THEN 1 END) as open_tickets,
    COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_tickets,
    COUNT(CASE WHEN status = 'Resolved' THEN 1 END) as resolved_tickets,
    COUNT(CASE WHEN status = 'Closed' THEN 1 END) as closed_tickets,
    COUNT(CASE WHEN priority = 'Critical' THEN 1 END) as critical_tickets,
    COUNT(CASE WHEN priority = 'High' THEN 1 END) as high_priority_tickets,
    NULL as avg_resolution_days
FROM tickets t
WHERE $where_sql";

$stmt = $pdo->prepare($tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$tickets_stats = $stmt->fetch();

// Get ticket distribution by priority
// We need to duplicate the parameters since they're used in both the main query and subquery
$duplicated_params = array_merge($params, $params);
$duplicated_types = array_merge($types, $types);
$priority_distribution_sql = "SELECT 
    priority,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tickets t WHERE $where_sql), 1) as percentage
FROM tickets t
WHERE $where_sql
GROUP BY priority
ORDER BY count DESC";

$stmt = $pdo->prepare($priority_distribution_sql);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$priority_distribution = $stmt->fetchAll();

// Get ticket distribution by status
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tickets t WHERE $where_sql), 1) as percentage
FROM tickets t
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Get tickets by client
$client_tickets_sql = "SELECT 
    c.company_name,
    COUNT(t.id) as ticket_count,
    COUNT(CASE WHEN t.status = 'Open' THEN 1 END) as open_count,
    COUNT(CASE WHEN t.status = 'Resolved' OR t.status = 'Closed' THEN 1 END) as resolved_count
FROM clients c
LEFT JOIN tickets t ON c.id = t.client_id AND (t.created_at BETWEEN ? AND ?)
GROUP BY c.id, c.company_name
HAVING COUNT(t.id) > 0
ORDER BY ticket_count DESC
LIMIT 10";

$stmt = $pdo->prepare($client_tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$client_tickets = $stmt->fetchAll();

// Get top 10 tickets
$recent_tickets_sql = "SELECT 
    t.*,
    c.company_name,
    u.email as assigned_to
FROM tickets t
LEFT JOIN clients c ON t.client_id = c.id
LEFT JOIN users u ON t.assigned_to = u.id
WHERE $where_sql
ORDER BY t.created_at DESC
LIMIT 10";

$stmt = $pdo->prepare($recent_tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$recent_tickets = $stmt->fetchAll();

// Helper function to format numbers
function formatNumber($number) {
    return number_format($number);
}

// Helper function to get priority class
function getPriorityClass($priority) {
    switch ($priority) {
        case 'Critical': return 'badge bg-danger';
        case 'High': return 'badge bg-warning text-dark';
        case 'Medium': return 'badge bg-info';
        case 'Low': return 'badge bg-success';
        default: return 'badge bg-secondary';
    }
}

// Helper function to get status class
function getStatusClass($status) {
    switch ($status) {
        case 'Open': return 'status-badge status-active';
        case 'In Progress': return 'status-badge status-maintenance';
        case 'Resolved': return 'status-badge status-retired';
        case 'Closed': return 'status-badge status-inactive';
        default: return 'status-badge';
    }
}

// Get report title
$report_title = 'Ticket Report';
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
                    <h1><i class="fas fa-ticket-alt"></i> <?php echo $report_title; ?></h1>
                    <p class="text-muted">Comprehensive ticket reports and analytics</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Ticket Report</li>
                </ol>
            </nav>
            
            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="type" value="ticket">
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
                        <label class="form-label">Priority</label>
                        <select class="form-select select2" name="priority">
                            <option value="">All Priorities</option>
                            <?php foreach ($priorities as $priority_opt): ?>
                            <option value="<?php echo htmlspecialchars($priority_opt); ?>"
                                <?php echo $priority == $priority_opt ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($priority_opt); ?>
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
                                <a href="?type=ticket" class="btn btn-outline-secondary">
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
            
            <!-- Ticket Report -->
            <div class="report-section active" id="ticket-report">
                <!-- Key Insights -->
                <div class="insight-card">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="text-white"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                            <p class="text-white-50 mb-0">
                                <?php if ($tickets_stats['critical_tickets'] > 0): ?>
                                • <?php echo $tickets_stats['critical_tickets']; ?> critical priority tickets<br>
                                <?php endif; ?>
                                <?php if ($tickets_stats['open_tickets'] > 0): ?>
                                • <?php echo round(($tickets_stats['open_tickets'] / $tickets_stats['total_tickets']) * 100, 1); ?>% of tickets are still open<br>
                                <?php endif; ?>
                                <?php if ($tickets_stats['avg_resolution_days'] > 0): ?>
                                • Average resolution time: <?php echo round($tickets_stats['avg_resolution_days'], 1); ?> days<br>
                                <?php endif; ?>
                                • Total tickets processed: <?php echo $tickets_stats['total_tickets']; ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="insight-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4 class="mb-0">Ticket Health Score</h4>
                            <div class="display-4">
                                <?php 
                                $health_score = 100;
                                if ($tickets_stats['total_tickets'] > 0) {
                                    $resolved_rate = ($tickets_stats['resolved_tickets'] + $tickets_stats['closed_tickets']) / $tickets_stats['total_tickets'];
                                    $critical_rate = $tickets_stats['critical_tickets'] / $tickets_stats['total_tickets'];
                                    $open_rate = $tickets_stats['open_tickets'] / $tickets_stats['total_tickets'];
                                    
                                    $health_score = round((($resolved_rate * 100) - ($critical_rate * 50) - ($open_rate * 30)) * 2, 0);
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
                        <h3><i class="fas fa-ticket-alt"></i> Total Tickets</h3>
                        <div class="stat-number"><?php echo formatNumber($tickets_stats['total_tickets']); ?></div>
                        <div class="stat-label">Tickets Processed</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar"></i> 
                                <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-exclamation-triangle"></i> Open Tickets</h3>
                        <div class="stat-number"><?php echo formatNumber($tickets_stats['open_tickets']); ?></div>
                        <div class="stat-label">Pending Resolution</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-percentage"></i> 
                                <?php echo $tickets_stats['total_tickets'] > 0 ? round(($tickets_stats['open_tickets'] / $tickets_stats['total_tickets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-check-circle"></i> Resolved Tickets</h3>
                        <div class="stat-number"><?php echo formatNumber($tickets_stats['resolved_tickets']); ?></div>
                        <div class="stat-label">Successfully Resolved</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-percentage"></i> 
                                <?php echo $tickets_stats['total_tickets'] > 0 ? round(($tickets_stats['resolved_tickets'] / $tickets_stats['total_tickets']) * 100, 1) : 0; ?>% of total
                            </small>
                        </div>
                    </div>
                    
                    <div class="report-card">
                        <h3><i class="fas fa-clock"></i> Avg Resolution</h3>
                        <div class="stat-number">
                            <?php echo $tickets_stats['avg_resolution_days'] > 0 ? round($tickets_stats['avg_resolution_days'], 1) : 0; ?>
                        </div>
                        <div class="stat-label">Days to Resolve</div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-history"></i> 
                                Average time from creation to resolution
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Charts -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-pie"></i> Ticket Priority Distribution</h3>
                            <div class="chart-container">
                                <canvas id="priorityDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="report-card">
                            <h3><i class="fas fa-chart-bar"></i> Ticket Status</h3>
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Tickets -->
                <div class="report-card">
                    <h3><i class="fas fa-history"></i> Recent Tickets</h3>
                    <div class="table-responsive">
                        <table class="table data-table">
                            <thead>
                                <tr>
                                    <th>Ticket #</th>
                                    <th>Client</th>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Assigned To</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_tickets as $ticket): ?>
                                <tr>
                                    <td>#<?php echo $ticket['id']; ?></td>
                                    <td><?php echo htmlspecialchars($ticket['company_name'] ?? 'Internal'); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['subject'] ?? 'No Subject'); ?></td>
                                    <td>
                                        <span class="<?php echo getPriorityClass($ticket['priority']); ?>">
                                            <?php echo htmlspecialchars($ticket['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getStatusClass($ticket['status']); ?>">
                                            <?php echo htmlspecialchars($ticket['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['assigned_to'] ?? 'Unassigned'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
            
            // Initialize charts
            initializeCharts();
            
            function initializeCharts() {
                // Priority Distribution Chart
                const priorityCtx = document.getElementById('priorityDistributionChart').getContext('2d');
                const priorityLabels = <?php echo json_encode(array_column($priority_distribution, 'priority')); ?>;
                const priorityData = <?php echo json_encode(array_column($priority_distribution, 'count')); ?>;
                
                new Chart(priorityCtx, {
                    type: 'doughnut',
                    data: {
                        labels: priorityLabels,
                        datasets: [{
                            data: priorityData,
                            backgroundColor: [
                                '#dc3545', '#ffc107', '#17a2b8', '#28a745'
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
                
                // Status Distribution Chart
                const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
                const statusLabels = <?php echo json_encode(array_column($status_distribution, 'status')); ?>;
                const statusData = <?php echo json_encode(array_column($status_distribution, 'count')); ?>;
                
                new Chart(statusCtx, {
                    type: 'bar',
                    data: {
                        labels: statusLabels,
                        datasets: [{
                            label: 'Ticket Count',
                            data: statusData,
                            backgroundColor: [
                                '#dc3545', '#ffc107', '#28a745', '#17a2b8'
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
                                    text: 'Number of Tickets'
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
                let csvContent = "Ticket Report - " + new Date().toLocaleDateString() + "\n";
                csvContent += "Date Range," + "<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>" + "\n\n";
                
                // Add summary data
                csvContent += "SUMMARY\n";
                csvContent += "Total Tickets," + <?php echo $tickets_stats['total_tickets']; ?> + "\n";
                csvContent += "Open Tickets," + <?php echo $tickets_stats['open_tickets']; ?> + "\n";
                csvContent += "Resolved Tickets," + <?php echo $tickets_stats['resolved_tickets']; ?> + "\n";
                csvContent += "Average Resolution Time," + <?php echo $tickets_stats['avg_resolution_days']; ?> + "\n\n";
                
                // Add priority distribution
                csvContent += "PRIORITY DISTRIBUTION\n";
                csvContent += "Priority,Count,Percentage\n";
                <?php foreach ($priority_distribution as $item): ?>
                csvContent += "<?php echo $item['priority']; ?>,<?php echo $item['count']; ?>,<?php echo $item['percentage']; ?>%\n";
                <?php endforeach; ?>
                csvContent += "\n";
                
                // Add recent tickets
                csvContent += "RECENT TICKETS\n";
                csvContent += "Ticket #,Client,Subject,Priority,Status,Created,Assigned To\n";
                <?php foreach ($recent_tickets as $ticket): ?>
                csvContent += "<?php echo $ticket['id']; ?>,<?php echo $ticket['company_name'] ?? 'Internal'; ?>,<?php echo $ticket['subject'] ?? 'No Subject'; ?>,<?php echo $ticket['priority']; ?>,<?php echo $ticket['status']; ?>,<?php echo date('Y-m-d', strtotime($ticket['created_at'])); ?>,<?php echo $ticket['assigned_to'] ?? 'Unassigned'; ?>\n";
                <?php endforeach; ?>
                
                // Create and download CSV
                const blob = new Blob([csvContent], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'ticket_report_' + new Date().toISOString().split('T')[0] + '.csv';
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