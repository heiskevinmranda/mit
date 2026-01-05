<?php
// admin_bulk_edit_users.php - Bulk User Privileges Editor
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php'; // Your database connection file

// ==================== SECURITY CHECK ====================
// Restrict access to Super Admin and Admin only
$allowed_roles = ['Super Admin', 'Admin'];
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    header('Location: /unauthorized.php');
    exit();
}

// ==================== DATABASE OPERATIONS ====================
$pdo = getPDO(); // Assume this function returns your PDO connection

// Fetch all roles for the dropdown
$role_stmt = $pdo->query("SELECT id, role_name, permissions FROM user_roles ORDER BY role_name");
$all_roles = $role_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for updating a user
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $user_id = $_POST['user_id'];
    $new_role_id = $_POST['role_id'];
    
    try {
        $update_stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
        $update_stmt->execute([$new_role_id, $user_id]);
        $update_message = '<div class="alert alert-success">User privileges updated successfully!</div>';
        
        // Log the action (optional)
        // logAudit($_SESSION['user_id'], 'UPDATE_USER_ROLE', 'users', $user_id, "Changed role to ID: $new_role_id");
    } catch (PDOException $e) {
        $update_message = '<div class="alert alert-danger">Error updating user: ' . $e->getMessage() . '</div>';
    }
}

// Handle form submission for updating multiple users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $selected_users = $_POST['selected_users'] ?? [];
    $bulk_role_id = $_POST['bulk_role_id'];
    
    if (!empty($selected_users) && $bulk_role_id) {
        $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
        $params = array_merge([$bulk_role_id], $selected_users);
        
        try {
            $bulk_stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE id IN ($placeholders)");
            $bulk_stmt->execute($params);
            $update_message = '<div class="alert alert-success">Updated ' . count($selected_users) . ' user(s) successfully!</div>';
        } catch (PDOException $e) {
            $update_message = '<div class="alert alert-danger">Bulk update error: ' . $e->getMessage() . '</div>';
        }
    } else {
        $update_message = '<div class="alert alert-warning">Please select users and a role for bulk update.</div>';
    }
}

// Fetch all users with their roles and staff info using your query
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
        array_to_string(r.permissions, ', ') as permissions_list,
        array_length(r.permissions, 1) as permission_count
    FROM users u
    LEFT JOIN user_roles r ON u.role_id = r.id
    LEFT JOIN staff_profiles s ON u.id = s.user_id
    ORDER BY u.created_at DESC, u.email
";

