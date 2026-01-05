<?php
require_once '../../includes/auth.php';
requireLogin();

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Check permissions - managers/admins can see all, techs see assigned
$can_manage = hasPermission('manager') || hasPermission('admin');

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$filters = [];
$params = [];

// Service filter
$where = "WHERE 1=1";
if (!empty($_GET['service_id'])) {
    $where .= " AND sr.client_service_id = ?";
    $params[] = $_GET['service_id'];
    $filters['service_id'] = $_GET['service_id'];
}

// Date range filter
if (!empty($_GET['date_from'])) {
    $where .= " AND DATE(sr.renewed_at) >= ?";
    $params[] = $_GET['date_from'];
    $filters['date_from'] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
    $where .= " AND DATE(sr.renewed_at) <= ?";
    $params[] = $_GET['date_to'];
    $filters['date_to'] = $_GET['date_to'];
}

// Status filter
if (!empty($_GET['status'])) {
    $where .= " AND sr.status = ?";
    $params[] = $_GET['status'];
    $filters['status'] = $_GET['status'];
}

// For non-admins, show only renewals for assigned clients
if (!$can_manage && !hasPermission('super_admin')) {
    // Get assigned clients for the staff member
    $staff_id = $current_user['staff_profile']['id'] ?? null;
    if ($staff_id) {
        $assigned_clients_sql = "SELECT client_id FROM ticket_assignees WHERE staff_id = ?";
        $assigned_stmt = $pdo->prepare($assigned_clients_sql);
        $assigned_stmt->execute([$staff_id]);
        $assigned_client_ids = $assigned_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($assigned_client_ids)) {
            $placeholders = implode(',', array_fill(0, count($assigned_client_ids), '?'));
            $where .= " AND cs.client_id IN ($placeholders)";
            $params = array_merge($params, $assigned_client_ids);
        } else {
            // If no assigned clients, show none
            $where .= " AND 1=0";
        }
    } else {
        // If no staff profile, show none
        $where .= " AND 1=0";
    }
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM service_renewals sr
              LEFT JOIN client_services cs ON sr.client_service_id = cs.id
              LEFT JOIN clients c ON cs.client_id = c.id
              $where";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_count = $count_stmt->fetchColumn();
$total_pages = ceil($total_count / $limit);

// Get renewals with pagination
$sql = "SELECT sr.*, 
               cs.service_name,
               cs.domain_name,
               cs.service_category,
               cs.expiry_date as service_expiry,
               c.company_name,
               c.contact_person,
               c.email as client_email,
               u.email as renewed_by_email,
               sp.full_name as renewed_by_name
        FROM service_renewals sr
        LEFT JOIN client_services cs ON sr.client_service_id = cs.id
        LEFT JOIN clients c ON cs.client_id = c.id
        LEFT JOIN users u ON sr.renewed_by = u.id
        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
        $where
        ORDER BY sr.renewed_at DESC
        LIMIT ? OFFSET ?";

$params_with_pagination = array_merge($params, [$limit, $offset]);
$stmt = $pdo->prepare($sql);
$stmt->execute($params_with_pagination);
$renewals = $stmt->fetchAll();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(sr.amount) as total_amount,
    COUNT(CASE WHEN sr.status = 'Completed' THEN 1 END) as completed,
    COUNT(CASE WHEN sr.status = 'Pending' THEN 1 END) as pending,
    COUNT(CASE WHEN sr.status = 'Failed' THEN 1 END) as failed,
    COUNT(CASE WHEN sr.renewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as last_30_days
    FROM service_renewals sr";
    
if (!$can_manage && !hasPermission('super_admin') && isset($staff_id) && !empty($assigned_client_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_client_ids), '?'));
    $stats_sql .= " LEFT JOIN client_services cs ON sr.client_service_id = cs.id
                    WHERE cs.client_id IN ($placeholders)";
    $stats_params = $assigned_client_ids;
} else {
    $stats_params = [];
}

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();

// Get services for filter dropdown
$services_sql = "SELECT cs.id, cs.service_name, c.company_name 
                 FROM client_services cs
                 LEFT JOIN clients c ON cs.client_id = c.id
                 WHERE cs.status = 'Active'
                 ORDER BY c.company_name, cs.service_name";
