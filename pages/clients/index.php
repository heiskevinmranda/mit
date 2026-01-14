<?php
// pages/clients/index.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/includes/client_functions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$user_role = $_SESSION['user_type'] ?? null;
$can_create = hasClientPermission('create');
$can_edit = hasClientPermission('edit');
$can_delete = hasClientPermission('delete');

$pdo = getDBConnection();

// Simple search
$search = $_GET['search'] ?? '';
$industry = $_GET['industry'] ?? '';

$query = "SELECT c.*, COUNT(cl.id) as location_count 
          FROM clients c 
          LEFT JOIN client_locations cl ON c.id = cl.client_id 
          WHERE c.status != 'Archived'";
$params = [];

if (!empty($search)) {
    $query .= " AND (LOWER(c.company_name) LIKE LOWER(?) OR LOWER(c.contact_person) LIKE LOWER(?) OR LOWER(c.email) LIKE LOWER(?))";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($industry)) {
    $query .= " AND c.industry = ?";
    $params[] = $industry;
}

$query .= " GROUP BY c.id ORDER BY c.company_name LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all industries for filter dropdown
$industries = getAllIndustries($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management - MSP Application</title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
<!-- Add this CSS after the Bootstrap CDN link -->
<style>

</style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-building"></i> Client Management</h1>
                <div class="btn-group">
                    <?php if ($can_create): ?>
                    <a href="<?php echo route('clients.create'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Client
                    </a>
                    <?php endif; ?>
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
            
            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Search & Filter</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Company name, contact person, email..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Industry</label>
                            <select name="industry" class="form-control">
                                <option value="">All Industries</option>
                                <?php foreach ($industries as $ind): ?>
                                <option value="<?= htmlspecialchars($ind) ?>" <?= $industry === $ind ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ind) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </form>
                    <div class="mt-3 d-flex justify-content-between align-items-center">
                        <div class="text-muted">
                            Showing <?= count($clients) ?> of <?= count($clients) ?> clients
                        </div>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-undo"></i> Clear
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Success Message -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Clients Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Client List</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($clients)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-building fa-3x text-muted mb-3"></i>
                        <h4>No clients found</h4>
                        <p class="text-muted"><?= !empty($search) ? 'Try a different search term' : 'Add your first client to get started' ?></p>
                        <?php if ($can_create): ?>
                        <a href="<?php echo route('clients.create'); ?>" class="btn btn-primary mt-2">
                            <i class="fas fa-plus"></i> Add First Client
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Company Details</th>
                                    <th>Contact</th>
                                    <th>Industry</th>
                                    <th>Locations</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary" title="<?= htmlspecialchars($client['id']) ?>">
                                            #<?= substr($client['id'], 0, 8) ?>...
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($client['company_name'] ?? 'No Name') ?></div>
                                        <?php if ($client['website']): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-globe"></i> <?= htmlspecialchars($client['website']) ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($client['contact_person'] ?? 'Not specified') ?></div>
                                        <?php if ($client['email']): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-envelope"></i> <?= htmlspecialchars($client['email']) ?>
                                        </small>
                                        <?php endif; ?>
                                        <?php if ($client['phone']): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> <?= formatPhone($client['phone']) ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($client['industry']): ?>
                                        <span class="badge bg-primary"><?= htmlspecialchars($client['industry']) ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark">Not Set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <i class="fas fa-map-marker-alt"></i> <?= $client['location_count'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($client['status'] === 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php elseif ($client['status'] === 'Archived'): ?>
                                        <span class="badge bg-secondary">Archived</span>
                                        <?php else: ?>
                                        <span class="badge bg-warning"><?= htmlspecialchars($client['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?php echo route('clients.view', ['id' => $client['id']]); ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($can_edit): ?>
                                            <a href="<?php echo route('clients.edit', ['id' => $client['id']]); ?>" class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <a href="<?php echo route('clients.delete', ['id' => $client['id']]); ?>" class="btn btn-danger" title="Delete" onclick="return confirm('Delete this client?')">
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
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>