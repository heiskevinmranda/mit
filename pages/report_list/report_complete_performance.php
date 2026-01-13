<?php
// report_complete_performance.php
require_once __DIR__ . '/db_config.php';

// Get optional filters from request
$department = $_GET['department'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$pdo = getDBConnection();

$sql = "
WITH performance_metrics AS (
    SELECT 
        sp.id as staff_id,
        sp.staff_id as employee_id,
        sp.full_name,
        COALESCE(sp.department, 'Not Assigned') as department,
        COALESCE(sp.designation, 'Not Specified') as designation,
        COALESCE(sp.role_category, 'Not Categorized') as role_category,
        sp.employment_status,
        
        -- Ticket Metrics
        COUNT(DISTINCT t.id) as total_tickets_assigned,
        COUNT(DISTINCT CASE WHEN t.status = 'Closed' THEN t.id END) as tickets_closed,
        COUNT(DISTINCT CASE WHEN t.status IN ('Open', 'In Progress') THEN t.id END) as tickets_open,
        COUNT(DISTINCT CASE WHEN t.status = 'Pending' THEN t.id END) as tickets_pending,
        
        -- Resolution Time Metrics
        ROUND(AVG(CASE 
            WHEN t.status = 'Closed' AND t.created_at IS NOT NULL AND t.closed_at IS NOT NULL 
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END)::numeric, 1) as avg_resolution_hours,
        
        -- SLA Compliance
        COUNT(DISTINCT CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL AND t.created_at IS NOT NULL
            AND EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 <= 24
            THEN t.id 
        END) as sla_compliant_tickets,
        
        -- Priority Distribution
        COUNT(DISTINCT CASE WHEN t.priority = 'Critical' THEN t.id END) as critical_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'High' THEN t.id END) as high_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Medium' THEN t.id END) as medium_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Low' THEN t.id END) as low_priority_tickets,
        
        -- Client Satisfaction
        ROUND(AVG(t.rating)::numeric, 2) as avg_client_rating,
        COUNT(DISTINCT CASE WHEN t.rating >= 4 THEN t.id END) as high_rating_tickets,
        COUNT(DISTINCT CASE WHEN t.rating <= 2 AND t.rating IS NOT NULL THEN t.id END) as low_rating_tickets,
        
        -- Work Log Metrics
        COALESCE(SUM(wl.total_hours), 0) as total_hours_logged,
        COUNT(DISTINCT wl.id) as total_work_entries,
        
        -- Site Visit Metrics
        COUNT(DISTINCT sv.id) as total_site_visits,
        
        -- Recent Activity
        MAX(t.updated_at) as last_ticket_update,
        MAX(wl.work_date) as last_work_log_date,
        MAX(sv.created_at) as last_site_visit
        
    FROM staff_profiles sp
    LEFT JOIN tickets t ON (t.assigned_to = sp.id OR t.primary_assignee = sp.id)
        AND t.created_at BETWEEN :start_date AND :end_date
    LEFT JOIN work_logs wl ON wl.staff_id = sp.id 
        AND wl.ticket_id = t.id
        AND wl.work_date BETWEEN :start_date AND :end_date
    LEFT JOIN site_visits sv ON sv.engineer_id = sp.id
        AND sv.created_at BETWEEN :start_date AND :end_date
    WHERE sp.employment_status = 'Active'
    " . ($department && $department !== 'Not Assigned' ? " AND sp.department = :department" : "") . "
    GROUP BY sp.id, sp.staff_id, sp.full_name, sp.department, sp.designation, 
             sp.role_category, sp.employment_status
),
calculated_scores AS (
    SELECT *,
        -- Productivity Score
        CASE 
            WHEN total_tickets_assigned > 0 
            THEN ROUND((tickets_closed::DECIMAL / total_tickets_assigned) * 100, 1)
            ELSE 0 
        END as closure_rate,
        
        -- Efficiency Score
        CASE 
            WHEN avg_resolution_hours IS NULL THEN 50
            WHEN avg_resolution_hours < 4 THEN 100
            WHEN avg_resolution_hours < 8 THEN 90
            WHEN avg_resolution_hours < 24 THEN 80
            WHEN avg_resolution_hours < 48 THEN 60
            WHEN avg_resolution_hours < 72 THEN 40
            ELSE 20
        END as efficiency_score,
        
        -- Quality Score
        CASE 
            WHEN avg_client_rating IS NULL THEN 60
            WHEN avg_client_rating >= 4.5 THEN 100
            WHEN avg_client_rating >= 4.0 THEN 90
            WHEN avg_client_rating >= 3.5 THEN 80
            WHEN avg_client_rating >= 3.0 THEN 70
            WHEN avg_client_rating >= 2.5 THEN 60
            ELSE 50
        END as quality_score,
        
        -- SLA Compliance Score
        CASE 
            WHEN total_tickets_assigned > 0 
            THEN ROUND((sla_compliant_tickets::DECIMAL / total_tickets_assigned) * 100, 1)
            ELSE 0 
        END as sla_compliance_rate
        
    FROM performance_metrics
)
SELECT 
    employee_id,
    full_name as staff_name,
    department,
    designation,
    role_category,
    total_tickets_assigned,
    tickets_closed,
    tickets_open,
    tickets_pending,
    closure_rate,
    COALESCE(avg_resolution_hours, 0) as avg_resolution_hours,
    COALESCE(avg_client_rating, 0) as avg_client_rating,
    sla_compliance_rate,
    ROUND(total_hours_logged, 1) as total_hours_logged,
    total_site_visits,
    
    -- Overall Performance Score
    ROUND((
        (closure_rate * 0.25) +
        (efficiency_score * 0.20) +
        (quality_score * 0.25) +
        (sla_compliance_rate * 0.15) +
        (CASE WHEN total_hours_logged > 0 THEN 80 ELSE 50 END * 0.10) +
        (CASE WHEN total_site_visits > 0 THEN 70 ELSE 50 END * 0.05)
    ), 1) as overall_performance_score,
    
    -- Performance Tier
    CASE 
        WHEN tickets_closed >= 10 AND COALESCE(avg_client_rating, 0) >= 4.0 
             AND sla_compliance_rate >= 70 THEN 'Top Performer'
        WHEN tickets_closed >= 5 AND COALESCE(avg_client_rating, 0) >= 3.5 
             AND sla_compliance_rate >= 60 THEN 'High Performer'
        WHEN tickets_closed >= 3 AND COALESCE(avg_client_rating, 0) >= 3.0 
             AND sla_compliance_rate >= 50 THEN 'Solid Performer'
        WHEN tickets_closed >= 1 THEN 'Developing'
        ELSE 'New/No Activity'
    END as performance_tier,
    
    high_rating_tickets,
    low_rating_tickets,
    critical_tickets,
    high_priority_tickets,
    medium_priority_tickets,
    low_priority_tickets,
    
    CASE 
        WHEN last_ticket_update IS NOT NULL 
        THEN TO_CHAR(last_ticket_update, 'MM/DD HH24:MI')
        ELSE 'No activity'
    END as last_update
    
