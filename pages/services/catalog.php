<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

// Check permissions
if (!hasPermission('admin') && !hasPermission('manager')) {
    header('Location: ../dashboard.php');
    exit;
}

$pdo = getDBConnection();

// Get all service templates/categorizations
$service_categories = $pdo->query("
    SELECT DISTINCT service_category 
    FROM client_services 
    WHERE service_category IS NOT NULL 
    ORDER BY service_category
")->fetchAll(PDO::FETCH_COLUMN);

// Get services by category
$category_services = [];
foreach ($service_categories as $category) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT service_name, service_category, monthly_price, billing_cycle, description
        FROM client_services 
        WHERE service_category = ? 
        ORDER BY service_name
    ");
    $stmt->execute([$category]);
    $category_services[$category] = $stmt->fetchAll();
}

// Get all unique service names for the catalog
$all_services = $pdo->query("
    SELECT DISTINCT service_name, service_category, monthly_price, billing_cycle, description
    FROM client_services
    WHERE status != 'Deleted'
    ORDER BY service_category, service_name
")->fetchAll();

$current_user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Catalog | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .service-card {
            border: 1px solid #eaeaea;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .category-header {
            background: linear-gradient(135deg, #004E89, #0066CC);
            color: white;
            padding: 15px;
            border-radius: 10px 10px 0 0;
        }
        .service-item {
            border-bottom: 1px solid #eee;
            padding: 15px;
        }
        .service-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-list-alt"></i> Service Catalog</h1>
                    <p class="text-muted">Browse and manage service templates</p>
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
                    <li class="breadcrumb-item"><a href="index.php">Services</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Service Catalog</li>
                </ol>
            </nav>
            
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-th-list"></i> Service Categories</h5>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                                    <i class="fas fa-plus"></i> Add Template
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($service_categories)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No service categories found</h5>
                                    <p class="text-muted">Services will appear here once added to the system</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($service_categories as $category): ?>
                                <div class="mb-4">
                                    <div class="category-header">
                                        <h5 class="mb-0">
                                            <i class="fas fa-folder"></i> 
                                            <?php echo htmlspecialchars($category); ?>
                                            <span class="badge bg-light text-dark float-end"><?php echo count($category_services[$category]); ?> services</span>
                                        </h5>
                                    </div>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($category_services[$category] as $service): ?>
                                        <div class="list-group-item service-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($service['service_name']); ?></h6>
                                                    <?php if ($service['description']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($service['description']); ?></small>
                                                    <?php endif; ?>
                                                    <div class="mt-1">
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($service['billing_cycle']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="h6 mb-0">$<?php echo number_format($service['monthly_price'], 2); ?>/mo</div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($service['service_category']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle"></i> About Service Catalog</h5>
                        </div>
                        <div class="card-body">
                            <p>The Service Catalog contains all service templates used in the system. These templates help standardize service offerings and make it easier to add new services to clients.</p>
                            
                            <h6><i class="fas fa-lightbulb"></i> Benefits</h6>
                            <ul>
                                <li>Standardized service definitions</li>
                                <li>Consistent pricing</li>
                                <li>Quick service creation</li>
                                <li>Better reporting</li>
                            </ul>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Service templates can be used as a starting point when creating new client services.
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Service Statistics</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-3">
                                <div>
                                    <h6 class="text-muted mb-1">Total Services</h6>
                                    <h4 class="mb-0"><?php echo count($all_services); ?></h4>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1">Categories</h6>
                                    <h4 class="mb-0"><?php echo count($service_categories); ?></h4>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <h6>Top Categories</h6>
                                <?php 
                                $category_counts = [];
                                foreach ($all_services as $service) {
                                    $cat = $service['service_category'];
                                    $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;
                                }
                                arsort($category_counts);
                                $top_categories = array_slice($category_counts, 0, 5);
                                ?>
                                <?php foreach ($top_categories as $cat => $count): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><?php echo htmlspecialchars($cat); ?></span>
                                    <span class="badge bg-secondary"><?php echo $count; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add Service Template Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addServiceModalLabel"><i class="fas fa-plus-circle"></i> Add Service Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addServiceForm">
                        <div class="mb-3">
                            <label class="form-label">Service Name</label>
                            <input type="text" class="form-control" name="service_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="service_category" required>
                                <option value="">Select Category</option>
                                <?php foreach ($service_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                                <?php endforeach; ?>
                                <option value="New">+ Add New Category</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Monthly Price ($)</label>
                                <input type="number" class="form-control" name="monthly_price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Billing Cycle</label>
                                <select class="form-select" name="billing_cycle" required>
                                    <option value="Monthly">Monthly</option>
                                    <option value="Quarterly">Quarterly</option>
                                    <option value="Semi-Annually">Semi-Annually</option>
                                    <option value="Annually">Annually</option>
                                    <option value="Biennially">Biennially</option>
                                    <option value="Triennially">Triennially</option>
                                </select>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary">Save Template</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>