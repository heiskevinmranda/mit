<?php
// pages/tickets/index.php - FIXED VERSION

// Include authentication
require_once '../../includes/auth.php';

// Check if user is logged in
requireLogin();

// Get current user
$current_user = getCurrentUser();
$user_type = $current_user['user_type'];

// Check permissions - ALLOW all logged-in users to view tickets
// No permission check needed for viewing tickets index

$pdo = getDBConnection();

// ========== FILTERS ==========
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$assigned_to = $_GET['assigned_to'] ?? '';
$client_id = $_GET['client_id'] ?? '';
$search = $_GET['search'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// ========== BUILD QUERY ==========
$query = "SELECT t.*, c.company_name, sp.full_name as assigned_to_name, 
                 u.email as created_by_email, cl.location_name
          FROM tickets t
          LEFT JOIN clients c ON t.client_id = c.id
          LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
          LEFT JOIN users u ON t.created_by = u.id
          LEFT JOIN client_locations cl ON t.location_id = cl.id
          WHERE 1=1";

$params = [];

// Apply filters based on user role
if (!isManager() && !isAdmin()) {
    // Regular staff can only see tickets assigned to them or created by them
    $staff_id = $current_user['staff_profile']['id'] ?? 0;
    if ($staff_id) {
        $query .= " AND (t.assigned_to = ? OR t.created_by = ?)";
        $params[] = $staff_id;
        $params[] = $current_user['id'];
    } else {
        // If no staff profile, show only tickets created by user
        $query .= " AND t.created_by = ?";
        $params[] = $current_user['id'];
    }
}

// Add filters
if ($status && $status !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status;
}

if ($priority && $priority !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority;
}

if ($assigned_to && $assigned_to !== 'all') {
    $query .= " AND t.assigned_to = ?";
    $params[] = $assigned_to;
}

if ($client_id && $client_id !== 'all') {
    $query .= " AND t.client_id = ?";
    $params[] = $client_id;
}

if ($search) {
    $query .= " AND (t.ticket_number ILIKE ? OR t.title ILIKE ? OR c.company_name ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ========== COUNT TOTAL ==========
$count_query = "SELECT COUNT(*) as total FROM ($query) as subquery";
try {
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];
} catch (Exception $e) {
    $total = 0;
}

$total_pages = ceil($total / $limit);

// ========== GET TICKETS ==========
$query .= " ORDER BY 
            CASE t.priority 
                WHEN 'Critical' THEN 1 
                WHEN 'High' THEN 2 
                WHEN 'Medium' THEN 3 
                WHEN 'Low' THEN 4 
                ELSE 5 
            END,
            t.created_at DESC 
            LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {
    $tickets = [];
    $error = "Error loading tickets: " . $e->getMessage();
}

// ========== GET FILTER DATA ==========
$clients = [];
$staff_members = [];

try {
    if (isManager() || isAdmin()) {
        $clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
        $staff_members = $pdo->query("SELECT id, full_name FROM staff_profiles WHERE employment_status = 'Active' ORDER BY full_name")->fetchAll();
    }
} catch (Exception $e) {
    // Ignore errors for filter data
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management | MSP Application</title>
    <link rel="stylesheet" href="../../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stats-card h5 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .stats-card h2 {
            color: #004E89;
            font-weight: bold;
            margin: 0;
        }
        
        .badge-critical { background: #dc3545; color: white; }
        .badge-high { background: #fd7e14; color: white; }
        .badge-medium { background: #ffc107; color: #212529; }
        .badge-low { background: #6c757d; color: white; }
        
        .badge-open { background: #007bff; color: white; }
        .badge-in-progress { background: #17a2b8; color: white; }
        .badge-waiting { background: #6f42c1; color: white; }
        .badge-resolved { background: #28a745; color: white; }
        .badge-closed { background: #6c757d; color: white; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Include Sidebar -->
        <?php 
        // Create simple sidebar include
        $sidebar = '
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-network-wired"></i> MSP Portal</h3>
                <p>' . htmlspecialchars($current_user['staff_profile']['full_name'] ?? $current_user['email']) . '</p>
                <span class="user-role">' . ucfirst(str_replace('_', ' ', $user_type)) . '</span>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="../../dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a></li>
                    
                    <li><a href="index.php" class="active">
                        <i class="fas fa-ticket-alt"></i> Tickets
                    </a></li>
                    
                    <li><a href="../clients/index.php">
                        <i class="fas fa-building"></i> Clients
                    </a></li>
                    
                    <li><a href="../assets/index.php">
                        <i class="fas fa-server"></i> Assets
                    </a></li>
                    
                    <li><a href="../staff/profile.php">
                        <i class="fas fa-user"></i> My Profile
                    </a></li>
                    
                    <li><a href="../../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a></li>
                </ul>
            </nav>
        </aside>';
        echo $sidebar;
        ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-ticket-alt"></i> Ticket Management</h1>
                <div class="btn-group">
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Ticket
                    </a>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $flash['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <h5>Open Tickets</h5>
                        <h2>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'In Progress', 'Waiting')");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h5>Critical Priority</h5>
                        <h2>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE priority = 'Critical' AND status != 'Closed'");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h5>Resolved Today</h5>
                        <h2>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'Resolved' AND DATE(updated_at) = CURRENT_DATE");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h5>Total Tickets</h5>
                        <h2>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets");
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all">All Status</option>
                            <option value="Open" <?php echo $status == 'Open' ? 'selected' : ''; ?>>Open</option>
                            <option value="In Progress" <?php echo $status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Waiting" <?php echo $status == 'Waiting' ? 'selected' : ''; ?>>Waiting</option>
                            <option value="Resolved" <?php echo $status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="Closed" <?php echo $status == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="all">All Priorities</option>
                            <option value="Critical" <?php echo $priority == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                            <option value="High" <?php echo $priority == 'High' ? 'selected' : ''; ?>>High</option>
                            <option value="Medium" <?php echo $priority == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="Low" <?php echo $priority == 'Low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    
                    <?php if (isManager() || isAdmin()): ?>
                    <div class="col-md-2">
                        <label class="form-label">Assigned To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="all">All Staff</option>
                            <?php foreach ($staff_members as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>" 
                                    <?php echo $assigned_to == $staff['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($staff['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select">
                            <option value="all">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>" 
                                    <?php echo $client_id == $client['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" 
                               placeholder="Ticket #, Title, Client..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Tickets Table -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($tickets)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4>No tickets found</h4>
                        <p class="text-muted"><?php echo $search ? 'Try different search terms' : 'Create your first ticket'; ?></p>
                        <a href="create.php" class="btn btn-primary">Create New Ticket</a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Ticket #</th>
                                    <th>Title</th>
                                    <th>Client</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                        <div class="text-muted small"><?php echo htmlspecialchars($ticket['category'] ?? 'General'); ?></div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($ticket['title']); ?></strong>
                                        <div class="text-muted small text-truncate" style="max-width: 250px;">
                                            <?php echo htmlspecialchars(substr($ticket['description'] ?? '', 0, 50)); ?>...
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($ticket['priority']); ?>">
                                            <?php echo htmlspecialchars($ticket['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($ticket['status'])); ?>">
                                            <?php echo htmlspecialchars($ticket['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($ticket['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view.php?id=<?php echo $ticket['id']; ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $ticket['id']; ?>" class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    Previous
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" 
                                   href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    Next
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                    <?php endif; ?>
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
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.remove();
            });
        }, 5000);
    </script>
</body>
</html>