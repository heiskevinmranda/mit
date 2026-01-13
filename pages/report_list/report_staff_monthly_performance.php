<?php
// report_staff_monthly_performance.php - FIXED VERSION
require_once __DIR__ . '/db_config.php';

// Get parameters
$staff_id = $_GET['staff_id'] ?? null;
$month = $_GET['month'] ?? date('m');
$year = $_GET['year'] ?? date('Y');
$department = $_GET['department'] ?? null;

// Calculate date range for the selected month
$start_date = date("$year-$month-01");
$end_date = date("$year-$month-t", strtotime($start_date));

$pdo = getDBConnection();

// Get staff list for dropdown
$staffQuery = "SELECT staff_id, full_name, COALESCE(department, 'Not Assigned') as department 
               FROM staff_profiles 
               WHERE employment_status = 'Active' 
               ORDER BY full_name";
$staffList = $pdo->query($staffQuery)->fetchAll();

// Main query for monthly performance - FIXED with COALESCE
$sql = "
WITH monthly_performance AS (
    SELECT 
        sp.staff_id as employee_id,
        sp.full_name,
        COALESCE(sp.department, 'Not Assigned') as department,
        COALESCE(sp.designation, 'Not Specified') as designation,
        COALESCE(sp.role_category, 'Not Categorized') as role_category,
        DATE_TRUNC('month', t.created_at) as report_month,
        
        -- Ticket Statistics
        COUNT(DISTINCT t.id) as total_tickets,
        COUNT(DISTINCT CASE WHEN t.status = 'Closed' THEN t.id END) as tickets_closed,
        COUNT(DISTINCT CASE WHEN t.status IN ('Open', 'In Progress') THEN t.id END) as tickets_open,
        COUNT(DISTINCT CASE WHEN t.status = 'Pending' THEN t.id END) as tickets_pending,
        
        -- Resolution Time Metrics
        ROUND(AVG(CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END)::numeric, 2) as avg_resolution_hours,
        
        MIN(CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END) as min_resolution_hours,
        
        MAX(CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END) as max_resolution_hours,
        
        -- Client Satisfaction
        ROUND(AVG(t.rating)::numeric, 2) as avg_client_rating,
        COUNT(DISTINCT CASE WHEN t.rating >= 4 THEN t.id END) as high_rating_tickets,
        COUNT(DISTINCT CASE WHEN t.rating <= 2 AND t.rating IS NOT NULL THEN t.id END) as low_rating_tickets,
        
        -- Work Log Metrics
        COALESCE(SUM(wl.total_hours), 0) as total_hours_logged,
        COUNT(DISTINCT wl.id) as work_log_entries,
        ROUND(AVG(wl.total_hours)::numeric, 2) as avg_hours_per_log,
        
        -- Site Visits
        COUNT(DISTINCT sv.id) as site_visits_completed,
        COALESCE(SUM(CASE 
            WHEN sv.check_in_time IS NOT NULL AND sv.check_out_time IS NOT NULL
            THEN EXTRACT(EPOCH FROM (sv.check_out_time - sv.check_in_time)) / 3600
            ELSE 0 
        END), 0) as total_site_hours,
        
        -- Priority Breakdown
        COUNT(DISTINCT CASE WHEN t.priority = 'Critical' THEN t.id END) as critical_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'High' THEN t.id END) as high_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Medium' THEN t.id END) as medium_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Low' THEN t.id END) as low_priority_tickets,
        
        -- SLA Compliance
        COUNT(DISTINCT CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL AND t.created_at IS NOT NULL
            AND EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 <= 24
            THEN t.id 
        END) as sla_compliant_tickets,
        
        -- Activity Dates
        MIN(t.created_at) as first_ticket_date,
        MAX(t.updated_at) as last_ticket_update,
        MIN(wl.work_date) as first_work_date,
        MAX(wl.work_date) as last_work_date
        
    FROM staff_profiles sp
    LEFT JOIN tickets t ON (t.assigned_to = sp.id OR t.primary_assignee = sp.id)
        AND DATE_TRUNC('month', t.created_at) = DATE_TRUNC('month', :month_start::timestamp)
    LEFT JOIN work_logs wl ON wl.staff_id = sp.id 
        AND wl.ticket_id = t.id
        AND DATE_TRUNC('month', wl.work_date) = DATE_TRUNC('month', :month_start::timestamp)
    LEFT JOIN site_visits sv ON sv.engineer_id = sp.id
        AND DATE_TRUNC('month', sv.created_at) = DATE_TRUNC('month', :month_start::timestamp)
    WHERE sp.employment_status = 'Active'
    " . ($staff_id ? " AND sp.staff_id = :staff_id" : "") . "
    " . ($department && $department !== 'All' && $department !== 'Not Assigned' ? " AND sp.department = :department" : "") . "
    GROUP BY sp.id, sp.staff_id, sp.full_name, sp.department, sp.designation, 
             sp.role_category, DATE_TRUNC('month', t.created_at)
)
SELECT 
    employee_id,
    full_name,
    department,
    designation,
    role_category,
    TO_CHAR(report_month, 'Month YYYY') as report_month,
    
    -- Ticket Metrics
    total_tickets,
    tickets_closed,
    tickets_open,
    tickets_pending,
    
    -- Closure Rate
    CASE 
        WHEN total_tickets > 0 
        THEN ROUND((tickets_closed::DECIMAL / total_tickets) * 100, 2)
        ELSE 0 
    END as closure_rate_percent,
    
    -- Resolution Time
    avg_resolution_hours,
    min_resolution_hours,
    max_resolution_hours,
    
    -- Client Satisfaction
    COALESCE(avg_client_rating, 0) as avg_client_rating,
    high_rating_tickets,
    low_rating_tickets,
    
    -- Work Metrics
    ROUND(total_hours_logged, 2) as total_hours_logged,
    work_log_entries,
    avg_hours_per_log,
    
    -- Site Visit Metrics
    site_visits_completed,
    ROUND(total_site_hours, 2) as total_site_hours,
    CASE 
        WHEN site_visits_completed > 0 
        THEN ROUND(total_site_hours / site_visits_completed, 2)
        ELSE 0 
    END as avg_site_hours,
    
    -- Priority Distribution
    critical_tickets,
    high_priority_tickets,
    medium_priority_tickets,
    low_priority_tickets,
    
    -- SLA Compliance
    CASE 
        WHEN total_tickets > 0 
        THEN ROUND((sla_compliant_tickets::DECIMAL / total_tickets) * 100, 2)
        ELSE 0 
    END as sla_compliance_rate,
    
    -- Activity Dates
    CASE 
        WHEN first_ticket_date IS NOT NULL 
        THEN TO_CHAR(first_ticket_date, 'MM/DD')
        ELSE 'N/A'
    END as first_ticket,
    
    CASE 
        WHEN last_ticket_update IS NOT NULL 
        THEN TO_CHAR(last_ticket_update, 'MM/DD HH24:MI')
        ELSE 'N/A'
    END as last_ticket,
    
    CASE 
        WHEN first_work_date IS NOT NULL 
        THEN TO_CHAR(first_work_date, 'MM/DD')
        ELSE 'N/A'
    END as first_work,
    
    CASE 
        WHEN last_work_date IS NOT NULL 
        THEN TO_CHAR(last_work_date, 'MM/DD')
        ELSE 'N/A'
    END as last_work
    
