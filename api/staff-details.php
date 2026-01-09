<?php
require_once '../includes/auth.php';
requireLogin();

$current_user = getCurrentUser();
$user_type = $current_user['user_type'];

// Get database connection
$pdo = getDBConnection();

// Get staff ID from query parameter
$staff_id = $_GET['staff_id'] ?? '';

if (empty($staff_id)) {
    die(json_encode(['error' => 'No staff ID provided']));
}

// Function to get staff details
function getStaffDetails($pdo, $staff_id) {
    try {
        $query = "
            SELECT 
                sp.*,
                (SELECT COUNT(*) FROM ticket_assignees WHERE staff_id = sp.id AND is_primary = true) as total_assigned_tickets,
                (SELECT COUNT(*) FROM ticket_assignees ta 
                 JOIN tickets t ON ta.ticket_id = t.id 
                 WHERE ta.staff_id = sp.id AND t.status = 'Closed' AND ta.is_primary = true) as closed_tickets,
                (SELECT COUNT(*) FROM ticket_assignees ta 
                 JOIN tickets t ON ta.ticket_id = t.id 
                 WHERE ta.staff_id = sp.id AND t.priority = 'High' AND ta.is_primary = true) as high_priority_tickets,
                (SELECT COUNT(*) FROM site_visits WHERE engineer_id = sp.id) as site_visits,
                (SELECT COUNT(*) FROM ticket_logs WHERE staff_id = sp.id) as activity_logs
            FROM staff_profiles sp
            WHERE sp.id = :staff_id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':staff_id', $staff_id, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Staff details error: " . $e->getMessage());
        return null;
    }
}

// Function to get staff's recent tickets
function getStaffRecentTickets($pdo, $staff_id) {
    try {
        $query = "
            SELECT 
                t.*,
                c.company_name,
                ROUND(EXTRACT(EPOCH FROM (t.closed_at - t.created_at))/3600, 1) as resolution_hours
            FROM ticket_assignees ta
            JOIN tickets t ON ta.ticket_id = t.id
            LEFT JOIN clients c ON t.client_id = c.id
            WHERE ta.staff_id = :staff_id
            AND ta.is_primary = true
            ORDER BY t.created_at DESC
            LIMIT 10
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':staff_id', $staff_id, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Staff tickets error: " . $e->getMessage());
        return [];
    }
}

// Function to get staff's ticket statistics by status
function getStaffTicketStats($pdo, $staff_id) {
    try {
        $query = "
            SELECT 
                t.status,
                COUNT(*) as count
            FROM ticket_assignees ta
            JOIN tickets t ON ta.ticket_id = t.id
            WHERE ta.staff_id = :staff_id
            AND ta.is_primary = true
            GROUP BY t.status
            ORDER BY count DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':staff_id', $staff_id, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Staff ticket stats error: " . $e->getMessage());
        return [];
    }
}

// Get the data
$staff_details = getStaffDetails($pdo, $staff_id);
$staff_tickets = getStaffRecentTickets($pdo, $staff_id);
$staff_stats = getStaffTicketStats($pdo, $staff_id);

if (!$staff_details) {
    die(json_encode(['error' => 'Staff not found']));
}

// Calculate performance metrics
$total_tickets = $staff_details['total_assigned_tickets'] ?? 0;
$closed_tickets = $staff_details['closed_tickets'] ?? 0;
$success_rate = $total_tickets > 0 ? round(($closed_tickets / $total_tickets) * 100) : 0;

// Generate HTML for modal content
?>
<div class="modal-header bg-primary text-white p-4">
    <h5 class="modal-title">
        <i class="fas fa-user-tie me-2"></i>
        <?php echo htmlspecialchars($staff_details['full_name']); ?>
    </h5>
</div>

