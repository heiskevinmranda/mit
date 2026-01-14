<?php
// Turn off error reporting to prevent headers already sent issues
error_reporting(0);
ini_set('display_errors', 0);

require_once 'includes/auth.php';
require_once 'includes/routes.php';
requireLogin();

// Check if user is properly authenticated
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}

// Set default values to prevent undefined variable errors
$user_type = $current_user['user_type'] ?? 'guest';
$staff_profile = $current_user['staff_profile'] ?? [];

// Get database connection
$pdo = getDBConnection();

// Function to safely get count
function getCount($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Query error: " . $e->getMessage());
        return 0;
    }
}

// Fetch all statistics
$stats = [
    'total_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets"),
    'open_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'In Progress')"),
    'closed_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE status = 'Closed'"),
    'high_priority' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE priority = 'High'"),
    'medium_priority' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE priority = 'Medium'"),
    'low_priority' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE priority = 'Low'"),
    'active_clients' => getCount($pdo, "SELECT COUNT(*) FROM clients WHERE status = 'Active'"),
    'total_clients' => getCount($pdo, "SELECT COUNT(*) FROM clients"),
    'total_assets' => getCount($pdo, "SELECT COUNT(*) FROM assets"),
    'active_assets' => getCount($pdo, "SELECT COUNT(*) FROM assets WHERE status = 'Active'"),
    'active_staff' => getCount($pdo, "SELECT COUNT(*) FROM staff_profiles WHERE employment_status = 'Active'"),
    'total_staff' => getCount($pdo, "SELECT COUNT(*) FROM staff_profiles"),
    'site_visits' => getCount($pdo, "SELECT COUNT(*) FROM site_visits"),
    'contracts' => getCount($pdo, "SELECT COUNT(*) FROM contracts"),
    'services' => getCount($pdo, "SELECT COUNT(*) FROM client_services")
];

// NEW METRIC: Staff who have closed tickets
$staff_with_closed_tickets = 0;
try {
    // Count distinct staff members who have closed tickets
    $query = "SELECT COUNT(DISTINCT ta.staff_id) 
              FROM ticket_assignees ta 
              JOIN tickets t ON ta.ticket_id = t.id 
              WHERE t.status = 'Closed' 
              AND ta.staff_id IN (SELECT id FROM staff_profiles WHERE employment_status = 'Active')";
    $stmt = $pdo->query($query);
    $staff_with_closed_tickets = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Staff with closed tickets error: " . $e->getMessage());
}

// NEW: Fetch individual staff performance data
$staff_performance_data = [];
try {
    $query = "SELECT 
                sp.id,
                COALESCE(sp.full_name, 'Unknown Staff') as full_name,
                COALESCE(sp.designation, 'Not Specified') as designation,
                COALESCE(sp.department, 'Not Specified') as department,
                COALESCE(u.email, '') as email,
                COUNT(DISTINCT ta.ticket_id) as total_tickets,
                COUNT(DISTINCT CASE WHEN t.status = 'Closed' THEN ta.ticket_id END) as closed_tickets
              FROM staff_profiles sp
              LEFT JOIN users u ON sp.user_id = u.id
              LEFT JOIN ticket_assignees ta ON sp.id = ta.staff_id
              LEFT JOIN tickets t ON ta.ticket_id = t.id
              WHERE sp.employment_status = 'Active'
              GROUP BY sp.id, sp.full_name, sp.designation, sp.department, u.email
              HAVING COUNT(DISTINCT ta.ticket_id) > 0
              ORDER BY closed_tickets DESC, total_tickets DESC
              LIMIT 10";
    $stmt = $pdo->query($query);
    $staff_performance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate performance percentage for each staff
    foreach ($staff_performance_data as &$staff) {
        $total = $staff['total_tickets'] ?? 0;
        $closed = $staff['closed_tickets'] ?? 0;
        $staff['performance_percentage'] = $total > 0 ? round(($closed / $total) * 100) : 0;
    }
    unset($staff); // Break the reference
} catch (Exception $e) {
    error_log("Staff performance data error: " . $e->getMessage());
}

// Calculate staff closed ticket ratio
$staff_closed_ratio = $stats['active_staff'] > 0 ? 
    round(($staff_with_closed_tickets / $stats['active_staff']) * 100) : 0;

