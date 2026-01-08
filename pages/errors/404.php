<?php
// Minimal setup for error page to avoid circular dependencies
$page_title = 'Page Not Found - 404 Error';
$error_code = 404;
$error_title = 'Page Not Found';
$error_message = 'The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.';
$show_home_link = true;

// Include minimal header without authentication
include_once 'minimal_header.php';

// Include the error template
include_once 'error_template.php';
?>