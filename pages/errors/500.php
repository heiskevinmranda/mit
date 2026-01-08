<?php
// Minimal setup for error page to avoid circular dependencies
$page_title = 'Server Error - 500 Error';
$error_code = 500;
$error_title = 'Server Error';
$error_message = 'An internal server error has occurred. Our team has been notified and is working to resolve the issue. Please try again later.';
$show_home_link = true;

// Include minimal header without authentication
include_once 'minimal_header.php';

// Include the error template
include_once 'error_template.php';
?>