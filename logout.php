<?php
require_once 'includes/auth.php';

// Destroy session
session_destroy();

// Redirect to login page directly instead of using route function to avoid issues after session destruction
header('Location: https://admin.msp.co.tz/');
exit;
?>