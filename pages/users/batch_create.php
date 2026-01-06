<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/routes.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$user_role = $_SESSION['user_type'] ?? null;

// Check permissions based on user role
function canCreateUsers($user_role) {
    // Only Super Admin and Admin can create users
    return in_array($user_role, ['super_admin', 'admin']);
}

// Check current user's permissions
$can_create = canCreateUsers($user_role);

if (!$can_create) {
    $_SESSION['error'] = "You don't have permission to create users.";
    header("Location: " . route('users.index'));
    exit();
}

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $users_data = $_POST['users'] ?? [];
    $created_count = 0;
    $errors = [];
    
    foreach ($users_data as $index => $user_data) {
        if (empty($user_data['email'])) continue;
        
        try {
            // Check if user already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$user_data['email']]);
            
            if ($stmt->fetch()) {
                $errors[] = "User {$user_data['email']} already exists (Row {$index})";
                continue;
            }
            
            // Create user
            $password = password_hash($user_data['password'] ?? 'Password123', PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password, user_type, is_active, email_verified) 
                VALUES (?, ?, ?, ?, ?)
                RETURNING id
            ");
            $stmt->execute([
                $user_data['email'],
                $password,
                $user_data['user_type'],
                1,
                1
            ]);
            $user_id = $stmt->fetch()['id'];
            
            // Create staff profile if not client
            if ($user_data['user_type'] !== 'client') {
                $staff_id = $user_data['staff_id'] ?? 'MSP-' . strtoupper(uniqid());
                
                $stmt = $pdo->prepare("
                    INSERT INTO staff_profiles 
                    (user_id, staff_id, full_name, designation, department, phone_number, employment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user_id,
                    $staff_id,
                    $user_data['full_name'] ?? '',
                    $user_data['designation'] ?? '',
                    $user_data['department'] ?? '',
                    $user_data['phone_number'] ?? '',
                    'Active'
                ]);
            }
            
            $created_count++;
            
        } catch (PDOException $e) {
            $errors[] = "Error creating {$user_data['email']}: " . $e->getMessage();
        }
    }
    
    if ($created_count > 0) {
        setFlashMessage('success', "Successfully created {$created_count} users!");
    }
    
    if (!empty($errors)) {
        $_SESSION['batch_errors'] = $errors;
    }
    
    header('Location: ' . route('users.index'));
    exit;
}

$roles = $pdo->query("SELECT role_name FROM user_roles WHERE role_name != 'super_admin' ORDER BY role_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Create Users - MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-users"></i> Batch Create Users</h1>
                <a href="<?php echo route('users.index'); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Users
                </a>
            </div>

            <div class="row">
                <div class="col-md-10 mx-auto">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Create Multiple Users</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                Add multiple users at once. Fill in the required fields for each user.
                            </div>
                            
                            <form method="POST" id="batchForm" action="<?php echo route('users.batch_create'); ?>">
                                <div id="usersContainer">
                                    <div class="user-row card mb-3">
                                        <div class="card-body">
                                            <div class="row g-3">
                                                <div class="col-md-3">
                                                    <label class="form-label">Email *</label>
                                                    <input type="email" name="users[0][email]" class="form-control" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Password</label>
                                                    <input type="text" name="users[0][password]" class="form-control" value="Password123">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Role *</label>
                                                    <select name="users[0][user_type]" class="form-select" required>
                                                        <option value="">Select</option>
                                                        <?php foreach ($roles as $role): ?>
                                                        <option value="<?php echo $role['role_name']; ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $role['role_name'])); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Full Name</label>
                                                    <input type="text" name="users[0][full_name]" class="form-control">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Designation</label>
                                                    <input type="text" name="users[0][designation]" class="form-control">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="button" class="btn btn-outline-primary" id="addMore">
                                        <i class="fas fa-plus"></i> Add Another User
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Create All Users
                                    </button>
                                </div>
                            </form>
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
    let userCount = 1;
    
    document.getElementById('addMore').addEventListener('click', function() {
        const container = document.getElementById('usersContainer');
        const newRow = document.createElement('div');
        newRow.className = 'user-row card mb-3';
        newRow.innerHTML = `
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Email *</label>
                        <input type="email" name="users[${userCount}][email]" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Password</label>
                        <input type="text" name="users[${userCount}][password]" class="form-control" value="Password123">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Role *</label>
                        <select name="users[${userCount}][user_type]" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['role_name']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $role['role_name'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="users[${userCount}][full_name]" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Designation</label>
                        <input type="text" name="users[${userCount}][designation]" class="form-control">
                    </div>
                </div>
            </div>
        `;
        container.appendChild(newRow);
        userCount++;
    });
    
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });
    </script>
</body>
</html>