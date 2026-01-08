<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

$page_title = 'Edit Profile';

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get staff profile
$stmt = $pdo->prepare("SELECT sp.*, u.email as login_email 
                       FROM staff_profiles sp
                       LEFT JOIN users u ON sp.user_id = u.id
                       WHERE sp.user_id = ?");
$stmt->execute([$current_user['id']]);
$staff = $stmt->fetch();

// If no staff profile exists, create a basic one
if (!$staff) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$current_user['id']]);
    $user = $stmt->fetch();
    $staff = [
        'id' => null,
        'user_id' => $current_user['id'],
        'staff_id' => '',
        'full_name' => $current_user['email'],
        'phone_number' => '',
        'official_email' => $user['email'] ?? $current_user['email'],
        'personal_email' => '',
        'alternate_phone' => '',
        'current_address' => '',
        'emergency_contact_name' => '',
        'emergency_contact_number' => '',
        'username' => $current_user['email'],
        'login_email' => $user['email'] ?? $current_user['email']
    ];
}

// Initialize variables
$email = $current_user['email'];
$full_name = $staff['full_name'] ?? $current_user['email'];
$phone = $staff['phone_number'] ?? '';
$official_email = $staff['official_email'] ?? $current_user['email'];
$personal_email = $staff['personal_email'] ?? '';
$alternate_phone = $staff['alternate_phone'] ?? '';
$current_address = $staff['current_address'] ?? '';
$emergency_contact_name = $staff['emergency_contact_name'] ?? '';
$emergency_contact_number = $staff['emergency_contact_number'] ?? '';

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $official_email = trim($_POST['official_email'] ?? '');
    $personal_email = trim($_POST['personal_email'] ?? '');
    $alternate_phone = trim($_POST['alternate_phone'] ?? '');
    $current_address = trim($_POST['current_address'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    
    // Validate inputs
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } elseif ($email !== $current_user['email']) {
        // Check if new email already exists (if email changed)
        $checkEmailQuery = "SELECT id FROM users WHERE email = ? AND id != ?";
        $checkStmt = $pdo->prepare($checkEmailQuery);
        $checkStmt->execute([$email, $current_user['id']]);
        if ($checkStmt->fetch()) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (empty($full_name)) {
        $errors['full_name'] = 'Full name is required';
    }

    // Validate phones if provided
    if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) {
        $errors['phone'] = 'Invalid phone number';
    }
    
    if (!empty($alternate_phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $alternate_phone)) {
        $errors['alternate_phone'] = 'Invalid alternate phone number';
    }
    
    if (!empty($emergency_contact_number) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $emergency_contact_number)) {
        $errors['emergency_contact_number'] = 'Invalid emergency contact number';
    }

    // Password fields (optional update)
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $change_password = !empty($password);

    // Validate password if changing
    if ($change_password) {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }
        if ($password !== $confirm_password) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Update user email if changed
            if ($email !== $current_user['email']) {
                $updateUserQuery = "UPDATE users SET email = ? WHERE id = ?";
                $updateUserStmt = $pdo->prepare($updateUserQuery);
                $updateUserStmt->execute([$email, $current_user['id']]);
                
                // Update session email
                $_SESSION['email'] = $email;
            }

            // Update or create staff profile
            if ($staff['id']) {
                // Update existing staff profile
                $staffUpdateQuery = "UPDATE staff_profiles SET 
                                    full_name = ?, 
                                    phone_number = ?, 
                                    official_email = ?,
                                    personal_email = ?,
                                    alternate_phone = ?,
                                    current_address = ?,
                                    emergency_contact_name = ?,
                                    emergency_contact_number = ?,
                                    updated_at = NOW()
                                    WHERE user_id = ?";
                $staffUpdateStmt = $pdo->prepare($staffUpdateQuery);
                $staffUpdateStmt->execute([
                    $full_name, $phone, $official_email, $personal_email,
                    $alternate_phone, $current_address, $emergency_contact_name,
                    $emergency_contact_number, $current_user['id']
                ]);
            } else {
                // Create new staff profile
                $staffInsertQuery = "INSERT INTO staff_profiles 
                                    (user_id, full_name, phone_number, official_email,
                                     personal_email, alternate_phone, current_address,
                                     emergency_contact_name, emergency_contact_number, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $staffInsertStmt = $pdo->prepare($staffInsertQuery);
                $staffInsertStmt->execute([
                    $current_user['id'], $full_name, $phone, $official_email,
                    $personal_email, $alternate_phone, $current_address,
                    $emergency_contact_name, $emergency_contact_number
                ]);
            }

            // Update password if provided
            if ($change_password) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $updatePasswordQuery = "UPDATE users SET password = ? WHERE id = ?";
                $updatePasswordStmt = $pdo->prepare($updatePasswordQuery);
                $updatePasswordStmt->execute([$password_hash, $current_user['id']]);
            }

            $pdo->commit();
            
            $_SESSION['success'] = "Profile updated successfully!";
            // Redirect to profile page
            header("Location: profile.php");
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors['general'] = "Database error: " . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = "Error: " . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'MSP Application'; ?></title>
    
    <!-- Load CSS files -->
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        .main-content {
            padding: 1.5rem !important;
        }
        
        .form-section {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .section-header h5 {
            color: #2c3e50;
            font-weight: 600;
            margin: 0;
        }
        
        .form-control {
            border: 1px solid #ced4da;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus {
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .required:after {
            content: " *";
            color: #dc3545;
        }
        
        .btn {
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #004085;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        
        /* Password strength indicator */
        .password-strength {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        #strengthBar {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 4px;
        }
        
        .strength-weak {
            background-color: #dc3545 !important;
        }
        
        .strength-medium {
            background-color: #ffc107 !important;
        }
        
        .strength-strong {
            background-color: #28a745 !important;
        }
        
        .strength-very-strong {
            background-color: #17a2b8 !important;
        }
        
        /* Form actions */
        .form-actions {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            margin-top: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
    </style>    
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </h1>
                <p class="text-muted">Update your personal information</p>
            </div>
            <div>
                <a href="<?php echo route('staff.profile'); ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Profile
                </a>
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
        
        <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($errors['general']) ?>
        </div>
        <?php endif; ?>
        
        <!-- Edit Form -->
        <form method="POST" action="" id="editProfileForm">
            <div class="row">
                <div class="col-md-6">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-user-circle me-2"></i> Personal Information</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label required">Email Address</label>
                            <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                                   id="email" name="email" value="<?= htmlspecialchars($email) ?>" 
                                   placeholder="user@example.com" required>
                            <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label required">Full Name</label>
                            <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                   id="full_name" name="full_name" value="<?= htmlspecialchars($full_name) ?>" 
                                   placeholder="John Doe" required>
                            <?php if (isset($errors['full_name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" 
                                   id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" 
                                   placeholder="+1 (555) 123-4567">
                        </div>
                    </div>
                    
                    <!-- Contact Information Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-address-card me-2"></i> Contact Information</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="official_email" class="form-label">Official Email</label>
                            <input type="email" class="form-control" 
                                   id="official_email" name="official_email" 
                                   value="<?= htmlspecialchars($official_email) ?>" 
                                   placeholder="official@company.com">
                        </div>
                        
                        <div class="mb-3">
                            <label for="personal_email" class="form-label">Personal Email</label>
                            <input type="email" class="form-control" 
                                   id="personal_email" name="personal_email" 
                                   value="<?= htmlspecialchars($personal_email) ?>" 
                                   placeholder="personal@email.com">
                        </div>
                        
                        <div class="mb-3">
                            <label for="alternate_phone" class="form-label">Alternate Phone</label>
                            <input type="tel" class="form-control" 
                                   id="alternate_phone" name="alternate_phone" 
                                   value="<?= htmlspecialchars($alternate_phone) ?>" 
                                   placeholder="+1 (555) 987-6543">
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <!-- Address Information Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-home me-2"></i> Address Information</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="current_address" class="form-label">Current Address</label>
                            <textarea class="form-control" 
                                      id="current_address" name="current_address" 
                                      rows="4" 
                                      placeholder="Enter your current address"><?= htmlspecialchars($current_address) ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-heartbeat me-2"></i> Emergency Contact</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                            <input type="text" class="form-control" 
                                   id="emergency_contact_name" name="emergency_contact_name" 
                                   value="<?= htmlspecialchars($emergency_contact_name) ?>" 
                                   placeholder="John Smith">
                        </div>
                        
                        <div class="mb-3">
                            <label for="emergency_contact_number" class="form-label">Emergency Contact Number</label>
                            <input type="tel" class="form-control" 
                                   id="emergency_contact_number" name="emergency_contact_number" 
                                   value="<?= htmlspecialchars($emergency_contact_number) ?>" 
                                   placeholder="+1 (555) 111-2222">
                        </div>
                    </div>
                    
                    <!-- Security Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-shield-alt me-2"></i> Security Settings</h5>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Change your password. Leave blank to keep current password.
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" 
                                       id="password" name="password" 
                                       placeholder="Leave blank to keep current password">
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
                            <small class="text-muted">Minimum 8 characters. Use letters, numbers, and symbols for strength.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                       id="confirm_password" name="confirm_password" 
                                       placeholder="Re-enter new password">
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
            </div>
            
            <!-- Form Actions -->
            <div class="form-actions">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <a href="<?php echo route('staff.profile'); ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                    <div>
                        <button type="reset" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
        
        // Password strength checker
        const passwordField = document.getElementById('password');
        const confirmField = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('strengthBar');
        const passwordMatch = document.getElementById('passwordMatch');
        
        function checkPasswordStrength(password) {
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.className = '';
                return;
            }
            
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
        
        if (passwordField) {
            passwordField.addEventListener('input', function() {
                checkPasswordStrength(this.value);
                checkPasswordMatch();
            });
        }
        
        if (confirmField) {
            confirmField.addEventListener('input', checkPasswordMatch);
        }
        
        // Phone number formatting
        const phoneFields = ['phone', 'alternate_phone', 'emergency_contact_number'];
        phoneFields.forEach(fieldName => {
            const field = document.getElementById(fieldName);
            if (field) {
                field.addEventListener('input', function(e) {
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
            }
        });
        
        // Form validation
        document.getElementById('editProfileForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            // Only validate password if it's being changed
            if (password.length > 0) {
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
            }
        });
    </script>
</body>
</html>