// Fetch recent tickets
$recent_tickets = [];
try {
    $query = "SELECT t.*, c.company_name 
              FROM tickets t 
              LEFT JOIN clients c ON t.client_id = c.id 
              ORDER BY t.created_at DESC 
              LIMIT 5";
    $stmt = $pdo->query($query);
    $recent_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Recent tickets error: " . $e->getMessage());
}

// Fetch recent clients
$recent_clients = [];
try {
    $query = "SELECT * FROM clients 
              ORDER BY created_at DESC 
              LIMIT 5";
    $stmt = $pdo->query($query);
    $recent_clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Recent clients error: " . $e->getMessage());
}

// Fetch ticket status distribution
$ticket_status_data = [];
try {
    $query = "SELECT 
                CASE 
                    WHEN status IS NULL THEN 'Unknown'
                    WHEN status = '' THEN 'Unknown'
                    ELSE status 
                END as status,
                COUNT(*) as count 
              FROM tickets 
              GROUP BY 
                CASE 
                    WHEN status IS NULL THEN 'Unknown'
                    WHEN status = '' THEN 'Unknown'
                    ELSE status 
                END";
    $stmt = $pdo->query($query);
    $ticket_status_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Ticket status error: " . $e->getMessage());
}

// Fetch ticket priority distribution
$ticket_priority_data = [];
try {
    $query = "SELECT 
                CASE 
                    WHEN priority IS NULL THEN 'Unknown'
                    WHEN priority = '' THEN 'Unknown'
                    ELSE priority 
                END as priority,
                COUNT(*) as count 
              FROM tickets 
              GROUP BY 
                CASE 
                    WHEN priority IS NULL THEN 'Unknown'
                    WHEN priority = '' THEN 'Unknown'
                    ELSE priority 
                END";
    $stmt = $pdo->query($query);
    $ticket_priority_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Ticket priority error: " . $e->getMessage());
}

// Fetch asset types distribution
$asset_type_data = [];
try {
    $query = "SELECT 
                CASE 
                    WHEN asset_type IS NULL OR asset_type = '' THEN 'Unknown'
                    ELSE asset_type 
                END as asset_type,
                COUNT(*) as count 
              FROM assets 
              GROUP BY 
                CASE 
                    WHEN asset_type IS NULL OR asset_type = '' THEN 'Unknown'
                    ELSE asset_type 
                END
              ORDER BY count DESC
              LIMIT 10";
    $stmt = $pdo->query($query);
    $asset_type_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Asset type error: " . $e->getMessage());
}

// Fetch recent activities from multiple tables
$recent_activities = [];
try {
    // Get recent tickets
    $query1 = "SELECT 
                'ticket' as type,
                COALESCE(ticket_number, 'N/A') as identifier,
                COALESCE(title, 'No title') as description,
                created_at,
                'New Ticket Created' as action,
                'primary' as color
              FROM tickets 
              ORDER BY created_at DESC 
              LIMIT 3";
    
    // Get recent clients
    $query2 = "SELECT 
                'client' as type,
                COALESCE(company_name, 'N/A') as identifier,
                CONCAT('New client - ', COALESCE(contact_person, 'Unknown')) as description,
                created_at,
                'New Client Registered' as action,
                'success' as color
              FROM clients 
              ORDER BY created_at DESC 
              LIMIT 3";
    
    // Get recent assets
    $query3 = "SELECT 
                'asset' as type,
                COALESCE(asset_tag, 'N/A') as identifier,
                CONCAT('New asset - ', COALESCE(manufacturer, ''), ' ', COALESCE(model, '')) as description,
                created_at,
                'Asset Registered' as action,
                'warning' as color
              FROM assets 
              ORDER BY created_at DESC 
              LIMIT 3";
    
    $query = "($query1) UNION ALL ($query2) UNION ALL ($query3) ORDER BY created_at DESC LIMIT 10";
    $stmt = $pdo->query($query);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Recent activities error: " . $e->getMessage());
}

// Calculate percentages
$resolution_rate = $stats['total_tickets'] > 0 ? 
    round(($stats['closed_tickets'] / $stats['total_tickets']) * 100) : 0;

$active_asset_percentage = $stats['total_assets'] > 0 ? 
    round(($stats['active_assets'] / $stats['total_assets']) * 100) : 0;

