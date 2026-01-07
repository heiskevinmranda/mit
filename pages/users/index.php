<?php
// pages/users/index.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/../../includes/permissions.php';

$page_title = 'User Management';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$user_role = $_SESSION['user_type'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

// Check permissions based on user role
function canManageUsers($user_role) {
    // Super Admin and Admin can manage all users
    if (in_array($user_role, ['super_admin', 'admin'])) {
        return true;
    }
    // Manager can view users but not modify super admins/admins
    if ($user_role === 'manager') {
        return true;
    }
    return false;
}

function canCreateUsers($user_role) {
    return in_array($user_role, ['super_admin', 'admin']);
}

function canEditUsers($user_role, $target_user_role = null) {
    // Super Admin can edit anyone
    if ($user_role === 'super_admin') {
        return true;
    }
    // Admin can edit anyone except super_admin
    if ($user_role === 'admin') {
        return $target_user_role !== 'super_admin';
    }
    // Manager can only edit support_tech and client
    if ($user_role === 'manager') {
        return in_array($target_user_role, ['support_tech', 'client', null]);
    }
    return false;
}

function canDeleteUsers($user_role, $target_user_role = null) {
    // Only Super Admin can delete users (and only non-super_admin users)
    if ($user_role === 'super_admin') {
        return $target_user_role !== 'super_admin';
    }
    return false;
}

// Check current user's permissions
$can_manage = canManageUsers($user_role);
$can_create = canCreateUsers($user_role);
$can_edit_all = canEditUsers($user_role);
$can_delete_all = canDeleteUsers($user_role);

if (!$can_manage) {
    $_SESSION['error'] = "You don't have permission to access the user management section.";
    header("Location: ../../dashboard.php");
    exit();
}

$pdo = getDBConnection();

// Search and filter parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters - adjust based on user role
$query = "SELECT u.*, ur.role_name 
          FROM users u 
          LEFT JOIN user_roles ur ON u.role_id = ur.id 
          WHERE 1=1";
$params = [];

// Filter by user role permissions
if ($user_role === 'admin') {
    // Admin cannot see super_admin users
    $query .= " AND u.user_type != 'super_admin'";
} elseif ($user_role === 'manager') {
    // Manager can only see support_tech and client users
    $query .= " AND u.user_type IN ('support_tech', 'client')";
}

if (!empty($search)) {
    $query .= " AND (LOWER(u.email) LIKE LOWER(?) OR LOWER(u.user_type) LIKE LOWER(?))";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($role_filter)) {
    $query .= " AND u.user_type = ?";
    $params[] = $role_filter;
}

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $query .= " AND u.is_active = true";
    } elseif ($status_filter === 'inactive') {
        $query .= " AND u.is_active = false";
    }
}

$query .= " ORDER BY u.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct user types for filter dropdown (filtered by permissions)
$userTypesQuery = "SELECT DISTINCT user_type FROM users WHERE user_type IS NOT NULL";
if ($user_role === 'admin') {
    $userTypesQuery .= " AND user_type != 'super_admin'";
} elseif ($user_role === 'manager') {
    $userTypesQuery .= " AND user_type IN ('support_tech', 'client')";
}
$userTypesQuery .= " ORDER BY user_type";

$userTypesStmt = $pdo->query($userTypesQuery);
$user_types = $userTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Format last login date
function formatLastLogin($timestamp) {
    if (!$timestamp) return 'Never';
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $interval = $date->diff($now);
    
    if ($interval->days == 0) return 'Today';
    if ($interval->days == 1) return 'Yesterday';
    if ($interval->days < 7) return $interval->days . ' days ago';
    if ($interval->days < 30) return floor($interval->days / 7) . ' weeks ago';
    return $date->format('M d, Y');
}

// Format user role badge
function getRoleBadge($role) {
    $badgeClasses = [
        'super_admin' => 'bg-danger',
        'admin' => 'bg-primary',
        'manager' => 'bg-success',
        'support_tech' => 'bg-info',
        'client' => 'bg-warning'
    ];
    
    $roleNames = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'support_tech' => 'Support Tech',
        'client' => 'Client'
    ];
    
    $class = $badgeClasses[$role] ?? 'bg-secondary';
    $name = $roleNames[$role] ?? ucfirst(str_replace('_', ' ', $role));
    
    return '<span class="badge ' . $class . '">' . $name . '</span>';
}

