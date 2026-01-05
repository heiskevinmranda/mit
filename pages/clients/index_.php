<?php
// pages/clients/index.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
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
          WHERE 1=1";
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
    
    <!-- Load CSS files with fallback -->
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="/mit/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Simple inline styles as fallback -->
    <style>
        /* Fallback styles if external CSS fails */
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            background: #f0f2f5; 
        }
        
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: #004E89;
            color: white;
            padding: 20px 0;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            background: white;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: #004E89;
            color: white;
            padding: 15px;
            font-weight: bold;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            font-weight: bold;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        
        .btn-primary {
            background: #FF6B35;
            color: white;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            display: inline-block;
        }
        
        .bg-success { background: #28a745; color: white; }
        .bg-secondary { background: #6c757d; color: white; }
        
        /* Force table to be visible */
        table {
            width: 100% !important;
            display: table !important;
            border-spacing: 0 !important;
            border-collapse: collapse !important;
        }
        
        th, td {
            display: table-cell !important;
            padding: 12px !important;
            text-align: left !important;
            vertical-align: middle !important;
        }
    </style>
</head>
<body>
    <!-- Simple Header -->
    <nav class="navbar">
        <div class="container-fluid">
            <h4><i class="fas fa-tools me-2"></i>MSP Portal</h4>
            <div class="d-flex align-items-center">
                <span class="text-white me-3">
                    <i class="fas fa-user me-1"></i> <?= htmlspecialchars($_SESSION['email'] ?? 'User') ?>
                </span>
                <a href="../../logout.php" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <div class="main-wrapper">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h2 mb-1">
                        <i class="fas fa-building text-primary"></i> Client Management
                    </h1>
                    <p class="text-muted mb-0">Manage your client companies and their information</p>
                </div>
                <?php if ($can_create): ?>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Client
                </a>
                <?php endif; ?>
            </div>
            
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
            
            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); endif; ?>
            
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
                        <a href="create.php" class="btn btn-primary mt-2">
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
                                        <span class="badge badge-client"><?= htmlspecialchars($client['industry']) ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark">Not Set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-location">
                                            <i class="fas fa-map-marker-alt"></i> <?= $client['location_count'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge status-active">Active</span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view.php?id=<?= $client['id'] ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($can_edit): ?>
                                            <a href="edit.php?id=<?= $client['id'] ?>" class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <a href="delete.php?id=<?= $client['id'] ?>" class="btn btn-danger" title="Delete" onclick="return confirm('Delete this client?')">
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
    
    <!-- Mobile Menu Toggle -->
    <button class="btn btn-primary d-md-none fixed-bottom m-3" style="z-index: 1000;" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i> Menu
    </button>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple mobile menu toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('d-none');
        }
        
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