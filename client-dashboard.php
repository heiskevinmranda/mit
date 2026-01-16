<?php
// client-dashboard.php - All Tickets Up To Now
session_start();

// Check if user is logged in as client
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: client-login.php');
    exit;
}

// Load database config
require_once 'config/database.php';

// Get database connection
$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

if (!$client_id) {
    header('Location: client-login.php?error=no_client');
    exit;
}

// Get client info
$client = null;
try {
    $stmt = $pdo->prepare("SELECT company_name, contact_person, email FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
} catch (Exception $e) {
    // Handle error silently
}

// Handle period filter
$period = $_GET['period'] ?? 'all'; // all, this_month, last_month, custom
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Set date range based on period
switch ($period) {
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'custom':
        // Use provided dates
        break;
    case 'all':
    default:
        // All tickets up to now - from beginning of time to today
        $start_date = '2000-01-01'; // Very old date to get everything
        $end_date = date('Y-m-d');
        $period = 'all';
        break;
}

// Get all tickets statistics
function getAllTicketStats($pdo, $client_id) {
    $stats = [];
    
    $queries = [
        'total_all_time' => "SELECT COUNT(*) FROM tickets WHERE client_id = ?",
        'open_all_time' => "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status IN ('Open', 'In Progress')",
        'closed_all_time' => "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status = 'Closed'",
        'avg_resolution_days' => "SELECT AVG(EXTRACT(DAY FROM (closed_at - created_at))) FROM tickets WHERE client_id = ? AND status = 'Closed' AND closed_at IS NOT NULL",
        'last_7_days' => "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND created_at >= NOW() - INTERVAL '7 days'",
    ];
    
    foreach ($queries as $key => $query) {
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$client_id]);
            $stats[$key] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $stats[$key] = 0;
        }
    }
    
    return $stats;
}