FROM calculated_scores
ORDER BY overall_performance_score DESC, tickets_closed DESC, total_hours_logged DESC
";

$params = [
    'start_date' => $start_date,
    'end_date' => $end_date . ' 23:59:59'
];

if ($department && $department !== 'Not Assigned') {
    $params['department'] = $department;
}

$stmt = executeQuery($pdo, $sql, $params);
$results = $stmt->fetchAll();

// Handle CSV export
if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="staff_performance_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    if (!empty($results)) {
        fputcsv($output, array_keys($results[0]));
    }
    
    // Add data
    foreach ($results as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Handle JSON export
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Staff Performance Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-stat {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .card-stat:hover {
            transform: translateY(-5px);
        }
        .card-stat-1 { border-color: #4e73df; }
        .card-stat-2 { border-color: #1cc88a; }
        .card-stat-3 { border-color: #36b9cc; }
        .card-stat-4 { border-color: #f6c23e; }
        .progress-thin {
            height: 15px;
            border-radius: 3px;
        }
        .performance-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .top-performer { background: linear-gradient(45deg, #28a745, #20c997); color: white; }
        .high-performer { background: linear-gradient(45deg, #17a2b8, #20c9d0); color: white; }
        .solid-performer { background: linear-gradient(45deg, #ffc107, #ffd054); color: #212529; }
        .developing { background: linear-gradient(45deg, #6c757d, #8a939b); color: white; }
        .new-no-activity { background: linear-gradient(45deg, #e9ecef, #f8f9fa); color: #6c757d; }
        .table-hover tbody tr:hover {
            background-color: rgba(0,123,255,0.05);
        }
        .rating-stars {
            color: #ffc107;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-3">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="fas fa-chart-line text-primary"></i> Staff Performance Dashboard</h1>
                <p class="text-muted mb-0">Period: <?php echo date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date)); ?></p>
            </div>
            <div>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'csv'])); ?>" class="btn btn-success">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'json'])); ?>" class="btn btn-info">
                    <i class="fas fa-file-code"></i> JSON
                </a>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filter Options</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php
                            $deptStmt = $pdo->query("SELECT DISTINCT department FROM staff_profiles WHERE employment_status = 'Active' AND department IS NOT NULL ORDER BY department");
                            while ($dept = $deptStmt->fetch()) {
                                $selected = $dept['department'] == $department ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($dept['department']) . "' $selected>" . htmlspecialchars($dept['department']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card card-stat card-stat-1 h-100">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Total Staff
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo count($results); ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card card-stat card-stat-2 h-100">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Total Tickets Closed
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo array_sum(array_column($results, 'tickets_closed')); ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card card-stat card-stat-3 h-100">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Total Hours Logged
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo round(array_sum(array_column($results, 'total_hours_logged')), 1); ?>h
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card card-stat card-stat-4 h-100">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Avg Performance Score
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php 
                                    $avgScore = count($results) > 0 ? 
                                        round(array_sum(array_column($results, 'overall_performance_score')) / count($results), 1) : 0;
                                    echo $avgScore;
                                    ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-star fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Table -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-table"></i> Performance Details</h5>
                <span class="badge bg-primary"><?php echo count($results); ?> Staff Members</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="performanceTable">
                        <thead class="table-light">
                            <tr>
                                <th>Rank</th>
                                <th>Employee ID</th>
                                <th>Staff Name</th>
                                <th>Department</th>
                                <th>Tickets</th>
                                <th>Closed</th>
                                <th>Closure Rate</th>
                                <th>Avg Res (h)</th>
                                <th>Rating</th>
                                <th>SLA %</th>
                                <th>Hours</th>
                                <th>Score</th>
                                <th>Performance</th>
                                <th>Last Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($results as $row): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-dark">#<?php echo $rank++; ?></span>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($row['employee_id']); ?></code>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['staff_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['designation']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($row['department']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="badge bg-primary rounded-pill px-3">
                                            <?php echo $row['total_tickets_assigned']; ?>
                                        </span>
                                        <?php if ($row['tickets_open'] > 0): ?>
                                        <small class="text-warning mt-1">
                                            <i class="fas fa-clock"></i> <?php echo $row['tickets_open']; ?> open
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-success rounded-pill px-3">
                                        <?php echo $row['tickets_closed']; ?>
                                    </span>
                                </td>
                                <td style="min-width: 120px;">
                                    <?php if ($row['total_tickets_assigned'] > 0): ?>
                                    <div class="d-flex align-items-center">
                                        <div class="progress progress-thin flex-grow-1 me-2">
                                            <div class="progress-bar 
                                                <?php echo $row['closure_rate'] >= 80 ? 'bg-success' : 
                                                       ($row['closure_rate'] >= 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                style="width: <?php echo min($row['closure_rate'], 100); ?>%">
                                            </div>
                                        </div>
                                        <small class="text-nowrap"><?php echo $row['closure_rate']; ?>%</small>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['avg_resolution_hours'] > 0): ?>
                                        <?php if ($row['avg_resolution_hours'] < 8): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-bolt"></i> <?php echo $row['avg_resolution_hours']; ?>h
                                            </span>
                                        <?php elseif ($row['avg_resolution_hours'] < 24): ?>
                                            <span class="badge bg-warning">
                                                <?php echo $row['avg_resolution_hours']; ?>h
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle"></i> <?php echo $row['avg_resolution_hours']; ?>h
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['avg_client_rating'] > 0): ?>
                                        <div class="d-flex align-items-center">
                                            <div class="rating-stars me-2">
                                                <?php
                                                $rating = $row['avg_client_rating'];
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
                                            <small class="fw-bold"><?php echo $row['avg_client_rating']; ?></small>
                                        </div>
                                        <?php if ($row['high_rating_tickets'] > 0): ?>
                                        <small class="text-success d-block">
                                            <i class="fas fa-thumbs-up"></i> <?php echo $row['high_rating_tickets']; ?> high ratings
                                        </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No ratings</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['sla_compliance_rate'] > 0): ?>
                                        <?php if ($row['sla_compliance_rate'] >= 90): ?>
                                            <span class="badge bg-success"><?php echo $row['sla_compliance_rate']; ?>%</span>
                                        <?php elseif ($row['sla_compliance_rate'] >= 70): ?>
                                            <span class="badge bg-warning"><?php echo $row['sla_compliance_rate']; ?>%</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><?php echo $row['sla_compliance_rate']; ?>%</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="badge bg-info">
                                            <?php echo $row['total_hours_logged']; ?>h
                                        </span>
                                        <?php if ($row['total_site_visits'] > 0): ?>
                                        <small class="text-muted mt-1">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo $row['total_site_visits']; ?> visits
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="performance-badge 
                                        <?php echo strtolower(str_replace([' ', '/'], ['-', '-'], $row['performance_tier'])); ?>">
                                        <?php echo $row['overall_performance_score']; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill 
                                        <?php echo match($row['performance_tier']) {
                                            'Top Performer' => 'bg-success',
                                            'High Performer' => 'bg-info',
                                            'Solid Performer' => 'bg-warning',
                                            'Developing' => 'bg-secondary',
                                            default => 'bg-light text-dark border'
                                        }; ?>">
                                        <?php if ($row['performance_tier'] == 'Top Performer'): ?>
                                            <i class="fas fa-crown"></i>
                                        <?php elseif ($row['performance_tier'] == 'High Performer'): ?>
                                            <i class="fas fa-trophy"></i>
                                        <?php elseif ($row['performance_tier'] == 'Solid Performer'): ?>
                                            <i class="fas fa-medal"></i>
                                        <?php elseif ($row['performance_tier'] == 'Developing'): ?>
                                            <i class="fas fa-seedling"></i>
                                        <?php else: ?>
                                            <i class="fas fa-user-clock"></i>
                                        <?php endif; ?>
                                        <?php echo $row['performance_tier']; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo $row['last_update']; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                order: [[0, 'asc']],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: {
                    search: '<i class="fas fa-search"></i>',
                    searchPlaceholder: 'Search staff...',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ staff',
                    paginate: {
                        first: '<i class="fas fa-angle-double-left"></i>',
                        last: '<i class="fas fa-angle-double-right"></i>',
                        next: '<i class="fas fa-angle-right"></i>',
                        previous: '<i class="fas fa-angle-left"></i>'
                    }
                },
                columnDefs: [
                    { orderable: true, targets: '_all' },
                    { className: 'text-center', targets: [0, 4, 5, 7, 9, 10, 11, 12] }
                ]
            });
        });
    </script>
</body>
</html>