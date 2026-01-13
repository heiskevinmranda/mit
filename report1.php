<!-- Advanced Staff Performance Dashboard -->
<?php
// Fetch data from staff_performance_dashboard view
$advanced_staff_performance = [];
try {
    $query = "SELECT * FROM staff_performance_dashboard ORDER BY tickets_closed DESC, total_tickets_assigned DESC";
    $stmt = $pdo->query($query);
    $advanced_staff_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate additional metrics
    foreach ($advanced_staff_performance as &$staff) {
        // Calculate closure rate
        $total_tickets = $staff['total_tickets_assigned'] ?? 0;
        $closed_tickets = $staff['tickets_closed'] ?? 0;
        $staff['closure_rate'] = $total_tickets > 0 ? round(($closed_tickets / $total_tickets) * 100) : 0;
        
        // Calculate SLA compliance rate
        $sla_compliant = $staff['sla_compliant_tickets'] ?? 0;
        $staff['sla_compliance_rate'] = $total_tickets > 0 ? round(($sla_compliant / $total_tickets) * 100) : 0;
        
        // Calculate efficiency score (combination of metrics)
        $efficiency_score = 0;
        if ($staff['closure_rate'] > 0) $efficiency_score += $staff['closure_rate'] * 0.3;
        if ($staff['sla_compliance_rate'] > 0) $efficiency_score += $staff['sla_compliance_rate'] * 0.3;
        if ($staff['avg_client_rating'] > 0) $efficiency_score += ($staff['avg_client_rating'] * 20) * 0.2; // Convert 1-5 scale to 0-100
        if ($staff['avg_resolution_hours'] > 0) {
            // Faster resolution = higher score (inverse relationship)
            $resolution_score = max(0, 100 - ($staff['avg_resolution_hours'] * 2));
            $efficiency_score += $resolution_score * 0.2;
        }
        $staff['efficiency_score'] = min(100, round($efficiency_score));
        
        // Determine performance tier
        $efficiency = $staff['efficiency_score'];
        if ($efficiency >= 85) {
            $staff['performance_tier'] = 'Excellent';
            $staff['performance_color'] = 'success';
        } elseif ($efficiency >= 70) {
            $staff['performance_tier'] = 'Good';
            $staff['performance_color'] = 'info';
        } elseif ($efficiency >= 50) {
            $staff['performance_tier'] = 'Average';
            $staff['performance_color'] = 'warning';
        } else {
            $staff['performance_tier'] = 'Needs Improvement';
            $staff['performance_color'] = 'danger';
        }
    }
    unset($staff); // Break the reference
} catch (Exception $e) {
    error_log("Advanced staff performance error: " . $e->getMessage());
}
?>

