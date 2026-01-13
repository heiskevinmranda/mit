<?php
// performance_ranking_report.php
require_once __DIR__ . '/db_config.php';

// Get parameters
$period = $_GET['period'] ?? 'monthly'; // daily, weekly, monthly, quarterly, yearly, custom
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$department = $_GET['department'] ?? null;
$min_tickets = $_GET['min_tickets'] ?? 5; // Minimum tickets to be included in ranking
$ranking_by = $_GET['ranking_by'] ?? 'overall'; // overall, productivity, efficiency, quality, activity

$pdo = getDBConnection();

// Calculate date range based on period
switch ($period) {
    case 'daily':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'weekly':
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');
        break;
    case 'monthly':
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = date('Y-m-d');
        break;
    case 'quarterly':
        $start_date = date('Y-m-d', strtotime('-90 days'));
        $end_date = date('Y-m-d');
        break;
    case 'yearly':
        $start_date = date('Y-m-d', strtotime('-365 days'));
        $end_date = date('Y-m-d');
        break;
    case 'custom':
        // Use provided dates
        break;
}

// Get departments for dropdown
$deptQuery = "SELECT DISTINCT COALESCE(department, 'Not Assigned') as department 
              FROM staff_profiles 
              WHERE employment_status = 'Active' 
              ORDER BY department";
$departments = $pdo->query($deptQuery)->fetchAll();