// Fetch staff statistics
$staff_stats = [];
try {
    // Get staff by department
    $query = "SELECT 
                COALESCE(department, 'Not Specified') as department,
                COUNT(*) as count
              FROM staff_profiles 
              WHERE employment_status = 'Active'
              GROUP BY department
              ORDER BY count DESC";
    $stmt = $pdo->query($query);
    $staff_stats['by_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get staff by designation
    $query = "SELECT 
                COALESCE(designation, 'Not Specified') as designation,
                COUNT(*) as count
              FROM staff_profiles 
              WHERE employment_status = 'Active'
              GROUP BY designation
              ORDER BY count DESC
              LIMIT 10";
    $stmt = $pdo->query($query);
    $staff_stats['by_designation'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent staff
    $query = "SELECT 
                sp.*,
                u.email,
                u.user_type
              FROM staff_profiles sp
              JOIN users u ON sp.user_id = u.id
              WHERE sp.employment_status = 'Active'
              ORDER BY sp.created_at DESC 
              LIMIT 5";
    $stmt = $pdo->query($query);
    $staff_stats['recent_staff'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Staff stats error: " . $e->getMessage());
}

// Fetch user-specific stats if needed
$user_stats = [];
if (in_array($user_type, ['support_tech', 'engineer'])) {
    try {
        $staff_id = $staff_profile['id'] ?? null;
        if ($staff_id) {
            // Get tickets assigned to this staff
            $query = "SELECT COUNT(*) FROM ticket_assignees WHERE staff_id = ?";
            $user_stats['assigned_tickets'] = getCount($pdo, $query, [$staff_id]);
            
            // Get open tickets assigned to this staff
            $query = "SELECT COUNT(DISTINCT t.id) 
                      FROM tickets t
                      JOIN ticket_assignees ta ON t.id = ta.ticket_id
                      WHERE ta.staff_id = ? AND t.status IN ('Open', 'In Progress')";
            $user_stats['open_assigned_tickets'] = getCount($pdo, $query, [$staff_id]);
            
            // Get recent work logs
            $query = "SELECT 
                        COUNT(*) as total_work_logs,
                        COALESCE(SUM(total_hours), 0) as total_hours
                      FROM work_logs 
                      WHERE staff_id = ? 
                      AND work_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$staff_id]);
            $work_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_stats = array_merge($user_stats, $work_stats);
        }
    } catch (Exception $e) {
        error_log("User stats error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MSP Application</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
        }

        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-container {
            min-height: 100vh;
        }

        .main-content {
            padding: 20px;
            background: #f5f7fb;
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.3);
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef2f7;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--primary);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 24px;
            color: white;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--dark);
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6c757d;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        /* Compact Charts Section */
        .compact-charts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef2f7;
        }

        .chart-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .chart-card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .chart-card-body {
            position: relative;
            height: 220px;
        }

        .chart-summary {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eef2f7;
        }

        .summary-item {
            text-align: center;
            flex: 1;
        }

        .summary-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }

        .summary-label {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }

        /* Staff Performance Table */
        .staff-performance-table {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .performance-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f7;
        }

        .performance-row:last-child {
            border-bottom: none;
        }

        .staff-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .staff-info {
            flex: 1;
        }

        .staff-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .staff-details {
            font-size: 12px;
            color: #6c757d;
        }

        .performance-stats {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .ticket-count {
            text-align: center;
            min-width: 100px;
        }

        .ticket-count .number {
            font-size: 18px;
            font-weight: 700;
        }

        .ticket-count .label {
            font-size: 11px;
            color: #6c757d;
        }

        .performance-bar-container {
            width: 150px;
        }

        .performance-bar-label {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #6c757d;
            margin-bottom: 3px;
        }

        .performance-bar {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
        }

        .performance-bar-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .performance-percentage {
            width: 60px;
            text-align: center;
            font-weight: 700;
            font-size: 14px;
        }

        .performance-excellent { color: #28a745; }
        .performance-good { color: #17a2b8; }
        .performance-average { color: #ffc107; }
        .performance-poor { color: #dc3545; }

        .recent-table {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eef2f7;
        }

        .data-table tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success { background-color: #d4edda; color: #155724; }
        .badge-warning { background-color: #fff3cd; color: #856404; }
        .badge-danger { background-color: #f8d7da; color: #721c24; }
        .badge-info { background-color: #d1ecf1; color: #0c5460; }
        .badge-primary { background-color: #d6e4ff; color: #0d6efd; }

        .priority-high { background-color: #f8d7da; color: #721c24; }
        .priority-medium { background-color: #fff3cd; color: #856404; }
        .priority-low { background-color: #d1ecf1; color: #0c5460; }

        .status-open { background-color: #d6e4ff; color: #0d6efd; }
        .status-in-progress { background-color: #fff3cd; color: #856404; }
        .status-closed { background-color: #d4edda; color: #155724; }
        .status-waiting { background-color: #e2e3e5; color: #383d41; }

        .kpi-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .kpi-value {
            font-size: 36px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--primary);
        }

        .kpi-progress {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            margin: 15px 0;
            overflow: hidden;
        }

        .kpi-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 5px;
            transition: width 1s ease;
        }

        .quick-actions {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .action-card {
            display: flex;
            align-items: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-card:hover {
            background: white;
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 20px;
        }

        .activity-timeline {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid #eef2f7;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-time {
            font-size: 12px;
            color: #6c757d;
        }

        /* Staff specific styles */
        .staff-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .staff-badge-admin { background: #dc3545; color: white; }
        .staff-badge-manager { background: #fd7e14; color: white; }
        .staff-badge-engineer { background: #17a2b8; color: white; }
        .staff-badge-tech { background: #28a745; color: white; }
        .staff-badge-staff { background: #6c757d; color: white; }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .compact-charts {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-card-body {
                height: 200px;
            }
            
            .performance-stats {
                flex-direction: column;
                gap: 10px;
                align-items: flex-end;
            }
            
            .performance-bar-container {
                width: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
                        <p class="mb-0">Welcome back, <?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?>!</p>
                        <small class="opacity-75">
                            <?php echo htmlspecialchars(ucfirst($user_type)); ?> • 
                            Last login: <?php echo date('M j, Y g:i A'); ?>
                        </small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="text-end">
                            <div class="fw-bold"><?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?></div>
                            <div class="small"><?php echo htmlspecialchars($staff_profile['designation'] ?? ucfirst($user_type)); ?></div>
                            <div class="small opacity-75"><?php echo htmlspecialchars($staff_profile['department'] ?? ''); ?></div>
                        </div>
                        <div class="user-avatar">
                            <?php 
                            $avatarInitial = 'U';
                            if (!empty($current_user['email'])) {
                                $avatarInitial = strtoupper(substr($current_user['email'], 0, 1));
                            }
                            echo $avatarInitial;
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User-specific KPIs for support staff -->
            <?php if (in_array($user_type, ['support_tech', 'engineer']) && !empty($user_stats)): ?>
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">My Assigned Tickets</div>
                        <div class="kpi-value"><?php echo $user_stats['assigned_tickets'] ?? 0; ?></div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php 
                                $open_percent = ($user_stats['assigned_tickets'] ?? 0) > 0 ? 
                                    round((($user_stats['open_assigned_tickets'] ?? 0) / ($user_stats['assigned_tickets'] ?? 1)) * 100) : 0;
                                echo $open_percent;
                            ?>%"></div>
                        </div>
                        <div class="text-muted small"><?php echo $user_stats['open_assigned_tickets'] ?? 0; ?> open</div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Work Hours (30d)</div>
                        <div class="kpi-value"><?php echo $user_stats['total_hours'] ?? 0; ?></div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php 
                                $hours_percent = min(($user_stats['total_hours'] ?? 0) * 10, 100);
                                echo $hours_percent;
                            ?>%"></div>
                        </div>
                        <div class="text-muted small"><?php echo $user_stats['total_work_logs'] ?? 0; ?> work logs</div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Completion Rate</div>
                        <div class="kpi-value">
                            <?php 
                                $assigned = $user_stats['assigned_tickets'] ?? 0;
                                $open = $user_stats['open_assigned_tickets'] ?? 0;
                                $completion_rate = $assigned > 0 ? 
                                    round((($assigned - $open) / $assigned) * 100) : 0;
                                echo $completion_rate;
                            ?>%
                        </div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php echo $completion_rate; ?>%"></div>
                        </div>
                        <div class="text-muted small">Personal performance</div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Avg. Resolution</div>
                        <div class="kpi-value">24h</div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: 75%"></div>
                        </div>
                        <div class="text-muted small">Average ticket resolution time</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- System KPIs -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Ticket Resolution Rate</div>
                        <div class="kpi-value"><?php echo $resolution_rate; ?>%</div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php echo $resolution_rate; ?>%"></div>
                        </div>
                        <div class="text-muted small"><?php echo $stats['closed_tickets']; ?> of <?php echo $stats['total_tickets']; ?> tickets resolved</div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Active Assets</div>
                        <div class="kpi-value"><?php echo $active_asset_percentage; ?>%</div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php echo $active_asset_percentage; ?>%"></div>
                        </div>
                        <div class="text-muted small"><?php echo $stats['active_assets']; ?> of <?php echo $stats['total_assets']; ?> assets active</div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="kpi-card">
                        <div class="kpi-label fw-bold">Staff Performance</div>
                        <div class="kpi-value"><?php echo $staff_closed_ratio; ?>%</div>
                        <div class="kpi-progress">
                            <div class="kpi-progress-bar" style="width: <?php echo $staff_closed_ratio; ?>%"></div>
                        </div>
                        <div class="text-muted small"><?php echo $staff_with_closed_tickets; ?> of <?php echo $stats['active_staff']; ?> staff closed tickets</div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <!-- Tickets Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <h4>Total Tickets</h4>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> <?php echo $stats['open_tickets']; ?> open</span>
                        <span class="ms-auto"><?php echo $stats['closed_tickets']; ?> closed</span>
                    </div>
                </div>
                
                <!-- Clients Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success) 0%, #20c997 100%);">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['active_clients']; ?></div>
                    <h4>Active Clients</h4>
                    <div class="stat-trend">
                        <span><i class="fas fa-users"></i> Total: <?php echo $stats['total_clients']; ?></span>
                        <span class="ms-auto"><?php echo $stats['services']; ?> services</span>
                    </div>
                </div>
                
                <!-- Assets Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--info) 0%, #0dcaf0 100%);">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_assets']; ?></div>
                    <h4>IT Assets</h4>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-check-circle"></i> <?php echo $stats['active_assets']; ?> active</span>
                        <span class="ms-auto"><?php echo $stats['site_visits']; ?> site visits</span>
                    </div>
                </div>
                
                <!-- MODIFIED: Staff Card with Closed Tickets Ratio -->
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning) 0%, #ffc107 100%);">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['active_staff']; ?></div>
                    <h4>Staff Members</h4>
                    <div class="stat-trend">
                        <span><i class="fas fa-check-circle text-success"></i> <?php echo $staff_with_closed_tickets; ?> closed tickets</span>
                        <span class="ms-auto"><?php echo $staff_closed_ratio; ?>% performance</span>
                    </div>
                </div>
            </div>

            <!-- Staff Performance Table -->
            <?php if (!empty($staff_performance_data)): ?>
            <div class="staff-performance-table">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4><i class="fas fa-user-chart me-2"></i>Staff Performance</h4>
                    <span class="badge bg-light text-dark">Top <?php echo count($staff_performance_data); ?> performers</span>
                </div>
                
                <?php foreach ($staff_performance_data as $staff): ?>
                    <div class="performance-row">
                        <div class="staff-avatar">
                            <?php 
                            $initials = '?';
                            $full_name = $staff['full_name'] ?? '';
                            $email = $staff['email'] ?? '';
                            if (!empty($full_name) && trim($full_name) !== 'Unknown Staff') {
                                $initials = strtoupper(substr(trim($full_name), 0, 1));
                            } elseif (!empty($email)) {
                                $initials = strtoupper(substr(trim($email), 0, 1));
                            }
                            echo $initials;
                            ?>
                        </div>
                        <div class="staff-info">
                            <div class="staff-name">
                                <?php echo htmlspecialchars($full_name); ?>
                            </div>
                            <div class="staff-details">
                                <?php 
                                $details = [];
                                $designation = $staff['designation'] ?? '';
                                $department = $staff['department'] ?? '';
                                
                                if (!empty($designation) && trim($designation) !== 'Not Specified') {
                                    $details[] = htmlspecialchars($designation);
                                }
                                if (!empty($department) && trim($department) !== 'Not Specified') {
                                    $details[] = htmlspecialchars($department);
                                }
                                echo implode(' • ', $details);
                                ?>
                            </div>
                        </div>
                        <div class="performance-stats">
                            <div class="ticket-count">
                                <div class="number text-primary"><?php echo $staff['closed_tickets'] ?? 0; ?></div>
                                <div class="label">Closed</div>
                            </div>
                            <div class="ticket-count">
                                <div class="number"><?php echo $staff['total_tickets'] ?? 0; ?></div>
                                <div class="label">Total</div>
                            </div>
                            <div class="performance-bar-container">
                                <div class="performance-bar-label">
                                    <span>Progress</span>
                                    <span><?php echo $staff['performance_percentage'] ?? 0; ?>%</span>
                                </div>
                                <div class="performance-bar">
                                    <div class="performance-bar-fill" 
                                         style="width: <?php echo $staff['performance_percentage'] ?? 0; ?>%;
                                                background: <?php 
                                                    $percentage = $staff['performance_percentage'] ?? 0;
                                                    if ($percentage >= 80) echo '#28a745';
                                                    elseif ($percentage >= 60) echo '#17a2b8';
                                                    elseif ($percentage >= 40) echo '#ffc107';
                                                    else echo '#dc3545';
                                                ?>;">
                                    </div>
                                </div>
                            </div>
                            <div class="performance-percentage 
                                <?php 
                                    $percentage = $staff['performance_percentage'] ?? 0;
                                    if ($percentage >= 80) echo 'performance-excellent';
                                    elseif ($percentage >= 60) echo 'performance-good';
                                    elseif ($percentage >= 40) echo 'performance-average';
                                    else echo 'performance-poor';
                                ?>">
                                <?php echo $percentage; ?>%
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (count($staff_performance_data) < 10): ?>
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            Showing <?php echo count($staff_performance_data); ?> staff with ticket assignments
                        </small>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Compact Charts Section -->
            <div class="compact-charts">
                <!-- Ticket Status Chart -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3 class="chart-card-title"><i class="fas fa-chart-bar me-2"></i>Ticket Status</h3>
                        <span class="badge bg-light text-dark"><?php echo $stats['total_tickets']; ?> total</span>
                    </div>
                    <div class="chart-card-body">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                    <div class="chart-summary">
                        <?php 
                        $open_count = 0;
                        $closed_count = 0;
                        foreach ($ticket_status_data as $status) {
                            $status_name = strtolower($status['status'] ?? '');
                            if ($status_name === 'open' || $status_name === 'in progress') {
                                $open_count += $status['count'];
                            } elseif ($status_name === 'closed') {
                                $closed_count = $status['count'];
                            }
                        }
                        ?>
                        <div class="summary-item">
                            <div class="summary-value text-primary"><?php echo $open_count; ?></div>
                            <div class="summary-label">Open</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-success"><?php echo $closed_count; ?></div>
                            <div class="summary-label">Closed</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-info"><?php echo $stats['total_tickets'] - $open_count - $closed_count; ?></div>
                            <div class="summary-label">Other</div>
                        </div>
                    </div>
                </div>
                
                <!-- Ticket Priority Chart -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3 class="chart-card-title"><i class="fas fa-chart-pie me-2"></i>Ticket Priority</h3>
                        <span class="badge bg-light text-dark">By priority</span>
                    </div>
                    <div class="chart-card-body">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                    <div class="chart-summary">
                        <div class="summary-item">
                            <div class="summary-value text-danger"><?php echo $stats['high_priority']; ?></div>
                            <div class="summary-label">High</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-warning"><?php echo $stats['medium_priority']; ?></div>
                            <div class="summary-label">Medium</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-info"><?php echo $stats['low_priority']; ?></div>
                            <div class="summary-label">Low</div>
                        </div>
                    </div>
                </div>
                
                <!-- Asset Types Chart -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3 class="chart-card-title"><i class="fas fa-server me-2"></i>Asset Types</h3>
                        <span class="badge bg-light text-dark"><?php echo $stats['total_assets']; ?> assets</span>
                    </div>
                    <div class="chart-card-body">
                        <canvas id="assetTypeChart"></canvas>
                    </div>
                    <div class="chart-summary">
                        <div class="summary-item">
                            <div class="summary-value text-success"><?php echo $stats['active_assets']; ?></div>
                            <div class="summary-label">Active</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-warning"><?php echo $stats['total_assets'] - $stats['active_assets']; ?></div>
                            <div class="summary-label">Inactive</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value text-info"><?php echo count($asset_type_data); ?></div>
                            <div class="summary-label">Types</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Data Tables -->
            <div class="row mb-4">
                <div class="col-lg-6 mb-3">
                    <div class="recent-table">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4><i class="fas fa-ticket-alt me-2"></i>Recent Tickets</h4>
                            <a href="<?php echo route('tickets.index'); ?>" class="btn btn-sm btn-primary">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Ticket #</th>
                                        <th>Title</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_tickets)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                                <p class="text-muted mb-0">No tickets found</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_tickets as $ticket): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($ticket['ticket_number'] ?? 'N/A'); ?></strong></td>
                                                <td>
                                                    <?php 
                                                    $title = $ticket['title'] ?? '';
                                                    echo htmlspecialchars(substr($title, 0, 30)) . (strlen($title) > 30 ? '...' : '');
                                                    if (!empty($ticket['company_name'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($ticket['company_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php $priority = strtolower($ticket['priority'] ?? 'unknown'); ?>
                                                    <span class="badge priority-<?php echo $priority; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($priority)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php $status = strtolower(str_replace(' ', '-', $ticket['status'] ?? 'unknown')); ?>
                                                    <span class="badge status-<?php echo $status; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($ticket['status'] ?? 'Unknown')); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mb-3">
                    <div class="recent-table">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4><i class="fas fa-building me-2"></i>Recent Clients</h4>
                            <a href="<?php echo route('clients.index'); ?>" class="btn btn-sm btn-primary">
                                View All <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Contact</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_clients)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <i class="fas fa-users fa-2x text-muted mb-3"></i>
                                                <p class="text-muted mb-0">No clients found</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_clients as $client): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($client['company_name'] ?? 'N/A'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($client['contact_person'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php $status = $client['status'] ?? 'Unknown'; ?>
                                                    <span class="badge <?php echo $status == 'Active' ? 'badge-success' : 'badge-secondary'; ?>">
                                                        <?php echo htmlspecialchars($status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activities -->
            <?php if (!empty($recent_activities)): ?>
            <div class="activity-timeline">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4><i class="fas fa-history me-2"></i>Recent Activities</h4>
                </div>
                <?php foreach ($recent_activities as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-icon" style="background: <?php 
                            $color = $activity['color'] ?? 'primary';
                            echo $color == 'primary' ? '#667eea' : 
                                 ($color == 'success' ? '#28a745' : 
                                 ($color == 'warning' ? '#ffc107' : '#6c757d'));
                        ?>;">
                            <i class="fas fa-<?php 
                                $type = $activity['type'] ?? 'ticket';
                                echo $type == 'ticket' ? 'ticket-alt' : 
                                     ($type == 'client' ? 'building' : 'server');
                            ?>"></i>
                        </div>
                        <div class="activity-content">
                            <div class="fw-bold"><?php echo htmlspecialchars($activity['action'] ?? 'Activity'); ?></div>
                            <div class="text-muted mb-1"><?php echo htmlspecialchars($activity['description'] ?? 'No description'); ?></div>
                            <div class="activity-time">
                                <i class="far fa-clock me-1"></i>
                                <?php 
                                $created_at = $activity['created_at'] ?? date('Y-m-d H:i:s');
                                echo date('M j, Y g:i A', strtotime($created_at)); 
                                ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($activity['identifier'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h4><i class="fas fa-bolt me-2"></i>Quick Actions</h4>
                <div class="actions-grid">
                    <a href="<?php echo route('tickets.create'); ?>" class="action-card">
                        <div class="action-icon" style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div>
                            <h5 class="mb-1">New Ticket</h5>
                            <p class="text-muted mb-0 small">Create support ticket</p>
                        </div>
                    </a>
                    
                    <a href="<?php echo route('clients.create'); ?>" class="action-card">
                        <div class="action-icon" style="background: linear-gradient(135deg, var(--success) 0%, #20c997 100%);">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div>
                            <h5 class="mb-1">Add Client</h5>
                            <p class="text-muted mb-0 small">Register new client</p>
                        </div>
                    </a>
                    
                    <a href="<?php echo route('assets.create'); ?>" class="action-card">
                        <div class="action-icon" style="background: linear-gradient(135deg, var(--info) 0%, #0dcaf0 100%);">
                            <i class="fas fa-server"></i>
                        </div>
                        <div>
                            <h5 class="mb-1">Add Asset</h5>
                            <p class="text-muted mb-0 small">Register IT asset</p>
                        </div>
                    </a>
                    
                    <a href="<?php echo route('reports.index'); ?>" class="action-card">
                        <div class="action-icon" style="background: linear-gradient(135deg, var(--warning) 0%, #ffc107 100%);">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div>
                            <h5 class="mb-1">Reports</h5>
                            <p class="text-muted mb-0 small">View analytics</p>
                        </div>
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Ticket Status Chart - Small Bar Chart
            const statusCtx = document.getElementById('ticketStatusChart').getContext('2d');
            const statusLabels = <?php echo json_encode(array_column($ticket_status_data, 'status')); ?>;
            const statusData = <?php echo json_encode(array_column($ticket_status_data, 'count')); ?>;
            
            // Define colors based on status
            const statusColors = statusLabels.map(label => {
                const labelLower = (label || '').toLowerCase();
                switch(labelLower) {
                    case 'open': return '#667eea';
                    case 'in progress': return '#ffc107';
                    case 'closed': return '#28a745';
                    case 'waiting': return '#6c757d';
                    case 'unknown': return '#adb5bd';
                    default: return '#17a2b8';
                }
            });

            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusData,
                        backgroundColor: statusColors,
                        borderColor: statusColors.map(color => color.replace('0.8', '1')),
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.label}: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45
                            }
                        }
                    }
                }
            });

            // Ticket Priority Chart - Small Doughnut Chart
            const priorityCtx = document.getElementById('ticketPriorityChart').getContext('2d');
            const priorityLabels = <?php echo json_encode(array_column($ticket_priority_data, 'priority')); ?>;
            const priorityData = <?php echo json_encode(array_column($ticket_priority_data, 'count')); ?>;
            
            // Define colors based on priority
            const priorityColors = priorityLabels.map(label => {
                const labelLower = (label || '').toLowerCase();
                switch(labelLower) {
                    case 'high': return '#dc3545';
                    case 'medium': return '#ffc107';
                    case 'low': return '#17a2b8';
                    case 'critical': return '#721c24';
                    case 'unknown': return '#adb5bd';
                    default: return '#6c757d';
                }
            });

            new Chart(priorityCtx, {
                type: 'doughnut',
                data: {
                    labels: priorityLabels,
                    datasets: [{
                        data: priorityData,
                        backgroundColor: priorityColors,
                        borderColor: 'white',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                font: {
                                    size: 10
                                }
                            }
                        }
                    }
                }
            });

            // Asset Type Chart - Small Horizontal Bar Chart
            const assetTypeCtx = document.getElementById('assetTypeChart').getContext('2d');
            const assetLabels = <?php echo json_encode(array_column($asset_type_data, 'asset_type')); ?>;
            const assetData = <?php echo json_encode(array_column($asset_type_data, 'count')); ?>;
            
            // Generate colors for assets
            const assetColors = [
                '#667eea', '#764ba2', '#28a745', '#ffc107', 
                '#dc3545', '#17a2b8', '#6c757d', '#fd7e14',
                '#20c997', '#0dcaf0'
            ];

            new Chart(assetTypeCtx, {
                type: 'horizontalBar',
                data: {
                    labels: assetLabels,
                    datasets: [{
                        data: assetData,
                        backgroundColor: assetColors.slice(0, assetLabels.length),
                        borderColor: assetColors.slice(0, assetLabels.length).map(color => color.replace('0.8', '1')),
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.label}: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            // Animate performance bars
            document.querySelectorAll('.performance-bar-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 300);
            });

            // Auto-refresh dashboard every 2 minutes
            setInterval(() => {
                window.location.reload();
            }, 120000);
        });

        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
            this.classList.toggle('active');
        });
    </script>
</body>
</html>