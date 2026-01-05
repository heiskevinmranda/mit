<?php
// admin_bulk_edit_users.php - Bulk User Privileges Editor
// Location: C:\wamp64\www\mit\pages\admin\admin_bulk_edit_users.php

session_start();

// ==================== PATH CORRECTION ====================
// For WAMP structure: C:\wamp64\www\mit\pages\admin\admin_bulk_edit_users.php
// Includes should be at: C:\wamp64\www\mit\includes\

$base_dir = dirname(__DIR__, 2); // Goes up 2 levels from /pages/admin/
$auth_file = $base_dir . '/includes/auth.php';
$db_file = $base_dir . '/includes/db.php';

// Debug: Check if files exist
// echo "Base dir: $base_dir<br>";
// echo "Auth file: $auth_file<br>";
// echo "DB file: $db_file<br>";

// Check if files exist before requiring
if (!file_exists($auth_file)) {
    // Try alternative path
    $auth_file = $base_dir . '/../includes/auth.php';
    $db_file = $base_dir . '/../includes/db.php';
}

if (!file_exists($auth_file)) {
    die("❌ Error: Cannot find auth.php. Tried:<br>
         1. $base_dir/includes/auth.php<br>
         2. $base_dir/../includes/auth.php<br>
         Please check your file structure.");
}

require_once $auth_file;
require_once $db_file;

// ==================== SECURITY CHECK ====================
// Temporarily bypass for testing - REMOVE IN PRODUCTION
$allowed_roles = ['Super Admin', 'Admin'];

if (!isset($_SESSION['user_type'])) {
    // For testing, set a dummy admin session
    $_SESSION['user_type'] = 'Super Admin';
    $_SESSION['email'] = 'admin@msp.com';
    $_SESSION['user_id'] = 'test-id-123';
}

// Uncomment this for production:
/*
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: ../../login.php');
    exit();
}
*/

// ==================== DATABASE OPERATIONS ====================
// Create database connection directly (in case db.php doesn't work)
try {
    // Try to get connection from db.php first
    if (function_exists('getPDO')) {
        $pdo = getPDO();
    } else {
        // Direct connection as fallback
        $pdo = new PDO('pgsql:host=localhost;dbname=MSP_Application', 'MSPAppUser', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch all roles for the dropdown
try {
    $role_stmt = $pdo->query("SELECT id, role_name, permissions FROM user_roles ORDER BY role_name");
    $all_roles = $role_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching roles: " . $e->getMessage());
}

// Handle form submission for updating a user
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_user'])) {
        $user_id = $_POST['user_id'] ?? '';
        $new_role_id = $_POST['role_id'] ?? '';
        
        if ($user_id && $new_role_id) {
            try {
                $update_stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
                $update_stmt->execute([$new_role_id, $user_id]);
                $update_message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> User privileges updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
            } catch (PDOException $e) {
                $update_message = '<div class="alert alert-danger">Error updating user: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    // Handle bulk update
    if (isset($_POST['bulk_update'])) {
        $selected_users = $_POST['selected_users'] ?? [];
        $bulk_role_id = $_POST['bulk_role_id'] ?? '';
        
        if (!empty($selected_users) && $bulk_role_id) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                $sql = "UPDATE users SET role_id = ? WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                
                // Bind parameters: role_id first, then user IDs
                $params = [$bulk_role_id];
                foreach ($selected_users as $user_id) {
                    $params[] = $user_id;
                }
                
                $stmt->execute($params);
                $update_message = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> Updated ' . count($selected_users) . ' user(s) successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
            } catch (PDOException $e) {
                $update_message = '<div class="alert alert-danger">Bulk update error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } else {
            $update_message = '<div class="alert alert-warning">Please select users and a role for bulk update.</div>';
        }
    }
}

// Fetch all users with their roles and staff info
try {
    $sql = "
        SELECT 
            u.id as user_id,
            u.email,
            u.user_type,
            u.role_id,
            r.role_name,
            u.is_active,
            u.email_verified,
            u.last_login,
            u.created_at as user_created,
            s.full_name as staff_name,
            s.designation,
            s.department,
            s.employment_status,
            r.permissions,
            array_length(r.permissions, 1) as permission_count
        FROM users u
        LEFT JOIN user_roles r ON u.role_id = r.id
        LEFT JOIN staff_profiles s ON u.id = s.user_id
        ORDER BY u.created_at DESC, u.email
    ";
    
    $user_stmt = $pdo->query($sql);
    $all_users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check if we got data
    // echo "<!-- Debug: Fetched " . count($all_users) . " users -->";
    
} catch (PDOException $e) {
    die("Error fetching users: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk User Privileges Editor</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #004E89;
            --secondary-color: #2a9d8f;
            --success-color: #198754;
            --warning-color: #fd7e14;
            --danger-color: #dc3545;
        }
        
        body { 
            background-color: #f8f9fa; 
            padding-top: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header-section { 
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%); 
            color: white; 
            border-radius: 10px; 
            padding: 25px; 
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .user-table th { 
            background-color: #e9ecef; 
            position: sticky; 
            top: 0;
            font-weight: 600;
            color: #495057;
        }
        
        .table-responsive { 
            max-height: 70vh; 
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .role-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
            margin: 2px;
        }
        
        .badge-super-admin { background-color: var(--danger-color); color: white; }
        .badge-admin { background-color: var(--warning-color); color: white; }
        .badge-manager { background-color: var(--primary-color); color: white; }
        .badge-support { background-color: var(--success-color); color: white; }
        .badge-client { background-color: #6c757d; color: white; }
        .badge-no-role { background-color: #adb5bd; color: white; }
        
        .permissions-pill {
            background-color: #e7f1ff;
            color: var(--primary-color);
            padding: 3px 10px;
            margin: 2px;
            border-radius: 15px;
            font-size: 0.75em;
            display: inline-block;
            border: 1px solid #cfe2ff;
        }
        
        .action-sidebar {
            background-color: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            position: sticky;
            top: 20px;
            height: fit-content;
        }
        
        .user-row:hover {
            background-color: #f8f9fa;
            transition: background-color 0.2s;
        }
        
        .form-control-sm { 
            font-size: 0.875rem; 
            border-radius: 5px;
        }
        
        .btn-sm {
            border-radius: 5px;
            padding: 4px 10px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .header-section {
                padding: 15px;
            }
            
            .table-responsive {
                font-size: 0.9rem;
            }
            
            .action-sidebar {
                position: static;
                margin-top: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="header-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-users-cog"></i> Bulk User Privileges Editor</h1>
                    <p class="mb-0">Manage roles and permissions for all users from a single window</p>
                </div>
                <a href="../../dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <!-- Quick Stats -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($all_users); ?></div>
                        <div class="text-muted">Total Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">
                            <?php 
                            $active_count = array_filter($all_users, fn($u) => $u['is_active']);
                            echo count($active_count);
                            ?>
                        </div>
                        <div class="text-muted">Active Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo count($all_roles); ?></div>
                        <div class="text-muted">Available Roles</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">
                            <?php 
                            $today = date('Y-m-d');
                            $recent_users = array_filter($all_users, fn($u) => 
                                date('Y-m-d', strtotime($u['user_created'])) === $today
                            );
                            echo count($recent_users);
                            ?>
                        </div>
                        <div class="text-muted">New Today</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Display Messages -->
        <?php echo $update_message; ?>

        <div class="row">
            <!-- Main Content: Users Table -->
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> All Users 
                            <span class="badge bg-primary"><?php echo count($all_users); ?></span>
                        </h5>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                            <label class="form-check-label" for="selectAllCheckbox">
                                Select All
                            </label>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 user-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>User Details</th>
                                        <th>Role</th>
                                        <th>Staff Info</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_users)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="fas fa-user-slash fa-2x mb-3"></i><br>
                                            No users found in the database.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_users as $index => $user): 
                                            // Determine role class
                                            $role_name = $user['role_name'] ?? 'No Role';
                                            $role_class = 'badge-' . strtolower(str_replace(' ', '-', $role_name));
                                            if (!in_array($role_class, ['badge-super-admin', 'badge-admin', 'badge-manager', 'badge-support', 'badge-client'])) {
                                                $role_class = 'badge-no-role';
                                            }
                                            
                                            // Format permissions for display
                                            $permissions = $user['permissions'] ?? [];
                                            $permission_count = $user['permission_count'] ?? 0;
                                        ?>
                                        <tr class="user-row">
                                            <td class="checkbox-cell">
                                                <input type="checkbox" class="user-checkbox" 
                                                       name="selected_users[]" 
                                                       value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <div style="width: 40px; height: 40px; background-color: #e9ecef; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                                            <i class="fas fa-user text-secondary"></i>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['email']); ?></strong>
                                                        <div class="text-muted small">
                                                            ID: <?php echo substr($user['user_id'], 0, 8); ?>...
                                                            • Joined: <?php echo date('M d, Y', strtotime($user['user_created'])); ?>
                                                        </div>
                                                        <?php if ($user['last_login']): ?>
                                                            <small class="text-success">
                                                                <i class="fas fa-sign-in-alt"></i> 
                                                                Last login: <?php echo date('M d, H:i', strtotime($user['last_login'])); ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <small class="text-warning">
                                                                <i class="fas fa-clock"></i> Never logged in
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="role-badge <?php echo $role_class; ?>">
                                                    <?php echo htmlspecialchars($role_name); ?>
                                                </span>
                                                <?php if ($permission_count > 0): ?>
                                                    <div class="mt-1 small text-muted">
                                                        <?php echo $permission_count; ?> permission(s)
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['staff_name']): ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['staff_name']); ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($user['designation'] ?? 'N/A'); ?>
                                                            <?php if ($user['department']): ?>
                                                                • <?php echo htmlspecialchars($user['department']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="badge bg-light text-dark border">
                                                            <?php echo htmlspecialchars($user['employment_status'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">
                                                        <i class="fas fa-user-times"></i> No staff profile
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle"></i> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times-circle"></i> Inactive
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($user['email_verified']): ?>
                                                    <div class="mt-1">
                                                        <span class="badge bg-info">
                                                            <i class="fas fa-envelope"></i> Verified
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <!-- Quick Edit Form -->
                                                <form method="POST" class="mb-2">
                                                    <input type="hidden" name="user_id" 
                                                           value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                                    <div class="input-group input-group-sm">
                                                        <select class="form-select form-select-sm" name="role_id" 
                                                                style="min-width: 130px; font-size: 0.8rem;">
                                                            <option value="">-- Change Role --</option>
                                                            <?php foreach ($all_roles as $role): ?>
                                                                <option value="<?php echo $role['id']; ?>" 
                                                                    <?php echo ($role['id'] == $user['role_id']) ? 'selected' : ''; ?>
                                                                    style="font-size: 0.8rem;">
                                                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" name="update_user" 
                                                                class="btn btn-primary btn-sm" 
                                                                title="Update role">
                                                            <i class="fas fa-save"></i>
                                                        </button>
                                                    </div>
                                                </form>
                                                
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-outline-info" 
                                                            onclick="viewUser('<?php echo htmlspecialchars($user['user_id']); ?>')"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-warning"
                                                            onclick="editUser('<?php echo htmlspecialchars($user['user_id']); ?>')"
                                                            title="Edit User">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($user['is_active']): ?>
                                                        <button type="button" class="btn btn-outline-danger"
                                                                onclick="deactivateUser('<?php echo htmlspecialchars($user['user_id']); ?>')"
                                                                title="Deactivate">
                                                            <i class="fas fa-user-slash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-success"
                                                                onclick="activateUser('<?php echo htmlspecialchars($user['user_id']); ?>')"
                                                                title="Activate">
                                                            <i class="fas fa-user-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar: Bulk Actions & Filters -->
            <div class="col-lg-4">
                <div class="action-sidebar">
                    <h5 class="mb-3">
                        <i class="fas fa-tasks"></i> Bulk Actions
                        <span class="badge bg-secondary" id="selectedCount">0</span>
                    </h5>
                    
                    <form method="POST" class="mb-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Apply Role to Selected Users</label>
                            <select class="form-select" name="bulk_role_id" required>
                                <option value="">-- Choose Role --</option>
                                <?php foreach ($all_roles as $role): 
                                    $perm_count = is_array($role['permissions']) ? count($role['permissions']) : 0;
                                ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?> 
                                    <span class="text-muted">(<?php echo $perm_count; ?> perms)</span>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Selected Users: <span id="selectedList" class="text-muted">None</span></label>
                            <div class="d-grid gap-2">
                                <button type="submit" name="bulk_update" class="btn btn-warning btn-lg">
                                    <i class="fas fa-users"></i> Update Selected Users
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="clearSelection">
                                    <i class="fas fa-times"></i> Clear Selection
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr class="my-4">
                    
                    <h6 class="mb-3"><i class="fas fa-search"></i> Quick Filters</h6>
                    <div class="mb-3">
                        <input type="text" class="form-control" id="userSearch" 
                               placeholder="Search by email or name...">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Filter by Role</label>
                        <select class="form-select" id="roleFilter">
                            <option value="">All Roles</option>
                            <?php 
                            $unique_roles = [];
                            foreach ($all_users as $user) {
                                $role_name = $user['role_name'] ?? 'No Role';
                                if (!in_array($role_name, $unique_roles)) {
                                    $unique_roles[] = $role_name;
                                    echo '<option value="' . htmlspecialchars($role_name) . '">' . 
                                         htmlspecialchars($role_name) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Filter by Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active Only</option>
                            <option value="inactive">Inactive Only</option>
                        </select>
                    </div>
                    
                    <hr class="my-4">
                    
                    <h6 class="mb-3"><i class="fas fa-cog"></i> Management Tools</h6>
                    <div class="d-grid gap-2">
                        <a href="user_roles_manage.php" class="btn btn-outline-primary">
                            <i class="fas fa-user-cog"></i> Manage Role Permissions
                        </a>
                        <a href="add_user.php" class="btn btn-outline-success">
                            <i class="fas fa-user-plus"></i> Add New User
                        </a>
                        <button type="button" class="btn btn-outline-info" onclick="exportToCSV()">
                            <i class="fas fa-file-export"></i> Export to CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for Interactive Features -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select All Checkbox functionality
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
            updateSelectedCount();
        });
        
        // Update selected count and list
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.user-checkbox:checked');
            const count = selected.length;
            document.getElementById('selectedCount').textContent = count;
            
            // Update selected list
            const selectedList = document.getElementById('selectedList');
            if (count === 0) {
                selectedList.textContent = 'None';
                selectedList.className = 'text-muted';
            } else {
                selectedList.textContent = count + ' user(s) selected';
                selectedList.className = 'text-primary fw-bold';
                
                // Show email list on hover
                const emails = Array.from(selected).map(cb => {
                    const row = cb.closest('tr');
                    const emailCell = row.querySelector('td:nth-child(2) strong');
                    return emailCell ? emailCell.textContent : 'Unknown';
                });
                
                selectedList.title = emails.join('\n');
            }
        }
        
        // Add event listeners to all user checkboxes
        document.querySelectorAll('.user-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });
        
        // Clear selection button
        document.getElementById('clearSelection').addEventListener('click', function() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelectedCount();
        });
        
        // Table filtering by search
        document.getElementById('userSearch').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.user-table tbody tr');
            
            rows.forEach(row => {
                if (row.classList.contains('text-center')) return; // Skip "no users" row
                
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        // Role filtering
        document.getElementById('roleFilter').addEventListener('change', function() {
            const filterRole = this.value;
            filterTable();
        });
        
        // Status filtering
        document.getElementById('statusFilter').addEventListener('change', function() {
            filterTable();
        });
        
        function filterTable() {
            const filterRole = document.getElementById('roleFilter').value;
            const filterStatus = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('.user-table tbody tr');
            
            rows.forEach(row => {
                if (row.classList.contains('text-center')) return;
                
                let showRow = true;
                
                // Filter by role
                if (filterRole) {
                    const roleCell = row.querySelector('.role-badge');
                    const roleText = roleCell ? roleCell.textContent.trim() : '';
                    if (roleText !== filterRole) showRow = false;
                }
                
                // Filter by status
                if (filterStatus && showRow) {
                    const statusCell = row.querySelector('td:nth-child(5)');
                    const isActive = statusCell ? statusCell.innerHTML.includes('bg-success') : false;
                    
                    if (filterStatus === 'active' && !isActive) showRow = false;
                    if (filterStatus === 'inactive' && isActive) showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // User action functions
        function viewUser(userId) {
            alert('View details for user ID: ' + userId + '\n\nIn a full implementation, this would open a detailed view or modal.');
        }
        
        function editUser(userId) {
            alert('Edit user ID: ' + userId + '\n\nThis would open an edit form.');
        }
        
        function deactivateUser(userId) {
            if (confirm('Are you sure you want to deactivate this user?')) {
                alert('Deactivating user ID: ' + userId + '\n\nThis would send a deactivation request to the server.');
            }
        }
        
        function activateUser(userId) {
            if (confirm('Are you sure you want to activate this user?')) {
                alert('Activating user ID: ' + userId + '\n\nThis would send an activation request to the server.');
            }
        }
        
        function exportToCSV() {
            alert('Exporting user data to CSV...\n\nIn a full implementation, this would download a CSV file.');
        }
        
        // Initialize
        updateSelectedCount();
        
        // Auto-refresh page every 60 seconds to show latest data
        setTimeout(() => {
            if (confirm('Refresh page to load latest user data?')) {
                location.reload();
            }
        }, 60000);
    </script>
</body>
</html>