// Main ranking query - CORRECTED VERSION
$sql = "
WITH performance_data AS (
    SELECT 
        sp.id as staff_id,
        sp.staff_id as employee_id,
        sp.full_name,
        COALESCE(sp.department, 'Not Assigned') as department,
        COALESCE(sp.designation, 'Not Specified') as designation,
        COALESCE(sp.role_category, 'Not Categorized') as role_category,
        sp.experience_years,
        
        -- TICKET METRICS
        COUNT(DISTINCT t.id) as total_tickets,
        COUNT(DISTINCT CASE WHEN t.status = 'Closed' THEN t.id END) as tickets_closed,
        COUNT(DISTINCT CASE WHEN t.status IN ('Open', 'In Progress') THEN t.id END) as tickets_open,
        COUNT(DISTINCT CASE WHEN t.status = 'Pending' THEN t.id END) as tickets_pending,
        
        -- RESOLUTION METRICS
        COUNT(DISTINCT CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL 
            AND EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 <= 24 
            THEN t.id 
        END) as tickets_closed_under_24h,
        
        COUNT(DISTINCT CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL 
            AND EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 > 24 
            THEN t.id 
        END) as tickets_closed_over_24h,
        
        AVG(CASE 
            WHEN t.status = 'Closed' AND t.closed_at IS NOT NULL
            THEN EXTRACT(EPOCH FROM (t.closed_at - t.created_at)) / 3600 
        END) as avg_resolution_hours,
        
        -- CLIENT SATISFACTION
        AVG(t.rating) as avg_client_rating,
        COUNT(DISTINCT CASE WHEN t.rating >= 4 THEN t.id END) as high_rating_tickets,
        COUNT(DISTINCT CASE WHEN t.rating <= 2 AND t.rating IS NOT NULL THEN t.id END) as low_rating_tickets,
        COUNT(DISTINCT CASE WHEN t.client_feedback IS NOT NULL THEN t.id END) as tickets_with_feedback,
        
        -- WORK LOG METRICS
        COALESCE(SUM(wl.total_hours), 0) as total_hours_logged,
        COUNT(DISTINCT wl.id) as work_log_entries,
        AVG(wl.total_hours) as avg_hours_per_log,
        
        -- SITE VISITS
        COUNT(DISTINCT sv.id) as site_visits_completed,
        SUM(CASE 
            WHEN sv.check_in_time IS NOT NULL AND sv.check_out_time IS NOT NULL
            THEN EXTRACT(EPOCH FROM (sv.check_out_time - sv.check_in_time)) / 3600
            ELSE 0 
        END) as total_site_hours,
        
        -- PRIORITY DISTRIBUTION
        COUNT(DISTINCT CASE WHEN t.priority = 'Critical' THEN t.id END) as critical_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'High' THEN t.id END) as high_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Medium' THEN t.id END) as medium_priority_tickets,
        COUNT(DISTINCT CASE WHEN t.priority = 'Low' THEN t.id END) as low_priority_tickets,
        
        -- SLA COMPLIANCE
        COUNT(DISTINCT CASE 
            WHEN t.actual_resolution_time <= sc.resolution_time 
            AND t.status = 'Closed' 
            THEN t.id 
        END) as sla_compliant_tickets,
        
        -- RECENCY METRICS
        MAX(t.created_at) as last_ticket_created,
        MAX(t.updated_at) as last_ticket_updated,
        MAX(wl.work_date) as last_work_log_date
        
    FROM staff_profiles sp
    LEFT JOIN tickets t ON (t.assigned_to = sp.id OR t.primary_assignee = sp.id)
        AND t.created_at::date BETWEEN :start_date AND :end_date
    LEFT JOIN work_logs wl ON wl.staff_id = sp.id 
        AND wl.ticket_id = t.id
        AND wl.work_date::date BETWEEN :start_date AND :end_date
    LEFT JOIN site_visits sv ON sv.engineer_id = sp.id
        AND sv.created_at::date BETWEEN :start_date AND :end_date
    LEFT JOIN sla_configurations sc ON sc.contract_id IN (
        SELECT c.id FROM contracts c WHERE c.client_id = t.client_id
    ) AND sc.priority = t.priority
    WHERE sp.employment_status = 'Active'
    " . ($department && $department !== 'All' && $department !== 'Not Assigned' ? " AND sp.department = :department" : "") . "
    GROUP BY sp.id, sp.staff_id, sp.full_name, sp.department, sp.designation, 
             sp.role_category, sp.experience_years
),
performance_scoring AS (
    SELECT *,
        -- PRODUCTIVITY SCORE (40%): Tickets closed and closure rate
        CASE 
            WHEN total_tickets = 0 THEN 0
            ELSE LEAST(100, (tickets_closed::DECIMAL / GREATEST(total_tickets, 1) * 100) * 1.5)
        END as productivity_raw_score,
        
        -- EFFICIENCY SCORE (30%): Resolution time and SLA compliance
        CASE 
            WHEN tickets_closed = 0 THEN 0
            ELSE LEAST(100, 
                (COALESCE(tickets_closed_under_24h, 0)::DECIMAL / GREATEST(tickets_closed, 1) * 50) +
                (COALESCE(sla_compliant_tickets, 0)::DECIMAL / GREATEST(total_tickets, 1) * 50)
            )
        END as efficiency_raw_score,
        
        -- QUALITY SCORE (20%): Client ratings and feedback
        CASE 
            WHEN total_tickets = 0 THEN 0
            ELSE LEAST(100,
                (COALESCE(avg_client_rating, 0) / 5 * 50) +
                (COALESCE(tickets_with_feedback, 0)::DECIMAL / GREATEST(total_tickets, 1) * 25) +
                (COALESCE(high_rating_tickets, 0)::DECIMAL / GREATEST(total_tickets, 1) * 25)
            )
        END as quality_raw_score,
        
        -- ACTIVITY SCORE (10%): Work logs and site visits
        LEAST(100,
            (LEAST(work_log_entries, 20) * 2.5) +  -- Max 50 points for work logs
            (LEAST(site_visits_completed, 10) * 5) -- Max 50 points for site visits
        ) as activity_raw_score
        
    FROM performance_data
    WHERE total_tickets >= :min_tickets
),
weighted_scoring AS (
    SELECT *,
        -- Weighted scores (normalized to 100)
        (productivity_raw_score * 0.40) as productivity_score_weighted,
        (efficiency_raw_score * 0.30) as efficiency_score_weighted,
        (quality_raw_score * 0.20) as quality_score_weighted,
        (activity_raw_score * 0.10) as activity_score_weighted,
        
        -- Overall score
        (productivity_raw_score * 0.40) + 
        (efficiency_raw_score * 0.30) + 
        (quality_raw_score * 0.20) + 
        (activity_raw_score * 0.10) as overall_score_raw
        
    FROM performance_scoring
),
final_ranking AS (
    SELECT *,
        ROW_NUMBER() OVER (ORDER BY overall_score_raw DESC) as overall_rank,
        ROW_NUMBER() OVER (ORDER BY productivity_raw_score DESC) as productivity_rank,
        ROW_NUMBER() OVER (ORDER BY efficiency_raw_score DESC) as efficiency_rank,
        ROW_NUMBER() OVER (ORDER BY quality_raw_score DESC) as quality_rank,
        ROW_NUMBER() OVER (ORDER BY activity_raw_score DESC) as activity_rank,
        
        ROUND(overall_score_raw, 2) as overall_score,
        ROUND(productivity_raw_score, 2) as productivity_score,
        ROUND(efficiency_raw_score, 2) as efficiency_score,
        ROUND(quality_raw_score, 2) as quality_score,
        ROUND(activity_raw_score, 2) as activity_score,
        
        -- Performance tier
        CASE 
            WHEN overall_score_raw >= 90 THEN 'Top Performer'
            WHEN overall_score_raw >= 80 THEN 'Strong Performer'
            WHEN overall_score_raw >= 70 THEN 'Satisfactory'
            WHEN overall_score_raw >= 60 THEN 'Needs Improvement'
            ELSE 'Below Expectations'
        END as performance_tier,
        
        -- Performance badge color
        CASE 
            WHEN overall_score_raw >= 90 THEN 'danger'  -- Red for top
            WHEN overall_score_raw >= 80 THEN 'warning' -- Yellow for strong
            WHEN overall_score_raw >= 70 THEN 'success' -- Green for satisfactory
            WHEN overall_score_raw >= 60 THEN 'info'    -- Blue for needs improvement
            ELSE 'secondary'                            -- Gray for below expectations
        END as tier_color,
        
        -- Trend indicator (simplified - you could add actual trend calculation)
        CASE 
            WHEN overall_score_raw >= 90 THEN 'trending-up'
            WHEN overall_score_raw >= 80 THEN 'trending-up'
            WHEN overall_score_raw >= 70 THEN 'minus'
            WHEN overall_score_raw >= 60 THEN 'trending-down'
            ELSE 'trending-down'
        END as trend_indicator,
        
        -- Recommendations based on scores
        CASE 
            WHEN overall_score_raw >= 90 
                THEN 'Excellent performance. Consider for promotion or additional responsibilities.'
            WHEN overall_score_raw >= 80 
                THEN 'Strong performer. Suitable for leadership roles or complex projects.'
            WHEN overall_score_raw >= 70 
                THEN 'Meets expectations. Continue current development path.'
            WHEN overall_score_raw >= 60 
                THEN 'Needs improvement. Consider additional training or mentoring.'
            ELSE 'Below expectations. Requires performance review and improvement plan.'
        END as recommendation
        
    FROM weighted_scoring
)
SELECT 
    staff_id,
    employee_id,
    full_name,
    department,
    designation,
    role_category,
    experience_years,
    
    -- Ranking
    overall_rank,
    productivity_rank,
    efficiency_rank,
    quality_rank,
    activity_rank,
    
    -- Scores
    overall_score,
    productivity_score,
    efficiency_score,
    quality_score,
    activity_score,
    productivity_score_weighted,
    efficiency_score_weighted,
    quality_score_weighted,
    activity_score_weighted,
    
    -- Raw metrics for display
    total_tickets,
    tickets_closed,
    tickets_open,
    tickets_pending,
    avg_client_rating,
    high_rating_tickets,
    low_rating_tickets,
    total_hours_logged,
    work_log_entries,
    site_visits_completed,
    avg_resolution_hours,
    tickets_closed_under_24h,
    sla_compliant_tickets,
    
    -- Performance metadata
    performance_tier,
    tier_color,
    trend_indicator,
    recommendation,
    
    -- Additional metrics for badges
    critical_tickets,
    high_priority_tickets,
    medium_priority_tickets,
    low_priority_tickets
    