<div class="modal-body p-4">
    <!-- Staff Information -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="text-center">
                <div class="staff-avatar-modal mb-3">
                    <?php if (!empty($staff_details['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($staff_details['photo_path']); ?>" 
                             alt="<?php echo htmlspecialchars($staff_details['full_name']); ?>"
                             style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div style="width: 120px; height: 120px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                              border-radius: 50%; display: flex; align-items: center; justify-content: center; 
                              color: white; font-size: 48px; font-weight: bold; margin: 0 auto;">
                            <?php echo strtoupper(substr($staff_details['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h5><?php echo htmlspecialchars($staff_details['full_name']); ?></h5>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($staff_details['designation']); ?></p>
                <p class="text-muted small"><?php echo htmlspecialchars($staff_details['department']); ?></p>
            </div>
        </div>
        
        <div class="col-md-9">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Contact Information</h6>
                            <p class="mb-1">
                                <i class="fas fa-envelope me-2 text-primary"></i>
                                <?php echo htmlspecialchars($staff_details['official_email']); ?>
                            </p>
                            <p class="mb-1">
                                <i class="fas fa-phone me-2 text-primary"></i>
                                <?php echo htmlspecialchars($staff_details['phone_number']); ?>
                            </p>
                            <?php if (!empty($staff_details['alternate_phone'])): ?>
                                <p class="mb-0">
                                    <i class="fas fa-mobile-alt me-2 text-primary"></i>
                                    <?php echo htmlspecialchars($staff_details['alternate_phone']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title text-muted">Employment Details</h6>
                            <p class="mb-1">
                                <i class="fas fa-calendar-alt me-2 text-primary"></i>
                                Joined: <?php echo date('M d, Y', strtotime($staff_details['date_of_joining'])); ?>
                            </p>
                            <p class="mb-1">
                                <i class="fas fa-briefcase me-2 text-primary"></i>
                                Type: <?php echo htmlspecialchars($staff_details['employment_type']); ?>
                            </p>
                            <p class="mb-0">
                                <i class="fas fa-user-check me-2 text-primary"></i>
                                Status: <?php echo htmlspecialchars($staff_details['employment_status']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Stats -->
    <div class="row mb-4">
        <div class="col-12">
            <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Performance Metrics</h5>
            <div class="row">
                <div class="col-md-3 col-6 mb-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fs-2 fw-bold text-primary"><?php echo $total_tickets; ?></div>
                        <div class="text-muted">Total Tickets</div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fs-2 fw-bold text-success"><?php echo $closed_tickets; ?></div>
                        <div class="text-muted">Closed Tickets</div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fs-2 fw-bold text-danger"><?php echo $staff_details['high_priority_tickets']; ?></div>
                        <div class="text-muted">High Priority</div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fs-2 fw-bold text-warning"><?php echo $staff_details['site_visits']; ?></div>
                        <div class="text-muted">Site Visits</div>
                    </div>
                </div>
            </div>
            
            <!-- Performance Score -->
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">Success Rate</h6>
                            <div class="progress" style="height: 20px; width: 300px;">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?php echo $success_rate; ?>%"
                                     aria-valuenow="<?php echo $success_rate; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo $success_rate; ?>%
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <?php
                            $performance_label = '';
                            $performance_color = '';
                            if ($success_rate >= 80) {
                                $performance_label = 'Excellent';
                                $performance_color = 'success';
                            } elseif ($success_rate >= 60) {
                                $performance_label = 'Good';
                                $performance_color = 'primary';
                            } elseif ($success_rate >= 40) {
                                $performance_label = 'Average';
                                $performance_color = 'warning';
                            } else {
                                $performance_label = 'Needs Improvement';
                                $performance_color = 'danger';
                            }
                            ?>
                            <span class="badge bg-<?php echo $performance_color; ?>">
                                <?php echo $performance_label; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Tickets -->
    <div class="row">
        <div class="col-12">
            <h5 class="mb-3"><i class="fas fa-ticket-alt me-2"></i>Recent Tickets</h5>
            <?php if (empty($staff_tickets)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                    <p class="text-muted">No tickets assigned to this staff member</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Title</th>
                                <th>Client</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Resolution Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_tickets as $ticket): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars(substr($ticket['title'], 0, 30)); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge priority-<?php echo strtolower($ticket['priority']); ?>">
                                            <?php echo htmlspecialchars($ticket['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge status-<?php echo strtolower(str_replace(' ', '-', $ticket['status'])); ?>">
                                            <?php echo htmlspecialchars($ticket['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d', strtotime($ticket['created_at'])); ?></td>
                                    <td>
                                        <?php if ($ticket['resolution_hours'] > 0): ?>
                                            <span class="badge bg-info">
                                                <?php echo $ticket['resolution_hours']; ?>h
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Ongoing</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-footer p-3">
    <button type="button" class="btn btn-secondary" onclick="closeStaffModal()">Close</button>
    <a href="<?php echo route('staff.view', ['id' => $staff_id]); ?>" class="btn btn-primary">
        <i class="fas fa-external-link-alt me-1"></i> View Full Profile
    </a>
</div>