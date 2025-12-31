<?php
require_once '../../includes/header.php';
requirePermission('admin');

$pdo = getDBConnection();

// Get all roles
$roles = $pdo->query("SELECT role_name FROM user_roles WHERE role_name != 'super_admin' ORDER BY role_name")->fetchAll();

// Get all staff for reference
$staff_members = $pdo->query("SELECT id, staff_id, full_name FROM staff_profiles ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Create user
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $user_type = $_POST['user_type'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, user_type, is_active, email_verified) 
            VALUES (?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$email, $password, $user_type, $is_active, 1]);
        $user_id = $stmt->fetch()['id'];
        
        // Create staff profile if role is not client
        if ($user_type !== 'client') {
            $staff_id = $_POST['staff_id'] ?? 'MSP-' . strtoupper(uniqid());
            $full_name = $_POST['full_name'];
            $designation = $_POST['designation'];
            $department = $_POST['department'];
            $phone_number = $_POST['phone_number'];
            
            $stmt = $pdo->prepare("
                INSERT INTO staff_profiles 
                (user_id, staff_id, full_name, designation, department, phone_number, employment_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id, $staff_id, $full_name, $designation, 
                $department, $phone_number, 'Active'
            ]);
        }
        
        $pdo->commit();
        setFlashMessage('success', 'User created successfully!');
        header('Location: index.php');
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Error creating user: " . $e->getMessage();
    }
}
?>

<div class="main-content">
    <div class="header">
        <h1><i class="fas fa-user-plus"></i> Create New User</h1>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Users
        </a>
    </div>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">User Information</h5>
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
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
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
                                        <option value="<?php echo $role['role_name']; ?>">
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
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                        <label class="form-check-label" for="is_active">
                                            Active Account
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Staff Information (hidden for client role) -->
                        <div id="staffInfo">
                            <hr>
                            <h5 class="mb-3">Staff Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" name="full_name" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Staff ID</label>
                                        <input type="text" name="staff_id" class="form-control" placeholder="Auto-generated if empty">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Designation *</label>
                                        <input type="text" name="designation" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Department *</label>
                                        <input type="text" name="department" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" name="phone_number" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Create User
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </button>
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
        // Make staff fields not required
        staffInfo.querySelectorAll('[required]').forEach(field => {
            field.removeAttribute('required');
        });
    } else {
        staffInfo.style.display = 'block';
        // Make staff fields required
        staffInfo.querySelectorAll('input').forEach(field => {
            if (field.name === 'full_name' || field.name === 'designation' || field.name === 'department') {
                field.setAttribute('required', 'required');
            }
        });
    }
});

// Trigger change on page load
document.getElementById('roleSelect').dispatchEvent(new Event('change'));
</script>

<?php require_once '../../includes/footer.php'; ?>