<?php
// Minimal setup for error page to avoid circular dependencies
$page_title = 'Access Denied - 403 Error';
$error_code = 403;
$error_title = 'Access Denied';
$error_message = 'You do not have permission to access this resource. Please contact your administrator if you believe this is an error.';
$show_home_link = true;

// Include minimal header without authentication
include_once 'minimal_header.php';

// Include the error template
include_once 'error_template.php';
?>