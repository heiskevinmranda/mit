<?php
// pages/certificates/index.php
// Main entry point for certificate management

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$current_user = getCurrentUser();
$user_role = $current_user['user_type'] ?? '';

// Redirect based on user role
if (in_array($user_role, ['super_admin', 'admin', 'manager'])) {
    // Admin users go to management dashboard
    header("Location: admin_manage.php");
    exit();
} else {
    // Regular users go to their profile
    header("Location: ../../pages/staff/profile.php");
    exit();
}
?>