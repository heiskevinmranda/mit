<?php
// Minimal header for error pages to avoid circular dependencies
ob_start(); // Start output buffering to prevent issues with headers

// Define a safe route function for error pages if it doesn't exist
if (!function_exists('route')) {
    function route($name, $params = []) {
        // Simple mapping for essential routes needed by error pages
        $routes = [
            'login' => '/mit/login',
            'dashboard' => '/mit/dashboard',
            'home' => '/mit/',
            'errors.404' => '/mit/errors/404',
            'errors.403' => '/mit/errors/403',
            'errors.500' => '/mit/errors/500',
            'errors.401' => '/mit/errors/401'
        ];
        
        return $routes[$name] ?? '/mit/'; // fallback to home page
    }
}

// Set page title if not already set
if (!isset($page_title)) {
    $page_title = 'Error - MSP Application';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">