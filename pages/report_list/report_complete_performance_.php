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
        AVG(CASE 
            WHEN t.status = 'Closed' AND t.created_at IS NOT NULL AND t.closed_at IS NOT NULL 
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END) as avg_resolution_hours,
        
        -- SLA Compliance (simplified - check if resolution time exists)
        COUNT(DISTINCT CASE 
            WHEN t.actual_resolution_time IS NOT NULL AND t.closed_at IS NOT NULL
            AND t.created_at IS NOT NULL
            AND EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 <= 24
            THEN t.id 
        END) as sla_compliant_tickets,
        
        -- Priority Distribution
        COUNT(DISTINCT CASE WHEN t.priority = 'Critical' THEN t.id END) as critical_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'High' THEN t.id END) as high_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Medium' THEN t.id END) as medium_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Low' THEN t.id END) as low_priority_tickets,
        
        -- Client Satisfaction
        AVG(t.rating) as avg_client_rating,
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
            THEN (tickets_closed::DECIMAL / total_tickets_assigned) * 100 
            ELSE 0 
        END as closure_rate,
        
        -- Efficiency Score (based on resolution time)
        CASE 
            WHEN avg_resolution_hours IS NULL THEN 50
            WHEN avg_resolution_hours < 4 THEN 100
            WHEN avg_resolution_hours < 8 THEN 90
            WHEN avg_resolution_hours < 24 THEN 80
            WHEN avg_resolution_hours < 48 THEN 60
            WHEN avg_resolution_hours < 72 THEN 40
            ELSE 20
        END as efficiency_score,
        
        -- Quality Score (based on ratings)
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
            THEN (sla_compliant_tickets::DECIMAL / total_tickets_assigned) * 100 
            ELSE 0 
        END as sla_compliance_rate
        
    FROM performance_metrics
),
final_scores AS (
    SELECT *,
        -- Overall Performance Score
        ROUND((
            (closure_rate * 0.25) +
            (efficiency_score * 0.20) +
            (quality_score * 0.25) +
            (sla_compliance_rate * 0.15) +
            (CASE WHEN total_hours_logged > 0 THEN 80 ELSE 50 END * 0.10) +
            (CASE WHEN total_site_visits > 0 THEN 70 ELSE 50 END * 0.05)
        ), 1) as overall_performance_score,
        
        -- Performance Tier (updated thresholds)
        CASE 
            WHEN tickets_closed >= 15 AND COALESCE(avg_client_rating, 0) >= 4.0 
                 AND sla_compliance_rate >= 80 THEN 'Top Performer'
            WHEN tickets_closed >= 10 AND COALESCE(avg_client_rating, 0) >= 3.5 
                 AND sla_compliance_rate >= 70 THEN 'High Performer'
            WHEN tickets_closed >= 5 AND COALESCE(avg_client_rating, 0) >= 3.0 
                 AND sla_compliance_rate >= 60 THEN 'Solid Performer'
            WHEN tickets_closed >= 1 THEN 'Developing'
            ELSE 'New/No Activity'
        END as performance_tier
        
    FROM calculated_scores
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
    ROUND(closure_rate, 1) as closure_rate,
    ROUND(COALESCE(avg_resolution_hours, 0), 1) as avg_resolution_hours,
    ROUND(COALESCE(avg_client_rating, 0), 2) as avg_client_rating,
    ROUND(sla_compliance_rate, 1) as sla_compliance_rate,
    ROUND(total_hours_logged, 1) as total_hours_logged,
    total_site_visits,
    overall_performance_score,
    performance_tier,
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
    END as last_update,
    CASE 
        WHEN last_work_log_date IS NOT NULL 
        THEN TO_CHAR(last_work_log_date, 'MM/DD')
        ELSE 'No work'
    END as last_work,
    CASE 
        WHEN last_site_visit IS NOT NULL 
        THEN TO_CHAR(last_site_visit, 'MM/DD')
        ELSE 'No visits'
    END as last_visit,
    employment_status
FROM final_scores
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

// Output as JSON or HTML
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($results);
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Staff Performance Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .performance-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .top-performer { background-color: #d4edda; color: #155724; }
        .high-performer { background-color: #cce5ff; color: #004085; }
        .solid-performer { background-color: #fff3cd; color: #856404; }
        .developing { background-color: #f8d7da; color: #721c24; }
        .new-no-activity { background-color: #e2e3e5; color: #383d41; }
        .progress {
            height: 20px;
        }
        .progress-bar {
            line-height: 20px;
            font-size: 12px;
        }
        .badge-sm {
            font-size: 0.75em;
            padding: 0.25em 0.5em;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <h1>Complete Staff Performance Dashboard</h1>
        <p class="text-muted">Period: <?php echo htmlspecialchars($start_date) . ' to ' . htmlspecialchars($end_date); ?></p>
        
        <div class="row mb-4">
            <div class="col-md-12">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label>Department</label>
                        <select name="department" class="form-select">
                            <option value="">All Departments</option>
                            <option value="Not Assigned" <?php echo ($department == 'Not Assigned') ? 'selected' : ''; ?>>Not Assigned</option>
                            <?php
                            $deptStmt = $pdo->query("SELECT DISTINCT department FROM staff_profiles WHERE employment_status = 'Active' AND department IS NOT NULL ORDER BY department");
                            $departments = $deptStmt->fetchAll();
                            foreach ($departments as $dept) {
                                if (!empty($dept['department'])) {
                                    $selected = ($dept['department'] == $department) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($dept['department']) . "\" $selected>" . htmlspecialchars($dept['department']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label>&nbsp;</label><br>
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="report_complete_performance.php?format=csv&<?php echo http_build_query($_GET); ?>" class="btn btn-success">Export CSV</a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Summary Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <h3><?php echo count($results); ?></h3>
                                <p class="text-muted">Total Staff</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3><?php echo array_sum(array_column($results, 'tickets_closed')); ?></h3>
                                <p class="text-muted">Total Tickets Closed</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3><?php echo round(array_sum(array_column($results, 'total_hours_logged')), 1); ?>h</h3>
                                <p class="text-muted">Total Hours Logged</p>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3><?php 
                                $avgScore = count($results) > 0 ? array_sum(array_column($results, 'overall_performance_score')) / count($results) : 0;
                                echo round($avgScore, 1);
                                ?></h3>
                                <p class="text-muted">Average Performance Score</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="performanceTable">
                <thead class="table-dark">
                    <tr>
                        <th>Rank</th>
                        <th>Employee ID</th>
                        <th>Staff Name</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Tickets</th>
                        <th>Closed</th>
                        <th>Closure Rate</th>
                        <th>Avg Res (h)</th>
                        <th>Avg Rating</th>
                        <th>SLA %</th>
                        <th>Hours</th>
                        <th>Score</th>
                        <th>Tier</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; ?>
                    <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($row['employee_id']); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['staff_name']); ?></strong></td>
                        <td>
                            <span class="badge bg-secondary">
                                <?php echo htmlspecialchars($row['department']); ?>
                            </span>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars($row['designation']); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-primary badge-sm"><?php echo $row['total_tickets_assigned']; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-success badge-sm"><?php echo $row['tickets_closed']; ?></span>
                        </td>
                        <td>
                            <?php if ($row['total_tickets_assigned'] > 0): ?>
                            <div class="progress" style="height: 20px;" title="<?php echo $row['closure_rate']; ?>%">
                                <div class="progress-bar <?php echo $row['closure_rate'] >= 80 ? 'bg-success' : ($row['closure_rate'] >= 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($row['closure_rate'], 100); ?>%;" 
                                     aria-valuenow="<?php echo $row['closure_rate']; ?>" 
                                     aria-valuemin="0" aria-valuemax="100">
                                    <?php echo number_format($row['closure_rate'], 1); ?>%
                                </div>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['avg_resolution_hours'] > 0): ?>
                                <?php if ($row['avg_resolution_hours'] < 8): ?>
                                    <span class="badge bg-success"><?php echo $row['avg_resolution_hours']; ?>h</span>
                                <?php elseif ($row['avg_resolution_hours'] < 24): ?>
                                    <span class="badge bg-warning"><?php echo $row['avg_resolution_hours']; ?>h</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><?php echo $row['avg_resolution_hours']; ?>h</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['avg_client_rating'] > 0): ?>
                                <?php if ($row['avg_client_rating'] >= 4.0): ?>
                                    <span class="badge bg-success"><?php echo $row['avg_client_rating']; ?>/5</span>
                                <?php elseif ($row['avg_client_rating'] >= 3.0): ?>
                                    <span class="badge bg-warning"><?php echo $row['avg_client_rating']; ?>/5</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><?php echo $row['avg_client_rating']; ?>/5</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No ratings</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['sla_compliance_rate'] > 0): ?>
                                <span class="badge bg-info"><?php echo $row['sla_compliance_rate']; ?>%</span>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['total_hours_logged'] > 0): ?>
                                <span class="badge bg-primary"><?php echo $row['total_hours_logged']; ?>h</span>
                            <?php else: ?>
                                <span class="text-muted">0h</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="performance-badge 
                                <?php echo strtolower(str_replace([' ', '/'], ['-', '-'], $row['performance_tier'])); ?>">
                                <?php echo $row['overall_performance_score']; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge 
                                <?php echo match($row['performance_tier']) {
                                    'Top Performer' => 'bg-success',
                                    'High Performer' => 'bg-info',
                                    'Solid Performer' => 'bg-warning',
                                    'Developing' => 'bg-secondary',
                                    default => 'bg-light text-dark'
                                }; ?>">
                                <?php echo $row['performance_tier']; ?>
                            </span>
                        </td>
                        <td>
                            <small><?php echo $row['last_update']; ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#performanceTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']], // Sort by rank
                dom: '<"top"lf>rt<"bottom"ip>',
                language: {
                    search: "Search staff:"
                }
            });
        });
    </script>
</body>
</html>
<?php
}
?>