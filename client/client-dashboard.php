<?php
// client-dashboard.php - Updated with navigation
session_start();

// Check if user is logged in as client
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: client-login.php');
    exit;
}

// Load database config
require_once '../includes/routes.php';
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
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
} catch (Exception $e) {
    error_log("Client fetch error: " . $e->getMessage());
}

// Get stats
function getCount($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Query error: " . $e->getMessage());
        return 0;
    }
}

$stats = [
    'total_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE client_id = ?", [$client_id]),
    'open_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status IN ('Open', 'In Progress')", [$client_id]),
    'closed_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status = 'Closed'", [$client_id]),
    'active_assets' => getCount($pdo, "SELECT COUNT(*) FROM assets WHERE client_id = ? AND status = 'Active'", [$client_id]),
    'total_assets' => getCount($pdo, "SELECT COUNT(*) FROM assets WHERE client_id = ?", [$client_id]),
    'active_contracts' => getCount($pdo, "SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'Active'", [$client_id]),
    'total_contracts' => getCount($pdo, "SELECT COUNT(*) FROM contracts WHERE client_id = ?", [$client_id]),
    'pending_site_visits' => getCount($pdo, "SELECT COUNT(*) FROM site_visits WHERE client_id = ? AND check_out_time IS NULL", [$client_id]),
];

// Get recent tickets
$recent_tickets = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, sp.full_name as assigned_to 
        FROM tickets t 
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id 
        WHERE t.client_id = ? 
        ORDER BY t.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$client_id]);
    $recent_tickets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Tickets error: " . $e->getMessage());
}

// Get recent assets
$recent_assets = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, cl.location_name
        FROM assets a
        LEFT JOIN client_locations cl ON a.location_id = cl.id
        WHERE a.client_id = ?
        ORDER BY a.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$client_id]);
    $recent_assets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Assets error: " . $e->getMessage());
}

// Get active contracts
$active_contracts = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM contracts 
        WHERE client_id = ? AND status = 'Active'
        ORDER BY end_date DESC 
        LIMIT 3
    ");
    $stmt->execute([$client_id]);
    $active_contracts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Contracts error: " . $e->getMessage());
}

// Calculate percentages
$resolution_rate = $stats['total_tickets'] > 0 ? 
    round(($stats['closed_tickets'] / $stats['total_tickets']) * 100) : 0;
