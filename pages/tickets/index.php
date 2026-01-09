<?php
// pages/tickets/index.php - FIXED VERSION

// Include authentication
require_once '../../includes/auth.php';
require_once '../../includes/permissions.php';
require_once '../../includes/routes.php'; // Include routes if route() function is defined there

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
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// ========== BUILD QUERY ==========
$query = "SELECT 
            t.*, 
            c.company_name, 
            sp.full_name as primary_assignee_name, 
            u.email as created_by_email, 
            cl.location_name,
            (SELECT COUNT(*) FROM ticket_assignees ta WHERE ta.ticket_id = t.id) as assignee_count,
            (SELECT SUM(total_hours) FROM work_logs wl WHERE wl.ticket_id = t.id) as actual_hours,
            (SELECT COUNT(*) FROM ticket_attachments ta2 WHERE ta2.ticket_id = t.id AND ta2.is_deleted = false) as attachment_count
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
        $query .= " AND (t.assigned_to = ? OR t.created_by = ? OR 
                EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.staff_id = ?))";
        $params[] = $staff_id;
        $params[] = $current_user['id'];
        $params[] = $staff_id;
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
    if ($assigned_to === 'unassigned') {
        $query .= " AND t.assigned_to IS NULL";
    } else {
        $query .= " AND t.assigned_to = ?";
        $params[] = $assigned_to;
    }
}

if ($client_id && $client_id !== 'all') {
    $query .= " AND t.client_id = ?";
    $params[] = $client_id;
}

if ($category && $category !== 'all') {
    $query .= " AND t.category = ?";
    $params[] = $category;
}