FROM final_ranking
ORDER BY 
    CASE :ranking_by_param 
        WHEN 'productivity' THEN productivity_rank
        WHEN 'efficiency' THEN efficiency_rank
        WHEN 'quality' THEN quality_rank
        WHEN 'activity' THEN activity_rank
        ELSE overall_rank
    END ASC
";

// Prepare parameters
$params = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'min_tickets' => $min_tickets,
    'ranking_by_param' => $ranking_by
];

if ($department && $department !== 'All' && $department !== 'Not Assigned') {
    $params['department'] = $department;
}

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$results = $stmt->fetchAll();

// Calculate summary statistics
$summary = [
    'total_ranked' => count($results),
    'avg_score' => count($results) > 0 ? round(array_sum(array_column($results, 'overall_score')) / count($results), 1) : 0,
    'top_score' => count($results) > 0 ? max(array_column($results, 'overall_score')) : 0,
    'bottom_score' => count($results) > 0 ? min(array_column($results, 'overall_score')) : 0,
    
    // Count by tier
    'top_performers' => count(array_filter($results, function($r) { return $r['performance_tier'] === 'Top Performer'; })),
    'strong_performers' => count(array_filter($results, function($r) { return $r['performance_tier'] === 'Strong Performer'; })),
    'satisfactory' => count(array_filter($results, function($r) { return $r['performance_tier'] === 'Satisfactory'; })),
    'needs_improvement' => count(array_filter($results, function($r) { return $r['performance_tier'] === 'Needs Improvement'; })),
    'below_expectations' => count(array_filter($results, function($r) { return $r['performance_tier'] === 'Below Expectations'; })),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Performance Ranking Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --top-performer: #dc3545;
            --strong-performer: #ffc107;
            --satisfactory: #28a745;
            --needs-improvement: #17a2b8;
            --below-expectations: #6c757d;
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        .rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .rank-2 { background: linear-gradient(135deg, #C0C0C0, #A9A9A9); }
        .rank-3 { background: linear-gradient(135deg, #CD7F32, #8B4513); }
        .rank-4-10 { background: linear-gradient(135deg, #6f42c1, #4a1e8a); }
        .rank-other { background: linear-gradient(135deg, #17a2b8, #0f6674); }
        
        .tier-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.8em;
        }
        .tier-top { background-color: var(--top-performer); color: white; }
        .tier-strong { background-color: var(--strong-performer); color: #000; }
        .tier-satisfactory { background-color: var(--satisfactory); color: white; }
        .tier-needs { background-color: var(--needs-improvement); color: white; }
        .tier-below { background-color: var(--below-expectations); color: white; }
        
        .score-card {
            border-radius: 10px;
            transition: transform 0.3s ease;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .score-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .score-progress {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            overflow: hidden;
        }
        .score-progress-bar {
            height: 100%;
            border-radius: 4px;
        }
        
        .metric-badge {
            font-size: 0.75em;
            padding: 2px 8px;
            border-radius: 10px;
            margin-right: 3px;
        }
        
        .table th {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
        }
        
        .staff-card {
            border-left: 5px solid transparent;
            transition: all 0.3s ease;
        }
        .staff-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .staff-card.top-performer { border-left-color: var(--top-performer); }
        .staff-card.strong-performer { border-left-color: var(--strong-performer); }
        .staff-card.satisfactory { border-left-color: var(--satisfactory); }
        .staff-card.needs-improvement { border-left-color: var(--needs-improvement); }
        .staff-card.below-expectations { border-left-color: var(--below-expectations); }
        
        .rating-stars {
            color: #ffc107;
            font-size: 0.9em;
        }
        
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .trend-neutral { color: #6c757d; }
        
        .summary-card {
            border-radius: 10px;
            border: none;
            color: white;
        }
        .summary-card-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .summary-card-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .summary-card-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .summary-card-4 { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        .tier-distribution .tier-bar {
            height: 30px;
            margin-bottom: 5px;
            border-radius: 4px;
            overflow: hidden;
        }
        .tier-bar-fill {
            height: 100%;
            display: flex;
            align-items: center;
            padding-left: 10px;
            color: white;
            font-weight: bold;
            font-size: 0.9em;
        }
        
        .weight-indicator {
            width: 60px;
            height: 20px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7em;
            font-weight: bold;
        }
        .weight-40 { background-color: #007bff; color: white; }
        .weight-30 { background-color: #28a745; color: white; }
        .weight-20 { background-color: #ffc107; color: #000; }
        .weight-10 { background-color: #17a2b8; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="fas fa-trophy text-warning"></i> Staff Performance Ranking Report</h1>
                <p class="lead">
                    <i class="fas fa-calendar-alt"></i> 
                    <?php echo date('F j, Y', strtotime($start_date)); ?> - <?php echo date('F j, Y', strtotime($end_date)); ?>
                    <span class="badge bg-primary ms-2"><?php echo ucfirst($period); ?> Report</span>
                    <?php if ($department && $department !== 'All'): ?>
                    <span class="badge bg-info ms-1">Department: <?php echo htmlspecialchars($department); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <div class="btn-group">
                    <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> CSV</a></li>
                        <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print"></i> Print</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Report Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Period</label>
                        <select name="period" class="form-select" onchange="this.form.submit()">
                            <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="quarterly" <?php echo $period === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="yearly" <?php echo $period === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    
                    <?php if ($period === 'custom'): ?>
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-select">
                            <option value="All">All Departments</option>
                            <option value="Not Assigned" <?php echo $department === 'Not Assigned' ? 'selected' : ''; ?>>Not Assigned</option>
                            <?php foreach ($departments as $dept): ?>
                                <?php if ($dept['department'] !== 'Not Assigned'): ?>
                                <option value="<?php echo htmlspecialchars($dept['department']); ?>"
                                    <?php echo $department === $dept['department'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department']); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Min Tickets</label>
                        <select name="min_tickets" class="form-select">
                            <option value="0" <?php echo $min_tickets == 0 ? 'selected' : ''; ?>>No Minimum</option>
                            <option value="5" <?php echo $min_tickets == 5 ? 'selected' : ''; ?>>5+ Tickets</option>
                            <option value="10" <?php echo $min_tickets == 10 ? 'selected' : ''; ?>>10+ Tickets</option>
                            <option value="20" <?php echo $min_tickets == 20 ? 'selected' : ''; ?>>20+ Tickets</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Rank By</label>
                        <select name="ranking_by" class="form-select">
                            <option value="overall" <?php echo $ranking_by === 'overall' ? 'selected' : ''; ?>>Overall Score</option>
                            <option value="productivity" <?php echo $ranking_by === 'productivity' ? 'selected' : ''; ?>>Productivity</option>
                            <option value="efficiency" <?php echo $ranking_by === 'efficiency' ? 'selected' : ''; ?>>Efficiency</option>
                            <option value="quality" <?php echo $ranking_by === 'quality' ? 'selected' : ''; ?>>Quality</option>
                            <option value="activity" <?php echo $ranking_by === 'activity' ? 'selected' : ''; ?>>Activity</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-sync-alt"></i> Update Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card summary-card summary-card-1">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-uppercase mb-1">Staff Ranked</div>
                                <div class="h2 mb-0"><?php echo $summary['total_ranked']; ?></div>
                                <div class="mt-2 text-white-50 small">
                                    <i class="fas fa-users"></i> Active staff with <?php echo $min_tickets; ?>+ tickets
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-trophy fa-3x" style="opacity: 0.3;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card summary-card summary-card-2">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-uppercase mb-1">Average Score</div>
                                <div class="h2 mb-0"><?php echo $summary['avg_score']; ?>/100</div>
                                <div class="mt-2 text-white-50 small">
                                    <i class="fas fa-chart-line"></i> 
                                    Top: <?php echo $summary['top_score']; ?> | Low: <?php echo $summary['bottom_score']; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chart-bar fa-3x" style="opacity: 0.3;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card summary-card summary-card-3">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-uppercase mb-1">Top Performers</div>
                                <div class="h2 mb-0"><?php echo $summary['top_performers']; ?></div>
                                <div class="mt-2 text-white-50 small">
                                    <i class="fas fa-crown"></i> Score ≥ 90/100
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-star fa-3x" style="opacity: 0.3;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card summary-card summary-card-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-uppercase mb-1">Needs Attention</div>
                                <div class="h2 mb-0"><?php echo $summary['needs_improvement'] + $summary['below_expectations']; ?></div>
                                <div class="mt-2 text-white-50 small">
                                    <i class="fas fa-exclamation-triangle"></i> Score < 70/100
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-bullhorn fa-3x" style="opacity: 0.3;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Tier Distribution -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Performance Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="tier-distribution">
                            <!-- Top Performers -->
                            <?php if ($summary['top_performers'] > 0): ?>
                            <div class="tier-bar">
                                <div class="tier-bar-fill" style="width: <?php echo ($summary['top_performers'] / max($summary['total_ranked'], 1)) * 100; ?>%; background-color: var(--top-performer);">
                                    Top Performers: <?php echo $summary['top_performers']; ?> staff (<?php echo round(($summary['top_performers'] / max($summary['total_ranked'], 1)) * 100, 1); ?>%)
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Strong Performers -->
                            <?php if ($summary['strong_performers'] > 0): ?>
                            <div class="tier-bar">
                                <div class="tier-bar-fill" style="width: <?php echo ($summary['strong_performers'] / max($summary['total_ranked'], 1)) * 100; ?>%; background-color: var(--strong-performer); color: #000;">
                                    Strong Performers: <?php echo $summary['strong_performers']; ?> staff (<?php echo round(($summary['strong_performers'] / max($summary['total_ranked'], 1)) * 100, 1); ?>%)
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Satisfactory -->
                            <?php if ($summary['satisfactory'] > 0): ?>
                            <div class="tier-bar">
                                <div class="tier-bar-fill" style="width: <?php echo ($summary['satisfactory'] / max($summary['total_ranked'], 1)) * 100; ?>%; background-color: var(--satisfactory);">
                                    Satisfactory: <?php echo $summary['satisfactory']; ?> staff (<?php echo round(($summary['satisfactory'] / max($summary['total_ranked'], 1)) * 100, 1); ?>%)
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Needs Improvement -->
                            <?php if ($summary['needs_improvement'] > 0): ?>
                            <div class="tier-bar">
                                <div class="tier-bar-fill" style="width: <?php echo ($summary['needs_improvement'] / max($summary['total_ranked'], 1)) * 100; ?>%; background-color: var(--needs-improvement);">
                                    Needs Improvement: <?php echo $summary['needs_improvement']; ?> staff (<?php echo round(($summary['needs_improvement'] / max($summary['total_ranked'], 1)) * 100, 1); ?>%)
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Below Expectations -->
                            <?php if ($summary['below_expectations'] > 0): ?>
                            <div class="tier-bar">
                                <div class="tier-bar-fill" style="width: <?php echo ($summary['below_expectations'] / max($summary['total_ranked'], 1)) * 100; ?>%; background-color: var(--below-expectations);">
                                    Below Expectations: <?php echo $summary['below_expectations']; ?> staff (<?php echo round(($summary['below_expectations'] / max($summary['total_ranked'], 1)) * 100, 1); ?>%)
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Scoring Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <small class="text-muted">Scoring Weights:</small>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span>Productivity <span class="weight-indicator weight-40">40%</span></span>
                                <strong>40%</strong>
                            </div>
                            <div class="score-progress">
                                <div class="score-progress-bar bg-primary" style="width: 40%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span>Efficiency <span class="weight-indicator weight-30">30%</span></span>
                                <strong>30%</strong>
                            </div>
                            <div class="score-progress">
                                <div class="score-progress-bar bg-success" style="width: 30%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span>Quality <span class="weight-indicator weight-20">20%</span></span>
                                <strong>20%</strong>
                            </div>
                            <div class="score-progress">
                                <div class="score-progress-bar bg-warning" style="width: 20%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span>Activity <span class="weight-indicator weight-10">10%</span></span>
                                <strong>10%</strong>
                            </div>
                            <div class="score-progress">
                                <div class="score-progress-bar bg-info" style="width: 10%"></div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="small text-muted">
                            <i class="fas fa-lightbulb"></i> 
                            <strong>Tip:</strong> Click on any staff member to see detailed performance breakdown
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top 3 Performers -->
        <?php if (count($results) >= 3): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-crown"></i> Top 3 Performers</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php 
                            // Get top 3 by overall score
                            $topPerformers = array_slice($results, 0, 3);
                            foreach ($topPerformers as $index => $topPerformer): 
                            ?>
                            <div class="col-md-4 mb-3">
                                <div class="card h-100 staff-card top-performer">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="rank-badge rank-<?php echo $index + 1; ?> me-3">
                                                #<?php echo $index + 1; ?>
                                            </div>
                                            <div>
                                                <h5 class="mb-0"><?php echo htmlspecialchars($topPerformer['full_name']); ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($topPerformer['employee_id']); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($topPerformer['department']); ?></span>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($topPerformer['designation']); ?></span>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between">
                                                <strong>Overall Score:</strong>
                                                <span class="h5 mb-0"><?php echo $topPerformer['overall_score']; ?>/100</span>
                                            </div>
                                            <div class="score-progress mt-1">
                                                <div class="score-progress-bar bg-success" 
                                                     style="width: <?php echo $topPerformer['overall_score']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-6">
                                                <div class="text-center">
                                                    <small class="text-muted d-block">Tickets Closed</small>
                                                    <h4 class="text-success"><?php echo $topPerformer['tickets_closed']; ?></h4>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="text-center">
                                                    <small class="text-muted d-block">Avg Rating</small>
                                                    <h4>
                                                        <?php if ($topPerformer['avg_client_rating'] > 0): ?>
                                                        <span class="text-warning"><?php echo round($topPerformer['avg_client_rating'], 1); ?>/5</span>
                                                        <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </h4>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <small class="text-muted">
                                                <i class="fas fa-chart-line"></i> 
                                                <?php echo $topPerformer['recommendation']; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Ranking Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list-ol"></i> Performance Ranking
                    <span class="badge bg-primary float-end">
                        <?php echo count($results); ?> Staff Ranked
                        <?php if ($ranking_by !== 'overall'): ?>
                        (Sorted by <?php echo ucfirst($ranking_by); ?>)
                        <?php endif; ?>
                    </span>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="rankingTable">
                        <thead>
                            <tr>
                                <th width="70">Rank</th>
                                <th>Staff Details</th>
                                <th>Performance Score</th>
                                <th>Ticket Metrics</th>
                                <th>Efficiency & Quality</th>
                                <th>Activity</th>
                                <th>Recommendation</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $index => $row): 
                                $tierClass = strtolower(str_replace(' ', '-', $row['performance_tier']));
                            ?>
                            <tr class="staff-card <?php echo $tierClass; ?>">
                                <!-- Rank Column -->
                                <td>
                                    <?php 
                                    $rank = $row['overall_rank'];
                                    $rankClass = 'rank-other';
                                    if ($rank == 1) $rankClass = 'rank-1';
                                    elseif ($rank == 2) $rankClass = 'rank-2';
                                    elseif ($rank == 3) $rankClass = 'rank-3';
                                    elseif ($rank <= 10) $rankClass = 'rank-4-10';
                                    ?>
                                    <div class="rank-badge <?php echo $rankClass; ?>">
                                        #<?php echo $rank; ?>
                                    </div>
                                    <div class="text-center mt-1">
                                        <small class="text-muted">
                                            <?php if ($ranking_by !== 'overall'): ?>
                                            #<?php echo $row[$ranking_by . '_rank']; ?> <?php echo ucfirst($ranking_by); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </td>
                                
                                <!-- Staff Details -->
                                <td>
                                    <div class="d-flex align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($row['full_name']); ?></h6>
                                            <small class="text-muted">ID: <?php echo htmlspecialchars($row['employee_id']); ?></small>
                                            
                                            <div class="mt-2">
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['department']); ?></span>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($row['designation']); ?></span>
                                                <span class="badge bg-info"><?php echo $row['experience_years']; ?> yrs exp</span>
                                            </div>
                                            
                                            <div class="mt-2">
                                                <?php 
                                                $tierBadgeClass = 'tier-' . strtolower(explode(' ', $row['performance_tier'])[0]);
                                                ?>
                                                <span class="tier-badge <?php echo $tierBadgeClass; ?>">
                                                    <?php echo $row['performance_tier']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Performance Score -->
                                <td>
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="flex-grow-1 me-2">
                                            <div class="d-flex justify-content-between">
                                                <strong>Overall:</strong>
                                                <span class="h6 mb-0"><?php echo $row['overall_score']; ?>/100</span>
                                            </div>
                                            <div class="score-progress">
                                                <div class="score-progress-bar bg-success" 
                                                     style="width: <?php echo $row['overall_score']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($row['trend_indicator'] === 'trending-up'): ?>
                                        <i class="fas fa-arrow-up trend-up" title="Improving"></i>
                                        <?php elseif ($row['trend_indicator'] === 'trending-down'): ?>
                                        <i class="fas fa-arrow-down trend-down" title="Declining"></i>
                                        <?php else: ?>
                                        <i class="fas fa-minus trend-neutral" title="Stable"></i>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="row small">
                                        <div class="col-6">
                                            <div class="d-flex justify-content-between">
                                                <span>Productivity:</span>
                                                <strong><?php echo $row['productivity_score']; ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="d-flex justify-content-between">
                                                <span>Efficiency:</span>
                                                <strong><?php echo $row['efficiency_score']; ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="d-flex justify-content-between">
                                                <span>Quality:</span>
                                                <strong><?php echo $row['quality_score']; ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="d-flex justify-content-between">
                                                <span>Activity:</span>
                                                <strong><?php echo $row['activity_score']; ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Ticket Metrics -->
                                <td>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Total:</span>
                                            <strong><?php echo $row['total_tickets']; ?></strong>
                                        </div>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <span class="metric-badge bg-success" title="Closed Tickets">✓ <?php echo $row['tickets_closed']; ?></span>
                                            <span class="metric-badge bg-warning text-dark" title="Open Tickets">⏱ <?php echo $row['tickets_open']; ?></span>
                                            <span class="metric-badge bg-info" title="Pending Tickets">⏳ <?php echo $row['tickets_pending']; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="small">
                                        <div class="d-flex justify-content-between">
                                            <span>Priority:</span>
                                            <span>
                                                <span class="text-danger" title="Critical">C:<?php echo $row['critical_tickets']; ?></span>
                                                <span class="text-warning" title="High">H:<?php echo $row['high_priority_tickets']; ?></span>
                                                <span class="text-primary" title="Medium">M:<?php echo $row['medium_priority_tickets']; ?></span>
                                                <span class="text-success" title="Low">L:<?php echo $row['low_priority_tickets']; ?></span>
                                            </span>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between mt-1">
                                            <span>24h Closure:</span>
                                            <strong><?php echo $row['tickets_closed_under_24h']; ?>/<?php echo $row['tickets_closed']; ?></strong>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between">
                                            <span>SLA Compliant:</span>
                                            <strong><?php echo $row['sla_compliant_tickets']; ?>/<?php echo $row['total_tickets']; ?></strong>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Efficiency & Quality -->
                                <td>
                                    <?php if ($row['avg_resolution_hours']): ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Avg Resolution:</span>
                                            <strong><?php echo round($row['avg_resolution_hours'], 1); ?>h</strong>
                                        </div>
                                        <div class="score-progress">
                                            <div class="score-progress-bar <?php 
                                                echo $row['avg_resolution_hours'] < 8 ? 'bg-success' : 
                                                     ($row['avg_resolution_hours'] < 24 ? 'bg-warning' : 'bg-danger'); 
                                            ?>" style="width: <?php echo min(($row['avg_resolution_hours'] / 48) * 100, 100); ?>%"></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Client Rating:</span>
                                            <strong>
                                                <?php if ($row['avg_client_rating']): ?>
                                                <?php echo round($row['avg_client_rating'], 1); ?>/5
                                                <?php else: ?>
                                                N/A
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                        <?php if ($row['avg_client_rating']): ?>
                                        <div class="rating-stars">
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
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="small">
                                        <div class="d-flex justify-content-between">
                                            <span>High Ratings:</span>
                                            <span class="text-success"><?php echo $row['high_rating_tickets']; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Low Ratings:</span>
                                            <span class="text-danger"><?php echo $row['low_rating_tickets']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Activity -->
                                <td>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Work Hours:</span>
                                            <strong><?php echo round($row['total_hours_logged'], 1); ?>h</strong>
                                        </div>
                                        <div class="small text-muted">
                                            <?php echo $row['work_log_entries']; ?> log entries
                                        </div>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between">
                                            <span>Site Visits:</span>
                                            <strong><?php echo $row['site_visits_completed']; ?></strong>
                                        </div>
                                        <div class="small text-muted">
                                            <?php echo round($row['total_site_hours'] ?? 0, 1); ?>h total
                                        </div>
                                    </div>
                                    
                                    <div class="small text-muted">
                                        <i class="fas fa-briefcase"></i> 
                                        <span title="Activity Level">
                                            <?php
                                            $activityLevel = $row['work_log_entries'] + $row['site_visits_completed'];
                                            if ($activityLevel >= 20) echo 'Very Active';
                                            elseif ($activityLevel >= 10) echo 'Active';
                                            elseif ($activityLevel >= 5) echo 'Moderate';
                                            else echo 'Low Activity';
                                            ?>
                                        </span>
                                    </div>
                                </td>
                                
                                <!-- Recommendation -->
                                <td>
                                    <div class="small">
                                        <p><?php echo $row['recommendation']; ?></p>
                                        <div class="text-muted">
                                            <i class="fas fa-lightbulb"></i>
                                            <small>Performance Tier: <?php echo $row['performance_tier']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                
                                <!-- Actions -->
                                <td>
                                    <div class="btn-group-vertical">
                                        <button class="btn btn-sm btn-outline-primary mb-1" 
                                                onclick="viewStaffDetails('<?php echo $row['employee_id']; ?>')"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success mb-1"
                                                onclick="generateReport('<?php echo $row['employee_id']; ?>')"
                                                title="Generate Report">
                                            <i class="fas fa-file-alt"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-info"
                                                onclick="sendFeedback('<?php echo $row['employee_id']; ?>')"
                                                title="Send Feedback">
                                            <i class="fas fa-comment"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Export and Summary -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Performance Insights</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Highest Overall Score</span>
                                <span class="badge bg-success"><?php echo $summary['top_score']; ?>/100</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Lowest Overall Score</span>
                                <span class="badge bg-danger"><?php echo $summary['bottom_score']; ?>/100</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Department Average</span>
                                <span class="badge bg-primary"><?php echo $summary['avg_score']; ?>/100</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Top Performers (≥90)</span>
                                <span class="badge bg-warning text-dark"><?php echo $summary['top_performers']; ?> staff</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Needs Attention (<70)</span>
                                <span class="badge bg-danger"><?php echo $summary['needs_improvement'] + $summary['below_expectations']; ?> staff</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-download"></i> Export Options</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <button class="btn btn-outline-primary w-100" onclick="exportToCSV()">
                                    <i class="fas fa-file-csv"></i> CSV Export
                                </button>
                            </div>
                            <div class="col-md-6 mb-3">
                                <button class="btn btn-outline-secondary w-100" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print Report
                                </button>
                            </div>
                            <div class="col-md-6 mb-3">
                                <button class="btn btn-outline-success w-100" onclick="exportTopPerformers()">
                                    <i class="fas fa-trophy"></i> Top 10 Report
                                </button>
                            </div>
                            <div class="col-md-6 mb-3">
                                <button class="btn btn-outline-danger w-100" onclick="exportAttentionList()">
                                    <i class="fas fa-exclamation-circle"></i> Attention List
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> CSV export includes all performance metrics and rankings.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#rankingTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: {
                    search: 'Search staff:',
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
            
            // Add tooltips
            $('[title]').tooltip();
        });
        
        function exportToCSV() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = '?' + params.toString();
        }
        
        function viewStaffDetails(staffId) {
            window.open('staff_performance_detail.php?staff_id=' + staffId + '&' + new URLSearchParams({
                start_date: '<?php echo $start_date; ?>',
                end_date: '<?php echo $end_date; ?>'
            }).toString(), '_blank');
        }
        
        function generateReport(staffId) {
            alert('Generating detailed report for staff: ' + staffId);
            // window.open('generate_staff_report.php?staff_id=' + staffId, '_blank');
        }
        
        function sendFeedback(staffId) {
            const feedback = prompt('Enter feedback for this staff member:');
            if (feedback) {
                alert('Feedback submitted for staff: ' + staffId);
                // Implement AJAX call to submit feedback
            }
        }
        
        function exportTopPerformers() {
            const params = new URLSearchParams(window.location.search);
            params.set('limit', 10);
            window.location.href = '?' + params.toString();
        }
        
        function exportAttentionList() {
            const params = new URLSearchParams(window.location.search);
            params.set('min_score', 0);
            params.set('max_score', 70);
            window.location.href = '?' + params.toString();
        }
    </script>
</body>
</html>