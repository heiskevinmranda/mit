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
function getCount($pdo, $query, $params = [])
{
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
            --primary-color: #FF6B35;
            --secondary-color: #004E89;
            --light-color: #F8F9FA;
            --dark-color: #343A40;
            --success-color: #28A745;
            --warning-color: #FFC107;
            --danger-color: #DC3545;
            --info-color: #17A2B8;
            --sidebar-width: 250px;
            --header-height: 60px;
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --dark: #343a40;
            --light: #f8f9fa;
            --gray: #6c757d;
            --card-bg: rgba(255, 255, 255, 0.85);
            --glass-bg: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.2);
            --glass-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
            --glass-backdrop: backdrop-filter: blur(10px);
            --gradient-primary: linear-gradient(135deg, var(--primary), var(--secondary));
            --gradient-success: linear-gradient(135deg, var(--success), #20c997);
            --gradient-info: linear-gradient(135deg, var(--info), #0dcaf0);
            --gradient-warning: linear-gradient(135deg, var(--warning), #e0a800);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark);
            overflow-x: hidden;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: var(--secondary-color);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 1.5rem;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4e7f1 100%);
            min-height: 100vh;
        }

        /* Hero Header */
        .hero-header {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 16px;
            padding: 1.8rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--glass-shadow);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            overflow: hidden;
        }

        .hero-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }

        .hero-content {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 2;
        }

        .hero-title {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.4rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .hero-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 0.8rem;
        }

        .hero-text-content {
            flex: 1;
        }

        .hero-user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: rgba(255, 255, 255, 0.9);
            margin-left: auto;
        }

        .hero-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--glass-bg);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: bold;
            color: white;
            backdrop-filter: blur(10px);
        }

        .hero-user-details h4 {
            color: white;
            margin-bottom: 0.25rem;
        }

        .hero-user-details p {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 0.9rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            min-height: 150px;
            display: flex;
            flex-direction: column;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(31, 38, 135, 0.2);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.4rem;
            font-size: 1rem;
            color: white;
            background: var(--gradient-primary);
            box-shadow: 0 3px 15px rgba(102, 126, 234, 0.4);
        }

        .stat-number {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0.1rem 0;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 0.2rem;
            flex-grow: 1;
            display: flex;
            align-items: center;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.7rem;
            color: var(--gray);
            flex-wrap: wrap;
            margin-top: auto;
        }

        .stat-trend span {
            white-space: nowrap;
        }

        .trend-up,
        .trend-down {
            font-size: 0.8rem;
        }

        .ms-auto {
            margin-left: auto;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        /* KPI Cards */
        .kpi-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(31, 38, 135, 0.15);
        }

        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .kpi-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .kpi-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0.8rem 0;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .progress-container {
            margin: 0.7rem 0;
        }

        .progress-bar {
            height: 8px;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 4px;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shine 2s infinite;
        }

        @keyframes shine {
            0% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .progress-text {
            font-size: 0.9rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* Circular Progress */
        .circular-progress {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: conic-gradient(var(--gradient-primary) var(--value, 0%), #e9ecef var(--value, 0%));
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }

        .circular-progress::before {
            content: '';
            position: absolute;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
        }

        .circular-progress-text {
            position: relative;
            z-index: 2;
            font-weight: 700;
            font-size: 1.2rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Staff Performance */
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.2rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .staff-performance {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            margin-bottom: 1.5rem;
        }

        .performance-item {
            display: flex;
            align-items: center;
            padding: 0.7rem 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .performance-item:last-child {
            border-bottom: none;
        }

        .staff-avatar-large {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
            margin-right: 0.8rem;
            box-shadow: 0 3px 12px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }

        .staff-avatar-large::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transform: rotate(45deg);
            animation: avatar-shine 3s infinite;
        }

        @keyframes avatar-shine {
            0% {
                transform: rotate(45deg) translateX(-100%);
            }

            100% {
                transform: rotate(45deg) translateX(100%);
            }
        }

        .staff-info {
            flex: 1;
        }

        .staff-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .staff-role {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .performance-stats {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-badge {
            background: var(--light);
            padding: 0.4rem 0.8rem;
            border-radius: 18px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .performance-rank {
            background: linear-gradient(135deg, var(--warning), var(--danger));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            height: 270px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .chart-container {
            height: calc(100% - 2rem);
            position: relative;
        }

        canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* Recent Tables */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .table-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: rgba(0, 0, 0, 0.02);
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid rgba(0, 0, 0, 0.05);
        }

        td {
            padding: 0.8rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        tr:hover {
            background: rgba(0, 0, 0, 0.02);
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Activity Timeline */
        .timeline-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            margin-bottom: 1.5rem;
        }

        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 1.5px;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.2rem;
            padding-left: 1.2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.8rem;
            top: 0.4rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid white;
            box-shadow: 0 0 0 2px var(--primary);
        }

        .timeline-content {
            background: rgba(102, 126, 234, 0.05);
            padding: 0.8rem;
            border-radius: 10px;
            border-left: 3px solid var(--primary);
        }

        .timeline-time {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.3rem;
        }

        /* Quick Actions */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .action-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 1.2rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 16px rgba(31, 38, 135, 0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .action-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(31, 38, 135, 0.2);
            background: rgba(255, 255, 255, 0.95);
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            margin-bottom: 0.7rem;
            background: var(--gradient-primary);
            box-shadow: 0 3px 15px rgba(102, 126, 234, 0.4);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }

        .action-desc {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Animations */
        .fade-in-up {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .delay-1 {
            animation-delay: 0.1s;
        }

        .delay-2 {
            animation-delay: 0.2s;
        }

        .delay-3 {
            animation-delay: 0.3s;
        }

        .delay-4 {
            animation-delay: 0.4s;
        }

        .delay-5 {
            animation-delay: 0.5s;
        }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .hero-header {
                padding: 1.2rem;
            }

            .hero-title {
                font-size: 1.6rem;
            }

            .hero-subtitle {
                font-size: 0.9rem;
            }

            .stats-grid,
            .kpi-section,
            .charts-grid,
            .tables-grid,
            .timeline-card,
            .actions-grid {
                grid-template-columns: 1fr;
            }

            .hero-user-info {
                flex-direction: column;
                text-align: center;
            }

            .actions-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }

            .stat-card {
                min-height: 140px;
                padding: 0.8rem;
            }

            .chart-card {
                height: 250px;
                padding: 1rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 0.8rem;
            }

            .hero-header {
                padding: 1rem;
            }

            .hero-title {
                font-size: 1.4rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                min-height: 130px;
                padding: 0.7rem;
            }

            .kpi-section {
                grid-template-columns: 1fr;
            }

            .chart-card {
                height: 220px;
            }

            .table-card {
                padding: 1rem;
            }

            .action-card {
                padding: 1rem;
            }

            .action-icon {
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }
        }

        /* Grouped Daily Tasks Styles */
        .grouped-tasks-container {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .user-task-group {
            border-left: 4px solid #007bff;
            padding-left: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .user-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .user-header h5 {
            font-weight: 600;
            color: #495057;
        }
        
        .table-dark th {
            background-color: #343a40;
            color: white;
            font-weight: 600;
        }
        
        .badge {
            font-size: 0.85em;
            padding: 0.4em 0.8em;
        }
        
        .task-description {
            font-size: 0.9em;
            opacity: 0.8;
        }
        
        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
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
            <!-- Hero Header -->
            <div class="hero-header fade-in-up">
                <div class="hero-content">
                    <div class="hero-text-content">
                        <h1 class="hero-title">
                            <i class="fas fa-rocket me-3"></i>Welcome Back!
                        </h1>
                        <p class="hero-subtitle">
                            Hello <?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?>, here's your dashboard overview
                        </p>
                        <div class="mt-3">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkAddDailyTaskModal">
                                <i class="fas fa-plus-circle me-2"></i>Add Task
                            </button>
                            <button type="button" class="btn btn-primary ms-2" onclick="loadDailyTasks()">
                                <i class="fas fa-list me-2"></i>View Tasks for Follow-up
                            </button>
                        </div>
                    </div>
                    <div class="hero-user-info">
                        <div class="hero-avatar">
                            <?php
                            $avatarInitial = 'U';
                            if (!empty($current_user['email'])) {
                                $avatarInitial = strtoupper(substr($current_user['email'], 0, 1));
                            }
                            echo $avatarInitial;
                            ?>
                        </div>
                        <div class="hero-user-details">
                            <h4><?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?></h4>
                            <p><?php echo htmlspecialchars($staff_profile['designation'] ?? ucfirst($user_type)); ?> • <?php echo htmlspecialchars($staff_profile['department'] ?? ''); ?></p>
                            <small>Last login: <?php echo date('M j, Y g:i A'); ?></small>
                        </div>
                    </div>
                </div>
            </div>


            <!-- User-specific KPIs for support staff -->
            <?php if (in_array($user_type, ['support_tech', 'engineer']) && !empty($user_stats)): ?>
                <div class="kpi-section fade-in-up delay-1">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <h3 class="kpi-title">My Assigned Tickets</h3>
                            <div class="circular-progress" style="--value: <?php
                                                                            $open_percent = ($user_stats['assigned_tickets'] ?? 0) > 0 ?
                                                                                round((($user_stats['open_assigned_tickets'] ?? 0) / ($user_stats['assigned_tickets'] ?? 1)) * 100) : 0;
                                                                            echo $open_percent;
                                                                            ?>%;">
                                <span class="circular-progress-text"><?php echo $open_percent; ?>%</span>
                            </div>
                        </div>
                        <div class="kpi-value"><?php echo $user_stats['assigned_tickets'] ?? 0; ?></div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $open_percent; ?>%;"></div>
                            </div>
                            <div class="progress-text"><?php echo $user_stats['open_assigned_tickets'] ?? 0; ?> open tickets</div>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <h3 class="kpi-title">Work Hours (30d)</h3>
                            <div class="circular-progress" style="--value: <?php
                                                                            $hours_percent = min(($user_stats['total_hours'] ?? 0) * 5, 100);
                                                                            echo $hours_percent;
                                                                            ?>%;">
                                <span class="circular-progress-text"><?php echo $user_stats['total_hours'] ?? 0; ?>h</span>
                            </div>
                        </div>
                        <div class="kpi-value"><?php echo $user_stats['total_hours'] ?? 0; ?></div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $hours_percent; ?>%;"></div>
                            </div>
                            <div class="progress-text"><?php echo $user_stats['total_work_logs'] ?? 0; ?> work logs</div>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <h3 class="kpi-title">Completion Rate</h3>
                            <div class="circular-progress" style="--value: <?php
                                                                            $assigned = $user_stats['assigned_tickets'] ?? 0;
                                                                            $open = $user_stats['open_assigned_tickets'] ?? 0;
                                                                            $completion_rate = $assigned > 0 ?
                                                                                round((($assigned - $open) / $assigned) * 100) : 0;
                                                                            echo $completion_rate;
                                                                            ?>%;">
                                <span class="circular-progress-text"><?php echo $completion_rate; ?>%</span>
                            </div>
                        </div>
                        <div class="kpi-value"><?php echo $completion_rate; ?>%</div>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $completion_rate; ?>%;"></div>
                            </div>
                            <div class="progress-text">Personal performance</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- System KPIs -->
            <div class="kpi-section fade-in-up delay-2">
                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Ticket Resolution Rate</h3>
                        <div class="circular-progress" style="--value: <?php echo $resolution_rate; ?>%;">
                            <span class="circular-progress-text"><?php echo $resolution_rate; ?>%</span>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo $resolution_rate; ?>%</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $resolution_rate; ?>%;"></div>
                        </div>
                        <div class="progress-text"><?php echo $stats['closed_tickets']; ?> of <?php echo $stats['total_tickets']; ?> tickets resolved</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Active Assets</h3>
                        <div class="circular-progress" style="--value: <?php echo $active_asset_percentage; ?>%;">
                            <span class="circular-progress-text"><?php echo $active_asset_percentage; ?>%</span>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo $active_asset_percentage; ?>%</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $active_asset_percentage; ?>%;"></div>
                        </div>
                        <div class="progress-text"><?php echo $stats['active_assets']; ?> of <?php echo $stats['total_assets']; ?> assets active</div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Staff Performance</h3>
                        <div class="circular-progress" style="--value: <?php echo $staff_closed_ratio; ?>%;">
                            <span class="circular-progress-text"><?php echo $staff_closed_ratio; ?>%</span>
                        </div>
                    </div>
                    <div class="kpi-value"><?php echo $staff_closed_ratio; ?>%</div>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $staff_closed_ratio; ?>%;"></div>
                        </div>
                        <div class="progress-text"><?php echo $staff_with_closed_tickets; ?> of <?php echo $stats['active_staff']; ?> staff closed tickets</div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid fade-in-up delay-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_tickets']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-arrow-up"></i> <?php echo $stats['open_tickets']; ?> open</span>
                        <span class="ms-auto"><?php echo $stats['closed_tickets']; ?> closed</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: var(--gradient-success);">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['active_clients']; ?></div>
                    <div class="stat-label">Active Clients</div>
                    <div class="stat-trend">
                        <span><i class="fas fa-users"></i> Total: <?php echo $stats['total_clients']; ?></span>
                        <span class="ms-auto"><?php echo $stats['services']; ?> services</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: var(--gradient-info);">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['total_assets']; ?></div>
                    <div class="stat-label">IT Assets</div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-check-circle"></i> <?php echo $stats['active_assets']; ?> active</span>
                        <span class="ms-auto"><?php echo $stats['site_visits']; ?> site visits</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: var(--gradient-warning);">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-number"><?php echo $stats['active_staff']; ?></div>
                    <div class="stat-label">Staff Members</div>
                    <div class="stat-trend">
                        <span><i class="fas fa-check-circle text-success"></i> <?php echo $staff_with_closed_tickets; ?> closed tickets</span>
                        <span class="ms-auto"><?php echo $staff_closed_ratio; ?>% performance</span>
                    </div>
                </div>
            </div>

            <!-- Staff Performance -->
            <?php if (!empty($staff_performance_data)): ?>
                <div class="staff-performance fade-in-up delay-4">
                    <h3 class="section-title"><i class="fas fa-users"></i> Top Performers</h3>
                    <?php foreach ($staff_performance_data as $index => $staff): ?>
                        <div class="performance-item">
                            <div class="staff-avatar-large">
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
                                <div class="staff-name"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="staff-role">
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
                                <div class="stat-badge">
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                    <?php echo $staff['closed_tickets'] ?? 0; ?> closed
                                </div>
                                <div class="stat-badge">
                                    <i class="fas fa-tasks text-primary me-1"></i>
                                    <?php echo $staff['total_tickets'] ?? 0; ?> total
                                </div>
                                <div class="stat-badge performance-rank">
                                    <?php echo $staff['performance_percentage'] ?? 0; ?>%
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Charts -->
            <div class="charts-grid fade-in-up delay-5">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-bar me-2"></i>Ticket Status</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketStatusChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-pie me-2"></i>Ticket Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ticketPriorityChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-server me-2"></i>Asset Types</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="assetTypeChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Tables -->
            <div class="tables-grid fade-in-up delay-5">
                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="section-title mb-0"><i class="fas fa-ticket-alt"></i> Recent Tickets</h3>
                        <a href="<?php echo route('tickets.index'); ?>" class="btn btn-sm btn-primary">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table>
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

                <div class="table-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="section-title mb-0"><i class="fas fa-building"></i> Recent Clients</h3>
                        <a href="<?php echo route('clients.index'); ?>" class="btn btn-sm btn-primary">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table>
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

            <!-- Recent Activities -->
            <?php if (!empty($recent_activities)): ?>
                <div class="timeline-card fade-in-up delay-5">
                    <h3 class="section-title mb-4"><i class="fas fa-history"></i> Recent Activities</h3>
                    <div class="timeline">
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="fw-bold"><?php echo htmlspecialchars($activity['action'] ?? 'Activity'); ?></div>
                                    <div class="text-muted small mb-1"><?php echo htmlspecialchars($activity['description'] ?? 'No description'); ?></div>
                                    <div class="timeline-time">
                                        <i class="far fa-clock me-1"></i>
                                        <?php
                                        $created_at = $activity['created_at'] ?? date('Y-m-d H:i:s');
                                        echo date('M j, Y g:i A', strtotime($created_at));
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="actions-grid fade-in-up delay-5">
                <a href="<?php echo route('tickets.create'); ?>" class="action-card">
                    <div class="action-icon" style="background: var(--gradient-primary);">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-content">
                        <h5 class="action-title">New Ticket</h5>
                        <p class="action-desc">Create support ticket</p>
                    </div>
                </a>

                <a href="<?php echo route('clients.create'); ?>" class="action-card">
                    <div class="action-icon" style="background: var(--gradient-success);">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="action-content">
                        <h5 class="action-title">Add Client</h5>
                        <p class="action-desc">Register new client</p>
                    </div>
                </a>

                <a href="<?php echo route('assets.create'); ?>" class="action-card">
                    <div class="action-icon" style="background: var(--gradient-info);">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="action-content">
                        <h5 class="action-title">Add Asset</h5>
                        <p class="action-desc">Register IT asset</p>
                    </div>
                </a>

                <a href="<?php echo route('reports.index'); ?>" class="action-card">
                    <div class="action-icon" style="background: var(--gradient-warning);">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="action-content">
                        <h5 class="action-title">Reports</h5>
                        <p class="action-desc">View analytics</p>
                    </div>
                </a>

                <a href="<?php echo route('daily_tasks.index'); ?>" class="action-card">
                    <div class="action-icon" style="background: var(--gradient-info);">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="action-content">
                        <h5 class="action-title">Daily Tasks</h5>
                        <p class="action-desc">Manage your tasks</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Ticket Status Chart - Enhanced Bar Chart
            const statusCtx = document.getElementById('ticketStatusChart').getContext('2d');
            const statusLabels = <?php echo json_encode(array_column($ticket_status_data, 'status')); ?>;
            const statusData = <?php echo json_encode(array_column($ticket_status_data, 'count')); ?>;

            // Define enhanced colors based on status
            const statusColors = statusLabels.map(label => {
                const labelLower = (label || '').toLowerCase();
                switch (labelLower) {
                    case 'open':
                        return '#667eea';
                    case 'in progress':
                        return '#ffc107';
                    case 'closed':
                        return '#28a745';
                    case 'waiting':
                        return '#6c757d';
                    case 'unknown':
                        return '#adb5bd';
                    default:
                        return '#17a2b8';
                }
            });

            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        label: 'Tickets',
                        data: statusData,
                        backgroundColor: statusColors.map(color => color + '80'), // Add transparency
                        borderColor: statusColors,
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
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
                            backgroundColor: 'rgba(255, 255, 255, 0.9)',
                            titleColor: '#343a40',
                            bodyColor: '#495057',
                            borderColor: '#dee2e6',
                            borderWidth: 1,
                            padding: 12,
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
                                drawBorder: false,
                                color: 'rgba(0, 0, 0, 0.05)'
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
                                maxRotation: 45,
                                color: '#6c757d'
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });

            // Ticket Priority Chart - Enhanced Doughnut Chart
            const priorityCtx = document.getElementById('ticketPriorityChart').getContext('2d');
            const priorityLabels = <?php echo json_encode(array_column($ticket_priority_data, 'priority')); ?>;
            const priorityData = <?php echo json_encode(array_column($ticket_priority_data, 'count')); ?>;

            // Define enhanced colors based on priority
            const priorityColors = priorityLabels.map(label => {
                const labelLower = (label || '').toLowerCase();
                switch (labelLower) {
                    case 'high':
                        return '#dc3545';
                    case 'medium':
                        return '#ffc107';
                    case 'low':
                        return '#17a2b8';
                    case 'critical':
                        return '#721c24';
                    case 'unknown':
                        return '#adb5bd';
                    default:
                        return '#6c757d';
                }
            });

            new Chart(priorityCtx, {
                type: 'doughnut',
                data: {
                    labels: priorityLabels,
                    datasets: [{
                        data: priorityData,
                        backgroundColor: priorityColors.map(color => color + '80'),
                        borderColor: priorityColors,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                font: {
                                    size: 11
                                },
                                color: '#6c757d'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.9)',
                            titleColor: '#343a40',
                            bodyColor: '#495057',
                            borderColor: '#dee2e6',
                            borderWidth: 1,
                            padding: 12
                        }
                    },
                    animation: {
                        animateRotate: true,
                        animateScale: true,
                        duration: 1000
                    }
                }
            });

            // Asset Type Chart - Enhanced Horizontal Bar Chart
            const assetTypeCtx = document.getElementById('assetTypeChart').getContext('2d');
            const assetLabels = <?php echo json_encode(array_column($asset_type_data, 'asset_type')); ?>;
            const assetData = <?php echo json_encode(array_column($asset_type_data, 'count')); ?>;

            // Generate enhanced colors for assets
            const assetColors = [
                '#667eea', '#764ba2', '#28a745', '#ffc107',
                '#dc3545', '#17a2b8', '#6c757d', '#fd7e14',
                '#20c997', '#0dcaf0'
            ].map(color => color + '80');

            new Chart(assetTypeCtx, {
                type: 'bar',
                data: {
                    labels: assetLabels,
                    datasets: [{
                        label: 'Assets',
                        data: assetData,
                        backgroundColor: assetColors.slice(0, assetLabels.length),
                        borderColor: assetColors.slice(0, assetLabels.length).map(color => color.replace('80', '')),
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.9)',
                            titleColor: '#343a40',
                            bodyColor: '#495057',
                            borderColor: '#dee2e6',
                            borderWidth: 1,
                            padding: 12,
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
                                drawBorder: false,
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                stepSize: 1,
                                color: '#6c757d'
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#6c757d'
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        });

        // Add scroll animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.fade-in-up').forEach(el => {
            observer.observe(el);
        });
    </script>

    <!-- Bulk Add Daily Tasks Modal -->
    <div class="modal fade" id="bulkAddDailyTaskModal" tabindex="-1" aria-labelledby="bulkAddDailyTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkAddDailyTaskModalLabel">
                        <i class="fas fa-tasks me-2"></i>Add Task(s)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="bulkAddTaskForm">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="bulkAssignedTo" class="form-label">Assign To <span class="text-danger">*</span></label>
                                <select class="form-select" id="bulkAssignedTo" name="assigned_to" required>
                                    <option value="">Select User</option>
                                    <?php
                                    // Get all users for assignment dropdown
                                    try {
                                        $userStmt = $pdo->query("
                                            SELECT u.id, u.email, sp.full_name 
                                            FROM users u 
                                            LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
                                            WHERE u.is_active = true 
                                            ORDER BY sp.full_name ASC, u.email ASC
                                        ");
                                        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($users as $user) {
                                            $display_name = !empty($user['full_name']) ? $user['full_name'] : $user['email'];
                                            echo "<option value='{$user['id']}'>{$display_name}</option>";
                                        }
                                    } catch (Exception $e) {
                                        // Fallback if staff_profiles table doesn't exist
                                        $userStmt = $pdo->query("SELECT id, email FROM users WHERE is_active = true ORDER BY email ASC");
                                        $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($users as $user) {
                                            echo "<option value='{$user['id']}'>{$user['email']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="bulkTaskPriority" class="form-label">Default Priority</label>
                                <select class="form-select" id="bulkTaskPriority" name="default_priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Tasks <span class="text-danger">*</span></label>
                            <div id="taskListContainer">
                                <!-- Task items will be added here dynamically -->
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addTaskButton">
                                <i class="fas fa-plus me-1"></i>Add Another Task
                            </button>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="bulkSubmitButton">
                                <i class="fas fa-save me-1"></i>Create Task(s)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Bulk Task JavaScript
        // Bulk task management functions
        let taskCounter = 1;

        // Initialize the first task field when modal opens
        document.getElementById('bulkAddDailyTaskModal').addEventListener('shown.bs.modal', function () {
            if (document.getElementById('taskListContainer').children.length === 0) {
                addTaskField();
            }
        });

        // Add task button event
        document.getElementById('addTaskButton').addEventListener('click', addTaskField);

        // Form submission
        document.getElementById('bulkAddTaskForm').addEventListener('submit', handleBulkTaskSubmission);

        // Add a new task field
        function addTaskField() {
            const container = document.getElementById('taskListContainer');
            const taskDiv = document.createElement('div');
            taskDiv.className = 'task-item mb-3 p-3 border rounded';
            taskDiv.dataset.taskId = taskCounter;
            
            taskDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Task #${taskCounter}</h6>
                    <button type="button" class="btn btn-outline-danger btn-sm remove-task" onclick="removeTaskField(${taskCounter})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <input type="text" class="form-control task-title" name="tasks[${taskCounter}][task_title]" 
                               placeholder="Task Title *" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select class="form-select task-priority" name="tasks[${taskCounter}][priority]">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100 copy-down" 
                                onclick="copyDown(${taskCounter})" title="Copy this priority to all tasks below">
                            <i class="fas fa-arrow-down"></i> Copy Down
                        </button>
                    </div>
                </div>
                <div class="mb-2">
                    <textarea class="form-control task-description" name="tasks[${taskCounter}][task_description]" 
                              rows="2" placeholder="Description (optional)"></textarea>
                </div>
            `;
            
            container.appendChild(taskDiv);
            taskCounter++;
            
            // Update remove buttons visibility
            updateRemoveButtons();
        }

        // Remove a task field
        function removeTaskField(taskId) {
            const taskElement = document.querySelector(`[data-task-id="${taskId}"]`);
            if (taskElement) {
                taskElement.remove();
                updateTaskNumbers();
                updateRemoveButtons();
            }
        }

        // Update task numbering after removal
        function updateTaskNumbers() {
            const tasks = document.querySelectorAll('.task-item');
            tasks.forEach((task, index) => {
                const title = task.querySelector('h6');
                const taskId = index + 1;
                title.textContent = `Task #${taskId}`;
                task.dataset.taskId = taskId;
                
                // Update input names
                const inputs = task.querySelectorAll('input, select, textarea');
                inputs.forEach(input => {
                    const name = input.name;
                    const newName = name.replace(/\[(\d+)\]/, `[${taskId}]`);
                    input.name = newName;
                });
            });
            
            taskCounter = tasks.length + 1;
        }

        // Update remove buttons visibility
        function updateRemoveButtons() {
            const tasks = document.querySelectorAll('.task-item');
            const removeButtons = document.querySelectorAll('.remove-task');
            
            // Hide remove button for the first task
            if (tasks.length <= 1) {
                removeButtons.forEach(btn => btn.style.display = 'none');
            } else {
                removeButtons.forEach(btn => btn.style.display = 'block');
            }
        }

        // Copy priority down to all tasks below
        function copyDown(currentTaskId) {
            const currentTask = document.querySelector(`[data-task-id="${currentTaskId}"]`);
            const currentPriority = currentTask.querySelector('.task-priority').value;
            
            const allTasks = Array.from(document.querySelectorAll('.task-item'));
            const currentIndex = allTasks.findIndex(task => parseInt(task.dataset.taskId) === currentTaskId);
            
            // Apply to all tasks below
            for (let i = currentIndex + 1; i < allTasks.length; i++) {
                allTasks[i].querySelector('.task-priority').value = currentPriority;
            }
            
            showToast('Priority copied to tasks below', 'success');
        }

        // Handle bulk task submission
        function handleBulkTaskSubmission(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            const submitButton = document.getElementById('bulkSubmitButton');
            
            // Disable submit button and show loading
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating Tasks...';
            
            // Collect all tasks
            const tasks = [];
            const assignedTo = formData.get('assigned_to');
            const defaultPriority = formData.get('default_priority');
            
            console.log('=== BULK TASK DEBUG INFO ===');
            console.log('Form data keys:', [...formData.keys()]);
            console.log('assigned_to value:', assignedTo);
            console.log('default_priority value:', defaultPriority);
            
            // Collect all tasks
            const taskItems = document.querySelectorAll('.task-item');
            console.log('Number of task items found:', taskItems.length);
            
            taskItems.forEach((item, index) => {
                console.log(`--- Processing task item ${index + 1} ---`);
                console.log('Task item element:', item);
                
                const titleInput = item.querySelector('.task-title');
                const descriptionInput = item.querySelector('.task-description');  
                const prioritySelect = item.querySelector('.task-priority');
                
                console.log('Title input element:', titleInput);
                console.log('Description input element:', descriptionInput);
                console.log('Priority select element:', prioritySelect);
                
                if (titleInput && descriptionInput && prioritySelect) {
                    const title = titleInput.value.trim();
                    const description = descriptionInput.value.trim();
                    const priority = prioritySelect.value;
                    
                    console.log('Extracted values:', { title, description, priority });
                    
                    if (title) {
                        tasks.push({
                            task_title: title,
                            task_description: description,
                            priority: priority,
                            assigned_to: assignedTo
                        });
                        console.log('✓ Task added to collection');
                    } else {
                        console.log('✗ Skipped - empty title');
                    }
                } else {
                    console.log('✗ Missing required input elements');
                    console.log('Title exists:', !!titleInput);
                    console.log('Description exists:', !!descriptionInput);
                    console.log('Priority exists:', !!prioritySelect);
                }
            });
            
            console.log('=== FINAL RESULTS ===');
            console.log('Total tasks collected:', tasks.length);
            console.log('Tasks data:', tasks);
            
            if (tasks.length === 0) {
                showToast('Please add at least one task with a title', 'error');
                resetSubmitButton();
                return;
            }
            
            // Send AJAX request
            fetch('ajax/create_multiple_daily_tasks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ tasks: tasks })
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Show detailed results
                    if (data.created_tasks.length > 0) {
                        console.log('Created tasks:', data.created_tasks);
                    }
                    if (data.failed_tasks.length > 0) {
                        console.log('Failed tasks:', data.failed_tasks);
                        showToast(`${data.failed_tasks.length} task(s) failed to create`, 'warning');
                    }
                    
                    // Close modal and reset form
                    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkAddDailyTaskModal'));
                    modal.hide();
                    
                    // Reset form
                    document.getElementById('bulkAddTaskForm').reset();
                    document.getElementById('taskListContainer').innerHTML = '';
                    taskCounter = 1;
                    
                    // Refresh task list if visible
                    if (typeof loadDailyTasks === 'function') {
                        setTimeout(loadDailyTasks, 500);
                    }
                } else {
                    showToast(data.message || 'Failed to create tasks', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while creating tasks: ' + error.message, 'error');
            })
            .finally(() => {
                resetSubmitButton();
            });
        }

        // Reset submit button
        function resetSubmitButton() {
            const submitButton = document.getElementById('bulkSubmitButton');
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="fas fa-save me-1"></i>Create All Tasks';
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : 'info'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            // Add to toast container
            let toastContainer = document.getElementById('toastContainer');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'toastContainer';
                toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                document.body.appendChild(toastContainer);
            }
            
            toastContainer.appendChild(toast);
            
            // Initialize and show toast
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Remove toast after it's hidden
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }
    </script>


    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- View/Edit/Delete Daily Tasks Modal -->
    <div class="modal fade" id="manageDailyTasksModal" tabindex="-1" aria-labelledby="manageDailyTasksModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="manageDailyTasksModalLabel">Manage Tasks (Follow-up)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="dailyTasksManagement">
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTaskModalLabel">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editTaskForm">
                    <input type="hidden" id="editTaskId" name="task_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editTaskTitle" class="form-label">Task Title *</label>
                            <input type="text" class="form-control" id="editTaskTitle" name="task_title" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label for="editTaskDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editTaskDescription" name="task_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="editAssignedTo" class="form-label">Assigned To</label>
                            <select class="form-select" id="editAssignedTo" name="assigned_to_id">
                                <option value="">Not assigned</option>
                                <?php
                                // Include database connection and get users
                                require_once 'config/database.php';
                                require_once 'includes/auth.php';
                                
                                try {
                                    $pdo = getDBConnection();
                                    // Join with staff_profiles to get full names
                                    $stmt = $pdo->query("SELECT u.id, u.email, u.user_type, sp.full_name 
                                                         FROM users u 
                                                         LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
                                                         WHERE u.is_active = true 
                                                         ORDER BY u.user_type DESC, sp.full_name, u.email");
                                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($users as $user) {
                                        $displayName = $user['full_name'] ?: $user['email'];
                                        echo '<option value="' . htmlspecialchars($user['id']) . '">' . 
                                             htmlspecialchars($displayName) . ' (' . 
                                             htmlspecialchars(ucfirst(str_replace('_', ' ', $user['user_type']))) . ')</option>';
                                    }
                                } catch (Exception $e) {
                                    error_log("Error fetching users for assignment: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="editTaskStatus" class="form-label">Status</label>
                                <select class="form-select" id="editTaskStatus" name="task_status">
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editTaskPriority" class="form-label">Priority</label>
                                <select class="form-select" id="editTaskPriority" name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Function to load and display daily tasks for management (using grouped display)
        function loadDailyTasks() {
            // Show the modal first
            const modal = new bootstrap.Modal(document.getElementById('manageDailyTasksModal'));
            modal.show();
            
            // Load grouped tasks via AJAX
            fetch('ajax/get_grouped_daily_tasks.php')
                .then(response => response.json())
                .then(data => {
                    const tasksContainer = document.getElementById('dailyTasksManagement');
                    
                    if (data.success && data.grouped) {
                        // Display grouped tasks
                        displayGroupedTasks(tasksContainer, data.data.users);
                    } else if (data.success && !data.grouped) {
                        // Fallback to flat display if grouping not available
                        displayFlatTasks(tasksContainer, data.data.tasks);
                    } else {
                        tasksContainer.innerHTML = `
                            <div class="text-center py-5">
                                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No tasks requiring follow-up</h5>
                                <p class="text-muted">All tasks are completed or no pending tasks assigned to you.</p>
                            </div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('dailyTasksManagement').innerHTML = `
                        <div class="alert alert-danger">
                            Error loading tasks: ${error.message}
                        </div>`;
                });
        }

        // Function to display tasks grouped by user
        function displayGroupedTasks(container, groupedUsers) {
            let html = '<div class="grouped-tasks-container">';
            
            // Iterate through each user group
            Object.keys(groupedUsers).forEach(userKey => {
                const userGroup = groupedUsers[userKey];
                const userTasks = userGroup.tasks;
                
                if (userTasks.length > 0) {
                    html += `
                        <div class="user-task-group mb-4">
                            <div class="user-header bg-light p-3 rounded mb-3">
                                <h5 class="mb-0">
                                    <i class="fas fa-user me-2"></i>
                                    ${escapeHtml(userGroup.user_name)}
                                    <span class="badge bg-primary ms-2">${userTasks.length} task${userTasks.length !== 1 ? 's' : ''}</span>
                                </h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="30%">Task Title</th>
                                            <th width="25%">Status</th>
                                            <th width="15%">Priority</th>
                                            <th width="20%">Created At</th>
                                            <th width="10%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;
                    
                    // Add each task for this user
                    userTasks.forEach(task => {
                        const priorityClass = getPriorityBadgeClass(task.priority);
                        const statusClass = getStatusBadgeClass(task.task_status);
                        const createdAt = formatDate(task.created_at);
                        
                        html += `
                            <tr>
                                <td>
                                    <strong>${escapeHtml(task.task_title)}</strong>
                                    ${task.task_description ? `<div class="small text-muted mt-1">${escapeHtml(task.task_description)}</div>` : ''}
                                </td>
                                <td><span class="badge ${statusClass}">${formatStatus(task.task_status)}</span></td>
                                <td><span class="badge ${priorityClass}">${formatPriority(task.priority)}</span></td>
                                <td>${createdAt}</td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="editTask(${task.id}, '${escapeHtml(task.task_title)}', '${escapeHtml(task.task_description || '')}', '${escapeHtml(task.assigned_to_name || '')}', '${task.task_status}', '${task.priority}')"
                                                title="Edit Task">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" 
                                                onclick="deleteTask(${task.id})"
                                                title="Delete Task">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>`;
                    });
                    
                    html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>`;
                }
            });
            
            html += '</div>';
            container.innerHTML = html;
        }

        // Function to display tasks in flat format (fallback)
        function displayFlatTasks(container, tasks) {
            if (tasks.length > 0) {
                let html = `
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Task Title</th>
                                    <th>Description</th>
                                    <th>Assigned To</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>`;
                
                tasks.forEach(task => {
                    const priorityClass = getPriorityBadgeClass(task.priority);
                    const statusClass = getStatusBadgeClass(task.task_status);
                    const createdAt = formatDate(task.created_at);
                    
                    html += `
                        <tr>
                            <td><strong>${escapeHtml(task.task_title)}</strong></td>
                            <td>${escapeHtml(task.task_description || 'No description')}</td>
                            <td>${escapeHtml(task.assigned_to_name || 'Not assigned')}</td>
                            <td><span class="badge ${priorityClass}">${formatPriority(task.priority)}</span></td>
                            <td><span class="badge ${statusClass}">${formatStatus(task.task_status)}</span></td>
                            <td>${createdAt}</td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" 
                                            onclick="editTask(${task.id}, '${escapeHtml(task.task_title)}', '${escapeHtml(task.task_description || '')}', '${escapeHtml(task.assigned_to_name || '')}', '${task.task_status}', '${task.priority}')"
                                            title="Edit Task">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="deleteTask(${task.id})"
                                            title="Delete Task">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>`;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>`;
                
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No tasks for today</h5>
                        <p class="text-muted">Click "Add Daily Task" to create your first task.</p>
                    </div>`;
            }
        }

        // Helper functions for formatting
        function getPriorityBadgeClass(priority) {
            const classes = {
                'urgent': 'bg-danger',
                'high': 'bg-warning text-dark',
                'medium': 'bg-info text-dark',
                'low': 'bg-secondary'
            };
            return classes[priority] || 'bg-secondary';
        }

        function getStatusBadgeClass(status) {
            const classes = {
                'pending': 'bg-secondary',
                'in_progress': 'bg-warning text-dark',
                'completed': 'bg-success',
                'cancelled': 'bg-danger'
            };
            return classes[status] || 'bg-secondary';
        }

        function formatPriority(priority) {
            const labels = {
                'low': 'Low',
                'medium': 'Medium',
                'high': 'High',
                'urgent': 'Urgent'
            };
            return labels[priority] || priority;
        }

        function formatStatus(status) {
            const labels = {
                'pending': 'Pending',
                'in_progress': 'In Progress',
                'completed': 'Completed',
                'cancelled': 'Cancelled'
            };
            return labels[status] || status;
        }

        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Function to open edit task modal
        function editTask(taskId, title, description, assignedTo, status, priority) {
            // Set form values
            document.getElementById('editTaskId').value = taskId;
            document.getElementById('editTaskTitle').value = title;
            document.getElementById('editTaskDescription').value = description;
            // For the assigned user dropdown, we'll need to match the assignedTo value with the option values
            const assignedToSelector = document.getElementById('editAssignedTo');
            // Try to match by name (now using full names instead of emails) or by user ID
            let matched = false;
            for (let i = 0; i < assignedToSelector.options.length; i++) {
                if (assignedToSelector.options[i].text.includes(assignedTo) || assignedToSelector.options[i].value === assignedTo) {
                    assignedToSelector.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            // If no match found, set to empty (not assigned)
            if (!matched) {
                assignedToSelector.selectedIndex = 0;
            }
            document.getElementById('editTaskStatus').value = status;
            document.getElementById('editTaskPriority').value = priority;
            
            // Hide the management modal and show edit modal
            const manageModal = bootstrap.Modal.getInstance(document.getElementById('manageDailyTasksModal'));
            manageModal.hide();
            
            const editModal = new bootstrap.Modal(document.getElementById('editTaskModal'));
            editModal.show();
        }

        // Handle edit task form submission
        document.getElementById('editTaskForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('ajax/edit_daily_task.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Task updated successfully!');
                    // Close edit modal
                    const editModal = bootstrap.Modal.getInstance(document.getElementById('editTaskModal'));
                    editModal.hide();
                    // Reload the management view
                    loadDailyTasks();
                } else {
                    alert('Error: ' + (data.message || 'Failed to update task'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the task.');
            });
        });

        // Function to delete a task
        function deleteTask(taskId) {
            if (confirm('Are you sure you want to delete this task?')) {
                const formData = new FormData();
                formData.append('task_id', taskId);
                
                fetch('ajax/delete_daily_task.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Task deleted successfully!');
                        // Reload the task list
                        loadDailyTasks();
                    } else {
                        alert('Error: ' + (data.message || 'Failed to delete task'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the task.');
                });
            }
        }
    </script>
</body>
</html>
