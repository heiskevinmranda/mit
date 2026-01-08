<?php
// error_handler.php - Centralized error handling for invalid routes
session_start();

// Include basic auth to check login status without full auth system
$base_path = dirname(__FILE__);

// Check if user is logged in by checking session
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

// Determine the appropriate error page based on login status
if ($is_logged_in) {
    // If logged in, redirect to 404 page with dashboard access
    require_once $base_path . '/pages/errors/404.php';
} else {
    // If not logged in, redirect to login page
    header('Location: /mit/login');
    exit;
}
?>