$asset_active_rate = $stats['total_assets'] > 0 ? 
    round(($stats['active_assets'] / $stats['total_assets']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard | MSP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --client-primary: #28a745;
            --client-secondary: #20c997;
            --client-light: #d4edda;
            --client-dark: #155724;
        }
        
        .client-sidebar {
            background: var(--client-dark);
            color: white;
            min-height: 100vh;
            padding: 0;
            position: fixed;
            width: 250px;
            left: 0;
            top: 0;
            z-index: 1000;
        }
        
        .client-sidebar .sidebar-header {
            padding: 20px;
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .client-sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            border-left: 4px solid transparent;
            transition: all 0.3s;
        }
        
        .client-sidebar .nav-link:hover,
        .client-sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--client-primary);
        }
        
        .client-sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }
        
        .client-main {
            margin-left: 250px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .client-header {
            background: linear-gradient(135deg, var(--client-primary) 0%, var(--client-secondary) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-left: 5px solid var(--client-primary);
            height: 100%;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: var(--client-primary);
            margin: 10px 0;
        }
        
        .data-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            height: 100%;
        }
        
        .btn-client {
            background: linear-gradient(135deg, var(--client-primary) 0%, var(--client-secondary) 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-client:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-open { background: #d6e4ff; color: #0d6efd; }
        .status-in-progress { background: #fff3cd; color: #856404; }
        .status-closed { background: #d4edda; color: #155724; }
        .status-waiting { background: #e2e3e5; color: #383d41; }
        
        .asset-active { background: #d4edda; color: #155724; }
        .asset-inactive { background: #e2e3e5; color: #383d41; }
        
        .contract-active { background: #d4edda; color: #155724; }
        .contract-expired { background: #f8d7da; color: #721c24; }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .action-card:hover {
            background: white;
            border-color: var(--client-primary);
            transform: translateY(-5px);
            text-decoration: none;
            color: #333;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--client-primary) 0%, var(--client-secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 20px;
        }
        
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            background: var(--client-primary);
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px;
            font-size: 20px;
        }
        
        @media (max-width: 768px) {
            .client-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .client-sidebar.active {
                transform: translateX(0);
            }
            
            .client-main {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--client-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar -->
    <div class="client-sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4 class="mb-0"><i class="fas fa-building me-2"></i>Client Portal</h4>
            <small class="text-white-50"><?php echo htmlspecialchars($client['company_name'] ?? ''); ?></small>
        </div>
        
        <!-- User Profile -->
        <div class="user-profile">
            <div class="user-avatar">
                <?php echo strtoupper(substr($client['company_name'] ?? 'C', 0, 1)); ?>
            </div>
            <div>
                <div class="fw-bold"><?php echo htmlspecialchars($client['contact_person'] ?? 'User'); ?></div>
                <small class="text-white-50"><?php echo htmlspecialchars($client['email'] ?? ''); ?></small>
            </div>
        </div>
        
        <!-- Navigation -->
        <ul class="nav flex-column pt-3">
            <li class="nav-item">
                <a class="nav-link active" href="client-dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-tickets.php">
                    <i class="fas fa-ticket-alt"></i> Support Tickets
                    <?php if ($stats['open_tickets'] > 0): ?>
                        <span class="badge bg-danger float-end"><?php echo $stats['open_tickets']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="new-ticket.php">
                    <i class="fas fa-plus-circle"></i> New Ticket
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-assets.php">
                    <i class="fas fa-server"></i> IT Assets
                    <span class="badge bg-info float-end"><?php echo $stats['total_assets']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-contracts.php">
                    <i class="fas fa-file-contract"></i> Contracts
                    <span class="badge bg-success float-end"><?php echo $stats['active_contracts']; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-site-visits.php">
                    <i class="fas fa-map-marker-alt"></i> Site Visits
                    <?php if ($stats['pending_site_visits'] > 0): ?>
                        <span class="badge bg-warning float-end"><?php echo $stats['pending_site_visits']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-reports.php">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-profile.php">
                    <i class="fas fa-user-cog"></i> My Profile
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="client-change-password.php">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </li>
            <li class="nav-item mt-4">
                <a class="nav-link text-danger" href="<?php echo route('logout'); ?>">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="client-main">
        <!-- Header -->
        <div class="client-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                    <p class="mb-0">Welcome to your client portal</p>
                </div>
                <div class="d-none d-md-block">
                    <span class="badge bg-light text-dark">
                        <i class="fas fa-clock me-1"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Open Tickets</div>
                            <div class="stat-number"><?php echo $stats['open_tickets']; ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Total: <?php echo $stats['total_tickets']; ?> • Closed: <?php echo $stats['closed_tickets']; ?></small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="fas fa-server"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Active Assets</div>
                            <div class="stat-number"><?php echo $stats['active_assets']; ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Active: <?php echo $asset_active_rate; ?>% (<?php echo $stats['active_assets']; ?>/<?php echo $stats['total_assets']; ?>)</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Active Contracts</div>
                            <div class="stat-number"><?php echo $stats['active_contracts']; ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Total: <?php echo $stats['total_contracts']; ?> contracts</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Resolution Rate</div>
                            <div class="stat-number"><?php echo $resolution_rate; ?>%</div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Ticket resolution success rate</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="data-card">
                    <h4 class="mb-4"><i class="fas fa-bolt me-2"></i>Quick Actions</h4>
                    <div class="quick-actions-grid">
                        <a href="new-ticket.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                            <h6 class="mb-2">New Support Ticket</h6>
                            <small class="text-muted">Report an issue or request</small>
                        </a>
                        
                        <a href="client-tickets.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <h6 class="mb-2">View All Tickets</h6>
                            <small class="text-muted">Check status and updates</small>
                        </a>
                        
                        <a href="client-assets.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-server"></i>
                            </div>
                            <h6 class="mb-2">Manage Assets</h6>
                            <small class="text-muted">View IT equipment list</small>
                        </a>
                        
                        <a href="client-contracts.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <h6 class="mb-2">View Contracts</h6>
                            <small class="text-muted">Service agreements & SLAs</small>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="row">
            <!-- Recent Tickets -->
            <div class="col-lg-6 mb-3">
                <div class="data-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-ticket-alt me-2"></i>Recent Tickets</h4>
                        <a href="client-tickets.php" class="btn btn-client btn-sm">View All</a>
                    </div>
                    
                    <?php if (empty($recent_tickets)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No tickets found</h5>
                            <p>You haven't submitted any support tickets yet.</p>
                            <a href="new-ticket.php" class="btn btn-client">Create First Ticket</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Ticket #</th>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_tickets as $ticket): ?>
                                        <tr>
                                            <td><strong><a href="client-ticket-details.php?id=<?php echo $ticket['id']; ?>"><?php echo htmlspecialchars($ticket['ticket_number']); ?></a></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars(substr($ticket['title'], 0, 30)); ?>
                                                <?php if (strlen($ticket['title']) > 30): ?>...<?php endif; ?>
                                                <br>
                                                <small class="text-muted">Assigned to: <?php echo htmlspecialchars($ticket['assigned_to'] ?? 'Unassigned'); ?></small>
                                            </td>
                                            <td>
                                                <?php $status = strtolower(str_replace(' ', '-', $ticket['status'] ?? 'unknown')); ?>
                                                <span class="status-badge status-<?php echo $status; ?>">
                                                    <?php echo htmlspecialchars($ticket['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Assets & Contracts -->
            <div class="col-lg-6 mb-3">
                <!-- Recent Assets -->
                <div class="data-card mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-server me-2"></i>Recent Assets</h4>
                        <a href="client-assets.php" class="btn btn-client btn-sm">View All</a>
                    </div>
                    
                    <?php if (empty($recent_assets)): ?>
                        <p class="text-muted">No assets found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Type</th>
                                        <th>Model</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_assets as $asset): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($asset['asset_tag']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($asset['asset_type']); ?></td>
                                            <td><?php echo htmlspecialchars($asset['model']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $asset['status'] == 'Active' ? 'asset-active' : 'asset-inactive'; ?>">
                                                    <?php echo htmlspecialchars($asset['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Active Contracts -->
                <?php if (!empty($active_contracts)): ?>
                <div class="data-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-file-contract me-2"></i>Active Contracts</h4>
                        <a href="client-contracts.php" class="btn btn-client btn-sm">View All</a>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Contract #</th>
                                    <th>Type</th>
                                    <th>End Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_contracts as $contract): ?>
                                    <?php 
                                    $end_date = strtotime($contract['end_date']);
                                    $today = time();
                                    $days_left = ceil(($end_date - $today) / (60 * 60 * 24));
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($contract['contract_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($contract['contract_type']); ?></td>
                                        <td>
                                            <?php if ($days_left < 30): ?>
                                                <span class="text-danger" title="<?php echo $days_left; ?> days left">
                                                    <?php echo date('M d, Y', $end_date); ?>
                                                </span>
                                            <?php else: ?>
                                                <?php echo date('M d, Y', $end_date); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Support Info -->
        <div class="row">
            <div class="col-12">
                <div class="data-card">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                            <i class="fas fa-headset fa-2x"></i>
                        </div>
                        <div>
                            <h5 class="mb-1">Need Immediate Assistance?</h5>
                            <p class="mb-0 text-muted">Contact our support team: 
                                <strong>support@msp.com</strong> | 
                                <strong>+255 123 456 789</strong> | 
                                <strong>Mon-Fri: 8:00 AM - 6:00 PM</strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
            this.innerHTML = document.getElementById('sidebar').classList.contains('active') 
                ? '<i class="fas fa-times"></i>' 
                : '<i class="fas fa-bars"></i>';
        });
        
        // Auto-refresh dashboard every 2 minutes
        setTimeout(() => {
            window.location.reload();
        }, 120000);
        
        // Add hover effects to action cards
        document.querySelectorAll('.action-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.getElementById('mobileMenuBtn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
    </script>
</body>
</html>