<?php
require_once 'includes/auth.php';
requireLogin();

$current_user = getCurrentUser();
$user_type = $current_user['user_type'];
$staff_profile = $current_user['staff_profile'];

// Get database connection
$pdo = getDBConnection();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MSP Application</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-network-wired"></i> MSP Portal</h3>
                <p><?php echo htmlspecialchars($staff_profile['full_name'] ?? $current_user['email']); ?></p>
                <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user_type)); ?></span>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a></li>
                    
                    <?php if (hasPermission('admin')): ?>
                    <li><a href="pages/users/index.php">
                        <i class="fas fa-users-cog"></i> User Management
                    </a></li>
                    <?php endif; ?>
                    
                    <?php if (hasPermission('manager')): ?>
                    <li><a href="pages/clients/index.php">
                        <i class="fas fa-building"></i> Clients
                    </a></li>
                    <li><a href="pages/contracts/index.php">
                        <i class="fas fa-file-contract"></i> Contracts
                    </a></li>
                    <?php endif; ?>
                    
                    <li><a href="pages/tickets/index.php">
                        <i class="fas fa-ticket-alt"></i> Tickets
                    </a></li>
                    
                    <li><a href="pages/assets/index.php">
                        <i class="fas fa-server"></i> Assets
                    </a></li>
                    
                    <?php if (hasPermission('manager')): ?>
                    <li><a href="pages/reports/index.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a></li>
                    <?php endif; ?>
                    
                    <li><a href="pages/staff/profile.php">
                        <i class="fas fa-user"></i> My Profile
                    </a></li>
                    
                    <li><a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a></li>
                </ul>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($staff_profile['designation'] ?? ucfirst($user_type)); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
            <div class="alert alert-<?php echo $flash['type']; ?>">
                <i class="fas fa-<?php echo $flash['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Welcome Message -->
            <div class="welcome-card">
                <h2>Welcome back, <?php echo htmlspecialchars($staff_profile['full_name'] ?? 'User'); ?>!</h2>
                <p>Here's what's happening with your MSP today.</p>
                <div class="current-time">
                    <i class="fas fa-clock"></i> <?php echo date('l, F j, Y - g:i A'); ?>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #FF6B35;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Tickets</h3>
                        <div class="stat-number">
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>All time</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #004E89;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Active Clients</h3>
                        <div class="stat-number">
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM clients");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-users"></i>
                            <span>Managed clients</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #28a745;">
                        <i class="fas fa-server"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Assets</h3>
                        <div class="stat-number">
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM assets");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-network-wired"></i>
                            <span>Infrastructure</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #17a2b8;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Staff Members</h3>
                        <div class="stat-number">
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM staff_profiles WHERE employment_status = 'Active'");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-user-check"></i>
                            <span>Active team</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Tickets -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-ticket-alt"></i> Recent Tickets</h3>
                    <a href="pages/tickets/index.php" class="btn btn-secondary">
                        View All Tickets
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Title</th>
                                <th>Client</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $query = "SELECT t.*, c.company_name FROM tickets t 
                                          LEFT JOIN clients c ON t.client_id = c.id 
                                          ORDER BY t.created_at DESC LIMIT 10";
                                $stmt = $pdo->query($query);
                                $tickets = $stmt->fetchAll();
                                
                                if (empty($tickets)) {
                                    echo '<tr><td colspan="6" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                            <p>No tickets found</p>
                                            <a href="pages/tickets/create.php" class="btn btn-primary">Create First Ticket</a>
                                          </td></tr>';
                                } else {
                                    foreach ($tickets as $ticket) {
                                        echo '<tr>
                                            <td>' . htmlspecialchars($ticket['ticket_number']) . '</td>
                                            <td>' . htmlspecialchars($ticket['title']) . '</td>
                                            <td>' . htmlspecialchars($ticket['company_name'] ?? 'N/A') . '</td>
                                            <td><span class="badge priority-' . strtolower($ticket['priority']) . '">' . 
                                                 htmlspecialchars($ticket['priority']) . '</span></td>
                                            <td><span class="badge status-' . str_replace(' ', '-', strtolower($ticket['status'])) . '">' . 
                                                 htmlspecialchars($ticket['status']) . '</span></td>
                                            <td>' . date('M d, Y', strtotime($ticket['created_at'])) . '</td>
                                        </tr>';
                                    }
                                }
                            } catch (Exception $e) {
                                echo '<tr><td colspan="6" class="text-center py-4 text-danger">
                                        <i class="fas fa-exclamation-triangle"></i> Error loading tickets
                                      </td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="actions-grid">
                    <a href="pages/tickets/create.php" class="action-card">
                        <div class="action-icon" style="background: #FF6B35;">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="action-text">
                            <h4>New Ticket</h4>
                            <p>Create support ticket</p>
                        </div>
                    </a>
                    
                    <a href="pages/clients/create.php" class="action-card">
                        <div class="action-icon" style="background: #004E89;">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="action-text">
                            <h4>Add Client</h4>
                            <p>Register new client</p>
                        </div>
                    </a>
                    
                    <a href="pages/assets/create.php" class="action-card">
                        <div class="action-icon" style="background: #28a745;">
                            <i class="fas fa-server"></i>
                        </div>
                        <div class="action-text">
                            <h4>Add Asset</h4>
                            <p>Register IT asset</p>
                        </div>
                    </a>
                    
                    <a href="pages/reports/index.php" class="action-card">
                        <div class="action-icon" style="background: #17a2b8;">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="action-text">
                            <h4>Reports</h4>
                            <p>View analytics</p>
                        </div>
                    </a>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
            this.classList.toggle('active');
        });
        
        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>