<?php if (!empty($advanced_staff_performance)): ?>
<div class="advanced-staff-performance-dashboard mt-4">
    <div class="card">
        <div class="card-header bg-info text-white">
            <h4 class="mb-0">
                <i class="fas fa-chart-line me-2"></i>Advanced Staff Performance Dashboard
                <small class="float-end mt-1">Using VIEW: staff_performance_dashboard</small>
            </h4>
        </div>
        <div class="card-body">
            <!-- Performance Summary Stats -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-primary mb-2">
                                <i class="fas fa-ticket-alt fa-2x"></i>
                            </div>
                            <h3 class="mb-1">
                                <?php 
                                $avg_tickets = count($advanced_staff_performance) > 0 ? 
                                    round(array_sum(array_column($advanced_staff_performance, 'total_tickets_assigned')) / count($advanced_staff_performance)) : 0;
                                echo $avg_tickets;
                                ?>
                            </h3>
                            <p class="text-muted mb-0">Avg. Tickets/Staff</p>
                            <small class="text-success">
                                <i class="fas fa-arrow-up"></i> 
                                <?php 
                                $total_closed = array_sum(array_column($advanced_staff_performance, 'tickets_closed'));
                                $total_assigned = array_sum(array_column($advanced_staff_performance, 'total_tickets_assigned'));
                                $overall_closure_rate = $total_assigned > 0 ? round(($total_closed / $total_assigned) * 100) : 0;
                                echo $overall_closure_rate; ?>% closure rate
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-success mb-2">
                                <i class="fas fa-star fa-2x"></i>
                            </div>
                            <h3 class="mb-1">
                                <?php 
                                $avg_rating = count($advanced_staff_performance) > 0 ? 
                                    round(array_sum(array_column($advanced_staff_performance, 'avg_client_rating')) / count($advanced_staff_performance), 1) : 0;
                                echo $avg_rating;
                                ?>/5
                            </h3>
                            <p class="text-muted mb-0">Avg. Client Rating</p>
                            <small class="text-success">
                                <i class="fas fa-smile"></i> 
                                <?php 
                                $high_rating_count = array_sum(array_column($advanced_staff_performance, 'high_rating_tickets'));
                                echo number_format($high_rating_count); ?> high ratings
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-warning mb-2">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                            <h3 class="mb-1">
                                <?php 
                                $avg_resolution = count($advanced_staff_performance) > 0 ? 
                                    round(array_sum(array_column($advanced_staff_performance, 'avg_resolution_hours')) / count($advanced_staff_performance), 1) : 0;
                                echo $avg_resolution;
                                ?>h
                            </h3>
                            <p class="text-muted mb-0">Avg. Resolution Time</p>
                            <small class="<?php echo $avg_resolution > 24 ? 'text-danger' : 'text-success'; ?>">
                                <i class="fas fa-<?php echo $avg_resolution > 24 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                                <?php echo $avg_resolution > 24 ? 'Above target' : 'Within target'; ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="text-danger mb-2">
                                <i class="fas fa-shield-alt fa-2x"></i>
                            </div>
                            <h3 class="mb-1">
                                <?php 
                                $avg_sla = count($advanced_staff_performance) > 0 ? 
                                    round(array_sum(array_column($advanced_staff_performance, 'sla_compliance_rate')) / count($advanced_staff_performance)) : 0;
                                echo $avg_sla;
                                ?>%
                            </h3>
                            <p class="text-muted mb-0">SLA Compliance</p>
                            <small class="<?php echo $avg_sla < 90 ? 'text-danger' : 'text-success'; ?>">
                                <i class="fas fa-<?php echo $avg_sla < 90 ? 'times' : 'check'; ?>"></i>
                                <?php echo $avg_sla < 90 ? 'Below target' : 'Above target'; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Performance Table -->
            <div class="table-responsive">
                <table class="table table-hover table-striped" id="advancedPerformanceTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Staff</th>
                            <th class="text-center">Role</th>
                            <th class="text-center">Tickets</th>
                            <th class="text-center">Performance</th>
                            <th class="text-center">SLA</th>
                            <th class="text-center">Rating</th>
                            <th class="text-center">Resolution</th>
                            <th class="text-center">Efficiency</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advanced_staff_performance as $staff): ?>
                            <?php 
                            $efficiency_class = '';
                            switch($staff['performance_color']) {
                                case 'success': $efficiency_class = 'bg-success text-white'; break;
                                case 'info': $efficiency_class = 'bg-info text-white'; break;
                                case 'warning': $efficiency_class = 'bg-warning text-dark'; break;
                                case 'danger': $efficiency_class = 'bg-danger text-white'; break;
                                default: $efficiency_class = 'bg-secondary text-white';
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3" 
                                             style="width: 36px; height: 36px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                    border-radius: 50%; display: flex; align-items: center; justify-content: center; 
                                                    color: white; font-weight: bold; font-size: 14px;">
                                            <?php 
                                            $staff_name = $staff['full_name'] ?? '';
                                            $initials = 'S';
                                            if (!empty($staff_name)) {
                                                $words = explode(' ', $staff_name);
                                                $initials = strtoupper(substr($words[0], 0, 1));
                                                if (count($words) > 1) {
                                                    $initials .= strtoupper(substr($words[1], 0, 1));
                                                }
                                            }
                                            echo $initials;
                                            ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($staff_name); ?></div>
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars($staff['employee_id'] ?? 'N/A'); ?> • 
                                                <?php echo htmlspecialchars($staff['department'] ?? 'Not Specified'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($staff['designation'] ?? 'Not Specified'); ?></span>
                                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($staff['role_category'] ?? 'N/A'); ?></div>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-around">
                                        <div>
                                            <div class="fw-bold text-primary"><?php echo $staff['total_tickets_assigned']; ?></div>
                                            <small class="text-muted">Total</small>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-success"><?php echo $staff['tickets_closed']; ?></div>
                                            <small class="text-muted">Closed</small>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-warning"><?php echo $staff['tickets_open']; ?></div>
                                            <small class="text-muted">Open</small>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold <?php echo 'text-' . $staff['performance_color']; ?>">
                                        <?php echo $staff['closure_rate']; ?>%
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-<?php echo $staff['performance_color']; ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo $staff['closure_rate']; ?>%" 
                                             aria-valuenow="<?php echo $staff['closure_rate']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-muted">Closure Rate</small>
                                </td>
                                <td class="text-center">
                                    <div class="fw-bold <?php echo $staff['sla_compliance_rate'] >= 90 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $staff['sla_compliance_rate']; ?>%
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?php echo $staff['sla_compliance_rate'] >= 90 ? 'bg-success' : 'bg-danger'; ?>" 
                                             role="progressbar" 
                                             style="width: <?php echo $staff['sla_compliance_rate']; ?>%" 
                                             aria-valuenow="<?php echo $staff['sla_compliance_rate']; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                    <small class="text-muted">SLA Compliance</small>
                                </td>
                                <td class="text-center">
                                    <?php if ($staff['avg_client_rating'] > 0): ?>
                                        <div class="star-rating mb-1">
                                            <?php 
                                            $rating = $staff['avg_client_rating'] ?? 0;
                                            $full_stars = floor($rating);
                                            $half_star = ($rating - $full_stars) >= 0.5;
                                            ?>
                                            <?php for($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= $full_stars): ?>
                                                    <i class="fas fa-star text-warning" style="font-size: 12px;"></i>
                                                <?php elseif ($half_star && $i == ($full_stars + 1)): ?>
                                                    <i class="fas fa-star-half-alt text-warning" style="font-size: 12px;"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star text-warning" style="font-size: 12px;"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="fw-bold text-warning"><?php echo number_format($rating, 1); ?></div>
                                        <small class="text-muted">
                                            <?php echo $staff['high_rating_tickets']; ?> high ratings
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted small">No ratings</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($staff['avg_resolution_hours'] > 0): ?>
                                        <div class="fw-bold <?php echo $staff['avg_resolution_hours'] > 24 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo number_format($staff['avg_resolution_hours'], 1); ?>h
                                        </div>
                                        <div class="small text-muted">
                                            Avg. Resolution
                                        </div>
                                        <?php if ($staff['total_site_visits'] > 0): ?>
                                            <div class="small text-info">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo $staff['total_site_visits']; ?> visits
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">No data</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $efficiency_class; ?>">
                                        <?php echo $staff['efficiency_score']; ?>%
                                    </span>
                                    <div class="small mt-1 <?php echo 'text-' . $staff['performance_color']; ?>">
                                        <?php echo $staff['performance_tier']; ?>
                                    </div>
                                    <?php if ($staff['total_hours_logged'] > 0): ?>
                                        <div class="small text-muted">
                                            <?php echo $staff['total_hours_logged']; ?> hrs logged
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="viewStaffDetails('<?php echo $staff['staff_id']; ?>')"
                                                title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-info" 
                                                onclick="viewStaffTickets('<?php echo $staff['staff_id']; ?>')"
                                                title="View Tickets">
                                            <i class="fas fa-ticket-alt"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-success" 
                                                onclick="generateStaffReport('<?php echo $staff['staff_id']; ?>')"
                                                title="Generate Report">
                                            <i class="fas fa-chart-bar"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="9">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="small text-muted">
                                        <i class="fas fa-database me-1"></i>
                                        Data from VIEW: staff_performance_dashboard • 
                                        Showing <?php echo count($advanced_staff_performance); ?> active staff
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-info" onclick="refreshPerformanceData()">
                                            <i class="fas fa-sync-alt me-1"></i> Refresh
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" onclick="exportPerformanceData()">
                                            <i class="fas fa-download me-1"></i> Export
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Performance Distribution Chart -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Performance Distribution</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="performanceDistributionChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Top Performers</h6>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <?php 
                                // Get top 5 performers
                                usort($advanced_staff_performance, function($a, $b) {
                                    return ($b['efficiency_score'] ?? 0) <=> ($a['efficiency_score'] ?? 0);
                                });
                                $top_performers = array_slice($advanced_staff_performance, 0, 5);
                                ?>
                                <?php foreach ($top_performers as $index => $staff): ?>
                                    <div class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3 position-relative">
                                                    <div class="avatar-circle-sm" 
                                                         style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                                border-radius: 50%; display: flex; align-items: center; justify-content: center; 
                                                                color: white; font-weight: bold; font-size: 12px;">
                                                        <?php 
                                                        $staff_name = $staff['full_name'] ?? '';
                                                        $initials = 'S';
                                                        if (!empty($staff_name)) {
                                                            $words = explode(' ', $staff_name);
                                                            $initials = strtoupper(substr($words[0], 0, 1));
                                                            if (count($words) > 1) {
                                                                $initials .= strtoupper(substr($words[1], 0, 1));
                                                            }
                                                        }
                                                        echo $initials;
                                                        ?>
                                                    </div>
                                                    <?php if ($index < 3): ?>
                                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning">
                                                            <?php echo $index + 1; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($staff['full_name'] ?? ''); ?></h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($staff['designation'] ?? ''); ?></small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-success"><?php echo $staff['efficiency_score']; ?>%</span>
                                                <div class="small text-muted">
                                                    <?php echo $staff['tickets_closed']; ?> tickets closed
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
        </div>
    </div>
</div>

<script>
// Performance Distribution Chart
document.addEventListener('DOMContentLoaded', function() {
    // Calculate distribution
    const performanceData = <?php echo json_encode($advanced_staff_performance); ?>;
    
    let excellent = 0, good = 0, average = 0, needsImprovement = 0;
    
    performanceData.forEach(staff => {
        const score = staff.efficiency_score || 0;
        if (score >= 85) excellent++;
        else if (score >= 70) good++;
        else if (score >= 50) average++;
        else needsImprovement++;
    });
    
    const ctx = document.getElementById('performanceDistributionChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Excellent (85%+)', 'Good (70-84%)', 'Average (50-69%)', 'Needs Improvement (<50%)'],
            datasets: [{
                data: [excellent, good, average, needsImprovement],
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                borderColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${label}: ${value} staff (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
    
    // Initialize DataTable if available
    if (typeof $.fn.DataTable !== 'undefined') {
        $('#advancedPerformanceTable').DataTable({
            pageLength: 10,
            order: [[7, 'desc']], // Sort by efficiency score
            dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
            language: {
                search: "Search staff:",
                lengthMenu: "Show _MENU_ entries"
            }
        });
    }
});

// Action functions
function viewStaffDetails(staffId) {
    alert('Viewing details for staff ID: ' + staffId + '\n\nIn a real implementation, this would open a detailed staff performance modal.');
    // window.location.href = '<?php echo route('staff.view'); ?>?id=' + staffId;
}

function viewStaffTickets(staffId) {
    alert('Viewing tickets for staff ID: ' + staffId + '\n\nIn a real implementation, this would filter the tickets page.');
    // window.location.href = '<?php echo route('tickets.index'); ?>?staff=' + staffId;
}

function generateStaffReport(staffId) {
    alert('Generating report for staff ID: ' + staffId + '\n\nIn a real implementation, this would generate a PDF performance report.');
    // window.open('<?php echo route('reports.staff'); ?>?id=' + staffId, '_blank');
}

function refreshPerformanceData() {
    window.location.reload();
}

function exportPerformanceData() {
    // Simple CSV export
    const performanceData = <?php echo json_encode($advanced_staff_performance); ?>;
    
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    
    // Headers
    const headers = [
        'Staff Name', 'Employee ID', 'Department', 'Designation',
        'Total Tickets', 'Tickets Closed', 'Closure Rate (%)',
        'Avg Resolution (hrs)', 'Avg Rating', 'SLA Compliance (%)',
        'Efficiency Score', 'Performance Tier'
    ];
    csvContent += headers.join(',') + "\n";
    
    // Data rows
    performanceData.forEach(staff => {
        const row = [
            `"${staff.full_name || ''}"`,
            staff.employee_id || '',
            `"${staff.department || ''}"`,
            `"${staff.designation || ''}"`,
            staff.total_tickets_assigned || 0,
            staff.tickets_closed || 0,
            staff.closure_rate || 0,
            staff.avg_resolution_hours ? Number(staff.avg_resolution_hours).toFixed(1) : 0,
            staff.avg_client_rating ? Number(staff.avg_client_rating).toFixed(1) : 0,
            staff.sla_compliance_rate || 0,
            staff.efficiency_score || 0,
            `"${staff.performance_tier || ''}"`
        ];
        csvContent += row.join(',') + "\n";
    });
    
    // Create download link
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "staff_performance_report_" + new Date().toISOString().slice(0,10) + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
<?php endif; ?>