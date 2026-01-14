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

// Get overall statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM assets) as total_assets,
    (SELECT COUNT(*) FROM tickets) as total_tickets,
    (SELECT COUNT(*) FROM contracts) as total_contracts,
    (SELECT COUNT(*) FROM clients) as total_clients,
    (SELECT COUNT(*) FROM users) as total_users
";

$stmt = $pdo->query($stats_sql);
$stats = $stmt->fetch();

// Get recent statistics for the last 30 days
$recent_stats_sql = "SELECT 
    (SELECT COUNT(*) FROM assets WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') as recent_assets,
    (SELECT COUNT(*) FROM tickets WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') as recent_tickets,
    (SELECT COUNT(*) FROM contracts WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') as recent_contracts
";

$stmt = $pdo->query($recent_stats_sql);
$recent_stats = $stmt->fetch();

// Get asset status distribution
$asset_status_sql = "SELECT 
    status,
    COUNT(*) as count
FROM assets
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->query($asset_status_sql);
$asset_status = $stmt->fetchAll();

// Get ticket status distribution
$ticket_status_sql = "SELECT 
    status,
    COUNT(*) as count
FROM tickets
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->query($ticket_status_sql);
$ticket_status = $stmt->fetchAll();

// Get monthly trend data for the last 6 months
$monthly_trend_sql = "SELECT 
    TO_CHAR(created_at, 'YYYY-MM') as month,
    COUNT(*) as asset_count
FROM assets
WHERE created_at >= CURRENT_DATE - INTERVAL '6 months'
GROUP BY TO_CHAR(created_at, 'YYYY-MM')
ORDER BY month";

$stmt = $pdo->query($monthly_trend_sql);
$monthly_trend = $stmt->fetchAll();

// Helper function to format numbers
function formatNumber($number) {
    return number_format($number);
}

function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard | MSP Application</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        }
        .report-card h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f8ff;
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.4rem;
            font-weight: 600;
        }
        .stat-card {
            text-align: center;
            padding: 25px 15px;
            border-radius: 12px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.8rem;
            color: white;
        }
        .stat-number {
            font-size: 2.8rem;
            font-weight: 700;
            margin: 10px 0;
            color: #004E89;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 0.95rem;
            color: #5a6268;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }
        .chart-container {
            position: relative;
            height: 320px;
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
            padding: 12px 0;
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
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
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
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 6px 16px rgba(0, 78, 137, 0.2);
        }
        .insight-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
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
        .report-type-card {
            text-align: center;
            padding: 35px 20px;
            border-radius: 12px;
            background: white;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .report-type-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
            border-color: #004E89;
        }
        .report-type-icon {
            font-size: 3rem;
            margin-bottom: 18px;
            color: #004E89;
        }
        .report-type-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #333;
        }
        .report-type-desc {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 20px;
            flex-grow: 1;
            display: flex;
            align-items: center;
        }
        .report-type-link {
            display: inline-block;
            padding: 10px 20px;
            background: #004E89;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s;
            border: 1px solid #004E89;
        }
        .report-type-link:hover {
            background: #003a66;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 78, 137, 0.25);
        }
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 25px;
        }
        .report-section {
            margin-bottom: 35px;
        }
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .stat-number {
                font-size: 2.2rem;
            }
            .chart-container {
                height: 280px;
            }
            .reports-grid {
                grid-template-columns: 1fr;
            }
        }
        .stat-card:nth-child(1) .stat-icon { background: linear-gradient(135deg, #28a745, #218838); }
        .stat-card:nth-child(2) .stat-icon { background: linear-gradient(135deg, #FF6B35, #E65100); }
        .stat-card:nth-child(3) .stat-icon { background: linear-gradient(135deg, #17a2b8, #117a8b); }
        .stat-card:nth-child(4) .stat-icon { background: linear-gradient(135deg, #6f42c1, #5a32a3); }
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
                    <h1><i class="fas fa-chart-bar"></i> Reports Dashboard</h1>
                    <p class="text-muted">Comprehensive reports and analytics for your MSP</p>
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
                    <li class="breadcrumb-item"><a href="<?php echo route('dashboard'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reports</li>
                </ol>
            </nav>
            
            <!-- Key Insights -->
            <div class="insight-card">
                <div class="row">
                    <div class="col-md-8">
                        <h3 class="text-white"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                        <p class="text-white-50 mb-0">
                            • <?php echo formatNumber($stats['total_assets']); ?> total assets managed<br>
                            • <?php echo formatNumber($stats['total_tickets']); ?> tickets processed<br>
                            • <?php echo formatNumber($stats['total_contracts']); ?> service contracts active<br>
                            • <?php echo formatNumber($stats['total_clients']); ?> clients served
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="insight-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4 class="mb-0">Overall Health Score</h4>
                        <div class="display-4">87</div>
                        <small>/100</small>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="dashboard-grid">
                <div class="report-card">
                    <h3><i class="fas fa-server"></i> Assets</h3>
                    <div class="stat-number"><?php echo formatNumber($stats['total_assets']); ?></div>
                    <div class="stat-label">Total Assets</div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-arrow-up trend-up"></i> 
                            <?php echo formatNumber($recent_stats['recent_assets']); ?> in last 30 days
                        </small>
                    </div>
                </div>
                
                <div class="report-card">
                    <h3><i class="fas fa-ticket-alt"></i> Tickets</h3>
                    <div class="stat-number"><?php echo formatNumber($stats['total_tickets']); ?></div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-arrow-up trend-up"></i> 
                            <?php echo formatNumber($recent_stats['recent_tickets']); ?> in last 30 days
                        </small>
                    </div>
                </div>
                
                <div class="report-card">
                    <h3><i class="fas fa-file-contract"></i> Contracts</h3>
                    <div class="stat-number"><?php echo formatNumber($stats['total_contracts']); ?></div>
                    <div class="stat-label">Service Contracts</div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-arrow-up trend-up"></i> 
                            <?php echo formatNumber($recent_stats['recent_contracts']); ?> in last 30 days
                        </small>
                    </div>
                </div>
                
                <div class="report-card">
                    <h3><i class="fas fa-users"></i> Clients</h3>
                    <div class="stat-number"><?php echo formatNumber($stats['total_clients']); ?></div>
                    <div class="stat-label">Total Clients</div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-user-plus"></i> 
                            <?php echo formatNumber($stats['total_users']); ?> users
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="row">
                <div class="col-md-6">
                    <div class="report-card">
                        <h3><i class="fas fa-chart-pie"></i> Asset Status Distribution</h3>
                        <div class="chart-container">
                            <canvas id="assetStatusChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="report-card">
                        <h3><i class="fas fa-chart-bar"></i> Ticket Status</h3>
                        <div class="chart-container">
                            <canvas id="ticketStatusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Monthly Trend -->
            <div class="report-card">
                <h3><i class="fas fa-chart-line"></i> Asset Growth Trend (Last 6 Months)</h3>
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>
            
            <!-- Report Types -->
            <div class="report-card">
                <h3><i class="fas fa-folder-open"></i> Report Types</h3>
                <div class="row">
                    <div class="col-md-4">
                        <div class="report-type-card">
                            <div class="report-type-icon">
                                <i class="fas fa-server"></i>
                            </div>
                            <h4 class="report-type-title">Asset Report</h4>
                            <p class="report-type-desc">Detailed asset inventory and status</p>
                            <a href="<?php echo route('reports.asset'); ?>" class="report-type-link">View Report</a>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="report-type-card">
                            <div class="report-type-icon">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <h4 class="report-type-title">Ticket Report</h4>
                            <p class="report-type-desc">Ticket analytics and performance metrics</p>
                            <a href="<?php echo route('reports.ticket'); ?>" class="report-type-link">View Report</a>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="report-type-card">
                            <div class="report-type-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <h4 class="report-type-title">Service Report</h4>
                            <p class="report-type-desc">Contract and service agreement details</p>
                            <a href="<?php echo route('reports.service'); ?>" class="report-type-link">View Report</a>
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
            // Initialize charts
            initializeCharts();
            
            function initializeCharts() {
                // Asset Status Chart
                const assetCtx = document.getElementById('assetStatusChart').getContext('2d');
                const assetLabels = <?php echo json_encode(array_column($asset_status, 'status')); ?>;
                const assetData = <?php echo json_encode(array_column($asset_status, 'count')); ?>;
                
                new Chart(assetCtx, {
                    type: 'doughnut',
                    data: {
                        labels: assetLabels,
                        datasets: [{
                            data: assetData,
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
                
                // Ticket Status Chart
                const ticketCtx = document.getElementById('ticketStatusChart').getContext('2d');
                const ticketLabels = <?php echo json_encode(array_column($ticket_status, 'status')); ?>;
                const ticketData = <?php echo json_encode(array_column($ticket_status, 'count')); ?>;
                
                new Chart(ticketCtx, {
                    type: 'bar',
                    data: {
                        labels: ticketLabels,
                        datasets: [{
                            label: 'Ticket Count',
                            data: ticketData,
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
                
                // Monthly Trend Chart
                const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
                const trendLabels = <?php echo json_encode(array_column($monthly_trend, 'month')); ?>;
                const trendData = <?php echo json_encode(array_column($monthly_trend, 'asset_count')); ?>;
                
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: trendLabels,
                        datasets: [{
                            label: 'Assets Added',
                            data: trendData,
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
                                    text: 'Number of Assets'
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
        });
    </script>
</body>
</html>