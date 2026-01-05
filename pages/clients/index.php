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
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
<!-- Add this CSS after the Bootstrap CDN link -->
<style>
    /* Override Bootstrap and custom styles to match the image */
    :root {
        --primary-color: #004E89;
        --secondary-color: #FF6B35;
        --accent-color: #43BCCD;
        --light-bg: #f8f9fa;
        --border-color: #dee2e6;
    }
    
    body {
        background-color: #f0f2f5;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    /* Navbar styling */
    .navbar {
        background: linear-gradient(135deg, var(--primary-color) 0%, #002D62 100%);
        padding: 12px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .navbar h4 {
        color: white;
        margin: 0;
        font-weight: 600;
    }
    
    /* Main layout */
    .main-wrapper {
        display: flex;
        min-height: calc(100vh - 56px);
    }
    
    /* Sidebar styling - exactly like image */
    .sidebar {
        width: 220px;
        background: white;
        border-right: 1px solid var(--border-color);
        padding: 0;
        box-shadow: 2px 0 5px rgba(0,0,0,0.05);
    }
    
    .sidebar-content {
        padding: 20px 0;
    }
    
    .sidebar-menu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .sidebar-menu li {
        margin: 0;
    }
    
    .sidebar-menu a {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #495057;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: all 0.2s;
    }
    
    .sidebar-menu a:hover {
        background-color: var(--light-bg);
        color: var(--primary-color);
        border-left-color: var(--primary-color);
    }
    
    .sidebar-menu a.active {
        background-color: #e3f2fd;
        color: var(--primary-color);
        border-left-color: var(--primary-color);
        font-weight: 500;
    }
    
    .sidebar-menu i {
        width: 24px;
        margin-right: 12px;
        font-size: 16px;
        text-align: center;
    }
    
    /* Main content area */
    .main-content {
        flex: 1;
        padding: 25px;
        background-color: #f0f2f5;
        min-height: calc(100vh - 56px);
    }
    
    /* Page header */
    .main-content .h2 {
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .main-content .text-muted {
        color: #6c757d !important;
    }
    
    /* Card styling - match image */
    .card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        margin-bottom: 24px;
        overflow: hidden;
    }
    
    .card-header {
        background-color: var(--primary-color);
        color: white;
        border-bottom: none;
        padding: 16px 20px;
        font-weight: 600;
    }
    
    .card-header h5 {
        margin: 0;
        font-weight: 600;
    }
    
    .card-body {
        padding: 25px;
    }
    
    /* Form controls */
    .form-control {
        border: 1px solid #ced4da;
        border-radius: 6px;
        padding: 10px 12px;
        transition: all 0.2s;
    }
    
    .form-control:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 0.2rem rgba(67, 188, 205, 0.25);
    }
    
    .input-group-text {
        background-color: #f8f9fa;
        border-color: #ced4da;
    }
    
    /* Buttons */
    .btn-primary {
        background-color: var(--secondary-color);
        border-color: var(--secondary-color);
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 6px;
        transition: all 0.2s;
    }
    
    .btn-primary:hover {
        background-color: #e85c2a;
        border-color: #e85c2a;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(255, 107, 53, 0.3);
    }
    
    .btn-outline-secondary {
        border-radius: 6px;
        padding: 6px 12px;
    }
    
    .btn-group-sm .btn {
        padding: 5px 10px;
        border-radius: 4px;
    }
    
    /* Table styling - exactly like image */
    .table {
        background-color: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .table thead {
        background-color: #f8f9fa;
    }
    
    .table th {
        font-weight: 600;
        color: #495057;
        text-transform: uppercase;
        font-size: 13px;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #dee2e6;
        padding: 16px 20px;
        white-space: nowrap;
    }
    
    .table td {
        padding: 16px 20px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f1f1;
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 78, 137, 0.03);
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(0, 78, 137, 0.05);
    }
    
    /* Badge styling */
    .badge {
        font-weight: 500;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
    }
    
    .bg-secondary {
        background-color: #6c757d !important;
    }
    
    .badge-client {
        background-color: #e3f2fd;
        color: var(--primary-color);
        border: 1px solid #bbdefb;
    }
    
    .badge-location {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #c8e6c9;
    }
    
    .status-active {
        background-color: #e8f5e9;
        color: #2e7d32;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    
    /* Alert styling */
    .alert {
        border: none;
        border-radius: 8px;
        padding: 15px 20px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background-color: #e8f5e9;
        color: #2e7d32;
        border-left: 4px solid #4caf50;
    }
    
    .alert-danger {
        background-color: #ffebee;
        color: #c62828;
        border-left: 4px solid #f44336;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .sidebar {
            position: fixed;
            top: 56px;
            left: -250px;
            height: calc(100vh - 56px);
            z-index: 1000;
            transition: left 0.3s;
        }
        
        .sidebar.show {
            left: 0;
        }
        
        .main-content {
            padding: 15px;
        }
        
        .card-body {
            padding: 15px;
        }
    }
    
    /* Search results info */
    .text-muted {
        font-size: 14px;
    }
    
    /* Empty state */
    .text-center.py-5 i {
        font-size: 48px;
        opacity: 0.3;
        margin-bottom: 20px;
    }
    
    /* Phone formatting */
    small.text-muted {
        display: block;
        margin-top: 4px;
    }
    
    /* ID badge */
    .badge.bg-secondary {
        font-family: 'Courier New', monospace;
        font-size: 11px;
        padding: 4px 8px;
    }
    
    /* Force table display */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    th, td {
        display: table-cell !important;
        vertical-align: middle !important;
    }
    
    /* Client name styling */
    .fw-bold {
        color: #2c3e50;
        font-size: 15px;
    }
    
    /* Action buttons */
    .btn-info {
        background-color: #17a2b8;
        border-color: #17a2b8;
    }
    
    .btn-warning {
        background-color: #ffc107;
        border-color: #ffc107;
    }
    
    .btn-danger {
        background-color: #dc3545;
        border-color: #dc3545;
    }
    
    /* Row hover effect */
    .table tbody tr {
        transition: background-color 0.2s;
    }
    
    /* Ensure proper spacing */
    .mb-4 {
        margin-bottom: 1.5rem !important;
    }
    
    .mt-2 {
        margin-top: 0.5rem !important;
    }
    
    /* Make sure everything is visible */
    * {
        box-sizing: border-box;
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
        <!-- Simple Sidebar -->
        <div class="sidebar d-none d-md-block">
            <div class="sidebar-content">
                <ul class="sidebar-menu">
                    <li><a href="../../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="../../pages/users/index.php"><i class="fas fa-users"></i> User Management</a></li>
                    <li><a href="index.php" class="active"><i class="fas fa-building"></i> Client Management</a></li>
                    <li><a href="../../pages/tickets/index.php"><i class="fas fa-ticket-alt"></i> Tickets</a></li>
                    <li><a href="../../pages/assets/index.php"><i class="fas fa-server"></i> Assets</a></li>
                    <li><a href="../../pages/reports/index.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="../../pages/staff/profile.php"><i class="fas fa-user-circle"></i> My Profile</a></li>
                    <li><a href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
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