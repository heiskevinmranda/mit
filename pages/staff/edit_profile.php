<?php
require_once '../../includes/auth.php';

// Define generateStaffId as a closure to ensure it's available
$generateStaffId = function() {
    // Generate a unique staff ID (e.g., STAFF + timestamp + random)
    return 'STAFF' . date('Ymd') . rand(1000, 9999);
};

require_once '../../includes/routes.php';
require_once '../../includes/profile_picture_helper.php';
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
        'staff_id' => $generateStaffId(),
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

// Additional staff profile fields
$staff_id = $staff['staff_id'] ?? $generateStaffId();
$designation = $staff['designation'] ?? '';
$department = $staff['department'] ?? '';
$employment_type = $staff['employment_type'] ?? '';
$date_of_joining = $staff['date_of_joining'] ?? '';
$employment_status = $staff['employment_status'] ?? 'Active';
$role_category = $staff['role_category'] ?? '';
$skills = $staff['skills'] ?? '';
$certifications = $staff['certifications'] ?? '';
$service_area = $staff['service_area'] ?? '';
$on_call_support = $staff['on_call_support'] ?? 0;
$experience_years = $staff['experience_years'] ?? 0;
$shift_timing = $staff['shift_timing'] ?? '';
$company_laptop_issued = $staff['company_laptop_issued'] ?? 0;
$asset_serial_number = $staff['asset_serial_number'] ?? '';
$vpn_access = $staff['vpn_access'] ?? 0;
$reporting_manager_id = $staff['reporting_manager_id'] ?? '';
$username = $staff['username'] ?? $current_user['email'];
$role_level = $staff['role_level'] ?? '';
$system_access = $staff['system_access'] ?? '';
$bank_name = $staff['bank_name'] ?? '';
$account_number = $staff['account_number'] ?? '';
$salary_type = $staff['salary_type'] ?? '';
$payment_method = $staff['payment_method'] ?? '';
$last_working_day = $staff['last_working_day'] ?? '';
$remarks = $staff['remarks'] ?? '';
$permanent_address = $staff['permanent_address'] ?? '';
$national_id = $staff['national_id'] ?? '';
$passport_number = $staff['passport_number'] ?? '';
$date_of_birth = $staff['date_of_birth'] ?? '';
$gender = $staff['gender'] ?? '';
$nationality = $staff['nationality'] ?? '';
$tax_id = $staff['tax_id'] ?? '';
$work_permit_details = $staff['work_permit_details'] ?? '';
$assigned_clients = $staff['assigned_clients'] ?? '';
$hr_approval_date = $staff['hr_approval_date'] ?? '';
$hr_manager_id = $staff['hr_manager_id'] ?? '';

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $staff_id = trim($_POST['staff_id'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $official_email = trim($_POST['official_email'] ?? '');
    $personal_email = trim($_POST['personal_email'] ?? '');
    $alternate_phone = trim($_POST['alternate_phone'] ?? '');
    $current_address = trim($_POST['current_address'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    
    // Handle profile picture upload
    $profile_picture_result = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $profile_picture_result = uploadProfilePicture($_FILES['profile_picture'], $current_user['id']);
        if (!$profile_picture_result['success']) {
            $errors['profile_picture'] = $profile_picture_result['message'];
        }
    }
    
    // Additional staff profile fields
    $designation = trim($_POST['designation'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $employment_type = trim($_POST['employment_type'] ?? '');
    $date_of_joining = trim($_POST['date_of_joining'] ?? '');
    $employment_status = trim($_POST['employment_status'] ?? 'Active');
    $role_category = trim($_POST['role_category'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $certifications = trim($_POST['certifications'] ?? '');
    $service_area = trim($_POST['service_area'] ?? '');
    $on_call_support = isset($_POST['on_call_support']) ? 1 : 0;
    $shift_timing = trim($_POST['shift_timing'] ?? '');
    $company_laptop_issued = isset($_POST['company_laptop_issued']) ? 1 : 0;
    $asset_serial_number = trim($_POST['asset_serial_number'] ?? '');
    $vpn_access = isset($_POST['vpn_access']) ? 1 : 0;
    $reporting_manager_id = trim($_POST['reporting_manager_id'] ?? '');
    $username = trim($_POST['username'] ?? $current_user['email']);
    $role_level = trim($_POST['role_level'] ?? '');
    $system_access = trim($_POST['system_access'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $salary_type = trim($_POST['salary_type'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? '');
    $last_working_day = trim($_POST['last_working_day'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $permanent_address = trim($_POST['permanent_address'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $passport_number = trim($_POST['passport_number'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $work_permit_details = trim($_POST['work_permit_details'] ?? '');
    $assigned_clients = trim($_POST['assigned_clients'] ?? '');
    $hr_approval_date = trim($_POST['hr_approval_date'] ?? '');
    $hr_manager_id = trim($_POST['hr_manager_id'] ?? '');
    
    // Convert empty date strings to NULL for database compatibility
    $date_of_joining = !empty($date_of_joining) ? $date_of_joining : null;
    $date_of_birth = !empty($date_of_birth) ? $date_of_birth : null;
    $last_working_day = !empty($last_working_day) ? $last_working_day : null;
    $hr_approval_date = !empty($hr_approval_date) ? $hr_approval_date : null;
    
    // Convert empty UUID strings to NULL for database compatibility
    $reporting_manager_id = !empty($reporting_manager_id) ? $reporting_manager_id : null;
    $hr_manager_id = !empty($hr_manager_id) ? $hr_manager_id : null;
    
    // Calculate experience years based on date of joining
    $experience_years = 0;
    if (!empty($date_of_joining)) {
        try {
            $join_date = new DateTime($date_of_joining);
            $current_date = new DateTime();
            $interval = $join_date->diff($current_date);
            $experience_years = $interval->y;
        } catch (Exception $e) {
            // If date parsing fails, set experience to 0
            $experience_years = 0;
        }
    }
    
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

            // Update profile picture if uploaded successfully
            if ($profile_picture_result && $profile_picture_result['success']) {
                updateUserProfilePicture($current_user['id'], $profile_picture_result['file_path']);
            }

            // Update or create staff profile
            if ($staff['id']) {
                // Update existing staff profile
                $staffUpdateQuery = "UPDATE staff_profiles SET 
                                    staff_id = ?,
                                    full_name = ?, 
                                    phone_number = ?, 
                                    official_email = ?,
                                    personal_email = ?,
                                    alternate_phone = ?,
                                    current_address = ?,
                                    permanent_address = ?,
                                    national_id = ?,
                                    passport_number = ?,
                                    date_of_birth = ?,
                                    gender = ?,
                                    nationality = ?,
                                    tax_id = ?,
                                    work_permit_details = ?,
                                    emergency_contact_name = ?,
                                    emergency_contact_number = ?,
                                    designation = ?,
                                    department = ?,
                                    employment_type = ?,
                                    date_of_joining = ?,
                                    employment_status = ?,
                                    role_category = ?,
                                    skills = ?,
                                    certifications = ?,
                                    experience_years = ?,
                                    assigned_clients = ?,
                                    service_area = ?,
                                    shift_timing = ?,
                                    on_call_support = ?,
                                    username = ?,
                                    role_level = ?,
                                    system_access = ?,
                                    company_laptop_issued = ?,
                                    asset_serial_number = ?,
                                    vpn_access = ?,
                                    reporting_manager_id = ?,
                                    bank_name = ?,
                                    account_number = ?,
                                    salary_type = ?,
                                    payment_method = ?,
                                    last_working_day = ?,
                                    remarks = ?,
                                    hr_approval_date = ?,
                                    hr_manager_id = ?,
                                    updated_at = NOW()
                                    WHERE user_id = ?";
                $staffUpdateStmt = $pdo->prepare($staffUpdateQuery);
                $staffUpdateStmt->execute([
                    $staff_id ?: $generateStaffId(), $full_name, $phone, $official_email, $personal_email,
                    $alternate_phone, $current_address, $permanent_address, $national_id, $passport_number,
                    $date_of_birth, $gender, $nationality, $tax_id, $work_permit_details,
                    $emergency_contact_name, $emergency_contact_number, $designation, $department,
                    $employment_type, $date_of_joining, $employment_status, $role_category, $skills,
                    $certifications, $experience_years, $assigned_clients, $service_area, $shift_timing,
                    $on_call_support, $username, $role_level, $system_access, $company_laptop_issued,
                    $asset_serial_number, $vpn_access, $reporting_manager_id, $bank_name, $account_number,
                    $salary_type, $payment_method, $last_working_day, $remarks, $hr_approval_date, $hr_manager_id,
                    $current_user['id']
                ]);
            } else {
                // Create new staff profile
                $staffInsertQuery = "INSERT INTO staff_profiles 
                                    (user_id, staff_id, full_name, phone_number, official_email,
                                     personal_email, alternate_phone, current_address, permanent_address,
                                     national_id, passport_number, date_of_birth, gender, nationality,
                                     tax_id, work_permit_details, emergency_contact_name, 
                                     emergency_contact_number, designation, department, employment_type,
                                     date_of_joining, employment_status, role_category, skills, 
                                     certifications, experience_years, assigned_clients, service_area,
                                     shift_timing, on_call_support, username, role_level, system_access,
                                     company_laptop_issued, asset_serial_number, vpn_access, 
                                     reporting_manager_id, bank_name, account_number, salary_type,
                                     payment_method, last_working_day, remarks, hr_approval_date, 
                                     hr_manager_id, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $staffInsertStmt = $pdo->prepare($staffInsertQuery);
                $staffInsertStmt->execute([
                    $current_user['id'], $staff_id ?: $generateStaffId(), $full_name, $phone, $official_email,
                    $personal_email, $alternate_phone, $current_address, $permanent_address, $national_id, 
                    $passport_number, $date_of_birth, $gender, $nationality, $tax_id, $work_permit_details,
                    $emergency_contact_name, $emergency_contact_number, $designation, $department,
                    $employment_type, $date_of_joining, $employment_status, $role_category, $skills,
                    $certifications, $experience_years, $assigned_clients, $service_area, $shift_timing,
                    $on_call_support, $username, $role_level, $system_access, $company_laptop_issued,
                    $asset_serial_number, $vpn_access, $reporting_manager_id, $bank_name, $account_number,
                    $salary_type, $payment_method, $last_working_day, $remarks, $hr_approval_date, $hr_manager_id
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
            header("Location: " . route('staff.profile'));
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
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    
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
        
        /* Profile Picture Styles */
        .profile-picture-initials, .profile-picture-img {
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .profile-picture-initials {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .profile-picture-img {
            display: block;
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
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($errors['general'] ?? '') ?>
        </div>
        <?php endif; ?>
        
        <!-- Edit Form -->
        <form method="POST" action="" id="editProfileForm" enctype="multipart/form-data">
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
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="staff_id" class="form-label">Staff ID</label>
                            <input type="text" class="form-control" 
                                   id="staff_id" name="staff_id" value="<?= htmlspecialchars($staff['staff_id'] ?? '') ?>" 
                                   placeholder="Staff ID" readonly>
                            <small class="text-muted">Staff ID is automatically generated and cannot be changed.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label required">Full Name</label>
                            <input type="text" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                                   id="full_name" name="full_name" value="<?= htmlspecialchars($full_name) ?>" 
                                   placeholder="John Doe" required>
                            <?php if (isset($errors['full_name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name'] ?? '') ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Profile Picture Upload -->
                        <div class="mb-3">
                            <label for="profile_picture" class="form-label">Profile Picture</label>
                            <div class="mb-2">
                                <?php echo getProfilePictureHTML($current_user['id'], $current_user['email'], 'lg'); ?>
                            </div>
                            <input type="file" class="form-control <?php echo isset($errors['profile_picture']) ? 'is-invalid' : ''; ?>" 
                                   id="profile_picture" name="profile_picture" 
                                   accept="image/jpeg,image/png,image/gif,image/webp">
                            <?php if (isset($errors['profile_picture'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['profile_picture'] ?? '') ?></div>
                            <?php endif; ?>
                            <small class="text-muted">Upload a profile picture (JPG, PNG, GIF, or WebP, max 5MB)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" 
                                   id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" 
                                   placeholder="+255 xxx xxx xxx">
                        </div>
                        
                        <div class="mb-3">
                            <label for="designation" class="form-label">Designation</label>
                            <input type="text" class="form-control" 
                                   id="designation" name="designation" value="<?= htmlspecialchars($designation) ?>" 
                                   placeholder="Your job title">
                        </div>
                        
                        <div class="mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" class="form-control" 
                                   id="department" name="department" value="<?= htmlspecialchars($department) ?>" 
                                   placeholder="Your department">
                        </div>
                        
                        <div class="mb-3">
                            <label for="date_of_joining" class="form-label">Date of Joining</label>
                            <input type="date" class="form-control" 
                                   id="date_of_joining" name="date_of_joining" 
                                   value="<?= htmlspecialchars($date_of_joining ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="employment_type" class="form-label">Employment Type</label>
                            <select class="form-control" id="employment_type" name="employment_type">
                                <option value="Full-time" <?= $employment_type == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                                <option value="Part-time" <?= $employment_type == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                                <option value="Contract" <?= $employment_type == 'Contract' ? 'selected' : '' ?>>Contract</option>
                                <option value="Intern" <?= $employment_type == 'Intern' ? 'selected' : '' ?>>Intern</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="employment_status" class="form-label">Employment Status</label>
                            <select class="form-control" id="employment_status" name="employment_status">
                                <option value="Active" <?= $employment_status == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $employment_status == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="Terminated" <?= $employment_status == 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                                <option value="Resigned" <?= $employment_status == 'Resigned' ? 'selected' : '' ?>>Resigned</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role_category" class="form-label">Role Category</label>
                            <input type="text" class="form-control" 
                                   id="role_category" name="role_category" value="<?= htmlspecialchars($role_category) ?>" 
                                   placeholder="Category of your role">
                        </div>
                        
                        <div class="mb-3">
                            <label for="skills" class="form-label">Skills</label>
                            <input type="text" class="form-control" 
                                   id="skills" name="skills" value="<?= htmlspecialchars($skills) ?>" 
                                   placeholder="Comma separated list of your skills">
                        </div>
                        
                        <div class="mb-3">
                            <label for="certifications" class="form-label">Certifications</label>
                            <input type="text" class="form-control" 
                                   id="certifications" name="certifications" value="<?= htmlspecialchars($certifications) ?>" 
                                   placeholder="List of your certifications">
                        </div>
                        
                        <div class="mb-3">
                            <label for="service_area" class="form-label">Service Area</label>
                            <input type="text" class="form-control" 
                                   id="service_area" name="service_area" value="<?= htmlspecialchars($service_area) ?>" 
                                   placeholder="Your service area">
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" 
                                   id="on_call_support" name="on_call_support" 
                                   <?= $on_call_support ? 'checked' : '' ?>>
                            <label class="form-check-label" for="on_call_support">On-call Support</label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="shift_timing" class="form-label">Shift Timing</label>
                            <input type="text" class="form-control" 
                                   id="shift_timing" name="shift_timing" value="<?= htmlspecialchars($shift_timing) ?>" 
                                   placeholder="Your shift schedule">
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
                                   placeholder="+255 xxx xxx xxx">
                        </div>
                        
                        <div class="mb-3">
                            <label for="permanent_address" class="form-label">Permanent Address</label>
                            <textarea class="form-control" 
                                      id="permanent_address" name="permanent_address" 
                                      rows="3" 
                                      placeholder="Enter your permanent address"><?= htmlspecialchars($permanent_address ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="national_id" class="form-label">National ID</label>
                            <input type="text" class="form-control" 
                                   id="national_id" name="national_id" 
                                   value="<?= htmlspecialchars($national_id) ?>" 
                                   placeholder="Your National ID number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="passport_number" class="form-label">Passport Number</label>
                            <input type="text" class="form-control" 
                                   id="passport_number" name="passport_number" 
                                   value="<?= htmlspecialchars($passport_number) ?>" 
                                   placeholder="Your Passport number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" 
                                   id="date_of_birth" name="date_of_birth" 
                                   value="<?= htmlspecialchars($date_of_birth ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-control" id="gender" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male" <?= $gender == 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $gender == 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= $gender == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nationality" class="form-label">Nationality</label>
                            <input type="text" class="form-control" 
                                   id="nationality" name="nationality" 
                                   value="<?= htmlspecialchars($nationality) ?>" 
                                   placeholder="Your nationality">
                        </div>
                        
                        <div class="mb-3">
                            <label for="tax_id" class="form-label">Tax ID</label>
                            <input type="text" class="form-control" 
                                   id="tax_id" name="tax_id" 
                                   value="<?= htmlspecialchars($tax_id) ?>" 
                                   placeholder="Your Tax ID">
                        </div>
                        
                        <div class="mb-3">
                            <label for="work_permit_details" class="form-label">Work Permit Details</label>
                            <input type="text" class="form-control" 
                                   id="work_permit_details" name="work_permit_details" 
                                   value="<?= htmlspecialchars($work_permit_details) ?>" 
                                   placeholder="Work permit details if applicable">
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
                                      placeholder="Enter your current address"><?= htmlspecialchars($current_address ?? '') ?></textarea>
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
                                   placeholder="+255 xxx xxx xxx">
                        </div>
                    </div>
                    
                    <!-- System Access Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-laptop me-2"></i> System Access</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" 
                                   id="username" name="username" 
                                   value="<?= htmlspecialchars($username) ?>" 
                                   placeholder="Username for system access">
                        </div>
                        
                        <div class="mb-3">
                            <label for="role_level" class="form-label">Role Level</label>
                            <input type="text" class="form-control" 
                                   id="role_level" name="role_level" 
                                   value="<?= htmlspecialchars($role_level) ?>" 
                                   placeholder="Role level in system">
                        </div>
                        
                        <div class="mb-3">
                            <label for="system_access" class="form-label">System Access</label>
                            <input type="text" class="form-control" 
                                   id="system_access" name="system_access" 
                                   value="<?= htmlspecialchars($system_access) ?>" 
                                   placeholder="Access permissions">
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" 
                                   id="company_laptop_issued" name="company_laptop_issued" 
                                   <?= $company_laptop_issued ? 'checked' : '' ?>>
                            <label class="form-check-label" for="company_laptop_issued">Company Laptop Issued</label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="asset_serial_number" class="form-label">Asset Serial Number</label>
                            <input type="text" class="form-control" 
                                   id="asset_serial_number" name="asset_serial_number" 
                                   value="<?= htmlspecialchars($asset_serial_number) ?>" 
                                   placeholder="Serial number of issued laptop/device">
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" 
                                   id="vpn_access" name="vpn_access" 
                                   <?= $vpn_access ? 'checked' : '' ?>>
                            <label class="form-check-label" for="vpn_access">VPN Access</label>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reporting_manager_id" class="form-label">Reporting Manager</label>
                            <input type="text" class="form-control" 
                                   id="reporting_manager_id" name="reporting_manager_id" 
                                   value="<?= htmlspecialchars($reporting_manager_id) ?>" 
                                   placeholder="ID or name of reporting manager">
                        </div>
                        
                        <div class="mb-3">
                            <label for="assigned_clients" class="form-label">Assigned Clients</label>
                            <input type="text" class="form-control" 
                                   id="assigned_clients" name="assigned_clients" 
                                   value="<?= htmlspecialchars($assigned_clients) ?>" 
                                   placeholder="Comma separated list of assigned clients">
                        </div>
                    </div>
                    
                    <!-- Financial Information Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-money-bill-wave me-2"></i> Financial Information</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bank_name" class="form-label">Bank Name</label>
                            <input type="text" class="form-control" 
                                   id="bank_name" name="bank_name" 
                                   value="<?= htmlspecialchars($bank_name) ?>" 
                                   placeholder="Name of your bank">
                        </div>
                        
                        <div class="mb-3">
                            <label for="account_number" class="form-label">Account Number</label>
                            <input type="text" class="form-control" 
                                   id="account_number" name="account_number" 
                                   value="<?= htmlspecialchars($account_number) ?>" 
                                   placeholder="Your bank account number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="salary_type" class="form-label">Salary Type</label>
                            <input type="text" class="form-control" 
                                   id="salary_type" name="salary_type" 
                                   value="<?= htmlspecialchars($salary_type) ?>" 
                                   placeholder="Monthly, Hourly, etc.">
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <input type="text" class="form-control" 
                                   id="payment_method" name="payment_method" 
                                   value="<?= htmlspecialchars($payment_method) ?>" 
                                   placeholder="Cash, Bank Transfer, etc.">
                        </div>
                    </div>
                    
                    <!-- HR & Employment Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h5><i class="fas fa-user-clock me-2"></i> HR & Employment</h5>
                        </div>
                        
                        <div class="mb-3">
                            <label for="last_working_day" class="form-label">Last Working Day</label>
                            <input type="date" class="form-control" 
                                   id="last_working_day" name="last_working_day" 
                                   value="<?= htmlspecialchars($last_working_day ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks</label>
                            <textarea class="form-control" 
                                      id="remarks" name="remarks" 
                                      rows="3" 
                                      placeholder="Any additional remarks"><?= htmlspecialchars($remarks ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="hr_approval_date" class="form-label">HR Approval Date</label>
                            <input type="date" class="form-control" 
                                   id="hr_approval_date" name="hr_approval_date" 
                                   value="<?= htmlspecialchars($hr_approval_date ?? '') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="hr_manager_id" class="form-label">HR Manager ID</label>
                            <input type="text" class="form-control" 
                                   id="hr_manager_id" name="hr_manager_id" 
                                   value="<?= htmlspecialchars($hr_manager_id) ?>" 
                                   placeholder="ID of HR manager">
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
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
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
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password'] ?? '') ?></div>
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