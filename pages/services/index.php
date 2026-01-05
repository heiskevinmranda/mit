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
$where = "WHERE cs.status != 'Deleted'";

// Search
if (!empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where .= " AND (cs.service_name ILIKE ? OR cs.domain_name ILIKE ? OR c.company_name ILIKE ?)";
    $params = array_merge($params, [$search, $search, $search]);
    $filters['search'] = $_GET['search'];
}

// Status filter
if (!empty($_GET['status']) && $_GET['status'] != 'all') {
    $where .= " AND cs.status = ?";
    $params[] = $_GET['status'];
    $filters['status'] = $_GET['status'];
}

// Category filter
if (!empty($_GET['category']) && $_GET['category'] != 'all') {
    $where .= " AND cs.service_category = ?";
    $params[] = $_GET['category'];
    $filters['category'] = $_GET['category'];
}

// Client filter
if (!empty($_GET['client_id'])) {
    $where .= " AND cs.client_id = ?";
    $params[] = $_GET['client_id'];
    $filters['client_id'] = $_GET['client_id'];
}

// Expiry filter
if (!empty($_GET['expiry'])) {
    switch ($_GET['expiry']) {
        case 'expiring':
            $where .= " AND cs.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'";
            break;
        case 'expired':
            $where .= " AND cs.expiry_date < CURRENT_DATE";
            break;
        case 'renewed':
            $where .= " AND cs.renewal_date > CURRENT_DATE";
            break;
    }
    $filters['expiry'] = $_GET['expiry'];
}

// Auto-renew filter
if (isset($_GET['auto_renew'])) {
    $auto_renew = $_GET['auto_renew'] == '1' ? 1 : 0;
    $where .= " AND cs.auto_renew = ?";
    $params[] = $auto_renew;
    $filters['auto_renew'] = $auto_renew;
}

// For non-admins, show only assigned clients
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
$count_sql = "SELECT COUNT(*) FROM client_services cs
              LEFT JOIN clients c ON cs.client_id = c.id
              $where";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_count = $count_stmt->fetchColumn();
$total_pages = ceil($total_count / $limit);

// Get services with pagination
$sql = "SELECT cs.*, 
               c.company_name,
               c.contact_person,
               c.email as client_email,
               c.phone as client_phone,
               (cs.expiry_date - CURRENT_DATE) as days_until_expiry,
               (SELECT COUNT(*) FROM service_renewals sr WHERE sr.client_service_id = cs.id) as renewal_count
        FROM client_services cs
        LEFT JOIN clients c ON cs.client_id = c.id
        $where
        ORDER BY cs.expiry_date ASC, cs.created_at DESC
        LIMIT ? OFFSET ?";