FROM monthly_performance
WHERE report_month IS NOT NULL OR total_hours_logged > 0 OR site_visits_completed > 0
ORDER BY tickets_closed DESC, total_hours_logged DESC
";

$params = [
    'month_start' => $start_date
];

if ($staff_id) {
    $params['staff_id'] = $staff_id;
}

if ($department && $department !== 'All' && $department !== 'Not Assigned') {
    $params['department'] = $department;
}

$stmt = executeQuery($pdo, $sql, $params);
$results = $stmt->fetchAll();

// Calculate summary statistics
$summary = [
    'total_staff' => count($results),
    'total_tickets' => array_sum(array_column($results, 'total_tickets')),
    'total_closed' => array_sum(array_column($results, 'tickets_closed')),
    'total_hours' => array_sum(array_column($results, 'total_hours_logged')),
    'total_site_visits' => array_sum(array_column($results, 'site_visits_completed')),
    'avg_closure_rate' => count($results) > 0 ? 
        round(array_sum(array_column($results, 'closure_rate_percent')) / count($results), 1) : 0,
    'avg_rating' => count($results) > 0 ? 
        round(array_sum(array_column($results, 'avg_client_rating')) / count($results), 2) : 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Monthly Performance Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stat-card {
            border-radius: 10px;
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .performance-meter {
            height: 10px;
            border-radius: 5px;
            background: #e9ecef;
            overflow: hidden;
        }
        .meter-fill {
            height: 100%;
            border-radius: 5px;
        }
        .rating-stars {
            color: #ffc107;
            font-size: 0.9em;
        }
        .badge-pill {
            border-radius: 10rem;
            padding: 0.25em 0.8em;
        }
        .table th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        .progress-thin {
            height: 12px;
            border-radius: 6px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .text-purple {
            color: #6f42c1;
        }
        .bg-purple {
            background-color: #6f42c1 !important;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="fas fa-chart-bar text-primary"></i> Staff Monthly Performance Report</h1>
                <p class="lead"><?php echo date('F Y', strtotime($start_date)); ?></p>
            </div>
            <div>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'csv'])); ?>" 
                   class="btn btn-success">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filter Options</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Staff Member</label>
                        <select name="staff_id" class="form-select">
                            <option value="">All Staff</option>
                            <?php foreach ($staffList as $staff): ?>
                            <option value="<?php echo htmlspecialchars($staff['staff_id'] ?? ''); ?>"
                                <?php echo ($staff_id == $staff['staff_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($staff['full_name'] ?? 'Unknown') . ' (' . ($staff['staff_id'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Month</label>
                        <select name="month" class="form-select">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo sprintf('%02d', $m); ?>"
                                <?php echo ($month == sprintf('%02d', $m)) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year</label>
                        <select name="year" class="form-select">
                            <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                            <option value="<?php echo $y; ?>"
                                <?php echo ($year == $y) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="All">All Departments</option>
                            <option value="Not Assigned" <?php echo ($department == 'Not Assigned') ? 'selected' : ''; ?>>Not Assigned</option>
                            <?php
                            $deptQuery = $pdo->query("SELECT DISTINCT COALESCE(department, 'Not Assigned') as department 
                                                     FROM staff_profiles 
                                                     ORDER BY department");
                            $departments = $deptQuery->fetchAll();
                            foreach ($departments as $dept):
                                if ($dept['department'] !== 'Not Assigned'):
                            ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>"
                                <?php echo ($department == $dept['department']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department']); ?>
                            </option>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Generate
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card border-left-primary">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Staff Reported
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $summary['total_staff']; ?>
                                </div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Active staff with activity</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users stat-icon text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card border-left-success">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Tickets Closed
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $summary['total_closed']; ?>
                                </div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Out of <?php echo $summary['total_tickets']; ?> total</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-check-circle stat-icon text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card border-left-info">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Hours Logged
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo number_format($summary['total_hours'], 1); ?>h
                                </div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Avg <?php echo $summary['total_staff'] > 0 ? number_format($summary['total_hours'] / $summary['total_staff'], 1) : 0; ?>h per staff</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock stat-icon text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card stat-card border-left-warning">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Avg Closure Rate
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $summary['avg_closure_rate']; ?>%
                                </div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Avg Rating: <?php echo $summary['avg_rating']; ?>/5</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chart-line stat-icon text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-table"></i> Monthly Performance Details
                    <span class="badge bg-primary float-end"><?php echo count($results); ?> Records</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped" id="performanceTable">
                        <thead>
                            <tr class="table-light">
                                <th>#</th>
                                <th>Staff Details</th>
                                <th>Tickets</th>
                                <th>Resolution Time</th>
                                <th>Client Rating</th>
                                <th>Work Hours</th>
                                <th>Site Visits</th>
                                <th>Closure Rate</th>
                                <th>SLA %</th>
                                <th>Activities</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                
                                <!-- Staff Details -->
                                <td>
                                    <div class="d-flex flex-column">
                                        <strong><?php echo htmlspecialchars($row['full_name'] ?? 'Unknown'); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['employee_id'] ?? ''); ?></small>
                                        <div class="mt-1">
                                            <span class="badge bg-secondary badge-pill">
                                                <?php echo htmlspecialchars($row['department'] ?? 'Not Assigned'); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['designation'] ?? 'Not Specified'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Tickets -->
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="mb-1">
                                            <span class="badge bg-primary">Total: <?php echo $row['total_tickets'] ?? 0; ?></span>
                                        </div>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <span class="badge bg-success">✓ <?php echo $row['tickets_closed'] ?? 0; ?></span>
                                            <span class="badge bg-warning">⏱ <?php echo $row['tickets_open'] ?? 0; ?></span>
                                            <span class="badge bg-info">⏳ <?php echo $row['tickets_pending'] ?? 0; ?></span>
                                        </div>
                                        <div class="mt-1 small">
                                            <span class="text-danger">C:<?php echo $row['critical_tickets'] ?? 0; ?></span> |
                                            <span class="text-warning">H:<?php echo $row['high_priority_tickets'] ?? 0; ?></span> |
                                            <span class="text-primary">M:<?php echo $row['medium_priority_tickets'] ?? 0; ?></span> |
                                            <span class="text-success">L:<?php echo $row['low_priority_tickets'] ?? 0; ?></span>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Resolution Time -->
                                <td>
                                    <?php if (($row['avg_resolution_hours'] ?? 0) > 0): ?>
                                    <div class="d-flex flex-column">
                                        <div class="mb-1">
                                            <span class="badge <?php 
                                                echo ($row['avg_resolution_hours'] ?? 0) < 8 ? 'bg-success' : 
                                                     (($row['avg_resolution_hours'] ?? 0) < 24 ? 'bg-warning' : 'bg-danger'); 
                                            ?>">
                                                Avg: <?php echo $row['avg_resolution_hours'] ?? 'N/A'; ?>h
                                            </span>
                                        </div>
                                        <div class="small text-muted">
                                            Min: <?php echo isset($row['min_resolution_hours']) && $row['min_resolution_hours'] !== null ? $row['min_resolution_hours'] . 'h' : 'N/A'; ?><br>
                                            Max: <?php echo isset($row['max_resolution_hours']) && $row['max_resolution_hours'] !== null ? $row['max_resolution_hours'] . 'h' : 'N/A'; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">No closed tickets</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Client Rating -->
                                <td>
                                    <?php if (($row['avg_client_rating'] ?? 0) > 0): ?>
                                    <div class="d-flex flex-column">
                                        <div class="rating-stars mb-1">
                                            <?php
                                            $rating = $row['avg_client_rating'] ?? 0;
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= floor($rating)) {
                                                    echo '<i class="fas fa-star"></i>';
                                                } elseif ($i == ceil($rating) && fmod($rating, 1) >= 0.5) {
                                                    echo '<i class="fas fa-star-half-alt"></i>';
                                                } else {
                                                    echo '<i class="far fa-star"></i>';
                                                }
                                            }
                                            ?>
                                        </div>
                                        <strong><?php echo $row['avg_client_rating'] ?? 0; ?>/5</strong>
                                        <div class="small text-muted">
                                            <span class="text-success">High: <?php echo $row['high_rating_tickets'] ?? 0; ?></span> |
                                            <span class="text-danger">Low: <?php echo $row['low_rating_tickets'] ?? 0; ?></span>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">No ratings</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Work Hours -->
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="mb-1">
                                            <span class="badge bg-info">
                                                <?php echo $row['total_hours_logged'] ?? 0; ?>h
                                            </span>
                                        </div>
                                        <div class="small text-muted">
                                            Logs: <?php echo $row['work_log_entries'] ?? 0; ?><br>
                                            Avg/Log: <?php echo $row['avg_hours_per_log'] ?? 0; ?>h
                                        </div>
                                        <div class="mt-1">
                                            <small>
                                                <i class="fas fa-calendar"></i> 
                                                <?php echo $row['first_work'] ?? 'N/A'; ?> - <?php echo $row['last_work'] ?? 'N/A'; ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Site Visits -->
                                <td>
                                    <?php if (($row['site_visits_completed'] ?? 0) > 0): ?>
                                    <div class="d-flex flex-column">
                                        <span class="badge bg-purple">
                                            <?php echo $row['site_visits_completed'] ?? 0; ?> visits
                                        </span>
                                        <div class="small text-muted mt-1">
                                            Hours: <?php echo $row['total_site_hours'] ?? 0; ?>h<br>
                                            Avg: <?php echo $row['avg_site_hours'] ?? 0; ?>h/visit
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">No visits</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Closure Rate -->
                                <td>
                                    <div class="d-flex flex-column">
                                        <div class="mb-2">
                                            <span class="badge <?php 
                                                echo ($row['closure_rate_percent'] ?? 0) >= 90 ? 'bg-success' : 
                                                     (($row['closure_rate_percent'] ?? 0) >= 70 ? 'bg-warning' : 'bg-danger'); 
                                            ?>">
                                                <?php echo $row['closure_rate_percent'] ?? 0; ?>%
                                            </span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar 
                                                <?php echo ($row['closure_rate_percent'] ?? 0) >= 90 ? 'bg-success' : 
                                                       (($row['closure_rate_percent'] ?? 0) >= 70 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                style="width: <?php echo min(($row['closure_rate_percent'] ?? 0), 100); ?>%">
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- SLA Compliance -->
                                <td>
                                    <?php if (($row['total_tickets'] ?? 0) > 0): ?>
                                    <div class="d-flex flex-column">
                                        <div class="mb-2">
                                            <span class="badge <?php 
                                                echo ($row['sla_compliance_rate'] ?? 0) >= 90 ? 'bg-success' : 
                                                     (($row['sla_compliance_rate'] ?? 0) >= 70 ? 'bg-warning' : 'bg-danger'); 
                                            ?>">
                                                <?php echo $row['sla_compliance_rate'] ?? 0; ?>%
                                            </span>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar 
                                                <?php echo ($row['sla_compliance_rate'] ?? 0) >= 90 ? 'bg-success' : 
                                                       (($row['sla_compliance_rate'] ?? 0) >= 70 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                style="width: <?php echo min(($row['sla_compliance_rate'] ?? 0), 100); ?>%">
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Activities -->
                                <td>
                                    <div class="d-flex flex-column small">
                                        <div class="mb-1">
                                            <i class="fas fa-ticket-alt text-primary"></i>
                                            First: <?php echo $row['first_ticket'] ?? 'N/A'; ?>
                                        </div>
                                        <div class="mb-1">
                                            <i class="fas fa-history text-info"></i>
                                            Last: <?php echo $row['last_ticket'] ?? 'N/A'; ?>
                                        </div>
                                        <div class="mb-1">
                                            <i class="fas fa-briefcase text-success"></i>
                                            Work: <?php echo $row['first_work'] ?? 'N/A'; ?> - <?php echo $row['last_work'] ?? 'N/A'; ?>
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
        
        <!-- Performance Summary -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Performance Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Top Performers (by Tickets Closed)</h6>
                        <ul class="list-group">
                            <?php 
                            usort($results, function($a, $b) {
                                return ($b['tickets_closed'] ?? 0) <=> ($a['tickets_closed'] ?? 0);
                            });
                            $top5 = array_slice($results, 0, 5);
                            foreach ($top5 as $top): 
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo htmlspecialchars($top['full_name'] ?? 'Unknown'); ?>
                                <span class="badge bg-primary rounded-pill"><?php echo $top['tickets_closed'] ?? 0; ?> closed</span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Performance Categories</h6>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <div class="card text-white bg-success">
                                    <div class="card-body text-center">
                                        <h6>Excellent (>90%)</h6>
                                        <h4>
                                            <?php 
                                            $excellent = array_filter($results, function($r) {
                                                return ($r['closure_rate_percent'] ?? 0) >= 90;
                                            });
                                            echo count($excellent);
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="card text-white bg-warning">
                                    <div class="card-body text-center">
                                        <h6>Good (70-89%)</h6>
                                        <h4>
                                            <?php 
                                            $good = array_filter($results, function($r) {
                                                $rate = $r['closure_rate_percent'] ?? 0;
                                                return $rate >= 70 && $rate < 90;
                                            });
                                            echo count($good);
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card text-white bg-danger">
                                    <div class="card-body text-center">
                                        <h6>Needs Improvement (<70%)</h6>
                                        <h4>
                                            <?php 
                                            $needs = array_filter($results, function($r) {
                                                return ($r['closure_rate_percent'] ?? 0) < 70;
                                            });
                                            echo count($needs);
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="card text-white bg-secondary">
                                    <div class="card-body text-center">
                                        <h6>No Activity</h6>
                                        <h4>
                                            <?php 
                                            $inactive = array_filter($results, function($r) {
                                                return ($r['total_tickets'] ?? 0) == 0;
                                            });
                                            echo count($inactive);
                                            ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#performanceTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: {
                    search: 'Search:',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ staff',
                    paginate: {
                        first: 'First',
                        last: 'Last',
                        next: 'Next',
                        previous: 'Previous'
                    }
                }
            });
        });
    </script>
</body>
</html>