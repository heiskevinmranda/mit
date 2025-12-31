<?php
require_once '../../includes/header.php';
requirePermission('admin');

$pdo = getDBConnection();
$user_id = $_GET['id'] ?? 0;

// Get user data
$stmt = $pdo->prepare("
    SELECT u.*, sp.* 
    FROM users u 
    LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    setFlashMessage('error', 'User not found!');
    header('Location: index.php');
    exit;
}

// Get all roles
$roles = $pdo->query("SELECT role_name FROM user_roles WHERE role_name != 'super_admin' ORDER BY role_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Update user
        $email = $_POST['email'];
        $user_type = $_POST['user_type'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET email = ?, user_type = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$email, $user_type, $is_active, $user_id]);
        
        // Update password if provided
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$password, $user_id]);
        }
        
        // Update staff profile if exists or create if needed
        if ($user_type !== 'client') {
            if ($user['staff_id']) {
                // Update existing staff profile
                $stmt = $pdo->prepare("
                    UPDATE staff_profiles 
                    SET full_name = ?, designation = ?, department = ?, phone_number = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $_POST['full_name'], $_POST['designation'], 
                    $_POST['department'], $_POST['phone_number'], $user_id
                ]);
            } else {
                // Create new staff profile
                $staff_id = $_POST['staff_id'] ?? 'MSP-' . strtoupper(uniqid());
                $stmt = $pdo->prepare("
                    INSERT INTO staff_profiles 
                    (user_id, staff_id, full_name, designation, department, phone_number, employment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user_id, $staff_id, $_POST['full_name'], $_POST['designation'], 
                    $_POST['department'], $_POST['phone_number'], 'Active'
                ]);
            }
        } else {
            // Delete staff profile if exists
            if ($user['staff_id']) {
                $stmt = $pdo->prepare("DELETE FROM staff_profiles WHERE user_id = ?");
                $stmt->execute([$user_id]);
            }
        }
        
        $pdo->commit();
        setFlashMessage('success', 'User updated successfully!');
        header('Location: index.php');
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error updating user: " . $e->getMessage();
    }
}
?>

<div class="main-content">
    <div class="header">
        <h1><i class="fas fa-user-edit"></i> Edit User</h1>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Users
        </a>
    </div>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Edit User Information</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Address *</label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">New Password (leave blank to keep current)</label>
                                    <input type="password" name="password" class="form-control" minlength="6">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">User Role *</label>
                                    <select name="user_type" class="form-select" required id="roleSelect">
                                        <option value="">Select Role</option>
                                        <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['role_name']; ?>" 
                                                <?php echo $user['user_type'] == $role['role_name'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $role['role_name'])); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Account Status</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" 
                                               id="is_active" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active Account
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Staff Information -->
                        <div id="staffInfo" style="<?php echo $user['user_type'] == 'client' ? 'display: none;' : ''; ?>">
                            <hr>
                            <h5 class="mb-3">Staff Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" name="full_name" class="form-control"
                                               value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>"
                                               <?php echo $user['user_type'] != 'client' ? 'required' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Staff ID</label>
                                        <input type="text" name="staff_id" class="form-control" 
                                               value="<?php echo htmlspecialchars($user['staff_id'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Designation *</label>
                                        <input type="text" name="designation" class="form-control"
                                               value="<?php echo htmlspecialchars($user['designation'] ?? ''); ?>"
                                               <?php echo $user['user_type'] != 'client' ? 'required' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Department *</label>
                                        <input type="text" name="department" class="form-control"
                                               value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>"
                                               <?php echo $user['user_type'] != 'client' ? 'required' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" name="phone_number" class="form-control"
                                               value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update User
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('roleSelect').addEventListener('change', function() {
    const staffInfo = document.getElementById('staffInfo');
    if (this.value === 'client') {
        staffInfo.style.display = 'none';
        staffInfo.querySelectorAll('[required]').forEach(field => {
            field.removeAttribute('required');
        });
    } else {
        staffInfo.style.display = 'block';
        staffInfo.querySelectorAll('input').forEach(field => {
            if (field.name === 'full_name' || field.name === 'designation' || field.name === 'department') {
                field.setAttribute('required', 'required');
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>