if ($search) {
    $query .= " AND (t.ticket_number ILIKE ? OR t.title ILIKE ? OR c.company_name ILIKE ? OR t.description ILIKE ?)";
    $params[] = "%$search%";
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
    $error = "Error counting tickets: " . $e->getMessage();
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
            CASE 
                WHEN t.sla_breach_time IS NOT NULL AND t.sla_breach_time < NOW() THEN 0
                WHEN t.sla_breach_time IS NOT NULL THEN 1
                ELSE 2
            END,
            t.sla_breach_time ASC,
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
$categories = [];

try {
    // Get staff members for filter - EXACT COPY FROM edit.php
    $staff_members = $pdo->query("
        SELECT 
            COALESCE(sp.id, u.id) as id,
            CASE 
                WHEN sp.full_name IS NOT NULL AND sp.full_name != '' THEN sp.full_name
                ELSE CONCAT(u.email, ' (Profile Incomplete)')
            END as full_name,
            CASE WHEN sp.full_name IS NULL OR sp.full_name = '' THEN 1 ELSE 0 END as needs_profile
        FROM users u
        LEFT JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE u.user_type IN ('super_admin', 'admin', 'manager', 'support_tech', 'staff', 'engineer')
          AND u.is_active = true
          AND (sp.employment_status = 'Active' OR sp.id IS NULL)
        ORDER BY needs_profile ASC, full_name ASC
    ")->fetchAll();
    
    if (isManager() || isAdmin()) {
        $clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
    }
    
    // Get all unique categories
    $categories_result = $pdo->query("SELECT DISTINCT category FROM tickets WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll();
    foreach ($categories_result as $cat) {
        $categories[] = $cat['category'];
    }
} catch (Exception $e) {
    $error = "Error loading filter data: " . $e->getMessage();
}

// Function to calculate SLA status
function getSlaStatus($ticket) {
    if (empty($ticket['sla_breach_time'])) {
        return ['status' => 'no-sla', 'class' => 'secondary', 'text' => 'No SLA'];
    }
    
    $now = new DateTime();
    $breach_time = new DateTime($ticket['sla_breach_time']);
    
    if ($breach_time < $now) {
        return ['status' => 'breached', 'class' => 'danger', 'text' => 'SLA Breached'];
    }
    
    $interval = $now->diff($breach_time);
    $hours_left = $interval->h + ($interval->days * 24);
    
    if ($hours_left < 24) {
        return ['status' => 'critical', 'class' => 'warning', 'text' => 'Due Soon'];
    }
    
    return ['status' => 'on-track', 'class' => 'success', 'text' => 'On Track'];
}

// Function to get priority hours
function getPriorityHours($priority) {
    switch ($priority) {
        case 'Critical': return 2; // 2 hours
        case 'High': return 4; // 4 hours
        case 'Medium': return 24; // 24 hours
        case 'Low': return 72; // 72 hours
        default: return 24;
    }
}

// Function to format time difference
function formatTimeDiff($date1, $date2 = null) {
    if (!$date1) return 'N/A';
    
    if (!($date1 instanceof DateTime)) {
        $date1 = new DateTime($date1);
    }
    
    if (!$date2) {
        $date2 = new DateTime();
    } elseif (!($date2 instanceof DateTime)) {
        $date2 = new DateTime($date2);
    }
    
    $diff = $date1->diff($date2);
    
    if ($diff->days > 0) {
        return $diff->days . 'd ' . $diff->h . 'h';
    } elseif ($diff->h > 0) {
        return $diff->h . 'h ' . $diff->i . 'm';
    } else {
        return $diff->i . 'm';
    }
}

// Get flash message (function is in auth.php)
$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
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
            transition: transform 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
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
            font-size: 24px;
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
        
        .ticket-row {
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .ticket-row:hover {
            background-color: #f8f9fa !important;
            transform: translateX(5px);
        }
        
        .sla-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
        }
        
        .assignee-badge {
            background: #e8f4fd;
            color: #004E89;
            border: 1px solid #004E89;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 3px;
        }
        
        .hours-progress {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .hours-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 3px;
        }
        
        .category-badge {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #dee2e6;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
        }
        
        .attachment-badge {
            background: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
        }
        
        .time-info {
            font-size: 12px;
            color: #666;
        }
        
        .time-info i {
            margin-right: 3px;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 10px;
            }
            
            .table-responsive {
                font-size: 14px;
            }
            
            .badge {
                font-size: 10px;
                padding: 3px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-ticket-alt"></i> Ticket Management</h1>
                <div class="btn-group">
                    <a href="<?php echo route('tickets.create'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Ticket
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?php echo route('tickets.export_bulk'); ?>?type=excel"><i class="fas fa-file-excel text-success"></i> Export as Excel</a></li>
                            <li><a class="dropdown-item" href="<?php echo route('tickets.export_bulk'); ?>?type=csv"><i class="fas fa-file-csv text-primary"></i> Export as CSV</a></li>
                            <li><a class="dropdown-item" href="<?php echo route('tickets.export_bulk'); ?>?type=pdf"><i class="fas fa-file-pdf text-danger"></i> Export as PDF</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php if ($flash): ?>
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
                        <h5><i class="fas fa-clock text-primary"></i> Open Tickets</h5>
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
                        <h5><i class="fas fa-exclamation-triangle text-danger"></i> Critical</h5>
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
                        <h5><i class="fas fa-user-check text-success"></i> My Tickets</h5>
                        <h2>
                            <?php 
                            try {
                                $staff_id = $current_user['staff_profile']['id'] ?? 0;
                                if ($staff_id) {
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status != 'Closed'");
                                    $stmt->execute([$staff_id]);
                                    echo $stmt->fetchColumn();
                                } else {
                                    echo "0";
                                }
                            } catch (Exception $e) {
                                echo "0";
                            }
                            ?>
                        </h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <h5><i class="fas fa-flag text-warning"></i> SLA Breached</h5>
                        <h2>
                            <?php 
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE sla_breach_time IS NOT NULL AND sla_breach_time < NOW() AND status != 'Closed'");
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
                        <label class="form-label"><i class="fas fa-filter"></i> Status</label>
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
                        <label class="form-label"><i class="fas fa-flag"></i> Priority</label>
                        <select name="priority" class="form-select">
                            <option value="all">All Priorities</option>
                            <option value="Critical" <?php echo $priority == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                            <option value="High" <?php echo $priority == 'High' ? 'selected' : ''; ?>>High</option>
                            <option value="Medium" <?php echo $priority == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="Low" <?php echo $priority == 'Low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-tag"></i> Category</label>
                        <select name="category" class="form-select">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" 
                                    <?php echo $category == $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- UPDATED: Assigned To dropdown matching edit.php -->
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-user"></i> Assigned To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="all">All Staff</option>
                            <option value="unassigned" <?php echo $assigned_to == 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            <?php foreach ($staff_members as $staff): ?>
                            <option value="<?php echo htmlspecialchars($staff['id']); ?>" 
                                    <?php echo $assigned_to == $staff['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($staff['full_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($staff_members)): ?>
                        <div class="text-danger small mt-1">
                            <i class="fas fa-exclamation-triangle"></i> 
                            No active staff members found. Please ensure staff profiles are created and marked as 'Active'.
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isManager() || isAdmin()): ?>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-building"></i> Client</label>
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
                        <label class="form-label"><i class="fas fa-search"></i> Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Ticket #, Title, Client, Description..."
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="<?php echo route('tickets.index'); ?>" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                        <span class="text-muted ms-3">
                            <i class="fas fa-info-circle"></i> Showing <?php echo count($tickets); ?> of <?php echo $total; ?> tickets
                        </span>
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
                        <a href="<?php echo route('tickets.create'); ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create New Ticket
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Ticket #</th>
                                    <th>Details</th>
                                    <th>Client & Category</th>
                                    <th>Priority & SLA</th>
                                    <th>Assignees</th>
                                    <th>Time Tracking</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): 
                                    $sla_status = getSlaStatus($ticket);
                                    $assignee_count = $ticket['assignee_count'] ?? 0;
                                    $actual_hours = $ticket['actual_hours'] ?? 0;
                                    $estimated_hours = $ticket['estimated_hours'] ?? 0;
                                    $progress_percent = $estimated_hours > 0 ? min(100, ($actual_hours / $estimated_hours) * 100) : 0;
                                    
                                    // Calculate due date based on priority if no SLA breach time
                                    $due_date = $ticket['sla_breach_time'];
                                    if (!$due_date && $ticket['created_at']) {
                                        $created = new DateTime($ticket['created_at']);
                                        $priority_hours = getPriorityHours($ticket['priority']);
                                        $created->modify("+{$priority_hours} hours");
                                        $due_date = $created->format('Y-m-d H:i:s');
                                    }
                                ?>
                                <tr class="ticket-row" onclick="window.location='<?php echo route('tickets.view', ['id' => $ticket['id']]); ?>'">
                                    <td>
                                        <strong class="d-block"><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                        <div class="time-info">
                                            <i class="fas fa-calendar"></i> 
                                            <?php echo date('M d', strtotime($ticket['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="d-block"><?php echo htmlspecialchars($ticket['title']); ?></strong>
                                        <div class="text-muted small text-truncate" style="max-width: 200px;">
                                            <?php 
                                            $desc = $ticket['description'] ?? '';
                                            echo htmlspecialchars(substr($desc, 0, 50));
                                            if (strlen($desc) > 50) echo '...';
                                            ?>
                                        </div>
                                        <?php if ($ticket['attachment_count'] > 0): ?>
                                        <span class="attachment-badge">
                                            <i class="fas fa-paperclip"></i> <?php echo $ticket['attachment_count']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-block mb-1"><?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?></div>
                                        <?php if ($ticket['category']): ?>
                                        <span class="category-badge">
                                            <?php echo htmlspecialchars($ticket['category']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($ticket['location_name']): ?>
                                        <div class="time-info mt-1">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ticket['location_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <span class="badge badge-<?php echo strtolower($ticket['priority']); ?>">
                                                <?php echo htmlspecialchars($ticket['priority']); ?>
                                            </span>
                                            <span class="badge badge-<?php echo str_replace(' ', '-', strtolower($ticket['status'])); ?>">
                                                <?php echo htmlspecialchars($ticket['status']); ?>
                                            </span>
                                            <?php if ($sla_status['status'] != 'no-sla'): ?>
                                            <span class="sla-badge bg-<?php echo $sla_status['class']; ?>">
                                                <?php echo $sla_status['text']; ?>
                                                <?php if ($due_date): ?>
                                                <br><small><?php echo date('M d H:i', strtotime($due_date)); ?></small>
                                                <?php endif; ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($ticket['primary_assignee_name']): ?>
                                        <div class="mb-1"><?php echo htmlspecialchars($ticket['primary_assignee_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($assignee_count > 0): ?>
                                        <span class="assignee-badge">
                                            <i class="fas fa-users"></i> <?php echo $assignee_count; ?> assignee<?php echo $assignee_count > 1 ? 's' : ''; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted small">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($estimated_hours > 0): ?>
                                        <div class="d-flex justify-content-between small">
                                            <span>Est: <?php echo number_format($estimated_hours, 1); ?>h</span>
                                            <span>Act: <?php echo number_format($actual_hours, 1); ?>h</span>
                                        </div>
                                        <div class="hours-progress">
                                            <div class="hours-progress-bar" style="width: <?php echo $progress_percent; ?>%"></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($ticket['total_work_hours'] > 0): ?>
                                        <div class="time-info">
                                            <i class="fas fa-clock"></i> Total: <?php echo number_format($ticket['total_work_hours'], 1); ?>h
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="time-info">
                                            <i class="fas fa-calendar-plus"></i> 
                                            <?php echo date('M d', strtotime($ticket['created_at'])); ?>
                                        </div>
                                        <div class="time-info">
                                            <i class="fas fa-clock"></i> 
                                            <?php echo date('H:i', strtotime($ticket['created_at'])); ?>
                                        </div>
                                        <?php if ($ticket['updated_at'] != $ticket['created_at']): ?>
                                        <div class="time-info">
                                            <i class="fas fa-sync"></i> Updated 
                                            <?php echo formatTimeDiff($ticket['updated_at']); ?> ago
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                                            <a href="<?php echo route('tickets.view', ['id' => $ticket['id']]); ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (isManager() || isAdmin() || $ticket['created_by'] == $current_user['id']): ?>
                                            <a href="<?php echo route('tickets.edit', ['id' => $ticket['id']]); ?>" class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if (canDeleteTickets()): ?>
                                            <a href="<?php echo route('tickets.delete', ['id' => $ticket['id']]); ?>" class="btn btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
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
                                   href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php 
                            // Show limited pagination
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            if ($start > 1): ?>
                            <li class="page-item">
                                <a class="page-link" 
                                   href="?page=1&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    1
                                </a>
                            </li>
                            <?php if ($start > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" 
                                   href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($end < $total_pages): ?>
                            <?php if ($end < $total_pages - 1): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" 
                                   href="?page=<?php echo $total_pages; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    <?php echo $total_pages; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&priority=<?php echo $priority; ?>&category=<?php echo $category; ?>&assigned_to=<?php echo $assigned_to; ?>&client_id=<?php echo $client_id; ?>&search=<?php echo urlencode($search); ?>">
                                    <i class="fas fa-chevron-right"></i>
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
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.remove();
            });
        }, 5000);
        
        // Make entire row clickable
        document.querySelectorAll('.ticket-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Don't trigger if clicking on buttons or links
                if (!e.target.closest('a') && !e.target.closest('button')) {
                    window.location = this.querySelector('a.btn-info').href;
                }
            });
        });
    </script>
</body>
</html>