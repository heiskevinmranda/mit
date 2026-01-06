<?php
// pages/users/create.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$user_role = $_SESSION['user_type'] ?? null;
$user_id = $_SESSION['user_id'] ?? null;

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

// Initialize variables
$email = $password = $confirm_password = $user_type = $full_name = $phone = $staff_id = '';
$is_active = 1;
$email_verified = 0;
$errors = [];

// Available user types (based on current user's role)
$available_user_types = [];
if ($user_role === 'super_admin') {
    $available_user_types = ['super_admin', 'admin', 'manager', 'support_tech', 'client'];
} elseif ($user_role === 'admin') {
    $available_user_types = ['admin', 'manager', 'support_tech', 'client'];
}

// Get user roles from database
$user_roles = [];
try {
    $rolesQuery = "SELECT id, role_name FROM user_roles ORDER BY role_name";
    $rolesStmt = $pdo->query($rolesQuery);
    $user_roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If user_roles table doesn't exist or is empty, create default roles
    $errors['general'] = "Please run the setup script to create default user roles.";
}

// Get staff profiles for assignment
$staff_profiles = [];
try {
    $staffQuery = "SELECT id, staff_id, full_name, designation FROM staff_profiles ORDER BY full_name";
    $staffStmt = $pdo->query($staffQuery);
    $staff_profiles = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Staff profiles table might not exist yet, ignore error
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_type = $_POST['user_type'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $staff_id = $_POST['staff_id'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $email_verified = isset($_POST['email_verified']) ? 1 : 0;
    $send_welcome_email = isset($_POST['send_welcome_email']);

    // Validate inputs
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email already exists
        $checkEmailQuery = "SELECT id FROM users WHERE email = ?";
        $checkStmt = $pdo->prepare($checkEmailQuery);
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    if (empty($user_type)) {
        $errors['user_type'] = 'User type is required';
    } elseif (!in_array($user_type, $available_user_types)) {
        $errors['user_type'] = 'Invalid user type selection';
    }

    if (empty($full_name)) {
        $errors['full_name'] = 'Full name is required';
    }

    // Validate phone if provided
    if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) {
        $errors['phone'] = 'Invalid phone number';
    }

    // If no errors, create user
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Get role_id for the user_type
            $role_id = null;
            $roleQuery = "SELECT id FROM user_roles WHERE role_name = ? LIMIT 1";
            $roleStmt = $pdo->prepare($roleQuery);
            $roleStmt->execute([$user_type]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($role) {
                $role_id = $role['id'];
            }

            // Insert user with role_id if available
            if ($role_id) {
                $userQuery = "INSERT INTO users (email, password, user_type, role_id, is_active, email_verified, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $userStmt = $pdo->prepare($userQuery);
                $userStmt->execute([$email, $password_hash, $user_type, $role_id, $is_active, $email_verified]);
            } else {
                // Insert without role_id (for backward compatibility)
                $userQuery = "INSERT INTO users (email, password, user_type, is_active, email_verified, created_at, updated_at) 
                              VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                $userStmt = $pdo->prepare($userQuery);
                $userStmt->execute([$email, $password_hash, $user_type, $is_active, $email_verified]);
            }
            
            // Get the last inserted ID
            $new_user_id = $pdo->lastInsertId();

            // Create staff profile if user type is staff and staff_id is provided
            if (in_array($user_type, ['admin', 'manager', 'support_tech']) && !empty($staff_id)) {
                // Check if staff profile already exists for this user
                $checkStaffQuery = "SELECT id FROM staff_profiles WHERE user_id = ? OR staff_id = ?";
                $checkStaffStmt = $pdo->prepare($checkStaffQuery);
                $checkStaffStmt->execute([$new_user_id, $staff_id]);
                
                if (!$checkStaffStmt->fetch()) {
                    $staffQuery = "INSERT INTO staff_profiles 
                                   (user_id, staff_id, full_name, phone_number, official_email, employment_status, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, 'Active', NOW(), NOW())";
                    $staffStmt = $pdo->prepare($staffQuery);
                    $staffStmt->execute([$new_user_id, $staff_id, $full_name, $phone, $email]);
                }
            } elseif ($user_type === 'client') {
                // For client users, we might want to create a client record
                // This could be expanded based on your requirements
            }

            // Audit log
            try {
                $auditQuery = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, created_at) 
                               VALUES (?, ?, ?, ?, ?, NOW())";
                $auditStmt = $pdo->prepare($auditQuery);
                $auditStmt->execute([
                    $user_id,
                    'CREATE',
                    'user',
                    $new_user_id,
                    $_SERVER['REMOTE_ADDR']
                ]);
            } catch (PDOException $e) {
                // Audit table might not exist, ignore error
                error_log("Audit log error: " . $e->getMessage());
            }

            $pdo->commit();
            
            $_SESSION['success'] = "User '$email' created successfully!";
            
            // Send welcome email if requested (placeholder - implement email functionality)
            if ($send_welcome_email) {
                // TODO: Implement email sending functionality
                // mail($email, "Welcome to MSP Portal", "Your account has been created. Email: $email");
            }
            
            // Redirect to user list or view page
            header("Location: " . route('users.index'));
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors['general'] = "Database error: " . $e->getMessage();
            error_log("User creation error: " . $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = "Error: " . $e->getMessage();
            error_log("User creation error: " . $e->getMessage());
        }
    }
}

