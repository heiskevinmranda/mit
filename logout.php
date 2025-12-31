<?php
require_once 'includes/auth.php';

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
?>