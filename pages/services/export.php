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

// Get services data based on filters
$where = "WHERE cs.status != 'Deleted'";
$params = [];

// Apply filters if provided
if (!empty($_GET['status']) && $_GET['status'] != 'all') {
    $where .= " AND cs.status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['category']) && $_GET['category'] != 'all') {
    $where .= " AND cs.service_category = ?";
    $params[] = $_GET['category'];
}

if (!empty($_GET['client_id'])) {
    $where .= " AND cs.client_id = ?";
    $params[] = $_GET['client_id'];
}

// Get services with all data
$sql = "SELECT cs.*, 
               c.company_name,
               c.contact_person,
               c.email as client_email,
               c.phone as client_phone
        FROM client_services cs
        LEFT JOIN clients c ON cs.client_id = c.id
        $where
        ORDER BY cs.service_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

// Export to CSV
if (isset($_GET['format']) && $_GET['format'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="services_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, [
        'Service Name',
        'Client',
        'Category',
        'Domain',
        'Status',
        'Expiry Date',
        'Monthly Price',
        'Billing Cycle',
        'Auto Renew',
        'Created Date'
    ]);
    
    // Add data rows
    foreach ($services as $service) {
        fputcsv($output, [
            $service['service_name'],
            $service['company_name'],
            $service['service_category'],
            $service['domain_name'],
            $service['status'],
            $service['expiry_date'],
            $service['monthly_price'],
            $service['billing_cycle'],
            $service['auto_renew'] ? 'Yes' : 'No',
            $service['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

// Get filter options
$categories = $pdo->query("SELECT DISTINCT service_category FROM client_services WHERE service_category IS NOT NULL ORDER BY service_category")->fetchAll(PDO::FETCH_COLUMN);
$statuses = $pdo->query("SELECT DISTINCT status FROM client_services WHERE status IS NOT NULL ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$clients = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'Active' ORDER BY company_name")->fetchAll();

$current_user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Services | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-file-export"></i> Export Services</h1>
                    <p class="text-muted">Export services data to various formats</p>
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
                    <li class="breadcrumb-item active" aria-current="page">Export Services</li>
                </ol>
            </nav>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter"></i> Export Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo htmlspecialchars($status); ?>" 
                                        <?php echo isset($_GET['status']) && $_GET['status'] == $status ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($status); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" 
                                        <?php echo isset($_GET['category']) && $_GET['category'] == $category ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Client</label>
                                <select class="form-select" name="client_id">
                                    <option value="">All Clients</option>
                                    <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo htmlspecialchars($client['id']); ?>" 
                                        <?php echo isset($_GET['client_id']) && $_GET['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['company_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="d-grid gap-2 w-100">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Apply Filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-download"></i> Export Options</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-file-csv fa-3x text-success mb-3"></i>
                                    <h5>CSV Export</h5>
                                    <p class="text-muted">Export services data to CSV format for use in spreadsheets</p>
                                    <a href="?format=csv<?php echo $_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''; ?>" class="btn btn-success">
                                        <i class="fas fa-download"></i> Download CSV
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-file-pdf fa-3x text-danger mb-3"></i>
                                    <h5>PDF Export (Coming Soon)</h5>
                                    <p class="text-muted">Export services data to PDF format for reports</p>
                                    <button class="btn btn-danger" disabled>
                                        <i class="fas fa-ban"></i> Not Available Yet
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h6>Export Summary</h6>
                        <p>Found <strong><?php echo count($services); ?></strong> services matching your filters.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>