$services = $pdo->query($services_sql)->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Renewals | MSP Application</title>
    <link rel="stylesheet" href="../../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .renewal-card {
            border-left: 4px solid;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .renewal-card.completed { border-color: #28a745; }
        .renewal-card.pending { border-color: #ffc107; }
        .renewal-card.failed { border-color: #dc3545; }
        
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        .stat-card.total { background: linear-gradient(135deg, #004E89, #0066CC); }
        .stat-card.amount { background: linear-gradient(135deg, #28a745, #20c997); }
        .stat-card.pending { background: linear-gradient(135deg, #ffc107, #fd7e14); }
        .stat-card.recent { background: linear-gradient(135deg, #6f42c1, #9b59b6); }
        
        .table th {
            background: #004E89;
            color: white;
            border: none;
        }
        
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-failed { background: #f8d7da; color: #721c24; }
        
        .action-buttons .btn {
            padding: 2px 8px;
            font-size: 0.875rem;
        }
        
        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .stat-card {
                margin-bottom: 15px;
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
            <!-- Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-sync"></i> Service Renewals</h1>
                    <p class="text-muted">Manage and track service renewals</p>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($current_user['staff_profile']['designation'] ?? ucfirst($current_user['user_type'])); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="index.php">Services Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Service Renewals</li>
                </ol>
            </nav>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card total">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['total'] ?? 0; ?></h2>
                                <p class="mb-0">Total Renewals</p>
                            </div>
                            <i class="fas fa-sync fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card amount">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0">$<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></h2>
                                <p class="mb-0">Total Amount</p>
                            </div>
                            <i class="fas fa-dollar-sign fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card pending">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['pending'] ?? 0; ?></h2>
                                <p class="mb-0">Pending</p>
                            </div>
                            <i class="fas fa-clock fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card recent">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['last_30_days'] ?? 0; ?></h2>
                                <p class="mb-0">Last 30 Days</p>
                            </div>
                            <i class="fas fa-calendar-alt fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions mb-4">
                <div class="row">
                    <div class="col">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="index.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left"></i> Back to Services
                            </a>
                            <a href="export_renewals.php" class="btn btn-success">
                                <i class="fas fa-file-export"></i> Export Renewals
                            </a>
                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkRenewModal">
                                <i class="fas fa-tasks"></i> Bulk Renew
                            </button>
                            <a href="renewal_reports.php" class="btn btn-secondary">
                                <i class="fas fa-chart-bar"></i> Renewal Reports
                            </a>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fas fa-filter"></i> Quick Filters
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?status=Completed"><i class="fas fa-check-circle text-success"></i> Completed</a></li>
                                    <li><a class="dropdown-item" href="?status=Pending"><i class="fas fa-clock text-warning"></i> Pending</a></li>
                                    <li><a class="dropdown-item" href="?status=Failed"><i class="fas fa-times-circle text-danger"></i> Failed</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="?date_from=<?php echo date('Y-m-01'); ?>"><i class="fas fa-calendar"></i> This Month</a></li>
                                    <li><a class="dropdown-item" href="?date_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>"><i class="fas fa-calendar-alt"></i> Last 30 Days</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters Section -->
            <div class="filter-section">
                <form method="GET" id="filter-form">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Service</label>
                            <select class="form-select select2" name="service_id" style="width: 100%;">
                                <option value="">All Services</option>
                                <?php foreach ($services as $service): ?>
                                <option value="<?php echo htmlspecialchars($service['id']); ?>" 
                                    <?php echo isset($_GET['service_id']) && $_GET['service_id'] == $service['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($service['company_name'] . ' - ' . $service['service_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="Completed" <?php echo isset($_GET['status']) && $_GET['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Pending" <?php echo isset($_GET['status']) && $_GET['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Failed" <?php echo isset($_GET['status']) && $_GET['status'] == 'Failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" 
                                   value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" 
                                   value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="renewals.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo"></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Renewals Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Renewal History</h5>
                    <small class="text-muted"><?php echo $total_count; ?> renewals found</small>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Renewal ID</th>
                                <th>Service</th>
                                <th>Client</th>
                                <th>Amount</th>
                                <th>Period</th>
                                <th>Renewed Date</th>
                                <th>Next Expiry</th>
                                <th>Status</th>
                                <th>Renewed By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($renewals as $renewal): 
                                $status_class = '';
                                switch ($renewal['status']) {
                                    case 'Completed': $status_class = 'badge-completed'; break;
                                    case 'Pending': $status_class = 'badge-pending'; break;
                                    case 'Failed': $status_class = 'badge-failed'; break;
                                }
                            ?>
                            <tr>
                                <td>
                                    <small class="text-muted">#REN-<?php echo str_pad($renewal['id'], 6, '0', STR_PAD_LEFT); ?></small>
                                </td>
                                <td>
                                    <div>
                                        <strong><?php echo htmlspecialchars($renewal['service_name']); ?></strong>
                                        <?php if ($renewal['domain_name']): ?>
                                        <div><small class="text-muted"><?php echo htmlspecialchars($renewal['domain_name']); ?></small></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($renewal['company_name']): ?>
                                    <div><?php echo htmlspecialchars($renewal['company_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($renewal['contact_person'] ?? ''); ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>$<?php echo number_format($renewal['amount'], 2); ?></strong>
                                </td>
                                <td>
                                    <?php echo $renewal['renewal_period'] ?? 1; ?> Year(s)
                                </td>
                                <td>
                                    <?php echo $renewal['renewed_at'] ? date('M d, Y', strtotime($renewal['renewed_at'])) : 'N/A'; ?>
                                    <div><small class="text-muted"><?php echo $renewal['renewed_at'] ? date('h:i A', strtotime($renewal['renewed_at'])) : ''; ?></small></div>
                                </td>
                                <td>
                                    <?php 
                                    $next_expiry = $renewal['service_expiry'];
                                    if ($next_expiry) {
                                        echo date('M d, Y', strtotime($next_expiry));
                                        $days_left = floor((strtotime($next_expiry) - time()) / (60 * 60 * 24));
                                        if ($days_left < 0) {
                                            echo '<div><small class="text-danger">Expired ' . abs($days_left) . ' days ago</small></div>';
                                        } elseif ($days_left <= 30) {
                                            echo '<div><small class="text-warning">' . $days_left . ' days left</small></div>';
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($renewal['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($renewal['renewed_by_name'] ?? $renewal['renewed_by_email'] ?? 'System'); ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary view-renewal-btn" 
                                                    data-id="<?php echo htmlspecialchars($renewal['id']); ?>"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($can_manage && $renewal['status'] == 'Pending'): ?>
                                            <button type="button" class="btn btn-outline-success complete-renewal-btn" 
                                                    data-id="<?php echo htmlspecialchars($renewal['id']); ?>"
                                                    title="Mark Complete">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning edit-renewal-btn" 
                                                    data-id="<?php echo htmlspecialchars($renewal['id']); ?>"
                                                    title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($can_manage): ?>
                                            <button type="button" class="btn btn-outline-danger delete-renewal-btn" 
                                                    data-id="<?php echo htmlspecialchars($renewal['id']); ?>"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($renewals)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No renewals found</h5>
                                        <p>No renewal history available for the selected filters.</p>
                                        <a href="index.php" class="btn btn-primary">
                                            <i class="fas fa-arrow-left"></i> Back to Services
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            
                            for ($i = $start; $i <= $end; $i++) {
                            ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php } ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Renewal Details Modal -->
    <div class="modal fade" id="viewRenewalModal" tabindex="-1" aria-labelledby="viewRenewalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewRenewalModalLabel"><i class="fas fa-eye"></i> Renewal Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="renewalDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="printRenewalBtn">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Renewal Modal -->
    <div class="modal fade" id="editRenewalModal" tabindex="-1" aria-labelledby="editRenewalModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRenewalModalLabel"><i class="fas fa-edit"></i> Edit Renewal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editRenewalForm">
                    <div class="modal-body">
                        <input type="hidden" id="editRenewalId" name="id">
                        <div class="mb-3">
                            <label for="editAmount" class="form-label">Amount ($)</label>
                            <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="editPeriod" class="form-label">Renewal Period (Years)</label>
                            <input type="number" class="form-control" id="editPeriod" name="renewal_period" min="1" max="10" required>
                        </div>
                        <div class="mb-3">
                            <label for="editStatus" class="form-label">Status</label>
                            <select class="form-select" id="editStatus" name="status" required>
                                <option value="Completed">Completed</option>
                                <option value="Pending">Pending</option>
                                <option value="Failed">Failed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="editNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Renew Modal -->
    <div class="modal fade" id="bulkRenewModal" tabindex="-1" aria-labelledby="bulkRenewModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkRenewModalLabel"><i class="fas fa-tasks"></i> Bulk Renew Services</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> This will create renewal records for selected services that are expiring soon.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Services Expiring Within</label>
                        <select class="form-select" id="bulkExpiryDays">
                            <option value="30">30 days</option>
                            <option value="60">60 days</option>
                            <option value="90">90 days</option>
                            <option value="180">180 days</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Renewal Period</label>
                        <select class="form-select" id="bulkDefaultPeriod">
                            <option value="1">1 Year</option>
                            <option value="2">2 Years</option>
                            <option value="3">3 Years</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Default Notes</label>
                        <textarea class="form-control" id="bulkDefaultNotes" rows="2" placeholder="Optional notes for all renewals"></textarea>
                    </div>
                    <div id="bulkServicesList" class="mb-3" style="max-height: 200px; overflow-y: auto;">
                        <!-- Services will be listed here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="processBulkRenew">Process Renewals</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                width: '100%',
                placeholder: 'Select...'
            });
            
            // View renewal details
            $('.view-renewal-btn').on('click', function() {
                const renewalId = $(this).data('id');
                
                $.ajax({
                    url: 'get_renewal_details.php',
                    method: 'GET',
                    data: { id: renewalId },
                    success: function(response) {
                        $('#renewalDetailsContent').html(response);
                        const viewModal = new bootstrap.Modal(document.getElementById('viewRenewalModal'));
                        viewModal.show();
                    },
                    error: function() {
                        showToast('Error loading renewal details', 'error');
                    }
                });
            });
            
            // Edit renewal
            $('.edit-renewal-btn').on('click', function() {
                const renewalId = $(this).data('id');
                
                $.ajax({
                    url: 'get_renewal.php',
                    method: 'GET',
                    data: { id: renewalId },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            $('#editRenewalId').val(data.renewal.id);
                            $('#editAmount').val(data.renewal.amount);
                            $('#editPeriod').val(data.renewal.renewal_period || 1);
                            $('#editStatus').val(data.renewal.status);
                            $('#editNotes').val(data.renewal.notes || '');
                            
                            const editModal = new bootstrap.Modal(document.getElementById('editRenewalModal'));
                            editModal.show();
                        } else {
                            showToast(data.message || 'Error loading renewal', 'error');
                        }
                    },
                    error: function() {
                        showToast('Error loading renewal', 'error');
                    }
                });
            });
            
            // Save edited renewal
            $('#editRenewalForm').on('submit', function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize();
                
                $.ajax({
                    url: 'update_renewal.php',
                    method: 'POST',
                    data: formData,
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            showToast('Renewal updated successfully', 'success');
                            bootstrap.Modal.getInstance(document.getElementById('editRenewalModal')).hide();
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(data.message || 'Error updating renewal', 'error');
                        }
                    },
                    error: function() {
                        showToast('Error updating renewal', 'error');
                    }
                });
            });
            
            // Mark renewal as complete
            $('.complete-renewal-btn').on('click', function() {
                const renewalId = $(this).data('id');
                
                if (confirm('Mark this renewal as complete?')) {
                    $.ajax({
                        url: 'complete_renewal.php',
                        method: 'POST',
                        data: { id: renewalId },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                showToast('Renewal marked as complete', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                showToast(data.message || 'Error completing renewal', 'error');
                            }
                        },
                        error: function() {
                            showToast('Error completing renewal', 'error');
                        }
                    });
                }
            });
            
            // Delete renewal
            $('.delete-renewal-btn').on('click', function() {
                const renewalId = $(this).data('id');
                
                if (confirm('Are you sure you want to delete this renewal record? This action cannot be undone.')) {
                    $.ajax({
                        url: 'delete_renewal.php',
                        method: 'POST',
                        data: { id: renewalId },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                showToast('Renewal deleted successfully', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                showToast(data.message || 'Error deleting renewal', 'error');
                            }
                        },
                        error: function() {
                            showToast('Error deleting renewal', 'error');
                        }
                    });
                }
            });
            
            // Print renewal details
            $('#printRenewalBtn').on('click', function() {
                window.print();
            });
            
            // Load services for bulk renew modal
            $('#bulkRenewModal').on('show.bs.modal', function() {
                const days = $('#bulkExpiryDays').val();
                
                $.ajax({
                    url: 'get_expiring_services.php',
                    method: 'GET',
                    data: { days: days },
                    success: function(response) {
                        const data = JSON.parse(response);
                        let servicesHtml = '';
                        
                        if (data.services && data.services.length > 0) {
                            servicesHtml = '<div class="list-group">';
                            data.services.forEach(function(service) {
                                servicesHtml += `
                                    <div class="list-group-item">
                                        <div class="form-check">
                                            <input class="form-check-input service-select" type="checkbox" value="${service.id}" id="service_${service.id}" checked>
                                            <label class="form-check-label" for="service_${service.id}">
                                                <strong>${service.service_name}</strong><br>
                                                <small class="text-muted">${service.company_name} - Expires: ${service.expiry_date}</small>
                                            </label>
                                        </div>
                                    </div>
                                `;
                            });
                            servicesHtml += '</div>';
                            $('#bulkServicesList').html(servicesHtml);
                        } else {
                            $('#bulkServicesList').html('<div class="alert alert-warning">No services expiring within selected period.</div>');
                            $('#processBulkRenew').prop('disabled', true);
                        }
                    },
                    error: function() {
                        showToast('Error loading services', 'error');
                    }
                });
            });
            
            // Update services list when expiry days change
            $('#bulkExpiryDays').on('change', function() {
                $('#bulkRenewModal').trigger('show.bs.modal');
            });
            
            // Process bulk renew
            $('#processBulkRenew').on('click', function() {
                const selectedServices = [];
                $('.service-select:checked').each(function() {
                    selectedServices.push($(this).val());
                });
                
                if (selectedServices.length === 0) {
                    showToast('Please select at least one service', 'warning');
                    return;
                }
                
                const period = $('#bulkDefaultPeriod').val();
                const notes = $('#bulkDefaultNotes').val();
                
                if (confirm(`Create renewal records for ${selectedServices.length} service(s)?`)) {
                    $.ajax({
                        url: 'bulk_renew.php',
                        method: 'POST',
                        data: {
                            service_ids: selectedServices,
                            renewal_period: period,
                            notes: notes
                        },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                showToast(`Created ${data.count} renewal record(s)`, 'success');
                                bootstrap.Modal.getInstance(document.getElementById('bulkRenewModal')).hide();
                                setTimeout(() => {
                                    location.reload();
                                }, 2000);
                            } else {
                                showToast(data.message || 'Error creating renewals', 'error');
                            }
                        },
                        error: function() {
                            showToast('Error creating renewals', 'error');
                        }
                    });
                }
            });
            
            // Toast notification function
            function showToast(message, type = 'info') {
                const typeClass = {
                    'success': 'bg-success',
                    'error': 'bg-danger',
                    'warning': 'bg-warning',
                    'info': 'bg-info'
                }[type] || 'bg-info';
                
                const icon = {
                    'success': 'fa-check-circle',
                    'error': 'fa-exclamation-circle',
                    'warning': 'fa-exclamation-triangle',
                    'info': 'fa-info-circle'
                }[type] || 'fa-info-circle';
                
                const toast = $(`
                    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050">
                        <div class="toast show" role="alert">
                            <div class="toast-header ${typeClass} text-white">
                                <i class="fas ${icon} me-2"></i>
                                <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                            </div>
                            <div class="toast-body">
                                ${message}
                            </div>
                        </div>
                    </div>
                `);
                $('body').append(toast);
                setTimeout(() => toast.remove(), 5000);
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                $('.alert').fadeOut('slow');
            }, 5000);
        });
    </script>
</body>
</html>