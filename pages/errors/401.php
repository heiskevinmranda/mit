<?php
// Minimal setup for error page to avoid circular dependencies
$page_title = 'Unauthorized - 401 Error';
$error_code = 401;
$error_title = 'Unauthorized Access';
$error_message = 'You need to log in to access this resource. Please sign in to continue.';
$show_home_link = false; // Don't show home link, show login link instead

// Include minimal header without authentication
include_once 'minimal_header.php';

// Include the error template
include_once 'error_template.php';
?>