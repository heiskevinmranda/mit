<?php
// pages/users/edit.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$current_user_role = $_SESSION['user_type'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Check permissions based on user role
function canEditUser($viewer_role, $target_user_role, $target_user_id, $viewer_id) {
    // Everyone can edit themselves (with limitations)
    if ($viewer_id == $target_user_id) {
        return true;
    }
    
    // Super Admin can edit anyone
    if ($viewer_role === 'super_admin') {
        return true;
    }
    
    // Admin can edit anyone except super_admin
    if ($viewer_role === 'admin') {
        return $target_user_role !== 'super_admin';
    }
    
    // Manager can edit support_tech and client (for ticket/site visit management)
    if ($viewer_role === 'manager') {
        return in_array($target_user_role, ['support_tech', 'client']);
    }
    
    return false;
}

// Check what fields can be edited based on role
function canEditField($field, $viewer_role, $target_user_role, $is_self) {
    // Users can always edit their own profile fields (except sensitive ones)
    if ($is_self) {
        $self_editable_fields = ['email', 'full_name', 'phone', 'password'];
        return in_array($field, $self_editable_fields);
    }
    
    // Field-specific permissions
    switch($field) {
        case 'user_type':
        case 'role':
        case 'is_active':
        case 'email_verified':
        case 'two_factor_enabled':
            // Only Super Admin can edit these sensitive fields
            return $viewer_role === 'super_admin';
            
        case 'staff_info':
        case 'employment_info':
            // Super Admin, Admin, and Manager can edit staff info
            return in_array($viewer_role, ['super_admin', 'admin', 'manager']) && 
                   in_array($target_user_role, ['admin', 'manager', 'support_tech']);
            
        case 'ticket_management':
        case 'site_visit_info':
            // Super Admin, Admin, and Manager can manage tickets/site visits
            return in_array($viewer_role, ['super_admin', 'admin', 'manager']);
            
        default:
            // For other fields, check role hierarchy
            if ($viewer_role === 'super_admin') return true;
            if ($viewer_role === 'admin') return $target_user_role !== 'super_admin';
            if ($viewer_role === 'manager') return in_array($target_user_role, ['support_tech', 'client']);
            return false;
    }
}

$pdo = getDBConnection();

// Get user ID from URL
$user_id = $_GET['id'] ?? null;

if (!$user_id) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: index.php");
    exit();
}

// Fetch user data with related information
$userQuery = "SELECT 
                u.*, 
                ur.role_name,
                sp.*,
                (SELECT COUNT(*) FROM tickets WHERE created_by = u.id) as ticket_count,
                (SELECT COUNT(*) FROM tickets WHERE assigned_to = u.id) as assigned_tickets,
                (SELECT COUNT(*) FROM site_visits WHERE engineer_id = u.id) as site_visits_count
              FROM users u 
              LEFT JOIN user_roles ur ON u.role_id = ur.id 
              LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
              WHERE u.id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: index.php");
    exit();
}

// Check if current user can edit this user
$can_edit = canEditUser($current_user_role, $user['user_type'], $user_id, $current_user_id);
$is_self = ($current_user_id == $user_id);

if (!$can_edit) {
    $_SESSION['error'] = "You don't have permission to edit this user.";
    header("Location: index.php");
    exit();
}