$params_with_pagination = array_merge($params, [$limit, $offset]);
$stmt = $pdo->prepare($sql);
$stmt->execute($params_with_pagination);
$services = $stmt->fetchAll();

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN status = 'Active' THEN 1 END) as active,
    COUNT(CASE WHEN status = 'Expired' THEN 1 END) as expired,
    COUNT(CASE WHEN status = 'Suspended' THEN 1 END) as suspended,
    COUNT(CASE WHEN auto_renew = true THEN 1 END) as auto_renew,
    COUNT(CASE WHEN expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' THEN 1 END) as expiring_soon,
    COUNT(CASE WHEN expiry_date < CURRENT_DATE THEN 1 END) as already_expired
    FROM client_services cs";
    
if (!$can_manage && !hasPermission('super_admin') && isset($staff_id) && !empty($assigned_client_ids)) {
    $placeholders = implode(',', array_fill(0, count($assigned_client_ids), '?'));
    $stats_sql .= " WHERE cs.client_id IN ($placeholders)";
    $stats_params = $assigned_client_ids;
} else {
    $stats_params = [];
}

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch();

// Get distinct categories and statuses for filters
$categories = $pdo->query("SELECT DISTINCT service_category FROM client_services WHERE service_category IS NOT NULL ORDER BY service_category")->fetchAll(PDO::FETCH_COLUMN);
$statuses = $pdo->query("SELECT DISTINCT status FROM client_services WHERE status IS NOT NULL ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$clients = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'Active' ORDER BY company_name")->fetchAll();

// Get expiring soon for alerts
$expiring_alerts = $pdo->query("
    SELECT cs.*, c.company_name, 
           (cs.expiry_date - CURRENT_DATE) as days_left
    FROM client_services cs
    JOIN clients c ON cs.client_id = c.id
    WHERE cs.status = 'Active'
    AND cs.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
    ORDER BY cs.expiry_date ASC
    LIMIT 10
")->fetchAll();

// Get recently expired
$expired_alerts = $pdo->query("
    SELECT cs.*, c.company_name,
           (CURRENT_DATE - cs.expiry_date) as days_expired
    FROM client_services cs
    JOIN clients c ON cs.client_id = c.id
    WHERE cs.status = 'Active'
    AND cs.expiry_date < CURRENT_DATE
    ORDER BY cs.expiry_date DESC
    LIMIT 10
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Management | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .service-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #eaeaea;
            border-radius: 10px;
            overflow: hidden;
        }
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .service-header {
            padding: 15px;
            color: white;
            display: flex;
            align-items: center;
        }
        .service-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.5rem;
            background: rgba(255,255,255,0.2);
        }
        .expiry-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.8rem;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 20px;
        }
        .category-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-right: 5px;
        }
        .badge-domain { background: #28a745; color: white; }
        .badge-hosting { background: #007bff; color: white; }
        .badge-email { background: #dc3545; color: white; }
        .badge-security { background: #ffc107; color: black; }
        .badge-subscription { background: #6f42c1; color: white; }
        .badge-other { background: #6c757d; color: white; }
        
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        .stat-card.total { background: linear-gradient(135deg, #004E89, #0066CC); }
        .stat-card.active { background: linear-gradient(135deg, #28a745, #20c997); }
        .stat-card.expiring { background: linear-gradient(135deg, #ffc107, #fd7e14); }
        .stat-card.expired { background: linear-gradient(135deg, #dc3545, #c82333); }
        
        .alert-card {
            border-left: 4px solid;
            margin-bottom: 15px;
        }
        .alert-card.expiring { border-color: #ffc107; }
        .alert-card.expired { border-color: #dc3545; }
        
        .action-buttons {
            opacity: 0;
            transition: opacity 0.2s;
        }
        .service-card:hover .action-buttons {
            opacity: 1;
        }
        
        .quick-actions {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .search-box {
            position: relative;
        }
        .search-box .form-control {
            padding-left: 40px;
        }
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .days-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .days-badge.warning { background: #ffc107; color: black; }
        .days-badge.danger { background: #dc3545; color: white; }
        .days-badge.success { background: #28a745; color: white; }
        
        .bulk-actions {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .service-card {
                margin-bottom: 20px;
            }
            .stat-card {
                margin-bottom: 15px;
            }
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .table th {
            background: #004E89;
            color: white;
            border: none;
            font-weight: 500;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .select2-container--default .select2-selection--single {
            border: 1px solid #ced4da;
            height: calc(1.5em + .75rem + 2px);
            border-radius: .25rem;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(1.5em + .75rem);
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 5px 10px;
            border-radius: 5px;
            margin: 0 2px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #004E89;
            color: white !important;
            border: none;
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
                    <h1><i class="fas fa-concierge-bell"></i> Services Management</h1>
                    <p class="text-muted">Manage domains, hosting, email, and security services for clients</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Services Management</li>
                </ol>
            </nav>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card total">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['total'] ?? 0; ?></h2>
                                <p class="mb-0">Total Services</p>
                            </div>
                            <i class="fas fa-concierge-bell fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card active">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['active'] ?? 0; ?></h2>
                                <p class="mb-0">Active Services</p>
                            </div>
                            <i class="fas fa-check-circle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card expiring">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['expiring_soon'] ?? 0; ?></h2>
                                <p class="mb-0">Expiring Soon</p>
                            </div>
                            <i class="fas fa-clock fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card expired">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $stats['already_expired'] ?? 0; ?></h2>
                                <p class="mb-0">Expired</p>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alerts Section -->
            <?php if (!empty($expiring_alerts) || !empty($expired_alerts)): ?>
            <div class="row mb-4">
                <?php if (!empty($expiring_alerts)): ?>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="fas fa-clock"></i> Services Expiring Soon</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($expiring_alerts as $alert): ?>
                                <div class="list-group-item alert-card expiring">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($alert['service_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($alert['company_name']); ?></small>
                                            <?php if ($alert['domain_name']): ?>
                                            <div><small class="text-muted"><?php echo htmlspecialchars($alert['domain_name']); ?></small></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-warning"><?php echo $alert['days_left']; ?> days</span>
                                            <div><small><?php echo date('M d, Y', strtotime($alert['expiry_date'])); ?></small></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="?expiry=expiring" class="btn btn-sm btn-warning">View All Expiring</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($expired_alerts)): ?>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Recently Expired</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($expired_alerts as $alert): ?>
                                <div class="list-group-item alert-card expired">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($alert['service_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($alert['company_name']); ?></small>
                                            <?php if ($alert['domain_name']): ?>
                                            <div><small class="text-muted"><?php echo htmlspecialchars($alert['domain_name']); ?></small></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-danger">Expired <?php echo $alert['days_expired']; ?> days ago</span>
                                            <div><small><?php echo date('M d, Y', strtotime($alert['expiry_date'])); ?></small></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="?expiry=expired" class="btn btn-sm btn-danger">View All Expired</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="quick-actions mb-4">
                <div class="row">
                    <div class="col">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="create.php" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Add New Service
                            </a>
                            <a href="renewals.php" class="btn btn-warning">
                                <i class="fas fa-sync"></i> Manage Renewals
                            </a>
                            <a href="export.php" class="btn btn-success">
                                <i class="fas fa-file-export"></i> Export Services
                            </a>
                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkActionsModal">
                                <i class="fas fa-tasks"></i> Bulk Actions
                            </button>
                            <?php if ($can_manage): ?>
                            <a href="catalog.php" class="btn btn-secondary">
                                <i class="fas fa-list-alt"></i> Service Catalog
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fas fa-filter"></i> Quick Filters
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?status=Active"><i class="fas fa-check-circle text-success"></i> Active Only</a></li>
                                    <li><a class="dropdown-item" href="?auto_renew=1"><i class="fas fa-sync text-info"></i> Auto-renew</a></li>
                                    <li><a class="dropdown-item" href="?expiry=expiring"><i class="fas fa-clock text-warning"></i> Expiring Soon</a></li>
                                    <li><a class="dropdown-item" href="?expiry=expired"><i class="fas fa-exclamation-triangle text-danger"></i> Expired</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php foreach ($categories as $category): ?>
                                    <li><a class="dropdown-item" href="?category=<?php echo urlencode($category); ?>">
                                        <span class="category-badge badge-<?php echo strtolower($category); ?>"><?php echo substr($category, 0, 1); ?></span>
                                        <?php echo htmlspecialchars($category); ?> Services
                                    </a></li>
                                    <?php endforeach; ?>
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
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" class="form-control" name="search" 
                                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" 
                                       placeholder="Search services, domains, or clients...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all">All Status</option>
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" 
                                    <?php echo isset($_GET['status']) && $_GET['status'] == $status ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                    <?php echo isset($_GET['category']) && $_GET['category'] == $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Client</label>
                            <select class="form-select select2" name="client_id" style="width: 100%;">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $client): ?>
                                <option value="<?php echo htmlspecialchars($client['id']); ?>" 
                                    <?php echo isset($_GET['client_id']) && $_GET['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['company_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Expiry</label>
                            <select class="form-select" name="expiry">
                                <option value="">All</option>
                                <option value="expiring" <?php echo isset($_GET['expiry']) && $_GET['expiry'] == 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
                                <option value="expired" <?php echo isset($_GET['expiry']) && $_GET['expiry'] == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="renewed" <?php echo isset($_GET['expiry']) && $_GET['expiry'] == 'renewed' ? 'selected' : ''; ?>>Recently Renewed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Auto-renew</label>
                            <select class="form-select" name="auto_renew">
                                <option value="">All</option>
                                <option value="1" <?php echo isset($_GET['auto_renew']) && $_GET['auto_renew'] == '1' ? 'selected' : ''; ?>>Auto-renew ON</option>
                                <option value="0" <?php echo isset($_GET['auto_renew']) && $_GET['auto_renew'] == '0' ? 'selected' : ''; ?>>Auto-renew OFF</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo"></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Services Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Services List</h5>
                    <div class="d-flex align-items-center">
                        <small class="text-muted me-3"><?php echo $total_count; ?> services found</small>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleView">
                                <i class="fas fa-table"></i> Table View
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="toggleCardView">
                                <i class="fas fa-th-large"></i> Card View
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Table View (Hidden by default) -->
                <div class="table-responsive d-none" id="tableView">
                    <table class="table table-hover mb-0" id="servicesTable">
                        <thead>
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th>Service</th>
                                <th>Client</th>
                                <th>Category</th>
                                <th>Domain/Details</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Price</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): 
                                $category_class = 'badge-' . strtolower($service['service_category'] ?? 'other');
                                $days_left = $service['days_until_expiry'] ?? 0;
                                
                                // Determine days badge class
                                if ($days_left < 0) {
                                    $days_class = 'danger';
                                    $days_text = 'Expired ' . abs($days_left) . ' days ago';
                                } elseif ($days_left <= 7) {
                                    $days_class = 'danger';
                                    $days_text = $days_left . ' days';
                                } elseif ($days_left <= 30) {
                                    $days_class = 'warning';
                                    $days_text = $days_left . ' days';
                                } else {
                                    $days_class = 'success';
                                    $days_text = $days_left . ' days';
                                }
                                
                                // Determine status class
                                $status_class = '';
                                switch ($service['status']) {
                                    case 'Active': $status_class = 'success'; break;
                                    case 'Pending': $status_class = 'warning'; break;
                                    case 'Suspended': $status_class = 'danger'; break;
                                    case 'Cancelled': $status_class = 'secondary'; break;
                                    case 'Expired': $status_class = 'dark'; break;
                                }
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="service-checkbox" value="<?php echo htmlspecialchars($service['id']); ?>">
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="service-icon me-2" 
                                             style="width: 30px; height: 30px; font-size: 0.9rem; background: #<?php echo substr(md5($service['service_category'] ?? 'other'), 0, 6); ?>; color: white;">
                                            <i class="fas fa-concierge-bell"></i>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($service['service_name']); ?></strong>
                                            <?php if ($service['auto_renew']): ?>
                                            <small class="d-block"><i class="fas fa-sync text-info"></i> Auto-renew</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($service['company_name']): ?>
                                    <div><strong><?php echo htmlspecialchars($service['company_name']); ?></strong></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($service['contact_person'] ?? ''); ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">No client</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="category-badge <?php echo $category_class; ?>">
                                        <?php echo htmlspecialchars($service['service_category'] ?? 'Other'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($service['domain_name']): ?>
                                    <div><strong><?php echo htmlspecialchars($service['domain_name']); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($service['hosting_plan']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($service['hosting_plan']); ?></small>
                                    <?php elseif ($service['email_type']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($service['email_type']); ?> (<?php echo $service['email_accounts'] ?? 1; ?> accounts)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo $service['expiry_date'] ? date('M d, Y', strtotime($service['expiry_date'])) : 'N/A'; ?></div>
                                    <?php if ($service['expiry_date']): ?>
                                    <span class="days-badge <?php echo $days_class; ?>"><?php echo $days_text; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $status_class; ?> status-badge">
                                        <?php echo htmlspecialchars($service['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div>$<?php echo number_format($service['monthly_price'] ?? 0, 2); ?>/mo</div>
                                    <small class="text-muted"><?php echo htmlspecialchars($service['billing_cycle'] ?? 'Monthly'); ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <div class="btn-group btn-group-sm">
                                            <a href="view.php?id=<?php echo urlencode($service['id']); ?>" class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($can_manage): ?>
                                            <a href="edit.php?id=<?php echo urlencode($service['id']); ?>" class="btn btn-outline-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-success renew-btn" 
                                                    data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                    data-name="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                    title="Renew">
                                                <i class="fas fa-sync"></i>
                                            </button>
                                            <?php endif; ?>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" 
                                                        data-bs-toggle="dropdown" title="More">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item" href="view.php?id=<?php echo urlencode($service['id']); ?>">
                                                            <i class="fas fa-eye"></i> View Details
                                                        </a>
                                                    </li>
                                                    <?php if ($can_manage): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="edit.php?id=<?php echo urlencode($service['id']); ?>">
                                                            <i class="fas fa-edit"></i> Edit Service
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item renew-btn" href="#" 
                                                           data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                           data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                            <i class="fas fa-sync"></i> Renew Service
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <?php if ($service['status'] == 'Active'): ?>
                                                    <li>
                                                        <a class="dropdown-item suspend-btn" href="#" 
                                                           data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                           data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                            <i class="fas fa-pause"></i> Suspend
                                                        </a>
                                                    </li>
                                                    <?php elseif ($service['status'] == 'Suspended'): ?>
                                                    <li>
                                                        <a class="dropdown-item activate-btn" href="#" 
                                                           data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                           data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                            <i class="fas fa-play"></i> Activate
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <a class="dropdown-item text-danger delete-btn" href="#" 
                                                           data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                           data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item" href="renewals.php?service_id=<?php echo urlencode($service['id']); ?>">
                                                            <i class="fas fa-history"></i> Renewal History
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="../../pages/tickets/create.php?service_id=<?php echo urlencode($service['id']); ?>">
                                                            <i class="fas fa-ticket-alt"></i> Create Ticket
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($services)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No services found</h5>
                                        <p>Try changing your filters or add a new service.</p>
                                        <a href="create.php" class="btn btn-primary">
                                            <i class="fas fa-plus-circle"></i> Add New Service
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Card View (Default) -->
                <div class="card-body" id="cardView">
                    <div class="row">
                        <?php foreach ($services as $service): 
                            $category_class = 'badge-' . strtolower($service['service_category'] ?? 'other');
                            $header_color = '';
                            switch ($service['service_category']) {
                                case 'Domain': $header_color = '#28a745'; break;
                                case 'Hosting': $header_color = '#007bff'; break;
                                case 'Email': $header_color = '#dc3545'; break;
                                case 'Security': $header_color = '#ffc107'; break;
                                case 'Subscription': $header_color = '#6f42c1'; break;
                                default: $header_color = '#6c757d'; break;
                            }
                            
                            $days_left = $service['days_until_expiry'] ?? 0;
                            if ($days_left < 0) {
                                $expiry_class = 'bg-danger';
                                $expiry_text = 'Expired';
                            } elseif ($days_left <= 7) {
                                $expiry_class = 'bg-danger';
                                $expiry_text = $days_left . ' days';
                            } elseif ($days_left <= 30) {
                                $expiry_class = 'bg-warning';
                                $expiry_text = $days_left . ' days';
                            } else {
                                $expiry_class = 'bg-success';
                                $expiry_text = $days_left . ' days';
                            }
                            
                            $status_class = '';
                            switch ($service['status']) {
                                case 'Active': $status_class = 'success'; break;
                                case 'Pending': $status_class = 'warning'; break;
                                case 'Suspended': $status_class = 'danger'; break;
                                case 'Cancelled': $status_class = 'secondary'; break;
                                case 'Expired': $status_class = 'dark'; break;
                            }
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                            <div class="service-card h-100">
                                <div class="service-header" style="background: <?php echo $header_color; ?>;">
                                    <div class="service-icon">
                                        <?php 
                                        $icon = 'fa-concierge-bell';
                                        switch ($service['service_category']) {
                                            case 'Domain': $icon = 'fa-globe'; break;
                                            case 'Hosting': $icon = 'fa-server'; break;
                                            case 'Email': $icon = 'fa-envelope'; break;
                                            case 'Security': $icon = 'fa-shield-alt'; break;
                                            case 'Subscription': $icon = 'fa-sync'; break;
                                        }
                                        ?>
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($service['service_name']); ?></h6>
                                        <small class="opacity-75"><?php echo htmlspecialchars($service['company_name'] ?? 'No Client'); ?></small>
                                    </div>
                                    <?php if ($service['auto_renew']): ?>
                                    <span class="expiry-badge" title="Auto-renew enabled">
                                        <i class="fas fa-sync"></i>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <span class="category-badge <?php echo $category_class; ?>">
                                            <?php echo htmlspecialchars($service['service_category'] ?? 'Other'); ?>
                                        </span>
                                        <span class="badge bg-<?php echo $status_class; ?> status-badge float-end">
                                            <?php echo htmlspecialchars($service['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($service['domain_name']): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Domain:</small>
                                        <div class="fw-bold"><?php echo htmlspecialchars($service['domain_name']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($service['hosting_plan']): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Plan:</small>
                                        <div><?php echo htmlspecialchars($service['hosting_plan']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($service['email_type']): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">Email Service:</small>
                                        <div><?php echo htmlspecialchars($service['email_type']); ?> (<?php echo $service['email_accounts'] ?? 1; ?> accounts)</div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <hr>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted">Expires:</small>
                                            <div class="fw-bold"><?php echo $service['expiry_date'] ? date('M d, Y', strtotime($service['expiry_date'])) : 'N/A'; ?></div>
                                        </div>
                                        <div class="col-6 text-end">
                                            <span class="badge <?php echo $expiry_class; ?>">
                                                <?php echo $expiry_text; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-muted">Price:</small>
                                            <div>$<?php echo number_format($service['monthly_price'] ?? 0, 2); ?>/mo</div>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Cycle:</small>
                                            <div><?php echo htmlspecialchars($service['billing_cycle'] ?? 'Monthly'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent border-top-0">
                                    <div class="action-buttons d-flex justify-content-between">
                                        <a href="view.php?id=<?php echo urlencode($service['id']); ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($can_manage): ?>
                                        <a href="edit.php?id=<?php echo urlencode($service['id']); ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-success renew-btn" 
                                                data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                            <i class="fas fa-sync"></i> Renew
                                        </button>
                                        <?php endif; ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                    type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="view.php?id=<?php echo urlencode($service['id']); ?>">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </a>
                                                </li>
                                                <?php if ($can_manage): ?>
                                                <li>
                                                    <a class="dropdown-item" href="edit.php?id=<?php echo urlencode($service['id']); ?>">
                                                        <i class="fas fa-edit"></i> Edit Service
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item renew-btn" href="#" 
                                                       data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                       data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                        <i class="fas fa-sync"></i> Renew Service
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php if ($service['status'] == 'Active'): ?>
                                                <li>
                                                    <a class="dropdown-item suspend-btn" href="#" 
                                                       data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                       data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                        <i class="fas fa-pause"></i> Suspend
                                                    </a>
                                                </li>
                                                <?php elseif ($service['status'] == 'Suspended'): ?>
                                                <li>
                                                    <a class="dropdown-item activate-btn" href="#" 
                                                       data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                       data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                        <i class="fas fa-play"></i> Activate
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <li>
                                                    <a class="dropdown-item text-danger delete-btn" href="#" 
                                                       data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                                       data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item" href="renewals.php?service_id=<?php echo urlencode($service['id']); ?>">
                                                        <i class="fas fa-history"></i> Renewal History
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="../../pages/tickets/create.php?service_id=<?php echo urlencode($service['id']); ?>">
                                                        <i class="fas fa-ticket-alt"></i> Create Ticket
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($services)): ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <div class="text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <h5>No services found</h5>
                                    <p>Try changing your filters or add a new service.</p>
                                    <a href="create.php" class="btn btn-primary">
                                        <i class="fas fa-plus-circle"></i> Add New Service
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
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
    
    <!-- Bulk Actions Modal -->
    <div class="modal fade" id="bulkActionsModal" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkActionsModalLabel"><i class="fas fa-tasks"></i> Bulk Actions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="bulk-actions">
                        <div class="mb-3">
                            <label class="form-label">Selected Services: <span id="selectedCount">0</span></label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="bulkSelectAll">
                                <label class="form-check-label" for="bulkSelectAll">
                                    Select/Deselect All
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Action to Perform</label>
                            <select class="form-select" id="bulkAction">
                                <option value="">Choose action...</option>
                                <option value="renew">Renew Services</option>
                                <option value="activate">Activate Services</option>
                                <option value="suspend">Suspend Services</option>
                                <option value="cancel">Cancel Services</option>
                                <option value="delete">Delete Services</option>
                                <option value="change_status">Change Status</option>
                                <option value="change_category">Change Category</option>
                                <option value="export">Export Selected</option>
                            </select>
                        </div>
                        
                        <div id="bulkActionFields" style="display: none;">
                            <!-- Additional fields will be shown here based on selected action -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="executeBulkAction">Execute Action</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Renew Service Modal -->
    <div class="modal fade" id="renewModal" tabindex="-1" aria-labelledby="renewModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="renewModalLabel"><i class="fas fa-sync"></i> Renew Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="renewForm" method="POST" action="renew.php">
                    <input type="hidden" name="service_id" id="renewServiceId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <p>Renew service: <strong id="renewServiceName"></strong></p>
                        </div>
                        <div class="mb-3">
                            <label for="renewalPeriod" class="form-label">Renewal Period</label>
                            <select class="form-select" id="renewalPeriod" name="renewal_period">
                                <option value="1">1 Year</option>
                                <option value="2">2 Years</option>
                                <option value="3">3 Years</option>
                                <option value="5">5 Years</option>
                                <option value="10">10 Years</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="renewalAmount" class="form-label">Renewal Amount ($)</label>
                            <input type="number" class="form-control" id="renewalAmount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="renewalNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="renewalNotes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="updateExpiry" name="update_expiry" checked>
                            <label class="form-check-label" for="updateExpiry">
                                Update expiry date automatically
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Renew Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the service: <strong id="deleteServiceName"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i> 
                        <strong>Warning:</strong> This action cannot be undone. All associated renewal history will also be deleted.
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmDelete">
                        <label class="form-check-label" for="confirmDelete">
                            I understand this action is permanent
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>Delete Service</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                width: '100%',
                placeholder: 'Select...'
            });
            
            // Initialize DataTable
            $('#servicesTable').DataTable({
                pageLength: 20,
                lengthChange: false,
                searching: false,
                info: false,
                paging: false,
                order: [],
                language: {
                    emptyTable: "No services found"
                }
            });
            
            // Toggle between table and card view
            $('#toggleView').on('click', function() {
                $('#cardView').addClass('d-none');
                $('#tableView').removeClass('d-none');
                $(this).addClass('active');
                $('#toggleCardView').removeClass('active');
            });
            
            $('#toggleCardView').on('click', function() {
                $('#tableView').addClass('d-none');
                $('#cardView').removeClass('d-none');
                $(this).addClass('active');
                $('#toggleView').removeClass('active');
            });
            
            // Renew service modal
            $('.renew-btn').on('click', function() {
                const serviceId = $(this).data('id');
                const serviceName = $(this).data('name');
                
                $('#renewServiceId').val(serviceId);
                $('#renewServiceName').text(serviceName);
                
                // Show modal
                const renewModal = new bootstrap.Modal(document.getElementById('renewModal'));
                renewModal.show();
            });
            
            // Delete confirmation modal
            $('.delete-btn').on('click', function(e) {
                e.preventDefault();
                const serviceId = $(this).data('id');
                const serviceName = $(this).data('name');
                
                $('#deleteServiceName').text(serviceName);
                $('#confirmDeleteBtn').data('id', serviceId);
                $('#confirmDelete').prop('checked', false);
                $('#confirmDeleteBtn').prop('disabled', true);
                
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
                deleteModal.show();
            });
            
            // Enable delete button when checkbox is checked
            $('#confirmDelete').on('change', function() {
                $('#confirmDeleteBtn').prop('disabled', !$(this).prop('checked'));
            });
            
            // Handle delete confirmation
            $('#confirmDeleteBtn').on('click', function() {
                const serviceId = $(this).data('id');
                
                $.ajax({
                    url: 'delete.php',
                    method: 'POST',
                    data: {
                        id: serviceId,
                        confirm: true
                    },
                    success: function(response) {
                        const data = JSON.parse(response);
                        if (data.success) {
                            showToast('Service deleted successfully', 'success');
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showToast(data.message || 'Error deleting service', 'error');
                        }
                    },
                    error: function() {
                        showToast('Error deleting service', 'error');
                    }
                });
                
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
            });
            
            // Suspend service
            $('.suspend-btn').on('click', function(e) {
                e.preventDefault();
                const serviceId = $(this).data('id');
                const serviceName = $(this).data('name');
                
                if (confirm(`Are you sure you want to suspend "${serviceName}"?`)) {
                    $.ajax({
                        url: 'update_status.php',
                        method: 'POST',
                        data: {
                            id: serviceId,
                            status: 'Suspended'
                        },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                showToast('Service suspended successfully', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                showToast(data.message || 'Error suspending service', 'error');
                            }
                        }
                    });
                }
            });
            
            // Activate service
            $('.activate-btn').on('click', function(e) {
                e.preventDefault();
                const serviceId = $(this).data('id');
                const serviceName = $(this).data('name');
                
                if (confirm(`Are you sure you want to activate "${serviceName}"?`)) {
                    $.ajax({
                        url: 'update_status.php',
                        method: 'POST',
                        data: {
                            id: serviceId,
                            status: 'Active'
                        },
                        success: function(response) {
                            const data = JSON.parse(response);
                            if (data.success) {
                                showToast('Service activated successfully', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                showToast(data.message || 'Error activating service', 'error');
                            }
                        }
                    });
                }
            });
            
            // Select all checkboxes in table view
            $('#selectAll').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('.service-checkbox').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            // Bulk select all
            $('#bulkSelectAll').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('.service-checkbox').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            // Update selected count
            function updateSelectedCount() {
                const selectedCount = $('.service-checkbox:checked').length;
                $('#selectedCount').text(selectedCount);
                
                if (selectedCount > 0) {
                    $('#executeBulkAction').prop('disabled', false);
                } else {
                    $('#executeBulkAction').prop('disabled', true);
                }
            }
            
            // Update count when checkboxes change
            $('.service-checkbox').on('change', updateSelectedCount);
            
            // Bulk action selection
            $('#bulkAction').on('change', function() {
                const action = $(this).val();
                const actionFields = $('#bulkActionFields');
                
                if (action) {
                    let fields = '';
                    
                    switch (action) {
                        case 'change_status':
                            fields = `
                                <div class="mb-3">
                                    <label class="form-label">New Status</label>
                                    <select class="form-select" id="newStatus">
                                        <option value="Active">Active</option>
                                        <option value="Suspended">Suspended</option>
                                        <option value="Cancelled">Cancelled</option>
                                        <option value="Expired">Expired</option>
                                    </select>
                                </div>
                            `;
                            break;
                            
                        case 'change_category':
                            fields = `
                                <div class="mb-3">
                                    <label class="form-label">New Category</label>
                                    <select class="form-select" id="newCategory">
                                        <option value="Domain">Domain</option>
                                        <option value="Hosting">Hosting</option>
                                        <option value="Email">Email</option>
                                        <option value="Security">Security</option>
                                        <option value="Subscription">Subscription</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            `;
                            break;
                            
                        case 'renew':
                            fields = `
                                <div class="mb-3">
                                    <label class="form-label">Renewal Period (Years)</label>
                                    <input type="number" class="form-control" id="bulkRenewalYears" min="1" max="10" value="1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Notes</label>
                                    <textarea class="form-control" id="bulkRenewalNotes" rows="2"></textarea>
                                </div>
                            `;
                            break;
                    }
                    
                    actionFields.html(fields).show();
                } else {
                    actionFields.hide();
                }
            });
            
            // Execute bulk action
            $('#executeBulkAction').on('click', function() {
                const action = $('#bulkAction').val();
                const selectedIds = $('.service-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selectedIds.length === 0) {
                    showToast('Please select at least one service', 'warning');
                    return;
                }
                
                if (!action) {
                    showToast('Please select an action', 'warning');
                    return;
                }
                
                let confirmMessage = '';
                let data = {
                    action: action,
                    service_ids: selectedIds
                };
                
                switch (action) {
                    case 'delete':
                        confirmMessage = `Are you sure you want to delete ${selectedIds.length} service(s)? This cannot be undone.`;
                        break;
                    case 'suspend':
                        confirmMessage = `Are you sure you want to suspend ${selectedIds.length} service(s)?`;
                        break;
                    case 'activate':
                        confirmMessage = `Are you sure you want to activate ${selectedIds.length} service(s)?`;
                        break;
                    case 'renew':
                        confirmMessage = `Are you sure you want to renew ${selectedIds.length} service(s)?`;
                        data.renewal_years = $('#bulkRenewalYears').val() || 1;
                        data.notes = $('#bulkRenewalNotes').val();
                        break;
                    case 'change_status':
                        confirmMessage = `Are you sure you want to change status for ${selectedIds.length} service(s)?`;
                        data.new_status = $('#newStatus').val();
                        break;
                    case 'change_category':
                        confirmMessage = `Are you sure you want to change category for ${selectedIds.length} service(s)?`;
                        data.new_category = $('#newCategory').val();
                        break;
                    default:
                        confirmMessage = `Are you sure you want to perform this action on ${selectedIds.length} service(s)?`;
                }
                
                if (confirm(confirmMessage)) {
                    $.ajax({
                        url: 'bulk_actions.php',
                        method: 'POST',
                        data: data,
                        success: function(response) {
                            const result = JSON.parse(response);
                            if (result.success) {
                                showToast(result.message || 'Action completed successfully', 'success');
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                showToast(result.message || 'Error performing action', 'error');
                            }
                        },
                        error: function() {
                            showToast('Error performing action', 'error');
                        }
                    });
                    
                    // Close modal
                    bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal')).hide();
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
            
            // Quick filter links
            $('.dropdown-menu a').on('click', function(e) {
                if ($(this).attr('href').startsWith('?')) {
                    e.preventDefault();
                    window.location.href = $(this).attr('href');
                }
            });
            
            // Calculate renewal amount based on period
            $('#renewalPeriod').on('change', function() {
                const years = $(this).val();
                // You can implement logic to calculate renewal amount based on service
                // For now, just multiply by years
                const baseAmount = 50; // This should come from the service data
                $('#renewalAmount').val((baseAmount * years).toFixed(2));
            });
        });
    </script>
</body>
</html>