// Get other stats
function getSimpleCount($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Get all statistics
$ticket_stats = getAllTicketStats($pdo, $client_id);
$active_assets = getSimpleCount($pdo, 
    "SELECT COUNT(*) FROM assets WHERE client_id = ? AND status = 'Active'", 
    [$client_id]
);
$active_contracts = getSimpleCount($pdo, 
    "SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'Active'", 
    [$client_id]
);

// Get all tickets up to now (or filtered)
$all_tickets = [];
try {
    $query = "
        SELECT 
            t.id,
            t.ticket_number,
            t.title,
            t.description,
            t.status,
            t.priority,
            t.created_at,
            t.updated_at,
            t.closed_at,
            cl.contact_person as requested_by,
            cl.email as client_email,
            sp.full_name as staff_name,
            sp.email as staff_email,
            sp.phone as staff_phone
        FROM tickets t
        LEFT JOIN clients cl ON t.client_id = cl.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        WHERE t.client_id = ? 
        AND DATE(t.created_at) BETWEEN ? AND ?
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$client_id, $start_date, $end_date]);
    $all_tickets = $stmt->fetchAll();
    
    // Get activity count for each ticket
    foreach ($all_tickets as &$ticket) {
        // Get comment count
        $activity_stmt = $pdo->prepare("
            SELECT COUNT(*) as comment_count 
            FROM ticket_comments 
            WHERE ticket_id = ?
        ");
        $activity_stmt->execute([$ticket['id']]);
        $activity_result = $activity_stmt->fetch();
        $ticket['comment_count'] = $activity_result['comment_count'] ?? 0;
        
        // Get resolution time if closed
        if ($ticket['closed_at']) {
            $created = new DateTime($ticket['created_at']);
            $closed = new DateTime($ticket['closed_at']);
            $interval = $created->diff($closed);
            $ticket['resolution_days'] = $interval->days;
        } else {
            $ticket['resolution_days'] = null;
        }
        
        // Get last activity
        $last_activity_stmt = $pdo->prepare("
            SELECT created_at 
            FROM ticket_comments 
            WHERE ticket_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $last_activity_stmt->execute([$ticket['id']]);
        $last_activity = $last_activity_stmt->fetch();
        $ticket['last_activity'] = $last_activity['created_at'] ?? $ticket['updated_at'];
    }
    
} catch (Exception $e) {
    error_log("Tickets error: " . $e->getMessage());
}

// Calculate period statistics
$period_stats = [
    'total' => count($all_tickets),
    'open' => 0,
    'in_progress' => 0,
    'closed' => 0,
    'high_priority' => 0,
    'avg_resolution_days' => 0,
];

$total_resolution_days = 0;
$closed_count = 0;

foreach ($all_tickets as $ticket) {
    $status = strtolower($ticket['status']);
    if ($status == 'open') {
        $period_stats['open']++;
    } elseif ($status == 'closed') {
        $period_stats['closed']++;
        if ($ticket['resolution_days']) {
            $total_resolution_days += $ticket['resolution_days'];
            $closed_count++;
        }
    } elseif ($status == 'in progress') {
        $period_stats['in_progress']++;
    }
    
    if (strtolower($ticket['priority']) == 'high') {
        $period_stats['high_priority']++;
    }
}

if ($closed_count > 0) {
    $period_stats['avg_resolution_days'] = round($total_resolution_days / $closed_count, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard - All Tickets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .dashboard-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border-left: 4px solid #28a745;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .stat-number {
            font-size: 42px;
            font-weight: 800;
            color: #28a745;
            margin: 15px 0;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 15px;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        .period-filter {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .period-btn {
            margin: 0 5px 10px;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 600;
        }
        .period-btn.active {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        .tickets-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .table-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-bottom: 2px solid #dee2e6;
        }
        .table-responsive {
            border-radius: 0 0 15px 15px;
            overflow: hidden;
        }
        .table th {
            background: #f1f3f4;
            padding: 15px 12px;
            font-weight: 700;
            color: #495057;
            border-bottom: 3px solid #28a745;
        }
        .table td {
            padding: 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
        }
        .table tr:hover {
            background-color: #f8fff9 !important;
        }
        .ticket-link {
            color: #28a745;
            font-weight: 600;
            text-decoration: none;
        }
        .ticket-link:hover {
            color: #20c997;
            text-decoration: underline;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-open { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
        .status-in-progress { background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; }
        .status-closed { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        
        .priority-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .priority-high { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .priority-medium { background: #fff3e0; color: #ef6c00; border: 1px solid #ffe0b2; }
        .priority-low { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        
        .activity-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .activity-active { background: #e3f2fd; color: #1565c0; }
        .activity-none { background: #f5f5f5; color: #757575; }
        
        .time-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 11px;
            border: 1px solid #e9ecef;
        }
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .summary-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        .custom-date-inputs {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            border: 1px solid #e9ecef;
        }
        .export-btn {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
        }
        .export-btn:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-tachometer-alt me-2"></i>Client Dashboard</h1>
                    <p class="mb-0">Welcome, <?php echo htmlspecialchars($client['contact_person'] ?? 'Client'); ?></p>
                    <small><?php echo htmlspecialchars($client['company_name'] ?? ''); ?></small>
                </div>
                <div>
                    <a href="new-ticket.php" class="btn btn-light btn-lg me-2">
                        <i class="fas fa-plus-circle me-1"></i> New Ticket
                    </a>
                    <a href="logout.php" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- All Time Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3 class="stat-number"><?php echo $ticket_stats['total_all_time']; ?></h3>
                    <h6 class="mb-2">Total Tickets</h6>
                    <p class="text-muted small mb-0">All time</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 class="stat-number"><?php echo $ticket_stats['open_all_time']; ?></h3>
                    <h6 class="mb-2">Currently Open</h6>
                    <p class="text-muted small mb-0">Active tickets</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="stat-number"><?php echo $ticket_stats['closed_all_time']; ?></h3>
                    <h6 class="mb-2">Closed Tickets</h6>
                    <p class="text-muted small mb-0">Resolved</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3 class="stat-number">
                        <?php echo $ticket_stats['avg_resolution_days'] > 0 ? round($ticket_stats['avg_resolution_days'], 1) : 'N/A'; ?>
                    </h3>
                    <h6 class="mb-2">Avg. Resolution Days</h6>
                    <p class="text-muted small mb-0">Time to close</p>
                </div>
            </div>
        </div>

        <!-- Period Filter -->
        <div class="period-filter">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Tickets</h5>
            
            <!-- Quick Period Buttons -->
            <div class="d-flex flex-wrap mb-3">
                <a href="?period=all" class="btn btn-outline-success period-btn <?php echo $period == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list me-1"></i> All Tickets
                </a>
                <a href="?period=this_month" class="btn btn-outline-success period-btn <?php echo $period == 'this_month' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar me-1"></i> This Month
                </a>
                <a href="?period=last_month" class="btn btn-outline-success period-btn <?php echo $period == 'last_month' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt me-1"></i> Last Month
                </a>
                <button type="button" class="btn btn-outline-success period-btn" data-bs-toggle="collapse" data-bs-target="#customDateFilter">
                    <i class="fas fa-calendar-day me-1"></i> Custom Range
                </button>
            </div>
            
            <!-- Custom Date Range -->
            <div class="collapse <?php echo $period == 'custom' ? 'show' : ''; ?>" id="customDateFilter">
                <div class="custom-date-inputs">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="period" value="custom">
                        <div class="col-md-4">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?php echo $period == 'custom' ? htmlspecialchars($start_date) : ''; ?>" 
                                   max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?php echo $period == 'custom' ? htmlspecialchars($end_date) : date('Y-m-d'); ?>" 
                                   max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-search me-1"></i> Apply Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Current Filter Info -->
            <div class="mt-3">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Showing <?php echo $period_stats['total']; ?> tickets 
                    <?php if ($period == 'all'): ?>
                        (All tickets up to <?php echo date('M d, Y'); ?>)
                    <?php elseif ($period == 'custom'): ?>
                        (<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>)
                    <?php else: ?>
                        (<?php echo ucfirst(str_replace('_', ' ', $period)); ?>)
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <!-- Period Summary -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="summary-card">
                    <h5 class="summary-title"><i class="fas fa-chart-pie me-2"></i>Selected Period Summary</h5>
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="mb-3">
                                <div class="text-muted small">Total</div>
                                <h2 class="text-dark"><?php echo $period_stats['total']; ?></h2>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="mb-3">
                                <div class="text-muted small">Open</div>
                                <h2 class="text-primary"><?php echo $period_stats['open']; ?></h2>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="mb-3">
                                <div class="text-muted small">In Progress</div>
                                <h2 class="text-warning"><?php echo $period_stats['in_progress']; ?></h2>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="mb-3">
                                <div class="text-muted small">Closed</div>
                                <h2 class="text-success"><?php echo $period_stats['closed']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <?php if ($period_stats['avg_resolution_days'] > 0): ?>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>
                            Average resolution time: <strong><?php echo $period_stats['avg_resolution_days']; ?> days</strong>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card h-100">
                    <h5 class="summary-title"><i class="fas fa-server me-2"></i>Quick Stats</h5>
                    <div class="row">
                        <div class="col-6">
                            <div class="text-center mb-3">
                                <div class="text-muted small">Active Assets</div>
                                <h3 class="text-info"><?php echo $active_assets; ?></h3>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center mb-3">
                                <div class="text-muted small">Active Contracts</div>
                                <h3 class="text-warning"><?php echo $active_contracts; ?></h3>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="text-center">
                                <div class="text-muted small">Last 7 Days</div>
                                <h4 class="text-success"><?php echo $ticket_stats['last_7_days']; ?> new tickets</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Tickets Table -->
        <div class="tickets-card mb-5">
            <div class="table-header d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="fas fa-ticket-alt me-2"></i>
                        Ticket Details 
                        <span class="badge bg-success"><?php echo $period_stats['total']; ?> tickets</span>
                    </h4>
                </div>
                <div>
                    <button class="btn export-btn me-2" onclick="exportTable()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                    <a href="new-ticket.php" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> New Ticket
                    </a>
                </div>
            </div>
            
            <?php if (empty($all_tickets)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
                    <h3>No tickets found</h3>
                    <p class="text-muted mb-4">No tickets created in the selected period</p>
                    <a href="new-ticket.php" class="btn btn-success btn-lg">
                        <i class="fas fa-plus-circle me-1"></i> Create Your First Ticket
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="ticketsTable">
                        <thead>
                            <tr>
                                <th width="100">Date</th>
                                <th width="120">Ticket #</th>
                                <th>Details</th>
                                <th width="120">Status</th>
                                <th width="100">Priority</th>
                                <th width="150">Requested By</th>
                                <th width="150">Staff</th>
                                <th width="120">Activities</th>
                                <th width="100">Resolution</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_tickets as $ticket): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></div>
                                    <small class="text-muted"><?php echo date('h:i A', strtotime($ticket['created_at'])); ?></small>
                                </td>
                                <td>
                                    <a href="client-ticket-details.php?id=<?php echo $ticket['id']; ?>" 
                                       class="ticket-link">
                                        <?php echo htmlspecialchars($ticket['ticket_number']); ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-bold mb-1"><?php echo htmlspecialchars($ticket['title']); ?></div>
                                    <small class="text-muted">
                                        <?php 
                                        $desc = strip_tags($ticket['description']);
                                        echo htmlspecialchars(substr($desc, 0, 80));
                                        if (strlen($desc) > 80) echo '...';
                                        ?>
                                    </small>
                                    <?php if ($ticket['closed_at']): ?>
                                        <div class="mt-1">
                                            <small class="time-badge">
                                                <i class="fas fa-check-circle me-1"></i>
                                                Closed: <?php echo date('M d, Y', strtotime($ticket['closed_at'])); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $status = strtolower(str_replace(' ', '-', $ticket['status'] ?? 'unknown')); ?>
                                    <span class="status-badge status-<?php echo $status; ?> d-block text-center">
                                        <?php echo htmlspecialchars($ticket['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $priority = strtolower($ticket['priority'] ?? 'low'); ?>
                                    <span class="priority-badge priority-<?php echo $priority; ?> d-block text-center">
                                        <?php echo htmlspecialchars($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($ticket['requested_by']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($client['email'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php if ($ticket['staff_name']): ?>
                                        <div class="fw-bold"><?php echo htmlspecialchars($ticket['staff_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($ticket['staff_email']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ticket['comment_count'] > 0): ?>
                                        <a href="client-ticket-details.php?id=<?php echo $ticket['id']; ?>#comments" 
                                           class="text-decoration-none">
                                            <span class="activity-badge activity-active d-block text-center">
                                                <i class="fas fa-comments me-1"></i>
                                                <?php echo $ticket['comment_count']; ?> comments
                                            </span>
                                        </a>
                                        <small class="text-muted d-block text-center mt-1">
                                            Last: <?php echo date('M d', strtotime($ticket['last_activity'])); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="activity-badge activity-none d-block text-center">
                                            <i class="fas fa-comment-slash"></i> No comments
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ticket['resolution_days']): ?>
                                        <div class="fw-bold text-success text-center">
                                            <?php echo $ticket['resolution_days']; ?> days
                                        </div>
                                    <?php elseif ($ticket['closed_at']): ?>
                                        <small class="text-muted">Closed</small>
                                    <?php else: ?>
                                        <small class="text-muted">Ongoing</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="row mb-4">
            <div class="col-md-3">
                <a href="client-assets.php" class="btn btn-outline-info w-100 py-3 mb-3">
                    <i class="fas fa-server fa-2x mb-2 d-block"></i>
                    <h5>IT Assets</h5>
                    <small class="text-muted">Manage equipment</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="client-contracts.php" class="btn btn-outline-warning w-100 py-3 mb-3">
                    <i class="fas fa-file-contract fa-2x mb-2 d-block"></i>
                    <h5>Contracts</h5>
                    <small class="text-muted">View agreements</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="client-reports.php" class="btn btn-outline-primary w-100 py-3 mb-3">
                    <i class="fas fa-chart-bar fa-2x mb-2 d-block"></i>
                    <h5>Reports</h5>
                    <small class="text-muted">Analytics & insights</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="client-profile.php" class="btn btn-outline-secondary w-100 py-3 mb-3">
                    <i class="fas fa-user-cog fa-2x mb-2 d-block"></i>
                    <h5>My Profile</h5>
                    <small class="text-muted">Account settings</small>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Export table function
        function exportTable() {
            const table = document.getElementById('ticketsTable');
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [], cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    // Remove HTML tags and get text
                    let text = cols[j].innerText.replace(/\s+/g, ' ').trim();
                    // Escape quotes and wrap in quotes if contains comma
                    row.push('"' + text.replace(/"/g, '""') + '"');
                }
                
                csv.push(row.join(','));
            }
            
            // Download CSV file
            const csvContent = "data:text/csv;charset=utf-8," + csv.join('\n');
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement('a');
            link.setAttribute('href', encodedUri);
            link.setAttribute('download', 'tickets_<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Add zebra striping to table
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('#ticketsTable tbody tr');
            tableRows.forEach((row, index) => {
                if (index % 2 === 0) {
                    row.style.backgroundColor = '#fafafa';
                }
            });
        });
    </script>
</body>
</html>