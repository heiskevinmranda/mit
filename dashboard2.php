<?php
session_start();
require_once 'config/database.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDBConnection();
$user_type = $_SESSION['user_type'] ?? 'staff';
$staff_profile = $_SESSION['staff_profile'] ?? null;

// Get statistics based on user role
$stats = [];
$recent_tickets = [];
$staff_performance = [];

try {
    if (in_array($user_type, ['super_admin', 'admin', 'manager'])) {
        // Admin/Manager stats
        $stats['total_tickets'] = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
        $stats['open_tickets'] = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'In Progress', 'Waiting')")->fetchColumn();
        $stats['total_clients'] = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        $stats['total_staff'] = $pdo->query("SELECT COUNT(*) FROM staff_profiles WHERE employment_status = 'Active'")->fetchColumn();
        
        // Get recent tickets
        $recent_tickets = $pdo->query("
            SELECT t.*, c.company_name, sp.full_name as assigned_to_name 
            FROM tickets t 
            LEFT JOIN clients c ON t.client_id = c.id 
            LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id 
            ORDER BY t.created_at DESC 
            LIMIT 10
        ")->fetchAll();
        
    } else {
        // Staff/Engineer stats
        $staff_id = $staff_profile['id'] ?? null;
        
        if ($staff_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ?");
            $stmt->execute([$staff_id]);
            $stats['total_tickets'] = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status IN ('Open', 'In Progress', 'Waiting')");
            $stmt->execute([$staff_id]);
            $stats['open_tickets'] = $stmt->fetchColumn();
            
            // Recent tickets for this staff
            $stmt = $pdo->prepare("
                SELECT t.*, c.company_name 
                FROM tickets t 
                LEFT JOIN clients c ON t.client_id = c.id 
                WHERE t.assigned_to = ? 
                ORDER BY t.created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$staff_id]);
            $recent_tickets = $stmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    // Handle error silently or log it
    error_log("Dashboard error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MSP Application</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Additional inline styles */
        :root {
            --primary-color: #FF6B35;
            --secondary-color: #004E89;
            --light-color: #F8F9FA;
            --dark-color: #343A40;
            --success-color: #28A745;
            --warning-color: #FFC107;
            --danger-color: #DC3545;
            --info-color: #17A2B8;
        }
        
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include 'includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($staff_profile['designation'] ?? 'Staff'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <?php if (in_array($user_type, ['super_admin', 'admin', 'manager'])): ?>
                    <div class="stat-card">
                        <h3>Total Tickets</h3>
                        <div class="stat-number"><?php echo $stats['total_tickets'] ?? 0; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line trend-up"></i>
                            <span>This Month: <?php echo ceil(($stats['total_tickets'] ?? 0) / 30); ?> avg/day</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Open Tickets</h3>
                        <div class="stat-number"><?php echo $stats['open_tickets'] ?? 0; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-exclamation-circle" style="color: var(--warning-color);"></i>
                            <span>
                                <?php 
                                if (isset($stats['total_tickets']) && $stats['total_tickets'] > 0) {
                                    echo round(($stats['open_tickets'] / $stats['total_tickets']) * 100) . '% of total';
                                } else {
                                    echo '0% of total';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Active Clients</h3>
                        <div class="stat-number"><?php echo $stats['total_clients'] ?? 0; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-building" style="color: var(--info-color);"></i>
                            <span>Active contracts: 0</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Staff Members</h3>
                        <div class="stat-number"><?php echo $stats['total_staff'] ?? 0; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-users" style="color: var(--secondary-color);"></i>
                            <span>Active today: <?php echo $stats['total_staff'] ?? 0; ?></span>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Staff/Engineer Stats -->
                    <div class="stat-card">
                        <h3>My Tickets</h3>
                        <div class="stat-number"><?php echo $stats['total_tickets'] ?? 0; ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-ticket-alt"></i>
                            <span><?php echo $stats['open_tickets'] ?? 0; ?> pending</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Today's Visits</h3>
                        <div class="stat-number">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Scheduled visits: 0</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>SLA Compliance</h3>
                        <div class="stat-number">92%</div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line trend-up"></i>
                            <span>+2% from last week</span>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Avg. Resolution</h3>
                        <div class="stat-number">4.2h</div>
                        <div class="stat-trend">
                            <i class="fas fa-clock"></i>
                            <span>Target: < 8h</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Tickets -->
            <div class="table-container" style="margin-bottom: 2rem;">
                <div class="table-header">
                    <h3><i class="fas fa-ticket-alt"></i> Recent Tickets</h3>
                    <a href="#" class="btn btn-secondary">View All</a>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Client</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <?php if (in_array($user_type, ['super_admin', 'admin', 'manager'])): ?>
                                <th>Assigned To</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_tickets)): ?>
                            <?php foreach ($recent_tickets as $ticket): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ticket['ticket_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($ticket['title'] ?? 'No title'); ?></td>
                                    <td>
                                        <span class="priority-<?php echo strtolower($ticket['priority'] ?? 'medium'); ?>">
                                            <?php echo htmlspecialchars($ticket['priority'] ?? 'Medium'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo str_replace(' ', '-', strtolower($ticket['status'] ?? 'open')); ?>">
                                            <?php echo htmlspecialchars($ticket['status'] ?? 'Open'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($ticket['created_at'] ?? 'now')); ?></td>
                                    <?php if (in_array($user_type, ['super_admin', 'admin', 'manager'])): ?>
                                        <td><?php echo htmlspecialchars($ticket['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo in_array($user_type, ['super_admin', 'admin', 'manager']) ? '7' : '6'; ?>" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; color: #ccc; margin-bottom: 1rem;"></i>
                                    <p>No tickets found</p>
                                    <a href="#" class="btn" style="margin-top: 1rem;">Create First Ticket</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (in_array($user_type, ['super_admin', 'admin', 'manager']) && isset($staff_performance) && !empty($staff_performance)): ?>
                <!-- Staff Performance -->
                <div class="table-container">
                    <div class="table-header">
                        <h3><i class="fas fa-chart-line"></i> Staff Performance</h3>
                        <a href="#" class="btn btn-secondary">Detailed Report</a>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Designation</th>
                                <th>Total Tickets</th>
                                <th>Closed</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_performance as $staff): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($staff['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($staff['designation'] ?? 'N/A'); ?></td>
                                    <td><?php echo $staff['total_tickets']; ?></td>
                                    <td><?php echo $staff['closed_tickets']; ?></td>
                                    <td>
                                        <?php 
                                        $performance = $staff['total_tickets'] > 0 ? ($staff['closed_tickets'] / $staff['total_tickets']) * 100 : 0;
                                        $color = $performance >= 80 ? 'success' : ($performance >= 60 ? 'warning' : 'danger');
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="flex: 1; height: 8px; background: #eee; border-radius: 4px;">
                                                <div style="width: <?php echo min($performance, 100); ?>%; height: 100%; background: var(--<?php echo $color; ?>-color); border-radius: 4px;"></div>
                                            </div>
                                            <span><?php echo round($performance); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 2rem;">
                <div class="stat-card" style="text-align: center; cursor: pointer;" onclick="window.location.href='#'">
                    <i class="fas fa-plus-circle" style="font-size: 2rem; color: var(--primary-color); margin-bottom: 1rem;"></i>
                    <h3>New Ticket</h3>
                    <p>Create support ticket</p>
                </div>
                
                <div class="stat-card" style="text-align: center; cursor: pointer;" onclick="window.location.href='#'">
                    <i class="fas fa-calendar-check" style="font-size: 2rem; color: var(--success-color); margin-bottom: 1rem;"></i>
                    <h3>Schedule Visit</h3>
                    <p>Plan site visit</p>
                </div>
                
                <div class="stat-card" style="text-align: center; cursor: pointer;" onclick="window.location.href='#'">
                    <i class="fas fa-file-invoice" style="font-size: 2rem; color: var(--info-color); margin-bottom: 1rem;"></i>
                    <h3>Reports</h3>
                    <p>Generate reports</p>
                </div>
                
                <div class="stat-card" style="text-align: center; cursor: pointer;" onclick="window.location.href='#'">
                    <i class="fas fa-chart-pie" style="font-size: 2rem; color: var(--warning-color); margin-bottom: 1rem;"></i>
                    <h3>Analytics</h3>
                    <p>View analytics</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <script src="js/main.js"></script>
    <script>
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileMenuToggle && sidebar) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                });
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(event) {
                    if (window.innerWidth <= 768 && 
                        sidebar.classList.contains('active') && 
                        !sidebar.contains(event.target) && 
                        event.target !== mobileMenuToggle) {
                        sidebar.classList.remove('active');
                    }
                });
            }
            
            // Auto-refresh dashboard every 60 seconds
            setInterval(function() {
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        // Update only specific parts if needed
                        console.log('Dashboard auto-refreshed');
                    })
                    .catch(error => console.error('Auto-refresh error:', error));
            }, 60000);
            
            // Add click effects to stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('click', function() {
                    if (this.style.cursor === 'pointer') {
                        this.style.transform = 'scale(0.98)';
                        setTimeout(() => {
                            this.style.transform = '';
                        }, 150);
                    }
                });
            });
        });
    </script>
</body>
</html>