// Function to format phone number
function formatPhone($phone) {
    if (empty($phone)) return '';
    // Remove all non-numeric characters
    $numbers = preg_replace('/[^0-9]/', '', $phone);
    
    // Format based on length
    if (strlen($numbers) == 10) {
        return '+1 (' . substr($numbers, 0, 3) . ') ' . substr($numbers, 3, 3) . '-' . substr($numbers, 6, 4);
    } elseif (strlen($numbers) > 10) {
        return '+' . substr($numbers, 0, strlen($numbers)-10) . ' (' . substr($numbers, -10, 3) . ') ' . 
               substr($numbers, -7, 3) . '-' . substr($numbers, -4);
    }
    return $phone;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User - MSP Application</title>
    
    <!-- Load CSS files with fallback -->
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* Additional styles specific to this page if needed */
        .main-content {
            padding: 1.5rem !important;
        }
    </style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title">
                <i class="fas fa-user-plus"></i> Create New User
            </h1>
            <p class="text-muted">Add a new user to the system</p>
        </div>
        <a href="<?php echo route('users.index'); ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to Users
        </a>
    </div>
    
    <!-- User Information Section -->
    <div class="card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-user-circle"></i> Current User Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-item">
                        <span class="info-label">Logged in as:</span>
                        <span class="info-value"><?php echo htmlspecialchars($_SESSION['email']); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-item">
                        <span class="info-label">Your role:</span>
                        <span class="info-value"><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user_role))) ?></strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
            
            <!-- Setup Warning -->
            <?php if (!empty($user_roles)): ?>
                <?php if (count($user_roles) === 0): ?>
                <div class="alert alert-warning setup-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Setup Required:</strong> The user roles table is empty. Please run the setup script or create user roles before adding users.
                    <div class="mt-2">
                        <a href="<?php echo route('setup'); ?>" class="btn btn-sm btn-warning">
                            <i class="fas fa-tools"></i> Run Setup
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
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
            
            <!-- Create User Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-user-edit"></i> User Information</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($errors['general']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="create.php" id="createUserForm">
                        <!-- Basic Information Section -->
                        <div class="form-section">
                            <h6><i class="fas fa-id-card me-2"></i> Basic Information</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label required">Email Address</label>
                                    <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                           id="email" name="email" value="<?= htmlspecialchars($email) ?>" 
                                           placeholder="user@example.com" required>
                                    <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label required">Full Name</label>
                                    <input type="text" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" 
                                           id="full_name" name="full_name" value="<?= htmlspecialchars($full_name) ?>" 
                                           placeholder="John Doe" required>
                                    <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                                           id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" 
                                           placeholder="+1 (555) 123-4567">
                                    <?php if (isset($errors['phone'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['phone']) ?></div>
                                    <?php endif; ?>
                                    <small class="text-muted">Optional. Include country code for international numbers.</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="user_type" class="form-label required">User Type / Role</label>
                                    <select class="form-control <?= isset($errors['user_type']) ? 'is-invalid' : '' ?>" 
                                            id="user_type" name="user_type" required>
                                        <option value="">Select User Type</option>
                                        <?php foreach ($available_user_types as $type): 
                                            $type_name = ucfirst(str_replace('_', ' ', $type));
                                            $role_exists = false;
                                            foreach ($user_roles as $role) {
                                                if ($role['role_name'] === $type) {
                                                    $role_exists = true;
                                                    break;
                                                }
                                            }
                                        ?>
                                        <option value="<?= $type ?>" <?= $user_type === $type ? 'selected' : '' ?>>
                                            <?= $type_name ?>
                                            <?php if (!$role_exists && !empty($user_roles)): ?>
                                            <span class="text-warning"> (Role not defined)</span>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($errors['user_type'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['user_type']) ?></div>
                                    <?php endif; ?>
                                    
                                    <!-- Role Descriptions -->
                                    <div class="role-description mt-2">
                                        <small>
                                            <?php if ($user_role === 'super_admin'): ?>
                                            <strong>Super Admin:</strong> Full system access<br>
                                            <strong>Admin:</strong> Manage users, clients, contracts<br>
                                            <strong>Manager:</strong> Operational management, ticket assignment<br>
                                            <strong>Support Tech:</strong> Handle tickets, site visits<br>
                                            <strong>Client:</strong> Self-service portal access
                                            <?php elseif ($user_role === 'admin'): ?>
                                            <strong>Admin:</strong> Manage users, clients, contracts<br>
                                            <strong>Manager:</strong> Operational management, ticket assignment<br>
                                            <strong>Support Tech:</strong> Handle tickets, site visits<br>
                                            <strong>Client:</strong> Self-service portal access
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <!-- Staff ID (for staff users) -->
                                <div class="col-md-6" id="staffIdField" style="display: none;">
                                    <label for="staff_id" class="form-label">Staff ID</label>
                                    <select class="form-control" id="staff_id" name="staff_id">
                                        <option value="">Select Staff ID (Optional)</option>
                                        <?php foreach ($staff_profiles as $staff): ?>
                                        <option value="<?= htmlspecialchars($staff['staff_id']) ?>" 
                                                <?= $staff_id === $staff['staff_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($staff['staff_id']) ?> - <?= htmlspecialchars($staff['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Link to existing staff profile. Leave blank to create new.</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Section -->
                        <div class="form-section">
                            <h6><i class="fas fa-shield-alt me-2"></i> Security Settings</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="password" class="form-label required">Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                               id="password" name="password" value="<?= htmlspecialchars($password) ?>" 
                                               placeholder="Minimum 8 characters" required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($errors['password'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
                                    <?php endif; ?>
                                    <div class="password-strength mt-1" id="passwordStrength">
                                        <div id="strengthBar" style="width: 0%;"></div>
                                    </div>
                                    <small class="text-muted">Use a strong password with letters, numbers, and symbols.</small>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label required">Confirm Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                               id="confirm_password" name="confirm_password" 
                                               value="<?= htmlspecialchars($confirm_password) ?>" placeholder="Re-enter password" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <?php if (isset($errors['confirm_password'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                                    <?php endif; ?>
                                    <div id="passwordMatch" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Account Settings Section -->
                        <div class="form-section">
                            <h6><i class="fas fa-cog me-2"></i> Account Settings</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               value="1" <?= $is_active ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            <strong>Active Account</strong>
                                            <div class="text-muted">User can login if enabled</div>
                                        </label>
                                    </div>
                                    
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="email_verified" name="email_verified" 
                                               value="1" <?= $email_verified ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="email_verified">
                                            <strong>Email Verified</strong>
                                            <div class="text-muted">Skip email verification process</div>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="send_welcome_email" name="send_welcome_email" 
                                               value="1" checked>
                                        <label class="form-check-label" for="send_welcome_email">
                                            <strong>Send Welcome Email</strong>
                                            <div class="text-muted">Send login instructions to user</div>
                                        </label>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <small>User will receive their login credentials via email if enabled. Otherwise, you'll need to provide them manually.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div>
                                <a href="<?php echo route('users.index'); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div>
                                <button type="reset" class="btn btn-outline-secondary me-2">
                                    <i class="fas fa-redo"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary" <?= empty($user_roles) ? 'disabled' : '' ?>>
                                    <i class="fas fa-save"></i> Create User
                                    <?php if (empty($user_roles)): ?>
                                    <span class="ms-1"><small>(Setup required)</small></span>
                                    <?php endif; ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quick Tips -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Quick Tips</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="d-flex align-items-start mb-3">
                                <i class="fas fa-user-shield text-primary me-3 mt-1"></i>
                                <div>
                                    <h6 class="mb-1">Role Selection</h6>
                                    <p class="text-muted mb-0">Choose roles carefully based on required permissions.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-start mb-3">
                                <i class="fas fa-key text-warning me-3 mt-1"></i>
                                <div>
                                    <h6 class="mb-1">Password Security</h6>
                                    <p class="text-muted mb-0">Use strong passwords and enable 2FA for sensitive accounts.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-start mb-3">
                                <i class="fas fa-envelope text-success me-3 mt-1"></i>
                                <div>
                                    <h6 class="mb-1">Email Verification</h6>
                                    <p class="text-muted mb-0">Skip verification for trusted users to speed up onboarding.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    
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
        
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const icon = this.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const confirmField = document.getElementById('confirm_password');
            const icon = this.querySelector('i');
            if (confirmField.type === 'password') {
                confirmField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                confirmField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Show/hide staff ID field based on user type
        const userTypeSelect = document.getElementById('user_type');
        const staffIdField = document.getElementById('staffIdField');
        
        function toggleStaffIdField() {
            const selectedType = userTypeSelect.value;
            const staffTypes = ['admin', 'manager', 'support_tech'];
            
            if (staffTypes.includes(selectedType)) {
                staffIdField.style.display = 'block';
            } else {
                staffIdField.style.display = 'none';
            }
        }
        
        userTypeSelect.addEventListener('change', toggleStaffIdField);
        toggleStaffIdField(); // Initial check
        
        // Password strength checker
        const passwordField = document.getElementById('password');
        const confirmField = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('strengthBar');
        const passwordMatch = document.getElementById('passwordMatch');
        
        function checkPasswordStrength(password) {
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 10;
            
            // Complexity checks
            if (/[a-z]/.test(password)) strength += 15;
            if (/[A-Z]/.test(password)) strength += 15;
            if (/[0-9]/.test(password)) strength += 15;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 20;
            
            // Update strength bar
            strengthBar.style.width = Math.min(strength, 100) + '%';
            
            // Update color
            if (strength < 40) {
                strengthBar.className = 'strength-weak';
            } else if (strength < 60) {
                strengthBar.className = 'strength-medium';
            } else if (strength < 80) {
                strengthBar.className = 'strength-strong';
            } else {
                strengthBar.className = 'strength-very-strong';
            }
        }
        
        function checkPasswordMatch() {
            const password = passwordField.value;
            const confirm = confirmField.value;
            
            if (confirm.length === 0) {
                passwordMatch.innerHTML = '';
                return;
            }
            
            if (password === confirm) {
                passwordMatch.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Passwords match</span>';
            } else {
                passwordMatch.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
            }
        }
        
        passwordField.addEventListener('input', function() {
            checkPasswordStrength(this.value);
            checkPasswordMatch();
        });
        
        confirmField.addEventListener('input', checkPasswordMatch);
        
        // Phone number formatting
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length > 0) {
                if (value.length <= 3) {
                    value = '+1 (' + value;
                } else if (value.length <= 6) {
                    value = '+1 (' + value.substring(0, 3) + ') ' + value.substring(3);
                } else if (value.length <= 10) {
                    value = '+1 (' + value.substring(0, 3) + ') ' + value.substring(3, 6) + '-' + value.substring(6);
                } else {
                    // For international numbers
                    const countryCode = value.substring(0, value.length - 10);
                    const localNumber = value.substring(value.length - 10);
                    value = '+' + countryCode + ' (' + localNumber.substring(0, 3) + ') ' + 
                            localNumber.substring(3, 6) + '-' + localNumber.substring(6);
                }
            }
            
            e.target.value = value;
        });
        
        // Form validation
        document.getElementById('createUserForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match.');
                return;
            }
            
            // Check if user_roles are loaded
            const userType = document.getElementById('user_type').value;
            const options = document.getElementById('user_type').options;
            let roleHasWarning = false;
            
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === userType && options[i].text.includes('(Role not defined)')) {
                    roleHasWarning = true;
                    break;
                }
            }
            
            if (roleHasWarning && !confirm('The selected role is not defined in the database. The user may not have proper permissions. Continue anyway?')) {
                e.preventDefault();
                return;
            }
            
            // Optional: Show confirmation dialog
            if (!confirm('Are you sure you want to create this user?')) {
                e.preventDefault();
            }
        });
    
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });
    </script>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>
    
</body>
</html>