<?php
// pages/users/index.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/profile_picture_helper.php';

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

// Get distinct user types for tabs and filters (filtered by permissions)
$userTypesQuery = "SELECT DISTINCT user_type FROM users WHERE user_type IS NOT NULL";
if ($user_role === 'admin') {
    $userTypesQuery .= " AND user_type != 'super_admin'";
} elseif ($user_role === 'manager') {
    $userTypesQuery .= " AND user_type IN ('support_tech', 'client')";
}
$userTypesQuery .= " ORDER BY user_type";

$userTypesStmt = $pdo->query($userTypesQuery);
$user_types = $userTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Build query with filters - adjust based on user role
$usersByRole = [];
$countsByRole = [];

foreach ($user_types as $type) {
    $query = "SELECT u.id, u.email, u.user_type, u.is_active, u.email_verified, u.two_factor_enabled, u.last_login, u.created_at as user_created_at, u.updated_at as user_updated_at, u.role_id, ur.role_name 
              FROM users u 
              LEFT JOIN user_roles ur ON u.role_id = ur.id 
              WHERE u.user_type = ?";
    $params = [$type];

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
    $usersByRole[$type] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count for each role tab
    $countQuery = "SELECT COUNT(*) as count FROM users u WHERE u.user_type = ?";
    $countParams = [$type];
    
    if ($user_role === 'admin') {
        $countQuery .= " AND u.user_type != 'super_admin'";
    } elseif ($user_role === 'manager') {
        $countQuery .= " AND u.user_type IN ('support_tech', 'client')";
    }
    
    if (!empty($search)) {
        $countQuery .= " AND (LOWER(u.email) LIKE LOWER(?) OR LOWER(u.user_type) LIKE LOWER(?))";
        $searchTerm = "%$search%";
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }
    
    if (!empty($status_filter)) {
        if ($status_filter === 'active') {
            $countQuery .= " AND u.is_active = true";
        } elseif ($status_filter === 'inactive') {
            $countQuery .= " AND u.is_active = false";
        }
    }
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $countsByRole[$type] = $countResult['count'];
}

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
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
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
                                    Showing users across all roles
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
            
            <!-- Users Tabs Interface -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list"></i> User List</h5>
                </div>
                <div class="card-body">
                    <!-- Role Tabs -->
                    <ul class="nav nav-tabs" id="roleTabs" role="tablist">
                        <?php foreach ($user_types as $index => $type): 
                            $isActive = ($index === 0 && empty($role_filter)) || $role_filter === $type; 
                        ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $isActive ? 'active' : '' ?>" 
                                    id="<?= $type ?>-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#<?= $type ?>-pane" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="<?= $type ?>-pane" 
                                    aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?> 
                                <span class="badge bg-secondary ms-1"><?= $countsByRole[$type] ?></span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($user_types)): // Show all tab when no specific roles exist ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" 
                                    id="all-tab" 
                                    data-bs-toggle="tab" 
                                    data-bs-target="#all-pane" 
                                    type="button" 
                                    role="tab" 
                                    aria-controls="all-pane" 
                                    aria-selected="true">
                                All Users
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="roleTabContent">
                        <?php foreach ($user_types as $index => $type): 
                            $isActive = ($index === 0 && empty($role_filter)) || $role_filter === $type; 
                        ?>
                        <div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" 
                             id="<?= $type ?>-pane" 
                             role="tabpanel" 
                             aria-labelledby="<?= $type ?>-tab">
                            <?php if (empty($usersByRole[$type])): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h4>No <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?> users found</h4>
                                <p class="text-muted">There are currently no users with the role of <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?></p>
                                <?php if ($can_create): ?>
                                <a href="<?php echo route('users.create'); ?>?role=<?= urlencode($type) ?>" class="btn btn-primary mt-2">
                                    <i class="fas fa-plus"></i> Add <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) ?> User
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive mt-3">
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
                                        <?php foreach ($usersByRole[$type] as $user): 
                                            $can_edit_user = canEditUsers($user_role, $user['user_type']);
                                            $can_delete_user = canDeleteUsers($user_role, $user['user_type']);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo getProfilePictureHTML($user['id'], $user['email'], 'md'); ?>
                                            </td>
                                            <td>
                                                <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar"></i> Joined: <?= date('M d, Y', strtotime($user['user_created_at'])) ?>
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
                                                    <a href="<?php echo route('users.view', ['id' => $user['id']]); ?>" class="btn btn-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($can_edit_user): ?>
                                                    <a href="<?php echo route('users.edit', ['id' => $user['id']]); ?>" class="btn btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php else: ?>
                                                    <button class="btn btn-warning disabled" title="No permission to edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete_user): ?>
                                                    <button type="button" class="btn btn-danger delete-user-btn" 
                                                            data-id="<?= $user['id'] ?>" 
                                                            data-email="<?= htmlspecialchars($user['email']) ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
                        <?php endforeach; ?>
                        
                        <?php if (empty($user_types)): // Show content for all users when no specific roles exist ?>
                        <div class="tab-pane fade show active" 
                             id="all-pane" 
                             role="tabpanel" 
                             aria-labelledby="all-tab">
                            <div class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h4>No users found</h4>
                                <p class="text-muted">There are currently no users in the system</p>
                                <?php if ($can_create): ?>
                                <a href="<?php echo route('users.create'); ?>" class="btn btn-primary mt-2">
                                    <i class="fas fa-plus"></i> Add First User
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php
            // Calculate total stats across all roles
            $total_users = 0;
            $total_active_users = 0;
            $total_verified_users = 0;
            $total_2fa_users = 0;
            
            foreach ($usersByRole as $role_users) {
                $total_users += count($role_users);
                $total_active_users += count(array_filter($role_users, fn($u) => $u['is_active']));
                $total_verified_users += count(array_filter($role_users, fn($u) => $u['email_verified']));
                $total_2fa_users += count(array_filter($role_users, fn($u) => $u['two_factor_enabled']));
            }
            ?>
            
            <!-- Quick Stats -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0">Total Users</h6>
                                    <h2 class="mb-0"><?= $total_users ?></h2>
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
                                        <?= $total_active_users ?>
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
                                        <?= $total_verified_users ?>
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
                                        <?= $total_2fa_users ?>
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

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST" id="deleteUserForm">
                    <div class="modal-body">
                        <p>Are you sure you want to delete user <strong id="deleteUserEmail"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Deletion Type</label>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="delete_type" id="archiveOption" value="archive" checked>
                                <label class="form-check-label" for="archiveOption">
                                    Archive User (Recommended)
                                    <small class="d-block text-muted">Deactivate account and preserve data for reporting.</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="delete_type" id="deleteOption" value="delete">
                                <label class="form-check-label" for="deleteOption">
                                    Permanent Delete
                                    <small class="d-block text-danger">Irreversible action. Removes all user data.</small>
                                </label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="deleteReason" class="form-label">Reason for Deletion <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="deleteReason" name="reason" rows="3" required placeholder="Please provide a reason..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Deletion</button>
                    </div>
                </form>
            </div>
        </div>
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
        
        // Delete User Modal Handler
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
            const deleteForm = document.getElementById('deleteUserForm');
            const userEmailSpan = document.getElementById('deleteUserEmail');
            
            document.querySelectorAll('.delete-user-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.getAttribute('data-id');
                    const userEmail = this.getAttribute('data-email');
                    
                    // Set user email in modal
                    userEmailSpan.textContent = userEmail;
                    
                    // Update form action
                    // Construct the URL manually to ensure it matches the route pattern
                    // The .htaccess will handle the rewrite to delete.php?id=...
                    // Unlikely needed for .htaccess but good practice to clean double slashes if any
                    // Use a placeholder that we can safely replace
                    const routeUrl = "<?php echo route('users.delete', ['id' => '__ID__']); ?>";
                    deleteForm.action = routeUrl.replace('__ID__', userId);
                    
                    deleteModal.show();
                });
            });
        });
    </script>
</body>
</html>