// Close PHP and start HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'MSP Application'; ?></title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .main-content {
            padding: 1rem !important;
        }

        @media (min-width: 992px) {
            .main-content {
                padding: 1.5rem !important;
            }
        }

        .header {
            padding: 1.5rem !important;
            margin-bottom: 1.5rem !important;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .role-info {
            flex: 1;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
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
                <h1><i class="fas fa-users"></i> User Management</h1>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['email']); ?></div>
                        <div style="font-size: 0.9rem; color: #666;">User Management</div>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <div class="role-info">
                    Your role: <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user_role))) ?></strong>
                    <?php if (!$can_edit_all): ?>
                    <span class="permission-warning d-inline-block ms-2">
                        <i class="fas fa-info-circle text-warning"></i> Limited permissions
                    </span>
                    <?php endif; ?>
                </div>
                <div class="btn-group">
                    <?php if ($can_create): ?>
                    <a href="<?php echo route('users.create'); ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add User
                    </a>
                    <a href="<?php echo route('users.batch_create'); ?>" class="btn btn-outline-primary">
                        <i class="fas fa-user-plus"></i> Batch Add
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Search and Filter -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Search & Filter</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-lg-5 col-md-6">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Email, user type..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control">
                                <option value="">All Roles</option>
                                <?php foreach ($user_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= $role_filter === $type ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <option value="">All Status</option>
                                <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                        <div class="col-12 mt-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted">
                                    Showing <?= count($users) ?> user(s)
                                </div>
                                <a href="index.php" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-undo"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
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
            
            <!-- Permission Note -->
            <?php if ($user_role === 'manager'): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> 
                As a Manager, you can only view and manage Support Technicians and Clients. You cannot see or modify Admin or Super Admin accounts.
            </div>
            <?php elseif ($user_role === 'admin'): ?>
            <div class="alert alert-warning">
                <i class="fas fa-shield-alt me-2"></i> 
                As an Admin, you can manage all users except Super Admins. Use caution when modifying user permissions.
            </div>
            <?php endif; ?>
            
            <!-- Users Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> User List</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h4>No users found</h4>
                        <p class="text-muted"><?= !empty($search) ? 'Try a different search term' : 'Add your first user to get started' ?></p>
                        <?php if ($can_create): ?>
                        <a href="<?php echo route('users.create'); ?>" class="btn btn-primary mt-2">
                            <i class="fas fa-plus"></i> Add First User
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 50px;"></th>
                                    <th>User Details</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Verification</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): 
                                    $can_edit_user = canEditUsers($user_role, $user['user_type']);
                                    $can_delete_user = canDeleteUsers($user_role, $user['user_type']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-avatar">
                                            <?= strtoupper(substr($user['email'], 0, 1)) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> Joined: <?= date('M d, Y', strtotime($user['created_at'])) ?>
                                        </small>
                                        <?php if ($user['two_factor_enabled']): ?>
                                        <small class="two-factor-badge">2FA</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= getRoleBadge($user['user_type']) ?>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                        <span class="status-active">Active</span>
                                        <?php else: ?>
                                        <span class="status-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['email_verified']): ?>
                                        <span class="verified-badge">
                                            <i class="fas fa-check-circle"></i> Verified
                                        </span>
                                        <?php else: ?>
                                        <span class="unverified-badge">
                                            <i class="fas fa-exclamation-circle"></i> Unverified
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= formatLastLogin($user['last_login']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?php echo route('users.view'); ?>?id=<?= $user['id'] ?>" class="btn btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($can_edit_user): ?>
                                            <a href="<?php echo route('users.edit'); ?>?id=<?= $user['id'] ?>" class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-warning disabled" title="No permission to edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($can_delete_user): ?>
                                            <a href="<?php echo route('users.delete'); ?>?id=<?= $user['id'] ?>" class="btn btn-danger" title="Delete" onclick="return confirm('Delete this user? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php else: ?>
                                            <button class="btn btn-danger disabled" title="No permission to delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
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
            
            <!-- Quick Stats -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">Total Users</h6>
                                    <h2 class="mb-0"><?= count($users) ?></h2>
                                </div>
                                <i class="fas fa-users fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">Active Users</h6>
                                    <h2 class="mb-0">
                                        <?= count(array_filter($users, fn($u) => $u['is_active'])) ?>
                                    </h2>
                                </div>
                                <i class="fas fa-user-check fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">Verified Users</h6>
                                    <h2 class="mb-0">
                                        <?= count(array_filter($users, fn($u) => $u['email_verified'])) ?>
                                    </h2>
                                </div>
                                <i class="fas fa-check-circle fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">2FA Enabled</h6>
                                    <h2 class="mb-0">
                                        <?= count(array_filter($users, fn($u) => $u['two_factor_enabled'])) ?>
                                    </h2>
                                </div>
                                <i class="fas fa-shield-alt fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
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
    <script src="../../js/main.js"></script>
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert:not(.flash-message)').forEach(alert => {
                alert.remove();
            });
        }, 5000);
        
        // Confirm delete actions
        document.querySelectorAll('a[data-confirm]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm(this.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>