$user_stmt = $pdo->query($sql);
$all_users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
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
        body { background-color: #f8f9fa; padding-top: 20px; }
        .header-section { background: linear-gradient(135deg, #004E89 0%, #2a9d8f 100%); color: white; border-radius: 10px; padding: 25px; margin-bottom: 30px; }
        .user-table th { background-color: #e9ecef; position: sticky; top: 0; }
        .role-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .badge-super-admin { background-color: #dc3545; color: white; }
        .badge-admin { background-color: #fd7e14; color: white; }
        .badge-manager { background-color: #0d6efd; color: white; }
        .badge-support { background-color: #198754; color: white; }
        .badge-client { background-color: #6c757d; color: white; }
        .permissions-pill {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 3px 8px;
            margin: 2px;
            border-radius: 12px;
            font-size: 0.75em;
            display: inline-block;
        }
        .action-sidebar {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 20px;
        }
        .form-control-sm { font-size: 0.875rem; }
        .table-responsive { max-height: 600px; overflow-y: auto; }
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
                <a href="dashboard.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>

        <!-- Display Messages -->
        <?php echo $update_message; ?>

        <div class="row">
            <!-- Main Content: Users Table -->
            <div class="col-lg-9">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> All Users (<?php echo count($all_users); ?> total)</h5>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                            <label class="form-check-label" for="selectAllCheckbox">
                                Select All for Bulk Action
                            </label>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 user-table">
                                <thead>
                                    <tr>
                                        <th width="30"><input type="checkbox" id="selectAll"></th>
                                        <th>User Details</th>
                                        <th>Current Role</th>
                                        <th>Staff Info</th>
                                        <th>Status</th>
                                        <th>Permissions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users as $user): 
                                        $role_class = 'badge-' . strtolower(str_replace(' ', '-', $user['role_name'] ?? 'no-role'));
                                        $permissions_list = explode(', ', $user['permissions_list']);
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="user-checkbox" name="selected_users[]" value="<?php echo htmlspecialchars($user['user_id']); ?>"></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['email']); ?></strong><br>
                                            <small class="text-muted">ID: <?php echo substr($user['user_id'], 0, 8); ?>... | Joined: <?php echo date('Y-m-d', strtotime($user['user_created'])); ?></small><br>
                                            <small>Last login: <?php echo $user['last_login'] ? date('M d, H:i', strtotime($user['last_login'])) : 'Never'; ?></small>
                                        </td>
                                        <td>
                                            <span class="role-badge <?php echo $role_class; ?>">
                                                <?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['staff_name']): ?>
                                                <?php echo htmlspecialchars($user['staff_name']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($user['designation']); ?> • <?php echo htmlspecialchars($user['department']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">No staff profile</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['is_active']): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Inactive</span>
                                            <?php endif; ?>
                                            <br>
                                            <?php if ($user['email_verified']): ?>
                                                <span class="badge bg-info mt-1"><i class="fas fa-envelope"></i> Verified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="max-width: 200px;">
                                                <small><?php echo $user['permission_count']; ?> permission(s)</small>
                                                <?php if (!empty($user['permissions_list'])): ?>
                                                    <div class="mt-1">
                                                        <?php foreach (array_slice($permissions_list, 0, 3) as $perm): ?>
                                                            <span class="permissions-pill"><?php echo htmlspecialchars(trim($perm)); ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($permissions_list) > 3): ?>
                                                            <span class="permissions-pill">+<?php echo count($permissions_list) - 3; ?> more</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <!-- Quick Edit Form -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                                <div class="input-group input-group-sm">
                                                    <select class="form-select form-select-sm" name="role_id" style="min-width: 120px;">
                                                        <?php foreach ($all_roles as $role): ?>
                                                            <option value="<?php echo $role['id']; ?>" <?php echo ($role['id'] == $user['role_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($role['role_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="update_user" class="btn btn-primary btn-sm" title="Update this user">
                                                        <i class="fas fa-save"></i>
                                                    </button>
                                                </div>
                                            </form>
                                            <a href="javascript:void(0)" class="btn btn-outline-info btn-sm mt-1 w-100" 
                                               onclick="viewUserDetails('<?php echo htmlspecialchars($user['user_id']); ?>')">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar: Bulk Actions -->
            <div class="col-lg-3">
                <div class="action-sidebar">
                    <h5><i class="fas fa-tasks"></i> Bulk Actions</h5>
                    <p class="text-muted">Apply changes to selected users</p>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Select Role for Bulk Update</label>
                            <select class="form-select" name="bulk_role_id" required>
                                <option value="">-- Choose Role --</option>
                                <?php foreach ($all_roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?> 
                                        (<?php echo count(explode(',', array_to_string($role['permissions'] ?? [], ', '))); ?> perms)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Selected Users: <span id="selectedCount">0</span></label>
                            <div class="d-grid gap-2">
                                <button type="submit" name="bulk_update" class="btn btn-warning">
                                    <i class="fas fa-users"></i> Update Selected Users
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="clearSelection">
                                    <i class="fas fa-times"></i> Clear Selection
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr>
                    
                    <h6><i class="fas fa-search"></i> Quick Filter</h6>
                    <div class="mb-3">
                        <input type="text" class="form-control" id="userSearch" placeholder="Search by email or name...">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Filter by Role</label>
                        <select class="form-select" id="roleFilter">
                            <option value="">All Roles</option>
                            <?php foreach ($all_roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role['role_name']); ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="d-grid">
                        <a href="user_roles_manage.php" class="btn btn-outline-primary">
                            <i class="fas fa-cog"></i> Manage Role Permissions
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for Interactive Features -->
    <script>
        // Select All Checkbox functionality
        document.getElementById('selectAll').addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
            updateSelectedCount();
        });
        
        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.user-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = selected;
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
        
        // Table filtering
        document.getElementById('userSearch').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.user-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        // Role filtering
        document.getElementById('roleFilter').addEventListener('change', function() {
            const filterRole = this.value;
            const rows = document.querySelectorAll('.user-table tbody tr');
            
            rows.forEach(row => {
                if (!filterRole) {
                    row.style.display = '';
                    return;
                }
                
                const roleCell = row.querySelector('.role-badge');
                const roleText = roleCell ? roleCell.textContent.trim() : '';
                row.style.display = roleText === filterRole ? '' : 'none';
            });
        });
        
        // View user details (placeholder function)
        function viewUserDetails(userId) {
            alert('View details for user ID: ' + userId + '\n\nThis would open a detailed view or modal in a full implementation.');
            // In a real implementation, you might use:
            // window.open('user_details.php?id=' + userId, '_blank');
            // OR
            // fetchUserDetails(userId).then(data => showModal(data));
        }
        
        // Initialize selected count
        updateSelectedCount();
    </script>
</body>
</html>