// Available user types (based on current user's role)
$available_user_types = [];
if ($current_user_role === 'super_admin') {
    $available_user_types = ['super_admin', 'admin', 'manager', 'support_tech', 'client'];
} elseif ($current_user_role === 'admin') {
    $available_user_types = ['admin', 'manager', 'support_tech', 'client'];
} elseif ($current_user_role === 'manager') {
    $available_user_types = ['support_tech', 'client'];
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

// Get user roles from database
$user_roles = [];
try {
    $rolesQuery = "SELECT id, role_name FROM user_roles ORDER BY role_name";
    $rolesStmt = $pdo->query($rolesQuery);
    $user_roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If user_roles table doesn't exist
}

// Get assigned tickets for ticket management
$assigned_tickets = [];
if (canEditField('ticket_management', $current_user_role, $user['user_type'], $is_self)) {
    $ticketsQuery = "SELECT t.*, c.company_name 
                     FROM tickets t
                     LEFT JOIN clients c ON t.client_id = c.id
                     WHERE t.assigned_to = ? 
                     OR t.primary_assignee = ?
                     ORDER BY t.created_at DESC 
                     LIMIT 10";
    $ticketsStmt = $pdo->prepare($ticketsQuery);
    $ticketsStmt->execute([$user_id, $user_id]);
    $assigned_tickets = $ticketsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get site visits for site visit management
$site_visits = [];
if (canEditField('site_visit_info', $current_user_role, $user['user_type'], $is_self)) {
    $visitsQuery = "SELECT sv.*, c.company_name, cl.location_name
                     FROM site_visits sv
                     LEFT JOIN clients c ON sv.client_id = c.id
                     LEFT JOIN client_locations cl ON sv.location_id = cl.id
                     WHERE sv.engineer_id = ?
                     ORDER BY sv.created_at DESC 
                     LIMIT 10";
    $visitsStmt = $pdo->prepare($visitsQuery);
    $visitsStmt->execute([$user_id]);
    $site_visits = $visitsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Initialize variables
$email = $user['email'] ?? '';
$user_type = $user['user_type'] ?? '';
$full_name = $user['full_name'] ?? '';
$phone = $user['phone_number'] ?? '';
$staff_id = $user['staff_id'] ?? '';
$is_active = $user['is_active'] ?? 1;
$email_verified = $user['email_verified'] ?? 0;
$two_factor_enabled = $user['two_factor_enabled'] ?? 0;
$designation = $user['designation'] ?? '';
$department = $user['department'] ?? '';
$employment_type = $user['employment_type'] ?? '';
$date_of_joining = $user['date_of_joining'] ?? '';
$employment_status = $user['employment_status'] ?? 'Active';
$skills = $user['skills'] ?? '';
$certifications = $user['certifications'] ?? '';
$experience_years = $user['experience_years'] ?? '';
$shift_timing = $user['shift_timing'] ?? '';
$on_call_support = $user['on_call_support'] ?? 0;

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Information (editable by all with permission)
    $email = trim($_POST['email'] ?? '');
    $user_type = $_POST['user_type'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $staff_id = $_POST['staff_id'] ?? '';
    
    // Account Settings (only Super Admin)
    if (canEditField('is_active', $current_user_role, $user['user_type'], $is_self)) {
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $email_verified = isset($_POST['email_verified']) ? 1 : 0;
        $two_factor_enabled = isset($_POST['two_factor_enabled']) ? 1 : 0;
    } else {
        // Keep existing values if not allowed to edit
        $is_active = $user['is_active'];
        $email_verified = $user['email_verified'];
        $two_factor_enabled = $user['two_factor_enabled'];
    }
    
    // Staff Information (Super Admin, Admin, Manager)
    if (canEditField('staff_info', $current_user_role, $user['user_type'], $is_self)) {
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $employment_type = $_POST['employment_type'] ?? '';
        $date_of_joining = $_POST['date_of_joining'] ?? '';
        $employment_status = $_POST['employment_status'] ?? 'Active';
        $skills = trim($_POST['skills'] ?? '');
        $certifications = trim($_POST['certifications'] ?? '');
        $experience_years = $_POST['experience_years'] ?? '';
        $shift_timing = trim($_POST['shift_timing'] ?? '');
        $on_call_support = isset($_POST['on_call_support']) ? 1 : 0;
    }
    
    // Password fields (optional update - editable by self and admins)
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $change_password = !empty($password);
    
    // If editing self, some fields cannot be changed
    if ($is_self) {
        // Users cannot change their own user_type, is_active status, email_verified status
        // unless they are super_admin
        if ($current_user_role !== 'super_admin') {
            $user_type = $user['user_type'];
            $is_active = $user['is_active'];
            $email_verified = $user['email_verified'];
            $two_factor_enabled = $user['two_factor_enabled'];
        }
    }

    // Validate inputs
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } elseif ($email !== $user['email']) {
        // Check if new email already exists (if email changed)
        $checkEmailQuery = "SELECT id FROM users WHERE email = ? AND id != ?";
        $checkStmt = $pdo->prepare($checkEmailQuery);
        $checkStmt->execute([$email, $user_id]);
        if ($checkStmt->fetch()) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (canEditField('user_type', $current_user_role, $user['user_type'], $is_self)) {
        if (empty($user_type)) {
            $errors['user_type'] = 'User type is required';
        } elseif (!in_array($user_type, $available_user_types)) {
            $errors['user_type'] = 'Invalid user type selection';
        }
    }

    if (empty($full_name)) {
        $errors['full_name'] = 'Full name is required';
    }

    // Validate phone if provided
    if (!empty($phone) && !preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $phone)) {
        $errors['phone'] = 'Invalid phone number';
    }

    // Validate password if changing
    if ($change_password) {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }
        if ($password !== $confirm_password) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
    }

    // Validate employment info if applicable
    if ($date_of_joining && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_joining)) {
        $errors['date_of_joining'] = 'Invalid date format (YYYY-MM-DD)';
    }

    if ($experience_years && !is_numeric($experience_years)) {
        $errors['experience_years'] = 'Experience must be a number';
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Get role_id for the user_type
            $role_id = null;
            if (canEditField('user_type', $current_user_role, $user['user_type'], $is_self)) {
                $roleQuery = "SELECT id FROM user_roles WHERE role_name = ? LIMIT 1";
                $roleStmt = $pdo->prepare($roleQuery);
                $roleStmt->execute([$user_type]);
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($role) {
                    $role_id = $role['id'];
                }
            } else {
                // Keep existing role_id if not allowed to change
                $role_id = $user['role_id'];
            }

            // Build update query for users table
            if ($change_password) {
                // Update with password change
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $updateQuery = "UPDATE users SET email = ?, password = ?, user_type = ?, role_id = ?, 
                                is_active = ?, email_verified = ?, two_factor_enabled = ?, 
                                updated_at = NOW() WHERE id = ?";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateParams = [$email, $password_hash, $user_type, $role_id, 
                               $is_active, $email_verified, $two_factor_enabled, $user_id];
            } else {
                // Update without password change
                $updateQuery = "UPDATE users SET email = ?, user_type = ?, role_id = ?, 
                                is_active = ?, email_verified = ?, two_factor_enabled = ?, 
                                updated_at = NOW() WHERE id = ?";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateParams = [$email, $user_type, $role_id, 
                               $is_active, $email_verified, $two_factor_enabled, $user_id];
            }
            
            $updateStmt->execute($updateParams);

            // Update or create staff profile for staff users
            if (in_array($user_type, ['admin', 'manager', 'support_tech']) && 
                canEditField('staff_info', $current_user_role, $user['user_type'], $is_self)) {
                
                // Check if staff profile exists
                $checkStaffQuery = "SELECT id FROM staff_profiles WHERE user_id = ?";
                $checkStaffStmt = $pdo->prepare($checkStaffQuery);
                $checkStaffStmt->execute([$user_id]);
                
                if ($checkStaffStmt->fetch()) {
                    // Update existing staff profile
                    $staffUpdateQuery = "UPDATE staff_profiles SET 
                                        staff_id = ?, 
                                        full_name = ?, 
                                        phone_number = ?, 
                                        official_email = ?,
                                        designation = ?,
                                        department = ?,
                                        employment_type = ?,
                                        date_of_joining = ?,
                                        employment_status = ?,
                                        skills = ?,
                                        certifications = ?,
                                        experience_years = ?,
                                        shift_timing = ?,
                                        on_call_support = ?,
                                        updated_at = NOW()
                                        WHERE user_id = ?";
                    $staffUpdateStmt = $pdo->prepare($staffUpdateQuery);
                    $staffUpdateStmt->execute([
                        $staff_id, $full_name, $phone, $email,
                        $designation, $department, $employment_type, $date_of_joining,
                        $employment_status, $skills, $certifications, $experience_years,
                        $shift_timing, $on_call_support, $user_id
                    ]);
                } else {
                    // Create new staff profile
                    $staffInsertQuery = "INSERT INTO staff_profiles 
                                        (user_id, staff_id, full_name, phone_number, official_email,
                                         designation, department, employment_type, date_of_joining,
                                         employment_status, skills, certifications, experience_years,
                                         shift_timing, on_call_support, created_at, updated_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $staffInsertStmt = $pdo->prepare($staffInsertQuery);
                    $staffInsertStmt->execute([
                        $user_id, $staff_id, $full_name, $phone, $email,
                        $designation, $department, $employment_type, $date_of_joining,
                        $employment_status, $skills, $certifications, $experience_years,
                        $shift_timing, $on_call_support
                    ]);
                }
            }

            // Handle ticket reassignment if manager is editing
            if (canEditField('ticket_management', $current_user_role, $user['user_type'], $is_self) && 
                isset($_POST['reassign_tickets'])) {
                
                $new_assignee = $_POST['new_ticket_assignee'] ?? null;
                if ($new_assignee) {
                    $reassignQuery = "UPDATE tickets SET assigned_to = ?, updated_at = NOW() 
                                      WHERE assigned_to = ?";
                    $reassignStmt = $pdo->prepare($reassignQuery);
                    $reassignStmt->execute([$new_assignee, $user_id]);
                }
            }

            // Handle site visit reassignment if manager is editing
            if (canEditField('site_visit_info', $current_user_role, $user['user_type'], $is_self) && 
                isset($_POST['reassign_visits'])) {
                
                $new_engineer = $_POST['new_site_engineer'] ?? null;
                if ($new_engineer) {
                    $reassignVisitQuery = "UPDATE site_visits SET engineer_id = ?, updated_at = NOW() 
                                           WHERE engineer_id = ?";
                    $reassignVisitStmt = $pdo->prepare($reassignVisitQuery);
                    $reassignVisitStmt->execute([$new_engineer, $user_id]);
                }
            }

            // Audit log
            try {
                $auditQuery = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, created_at) 
                               VALUES (?, ?, ?, ?, ?, NOW())";
                $auditStmt = $pdo->prepare($auditQuery);
                $auditStmt->execute([
                    $current_user_id,
                    'UPDATE',
                    'user',
                    $user_id,
                    $_SERVER['REMOTE_ADDR']
                ]);
            } catch (PDOException $e) {
                // Audit table might not exist, ignore error
                error_log("Audit log error: " . $e->getMessage());
            }

            $pdo->commit();
            
            // Update session email if user edited their own profile
            if ($is_self && $email !== $_SESSION['email']) {
                $_SESSION['email'] = $email;
            }
            
            $_SESSION['success'] = "User '$email' updated successfully!";
            
            // Redirect based on who edited
            if ($is_self) {
                header("Location: ../staff/profile.php");
            } else {
                header("Location: view.php?id=$user_id");
            }
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors['general'] = "Database error: " . $e->getMessage();
            error_log("User update error: " . $e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = "Error: " . $e->getMessage();
            error_log("User update error: " . $e->getMessage());
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

// Function to get other staff for reassignment
function getOtherStaff($pdo, $current_user_id) {
    $staffQuery = "SELECT u.id, sp.full_name, sp.designation 
                   FROM users u 
                   LEFT JOIN staff_profiles sp ON u.id = sp.user_id 
                   WHERE u.id != ? AND u.user_type IN ('admin', 'manager', 'support_tech')
                   ORDER BY sp.full_name";
    $staffStmt = $pdo->prepare($staffQuery);
    $staffStmt->execute([$current_user_id]);
    return $staffStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - MSP Application</title>
    
    <!-- Load CSS files with fallback -->
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* Additional styles specific to this page if needed */
        .main-content {
            padding: 1.5rem !important;
        }
        
        /* Enhanced stats cards */
        .stats-card {
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            border: none;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.2);
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stats-label {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 500;
        }
        
        /* Enhanced form section */
        .form-section {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .field-header h6 {
            color: #2c3e50;
            font-weight: 600;
            margin: 0;
        }
        
        .permission-indicator {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .permission-full {
            background-color: #d4edda;
            color: #155724;
        }
        
        .permission-restricted {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* Enhanced tab navigation */
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
        }
        
        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: #6c757d;
            font-weight: 500;
            padding: 1rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link:hover {
            color: #495057;
            border-color: transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: #007bff;
            border-bottom-color: #007bff;
            background-color: transparent;
        }
        
        /* Enhanced form controls */
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
        
        /* Enhanced buttons */
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
        
        /* Enhanced header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }
        
        .role-info {
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Enhanced user info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
        }
        
        /* Enhanced alerts */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        /* Enhanced permission info */
        .permission-info-card {
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid #e3f2fd;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
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
        
        /* List items for tickets/visits */
        .item-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .list-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s ease;
        }
        
        .list-item:last-child {
            border-bottom: none;
        }
        
        .list-item:hover {
            background-color: #f8f9fa;
        }
        
        /* Management section */
        .management-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Form actions */
        .form-actions {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            margin-top: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        /* Mobile menu toggle */
        .mobile-menu-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            color: white;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
    </style>    
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title">
                <i class="fas fa-user-edit"></i> Edit User
            </h1>
            <p class="text-muted">Update information for <?= htmlspecialchars($user['full_name'] ?? $user['email']) ?></p>
        </div>
        <div class="btn-group">
            <a href="<?= route('users.view') . '?id=' . $user_id ?>" class="btn btn-outline-secondary">
                <i class="fas fa-eye"></i> View Profile
            </a>
            <a href="<?= route('users.index') ?>" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
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
                    <?php if ($is_self): ?>
                    <div class="info-item mt-2">
                        <span class="badge bg-info">Editing Your Own Profile</span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="info-item">
                        <span class="info-label">Your role:</span>
                        <span class="info-value"><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $current_user_role))) ?></strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
            
            <!-- User Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="stats-number"><?= $user['ticket_count'] ?? 0 ?></div>
                        <div class="stats-label">Tickets Created</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stats-number"><?= $user['assigned_tickets'] ?? 0 ?></div>
                        <div class="stats-label">Assigned Tickets</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="stats-number"><?= $user['site_visits_count'] ?? 0 ?></div>
                        <div class="stats-label">Site Visits</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="stats-number">
                            <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                        </div>
                        <div class="stats-label">Account Status</div>
                    </div>
                </div>
            </div>
            
            <!-- Permission Info -->
            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-start">
                    <i class="fas fa-user-shield fa-2x me-3 mt-1"></i>
                    <div>
                        <h6 class="mb-2">Editing Permissions</h6>
                        <p class="mb-1">
                            <strong>Your Access Level:</strong> 
                            <?php if ($current_user_role === 'super_admin'): ?>
                            <span class="badge bg-danger">Super Admin</span> - Full edit access to all fields
                            <?php elseif ($current_user_role === 'admin'): ?>
                            <span class="badge bg-primary">Admin</span> - Can edit most fields except Super Admin accounts
                            <?php elseif ($current_user_role === 'manager'): ?>
                            <span class="badge bg-success">Manager</span> - Can manage tickets/site visits and edit staff info
                            <?php else: ?>
                            <span class="badge bg-warning">Limited</span> - Can only edit own profile
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            <strong>Editing:</strong> 
                            <?= htmlspecialchars($user['full_name'] ?? $user['email']) ?>
                            <span class="badge bg-secondary ms-2">
                                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user['user_type']))) ?>
                            </span>
                        </p>
                    </div>
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
            
            <!-- Edit Form -->
            <form method="POST" action="?id=<?= htmlspecialchars($user_id) ?>" id="editUserForm">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs mb-4" id="editTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#basic" type="button" role="tab">
                            <i class="fas fa-user me-2"></i> Basic Info
                        </button>
                    </li>
                    
                    <?php if (canEditField('staff_info', $current_user_role, $user['user_type'], $is_self) && 
                              in_array($user['user_type'], ['admin', 'manager', 'support_tech'])): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="staff-tab" data-bs-toggle="tab" data-bs-target="#staff" type="button" role="tab">
                            <i class="fas fa-briefcase me-2"></i> Staff Info
                        </button>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (canEditField('account_settings', $current_user_role, $user['user_type'], $is_self)): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="account-tab" data-bs-toggle="tab" data-bs-target="#account" type="button" role="tab">
                            <i class="fas fa-cog me-2"></i> Account Settings
                        </button>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (canEditField('ticket_management', $current_user_role, $user['user_type'], $is_self) && !empty($assigned_tickets)): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tickets-tab" data-bs-toggle="tab" data-bs-target="#tickets" type="button" role="tab">
                            <i class="fas fa-ticket-alt me-2"></i> Ticket Management
                            <span class="badge bg-primary ms-2"><?= count($assigned_tickets) ?></span>
                        </button>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (canEditField('site_visit_info', $current_user_role, $user['user_type'], $is_self) && !empty($site_visits)): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="visits-tab" data-bs-toggle="tab" data-bs-target="#visits" type="button" role="tab">
                            <i class="fas fa-map-marker-alt me-2"></i> Site Visits
                            <span class="badge bg-success ms-2"><?= count($site_visits) ?></span>
                        </button>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                            <i class="fas fa-shield-alt me-2"></i> Security
                        </button>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content" id="editTabsContent">
                    <!-- Basic Information Tab -->
                    <div class="tab-pane fade show active" id="basic" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-id-card me-2"></i> Personal Information</h6>
                                <span class="permission-indicator <?= canEditField('email', $current_user_role, $user['user_type'], $is_self) ? 'permission-full' : 'permission-restricted' ?>">
                                    <?= canEditField('email', $current_user_role, $user['user_type'], $is_self) ? 'Editable' : 'Read Only' ?>
                                </span>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label required">Email Address</label>
                                    <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?> 
                                           <?= !canEditField('email', $current_user_role, $user['user_type'], $is_self) ? 'read-only-field' : '' ?>" 
                                           id="email" name="email" value="<?= htmlspecialchars($email) ?>" 
                                           placeholder="user@example.com" required
                                           <?= !canEditField('email', $current_user_role, $user['user_type'], $is_self) ? 'readonly' : '' ?>>
                                    <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label required">Full Name</label>
                                    <input type="text" class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?> 
                                           <?= !canEditField('full_name', $current_user_role, $user['user_type'], $is_self) ? 'read-only-field' : '' ?>" 
                                           id="full_name" name="full_name" value="<?= htmlspecialchars($full_name) ?>" 
                                           placeholder="John Doe" required
                                           <?= !canEditField('full_name', $current_user_role, $user['user_type'], $is_self) ? 'readonly' : '' ?>>
                                    <?php if (isset($errors['full_name'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['full_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?> 
                                           <?= !canEditField('phone', $current_user_role, $user['user_type'], $is_self) ? 'read-only-field' : '' ?>" 
                                           id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" 
                                           placeholder="+1 (555) 123-4567"
                                           <?= !canEditField('phone', $current_user_role, $user['user_type'], $is_self) ? 'readonly' : '' ?>>
                                    <?php if (isset($errors['phone'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['phone']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (canEditField('user_type', $current_user_role, $user['user_type'], $is_self)): ?>
                                <div class="col-md-6">
                                    <label for="user_type" class="form-label required">User Type / Role</label>
                                    <select class="form-control <?= isset($errors['user_type']) ? 'is-invalid' : '' ?>" 
                                            id="user_type" name="user_type" required>
                                        <option value="">Select User Type</option>
                                        <?php foreach ($available_user_types as $type): 
                                            $type_name = ucfirst(str_replace('_', ' ', $type));
                                        ?>
                                        <option value="<?= $type ?>" <?= $user_type === $type ? 'selected' : '' ?>>
                                            <?= $type_name ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($errors['user_type'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['user_type']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label">User Type / Role</label>
                                    <input type="text" class="form-control read-only-field" 
                                           value="<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $user_type))) ?>" 
                                           readonly>
                                    <input type="hidden" name="user_type" value="<?= htmlspecialchars($user_type) ?>">
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Staff Information Tab -->
                    <?php if (canEditField('staff_info', $current_user_role, $user['user_type'], $is_self) && 
                              in_array($user['user_type'], ['admin', 'manager', 'support_tech'])): ?>
                    <div class="tab-pane fade" id="staff" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-briefcase me-2"></i> Employment Information</h6>
                                <span class="permission-indicator permission-full">Editable</span>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="staff_id" class="form-label">Staff ID</label>
                                    <input type="text" class="form-control" 
                                           id="staff_id" name="staff_id" value="<?= htmlspecialchars($staff_id) ?>" 
                                           placeholder="EMP001">
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="designation" class="form-label">Designation</label>
                                    <input type="text" class="form-control" 
                                           id="designation" name="designation" value="<?= htmlspecialchars($designation) ?>" 
                                           placeholder="e.g., Senior Engineer">
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Department</label>
                                    <input type="text" class="form-control" 
                                           id="department" name="department" value="<?= htmlspecialchars($department) ?>" 
                                           placeholder="e.g., IT Support">
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="employment_type" class="form-label">Employment Type</label>
                                    <select class="form-control" id="employment_type" name="employment_type">
                                        <option value="">Select Type</option>
                                        <option value="Full-time" <?= $employment_type === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                                        <option value="Part-time" <?= $employment_type === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                                        <option value="Contract" <?= $employment_type === 'Contract' ? 'selected' : '' ?>>Contract</option>
                                        <option value="Intern" <?= $employment_type === 'Intern' ? 'selected' : '' ?>>Intern</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="date_of_joining" class="form-label">Date of Joining</label>
                                    <input type="date" class="form-control <?= isset($errors['date_of_joining']) ? 'is-invalid' : '' ?>" 
                                           id="date_of_joining" name="date_of_joining" value="<?= htmlspecialchars($date_of_joining) ?>">
                                    <?php if (isset($errors['date_of_joining'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['date_of_joining']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="employment_status" class="form-label">Employment Status</label>
                                    <select class="form-control" id="employment_status" name="employment_status">
                                        <option value="Active" <?= $employment_status === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="On Leave" <?= $employment_status === 'On Leave' ? 'selected' : '' ?>>On Leave</option>
                                        <option value="Resigned" <?= $employment_status === 'Resigned' ? 'selected' : '' ?>>Resigned</option>
                                        <option value="Terminated" <?= $employment_status === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="experience_years" class="form-label">Experience (Years)</label>
                                    <input type="number" class="form-control <?= isset($errors['experience_years']) ? 'is-invalid' : '' ?>" 
                                           id="experience_years" name="experience_years" value="<?= htmlspecialchars($experience_years) ?>" 
                                           placeholder="5" min="0" max="50">
                                    <?php if (isset($errors['experience_years'])): ?>
                                    <div class="invalid-feedback"><?= htmlspecialchars($errors['experience_years']) ?></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="shift_timing" class="form-label">Shift Timing</label>
                                    <input type="text" class="form-control" 
                                           id="shift_timing" name="shift_timing" value="<?= htmlspecialchars($shift_timing) ?>" 
                                           placeholder="e.g., 9:00 AM - 6:00 PM">
                                </div>
                                
                                <div class="col-md-12">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="on_call_support" name="on_call_support" 
                                               value="1" <?= $on_call_support ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="on_call_support">
                                            <strong>On-Call Support</strong>
                                            <div class="text-muted">Available for emergency support outside working hours</div>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-12">
                                    <label for="skills" class="form-label">Skills</label>
                                    <textarea class="form-control" id="skills" name="skills" rows="3" 
                                              placeholder="List skills separated by commas"><?= htmlspecialchars($skills) ?></textarea>
                                </div>
                                
                                <div class="col-md-12">
                                    <label for="certifications" class="form-label">Certifications</label>
                                    <textarea class="form-control" id="certifications" name="certifications" rows="3" 
                                              placeholder="List certifications"><?= htmlspecialchars($certifications) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Account Settings Tab -->
                    <?php if (canEditField('account_settings', $current_user_role, $user['user_type'], $is_self)): ?>
                    <div class="tab-pane fade" id="account" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-cog me-2"></i> Account Settings</h6>
                                <span class="permission-indicator permission-full">Super Admin Only</span>
                            </div>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                These settings affect user access and security. Only Super Administrators can modify these fields.
                            </div>
                            
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
                                        <input class="form-check-input" type="checkbox" id="two_factor_enabled" name="two_factor_enabled" 
                                               value="1" <?= $two_factor_enabled ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="two_factor_enabled">
                                            <strong>Two-Factor Authentication</strong>
                                            <div class="text-muted">Require 2FA for login (if configured)</div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Ticket Management Tab -->
                    <?php if (canEditField('ticket_management', $current_user_role, $user['user_type'], $is_self) && !empty($assigned_tickets)): ?>
                    <div class="tab-pane fade" id="tickets" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-ticket-alt me-2"></i> Ticket Management</h6>
                                <span class="permission-indicator permission-full">Manager/Super Admin</span>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Manage tickets assigned to this user. You can reassign them to another staff member.
                            </div>
                            
                            <div class="management-section">
                                <h6>Assigned Tickets (<?= count($assigned_tickets) ?>)</h6>
                                <div class="item-list">
                                    <?php foreach ($assigned_tickets as $ticket): ?>
                                    <div class="list-item">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong><?= htmlspecialchars($ticket['title']) ?></strong>
                                                <div class="text-muted small">
                                                    Client: <?= htmlspecialchars($ticket['company_name'] ?? 'N/A') ?> | 
                                                    Priority: <span class="badge bg-<?= $ticket['priority'] === 'High' ? 'danger' : ($ticket['priority'] === 'Medium' ? 'warning' : 'secondary') ?>">
                                                        <?= htmlspecialchars($ticket['priority']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-muted small">
                                                <?= date('M d, Y', strtotime($ticket['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label for="new_ticket_assignee" class="form-label">Reassign Tickets To</label>
                                        <select class="form-control" id="new_ticket_assignee" name="new_ticket_assignee">
                                            <option value="">Select Staff Member</option>
                                            <?php 
                                            $other_staff = getOtherStaff($pdo, $user_id);
                                            foreach ($other_staff as $staff): ?>
                                            <option value="<?= htmlspecialchars($staff['id']) ?>">
                                                <?= htmlspecialchars($staff['full_name']) ?> - <?= htmlspecialchars($staff['designation'] ?? 'Staff') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="reassign_tickets" name="reassign_tickets" value="1">
                                            <label class="form-check-label" for="reassign_tickets">
                                                Reassign all tickets
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Site Visits Tab -->
                    <?php if (canEditField('site_visit_info', $current_user_role, $user['user_type'], $is_self) && !empty($site_visits)): ?>
                    <div class="tab-pane fade" id="visits" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-map-marker-alt me-2"></i> Site Visit Management</h6>
                                <span class="permission-indicator permission-full">Manager/Super Admin</span>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Manage site visits assigned to this engineer.
                            </div>
                            
                            <div class="management-section">
                                <h6>Site Visits (<?= count($site_visits) ?>)</h6>
                                <div class="item-list">
                                    <?php foreach ($site_visits as $visit): ?>
                                    <div class="list-item">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong><?= htmlspecialchars($visit['location_name'] ?? 'Site Visit') ?></strong>
                                                <div class="text-muted small">
                                                    Client: <?= htmlspecialchars($visit['company_name'] ?? 'N/A') ?>
                                                </div>
                                            </div>
                                            <div class="text-muted small">
                                                <?= date('M d, Y', strtotime($visit['created_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label for="new_site_engineer" class="form-label">Reassign Site Visits To</label>
                                        <select class="form-control" id="new_site_engineer" name="new_site_engineer">
                                            <option value="">Select Engineer</option>
                                            <?php 
                                            $other_staff = getOtherStaff($pdo, $user_id);
                                            foreach ($other_staff as $staff): ?>
                                            <option value="<?= htmlspecialchars($staff['id']) ?>">
                                                <?= htmlspecialchars($staff['full_name']) ?> - <?= htmlspecialchars($staff['designation'] ?? 'Engineer') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="reassign_visits" name="reassign_visits" value="1">
                                            <label class="form-check-label" for="reassign_visits">
                                                Reassign all site visits
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Security Tab -->
                    <div class="tab-pane fade" id="security" role="tabpanel">
                        <div class="form-section">
                            <div class="field-header">
                                <h6><i class="fas fa-shield-alt me-2"></i> Security Settings</h6>
                                <span class="permission-indicator <?= $is_self ? 'permission-full' : ($current_user_role === 'super_admin' ? 'permission-full' : 'permission-limited') ?>">
                                    <?= $is_self ? 'Self Editable' : ($current_user_role === 'super_admin' ? 'Full Access' : 'Limited') ?>
                                </span>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Change the user's password. Leave blank to keep the current password.
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="password" class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
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
                                
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
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
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <a href="<?= route('users.view') . '?id=' . $user_id ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                        <div>
                            <button type="reset" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update User
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </main>
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
        const phoneField = document.getElementById('phone');
        if (phoneField) {
            phoneField.addEventListener('input', function(e) {
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
        
        // Form validation
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
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
            
            // Check if reassigning tickets/visits without selecting new assignee
            const reassignTickets = document.getElementById('reassign_tickets');
            const newTicketAssignee = document.getElementById('new_ticket_assignee');
            if (reassignTickets && reassignTickets.checked && (!newTicketAssignee || !newTicketAssignee.value)) {
                e.preventDefault();
                alert('Please select a staff member to reassign tickets to.');
                return;
            }
            
            const reassignVisits = document.getElementById('reassign_visits');
            const newSiteEngineer = document.getElementById('new_site_engineer');
            if (reassignVisits && reassignVisits.checked && (!newSiteEngineer || !newSiteEngineer.value)) {
                e.preventDefault();
                alert('Please select an engineer to reassign site visits to.');
                return;
            }
            
            // Show confirmation dialog
            const isSelf = <?= $is_self ? 'true' : 'false' ?>;
            const message = isSelf 
                ? 'Are you sure you want to update your profile?' 
                : 'Are you sure you want to update this user?';
                
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
        
        // Initialize Bootstrap tabs
        const tabEl = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEl.forEach(tab => {
            tab.addEventListener('shown.bs.tab', function (event) {
                // Save active tab to localStorage
                localStorage.setItem('activeEditTab', event.target.id);
            });
        });
        
        // Restore active tab from localStorage
        const activeTab = localStorage.getItem('activeEditTab');
        if (activeTab) {
            const tabElement = document.getElementById(activeTab);
            if (tabElement) {
                new bootstrap.Tab(tabElement).show();
            }
        }
        
        // Show/hide staff ID field based on user type
        const userTypeSelect = document.getElementById('user_type');
        if (userTypeSelect) {
            userTypeSelect.addEventListener('change', function() {
                const staffTypes = ['admin', 'manager', 'support_tech'];
                const staffTab = document.getElementById('staff-tab');
                
                if (staffTab) {
                    if (staffTypes.includes(this.value)) {
                        staffTab.style.display = 'block';
                    } else {
                        staffTab.style.display = 'none';
                    }
                }
            });
            
            // Initial check
            const staffTab = document.getElementById('staff-tab');
            if (staffTab) {
                const staffTypes = ['admin', 'manager', 'support_tech'];
                if (!staffTypes.includes(userTypeSelect.value)) {
                    staffTab.style.display = 'none';
                }
            }
        }
    
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('active');
    });
    
    // Add animation to stats cards on page load
    document.addEventListener('DOMContentLoaded', function() {
        const statsCards = document.querySelectorAll('.stats-card');
        statsCards.forEach((card, index) => {
            // Add animation delay for each card
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 150 * index);
        });
        
        // Add animation to form sections
        const formSections = document.querySelectorAll('.form-section');
        formSections.forEach((section, index) => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            section.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            
            setTimeout(() => {
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, 200 * (index + 1));
        });
    });
    </script>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
</body>
</html>