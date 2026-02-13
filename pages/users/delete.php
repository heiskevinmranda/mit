<?php
// pages/users/delete.php
session_start();

// Use absolute paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';

if (!isLoggedIn()) {
    header("Location: " . route('login'));
    exit();
}

$current_user_role = $_SESSION['user_type'] ?? null;
$current_user_id = $_SESSION['user_id'] ?? null;

// Only Super Admin can delete users
if ($current_user_role !== 'super_admin') {
    $_SESSION['error'] = "Only Super Administrators can delete users.";
    header("Location: " . route('users.index'));
    exit();
}

$pdo = getDBConnection();

// Get user ID from URL
$user_id = $_GET['id'] ?? null;
$archive_mode = true; // Set to true for archiving instead of permanent deletion

if (!$user_id) {
    $_SESSION['error'] = "User ID is required.";
    header("Location: " . route('users.index'));
    exit();
}

// Check if Request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If accessed via GET, redirect to index (since we use modal now)
    header("Location: " . route('users.index'));
    exit();
}

// Fetch user data for validation
$userQuery = "SELECT id, email, user_type FROM users WHERE id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found.";
    header("Location: " . route('users.index'));
    exit();
}

// Prevent deleting super_admin users
if ($user['user_type'] === 'super_admin') {
    $_SESSION['error'] = "Cannot delete Super Administrator accounts.";
    header("Location: " . route('users.index'));
    exit();
}

// Check if user is trying to delete themselves
if ($current_user_id == $user_id) {
    $_SESSION['error'] = "You cannot delete your own account.";
    header("Location: " . route('users.index'));
    exit();
}

// Handle deletion logic
$delete_type = $_POST['delete_type'] ?? 'archive';
$reason = trim($_POST['reason'] ?? '');
$transfer_tickets = isset($_POST['transfer_tickets']);
$transfer_to = $_POST['transfer_to'] ?? null;
$notify_user = isset($_POST['notify_user']);

if (empty($reason)) {
    $_SESSION['error'] = "Please provide a reason for deletion.";
    header("Location: " . route('users.index'));
    exit();
}

try {
    $pdo->beginTransaction();
    
    if ($archive_mode && $delete_type === 'archive') {
        // Archive user (soft delete)
        $archiveQuery = "UPDATE users SET 
                        is_active = false,
                        email = CONCAT(email, '_archived_', UUID()),
                        updated_at = NOW()
                        WHERE id = ?";
        $archiveStmt = $pdo->prepare($archiveQuery);
        $archiveStmt->execute([$user_id]);
        
        // Archive staff profile if exists
        $archiveStaffQuery = "UPDATE staff_profiles SET 
                                employment_status = 'Terminated',
                                updated_at = NOW()
                                WHERE user_id = ?";
        $archiveStaffStmt = $pdo->prepare($archiveStaffQuery);
        $archiveStaffStmt->execute([$user_id]);
        
        $action_type = 'ARCHIVE';
    } else {
        // Permanent deletion
        
        // Get staff_id first if exists (needed for ticket_assignees)
        $staffId = null;
        $getStaffIdQuery = "SELECT id FROM staff_profiles WHERE user_id = ?";
        $getStaffIdStmt = $pdo->prepare($getStaffIdQuery);
        $getStaffIdStmt->execute([$user_id]);
        $staffId = $getStaffIdStmt->fetchColumn();

        // 1. Update tickets created by this user
        $updateTicketsQuery = "UPDATE tickets SET created_by = NULL WHERE created_by = ?";
        $updateTicketsStmt = $pdo->prepare($updateTicketsQuery);
        $updateTicketsStmt->execute([$user_id]);
        
        // 2. Remove user from ticket assignees (using staff_id)
        if ($staffId) {
            $deleteAssigneesQuery = "DELETE FROM ticket_assignees WHERE staff_id = ?";
            $deleteAssigneesStmt = $pdo->prepare($deleteAssigneesQuery);
            $deleteAssigneesStmt->execute([$staffId]);
            
            // 3. Transfer/Unassign tickets assigned to this staff
            if ($transfer_tickets && $transfer_to) {
                // Check if transfer_to is a valid staff_id
                $transferQuery = "UPDATE tickets SET assigned_to = ? WHERE assigned_to = ?";
                $transferStmt = $pdo->prepare($transferQuery);
                $transferStmt->execute([$transfer_to, $staffId]);
            } else {
                $unassignQuery = "UPDATE tickets SET assigned_to = NULL WHERE assigned_to = ?";
                $unassignStmt = $pdo->prepare($unassignQuery);
                $unassignStmt->execute([$staffId]);
            }
            
            // 4. Delete staff profile
            $deleteStaffQuery = "DELETE FROM staff_profiles WHERE id = ?";
            $deleteStaffStmt = $pdo->prepare($deleteStaffQuery);
            $deleteStaffStmt->execute([$staffId]);
        }
        
        // 5. Finally delete the user
        $deleteUserQuery = "DELETE FROM users WHERE id = ?";
        $deleteUserStmt = $pdo->prepare($deleteUserQuery);
        $deleteUserStmt->execute([$user_id]);
        
        $action_type = 'DELETE';
    }
    
    // Audit log
    try {
        $auditQuery = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $auditStmt = $pdo->prepare($auditQuery);
        $auditStmt->execute([
            $current_user_id,
            $action_type,
            'user',
            $user_id,
            "Reason: $reason",
            $_SERVER['REMOTE_ADDR']
        ]);
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    // Set success message
    if ($archive_mode && $delete_type === 'archive') {
        $_SESSION['success'] = "User '{$user['email']}' has been archived successfully.";
    } else {
        $_SESSION['success'] = "User '{$user['email']}' has been permanently deleted.";
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = "Error: " . $e->getMessage();
    error_log("User deletion error: " . $e->getMessage());
}

// Always redirect back to index
header("Location: " . route('users.index'));
exit();
?>