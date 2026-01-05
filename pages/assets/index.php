<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to access the assets module.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Pagination setup
$items_per_page = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $items_per_page;

// Filter parameters
$search = $_GET['search'] ?? '';
$asset_type = $_GET['asset_type'] ?? '';
$status = $_GET['status'] ?? '';
$client_id = $_GET['client_id'] ?? '';
$location_id = $_GET['location_id'] ?? '';

// Build query
$where_clauses = ['1=1'];
$params = [];
$types = [];

if ($search) {
    $where_clauses[] = "(a.asset_type ILIKE ? OR a.manufacturer ILIKE ? OR a.model ILIKE ? OR a.serial_number ILIKE ? OR a.asset_tag ILIKE ?)";
    $search_term = "%$search%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $search_term;
        $types[] = PDO::PARAM_STR;
    }
}

if ($asset_type) {
    $where_clauses[] = "a.asset_type = ?";
    $params[] = $asset_type;
    $types[] = PDO::PARAM_STR;
}

if ($status) {
    $where_clauses[] = "a.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

if ($client_id) {
    $where_clauses[] = "a.client_id = ?";
    $params[] = $client_id;
    $types[] = PDO::PARAM_STR;
}

if ($location_id) {
    $where_clauses[] = "a.location_id = ?";
    $params[] = $location_id;
    $types[] = PDO::PARAM_STR;
}

$where_sql = implode(' AND ', $where_clauses);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM assets a WHERE $where_sql";
$stmt = $pdo->prepare($count_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$total_items = $stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

// Get assets with joins
$sql = "SELECT 
            a.*,
            c.company_name,
            c.id as client_uuid,
            cl.location_name,
            cl.city,
            cl.state,
            s.full_name as assigned_to_name,
            s.staff_id as staff_employee_id
        FROM assets a
        LEFT JOIN clients c ON a.client_id = c.id
        LEFT JOIN client_locations cl ON a.location_id = cl.id
        LEFT JOIN staff_profiles s ON a.assigned_to = s.id
        WHERE $where_sql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $items_per_page;
$types[] = PDO::PARAM_INT;
$params[] = $offset;
$types[] = PDO::PARAM_INT;

$stmt = $pdo->prepare($sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$assets = $stmt->fetchAll();

// Get filter options
$asset_types = $pdo->query("SELECT DISTINCT asset_type FROM assets WHERE asset_type IS NOT NULL ORDER BY asset_type")->fetchAll();
$statuses = $pdo->query("SELECT DISTINCT status FROM assets WHERE status IS NOT NULL ORDER BY status")->fetchAll();
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();
$locations = $pdo->query("SELECT id, location_name, client_id FROM client_locations ORDER BY location_name")->fetchAll();

// Get expiring assets (within 30 days)
$expiring_sql = "SELECT COUNT(*) as count FROM assets 
                 WHERE (warranty_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days')
                    OR (amc_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days')
                    OR (license_expiry BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days')";
$expiring_count = $pdo->query($expiring_sql)->fetchColumn();

// Get asset statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'Active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'Under Maintenance' THEN 1 END) as maintenance,
                COUNT(CASE WHEN status = 'Retired' THEN 1 END) as retired
              FROM assets";
$stats = $pdo->query($stats_sql)->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Management | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .asset-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .asset-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .asset-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-maintenance { background: #fff3cd; color: #856404; }
        .status-retired { background: #e2e3e5; color: #383d41; }
        .expiry-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .asset-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .view-toggle {
            cursor: pointer;
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: white;
        }
        .view-toggle.active {
            background: #004E89;
            color: white;
            border-color: #004E89;
        }
        .asset-details-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        .asset-details-table td:first-child {
            font-weight: 600;
            color: #666;
            width: 150px;
        }
        @media (max-width: 768px) {
            .asset-card {
                margin-bottom: 15px;
            }
            .view-toggle {
                padding: 6px 10px;
                font-size: 0.9rem;
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
                    <h1><i class="fas fa-server"></i> Asset Management</h1>
                    <p class="text-muted">Manage IT infrastructure and hardware assets</p>
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
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #004E89, #1a6cb0); color: white;">
                        <i class="fas fa-server fa-2x"></i>
                        <div class="stats-number"><?php echo $stats['total'] ?? 0; ?></div>
                        <div>Total Assets</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #28a745, #34ce57); color: white;">
                        <i class="fas fa-check-circle fa-2x"></i>
                        <div class="stats-number"><?php echo $stats['active'] ?? 0; ?></div>
                        <div>Active</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #ffc107, #ffd454); color: #212529;">
                        <i class="fas fa-tools fa-2x"></i>
                        <div class="stats-number"><?php echo $stats['maintenance'] ?? 0; ?></div>
                        <div>Under Maintenance</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6c757d, #868e96); color: white;">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                        <div class="stats-number"><?php echo $expiring_count; ?></div>
                        <div>Expiring Soon</div>
                    </div>
                </div>
            </div>
            
            <!-- Action Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Add New Asset
                    </a>
                    <?php if (hasPermission('admin')): ?>
                    <a href="import.php" class="btn btn-outline-secondary">
                        <i class="fas fa-file-import"></i> Import Assets
                    </a>
                    <?php endif; ?>
                    <a href="reports.php" class="btn btn-outline-info">
                        <i class="fas fa-chart-bar"></i> Asset Reports
                    </a>
                </div>
                <div class="d-flex align-items-center">
                    <span class="me-2">View:</span>
                    <div class="btn-group" role="group">
                        <button type="button" class="view-toggle active" id="view-grid">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button type="button" class="view-toggle" id="view-list">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" placeholder="Search assets..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="asset_type">
                            <option value="">All Types</option>
                            <?php foreach ($asset_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['asset_type']); ?>" 
                                <?php echo $asset_type == $type['asset_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['asset_type']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statuses as $stat): ?>
                            <option value="<?php echo htmlspecialchars($stat['status']); ?>"
                                <?php echo $status == $stat['status'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($stat['status']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select select2" name="client_id" id="client-select">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                            <option value="<?php echo htmlspecialchars($client['id']); ?>"
                                <?php echo $client_id == $client['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['company_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select select2" name="location_id" id="location-select">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                            <option value="<?php echo htmlspecialchars($location['id']); ?>"
                                data-client="<?php echo htmlspecialchars($location['client_id']); ?>"
                                <?php echo $location_id == $location['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </form>
                <?php if ($search || $asset_type || $status || $client_id || $location_id): ?>
                <div class="mt-3">
                    <small class="text-muted">
                        Showing <?php echo count($assets); ?> of <?php echo $total_items; ?> assets
                        <?php if ($search): ?> matching "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
                        <?php if ($asset_type): ?> • Type: <?php echo htmlspecialchars($asset_type); ?><?php endif; ?>
                        <?php if ($status): ?> • Status: <?php echo htmlspecialchars($status); ?><?php endif; ?>
                        <?php if ($client_id): ?>
                            • Client: <?php 
                                foreach ($clients as $c) {
                                    if ($c['id'] == $client_id) {
                                        echo htmlspecialchars($c['company_name']);
                                        break;
                                    }
                                }
                            ?>
                        <?php endif; ?>
                        <a href="?" class="text-danger ms-2">
                            <i class="fas fa-times"></i> Clear filters
                        </a>
                    </small>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Assets Grid View (Default) -->
            <div id="grid-view">
                <?php if (empty($assets)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-server fa-4x text-muted mb-3"></i>
                    <h3>No assets found</h3>
                    <p class="text-muted mb-4"><?php echo $total_items > 0 ? 'Try adjusting your filters' : 'Get started by adding your first asset'; ?></p>
                    <a href="create.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus-circle"></i> Add Your First Asset
                    </a>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($assets as $asset): 
                        $is_expiring = false;
                        $today = new DateTime();
                        $expiry_dates = [];
                        
                        if ($asset['warranty_expiry']) {
                            $warranty_date = new DateTime($asset['warranty_expiry']);
                            $diff = $today->diff($warranty_date)->days;
                            if ($diff <= 30 && $warranty_date > $today) {
                                $is_expiring = true;
                                $expiry_dates[] = "Warranty: " . $warranty_date->format('M d, Y');
                            }
                        }
                        
                        if ($asset['amc_expiry']) {
                            $amc_date = new DateTime($asset['amc_expiry']);
                            $diff = $today->diff($amc_date)->days;
                            if ($diff <= 30 && $amc_date > $today) {
                                $is_expiring = true;
                                $expiry_dates[] = "AMC: " . $amc_date->format('M d, Y');
                            }
                        }
                        
                        if ($asset['license_expiry']) {
                            $license_date = new DateTime($asset['license_expiry']);
                            $diff = $today->diff($license_date)->days;
                            if ($diff <= 30 && $license_date > $today) {
                                $is_expiring = true;
                                $expiry_dates[] = "License: " . $license_date->format('M d, Y');
                            }
                        }
                        
                        $status_class = 'status-' . strtolower(str_replace(' ', '-', $asset['status']));
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="asset-card p-3 h-100 position-relative">
                            <?php if ($is_expiring): ?>
                            <div class="expiry-badge" title="<?php echo implode(', ', $expiry_dates); ?>">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex align-items-start mb-3">
                                <div class="asset-icon" style="background: <?php echo getAssetColor($asset['asset_type']); ?>; color: white;">
                                    <i class="<?php echo getAssetIcon($asset['asset_type']); ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1"><?php echo htmlspecialchars($asset['asset_type']); ?></h5>
                                    <div class="d-flex justify-content-between">
                                        <span class="asset-status <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($asset['status']); ?>
                                        </span>
                                        <small class="text-muted">
                                            ID: <?php echo substr($asset['id'], 0, 8); ?>...
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="asset-details-table mb-3">
                                <table width="100%">
                                    <tr>
                                        <td>Manufacturer:</td>
                                        <td><?php echo htmlspecialchars($asset['manufacturer'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Model:</td>
                                        <td><?php echo htmlspecialchars($asset['model'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Serial No:</td>
                                        <td><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Asset Tag:</td>
                                        <td><?php echo htmlspecialchars($asset['asset_tag'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Client:</td>
                                        <td><?php echo htmlspecialchars($asset['company_name'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Location:</td>
                                        <td><?php echo htmlspecialchars($asset['location_name'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <?php if ($asset['assigned_to_name']): ?>
                                    <tr>
                                        <td>Assigned To:</td>
                                        <td><?php echo htmlspecialchars($asset['assigned_to_name']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">
                                    Added: <?php echo date('M d, Y', strtotime($asset['created_at'])); ?>
                                </small>
                                <div class="btn-group">
                                    <a href="view.php?id=<?php echo urlencode($asset['id']); ?>" 
                                       class="btn btn-sm btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo urlencode($asset['id']); ?>" 
                                       class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if (hasPermission('admin')): ?>
                                    <a href="delete.php?id=<?php echo urlencode($asset['id']); ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Are you sure you want to delete this asset?')" 
                                       title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Assets List View (Hidden by default) -->
            <div id="list-view" style="display: none;">
                <?php if (!empty($assets)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Asset Tag</th>
                                <th>Type</th>
                                <th>Manufacturer/Model</th>
                                <th>Serial Number</th>
                                <th>Client</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Warranty Expiry</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $asset): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $asset['status']));
                                $is_expiring = false;
                                
                                if ($asset['warranty_expiry']) {
                                    $warranty_date = new DateTime($asset['warranty_expiry']);
                                    $today = new DateTime();
                                    $diff = $today->diff($warranty_date)->days;
                                    if ($diff <= 30 && $warranty_date > $today) {
                                        $is_expiring = true;
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="asset-icon me-2" style="width: 30px; height: 30px; background: <?php echo getAssetColor($asset['asset_type']); ?>; color: white;">
                                            <i class="<?php echo getAssetIcon($asset['asset_type']); ?> fa-xs"></i>
                                        </div>
                                        <span><?php echo htmlspecialchars($asset['asset_tag'] ?: 'N/A'); ?></span>
                                        <?php if ($is_expiring): ?>
                                        <span class="badge bg-danger ms-2" title="Warranty expiring soon">!</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($asset['asset_type']); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($asset['manufacturer'] ?: 'N/A'); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($asset['model'] ?: ''); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($asset['company_name'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($asset['location_name'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="asset-status <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($asset['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($asset['warranty_expiry']): ?>
                                    <?php echo date('M d, Y', strtotime($asset['warranty_expiry'])); ?>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="view.php?id=<?php echo urlencode($asset['id']); ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo urlencode($asset['id']); ?>" 
                                           class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (hasPermission('admin')): ?>
                                        <a href="delete.php?id=<?php echo urlencode($asset['id']); ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Are you sure you want to delete this asset?')">
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
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo buildPaginationQuery($page - 1); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildPaginationQuery($i); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo buildPaginationQuery($page + 1); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
                <div class="text-center text-muted">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                    • <?php echo $total_items; ?> total assets
                </div>
            </nav>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.select2').select2({
                width: '100%',
                placeholder: 'Select...'
            });
            
            // Filter locations based on selected client
            $('#client-select').on('change', function() {
                var clientId = $(this).val();
                $('#location-select option').each(function() {
                    var option = $(this);
                    if (!clientId || option.data('client') == clientId) {
                        option.show();
                    } else {
                        option.hide();
                        if (option.prop('selected')) {
                            option.prop('selected', false);
                            $('#location-select').trigger('change');
                        }
                    }
                });
            });
            
            // View toggle
            $('#view-grid').on('click', function() {
                $('#grid-view').show();
                $('#list-view').hide();
                $(this).addClass('active');
                $('#view-list').removeClass('active');
            });
            
            $('#view-list').on('click', function() {
                $('#grid-view').hide();
                $('#list-view').show();
                $(this).addClass('active');
                $('#view-grid').removeClass('active');
            });
            
            // Auto-refresh expiring badges every minute
            setInterval(function() {
                location.reload();
            }, 60000);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'create.php';
            }
            if (e.key === 'Escape') {
                window.location.href = '?';
            }
        });
    </script>
</body>
</html>

<?php
// Helper functions
function getAssetIcon($type) {
    $icons = [
        'Firewall' => 'fas fa-shield-alt',
        'Switch' => 'fas fa-network-wired',
        'Server' => 'fas fa-server',
        'CCTV' => 'fas fa-video',
        'Biometric' => 'fas fa-fingerprint',
        'Gate Automation' => 'fas fa-door-open',
        'Router' => 'fas fa-wifi',
        'Access Point' => 'fas fa-wifi',
        'Desktop' => 'fas fa-desktop',
        'Laptop' => 'fas fa-laptop',
        'Printer' => 'fas fa-print',
        'Scanner' => 'fas fa-scanner',
        'Phone' => 'fas fa-phone',
        'Tablet' => 'fas fa-tablet-alt',
        'Mobile' => 'fas fa-mobile-alt',
    ];
    return $icons[$type] ?? 'fas fa-hdd';
}

function getAssetColor($type) {
    $colors = [
        'Firewall' => '#dc3545',
        'Switch' => '#007bff',
        'Server' => '#28a745',
        'CCTV' => '#17a2b8',
        'Biometric' => '#6610f2',
        'Gate Automation' => '#fd7e14',
        'Router' => '#20c997',
        'Access Point' => '#e83e8c',
        'Desktop' => '#6f42c1',
        'Laptop' => '#20c997',
        'Printer' => '#6c757d',
        'Scanner' => '#ffc107',
    ];
    return $colors[$type] ?? '#004E89';
}

function buildPaginationQuery($page) {
    $params = $_GET;
    $params['page'] = $page;
    return http_build_query($params);
}
?>