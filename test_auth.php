<?php
// test_auth.php
echo "<h2>Testing Authentication System</h2>";

// Include auth file
require_once 'includes/auth.php';

echo "<h3>Testing Database Connection</h3>";
try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<h3>Testing Authentication Functions</h3>";

// Test function exists
$functions_to_test = [
    'isLoggedIn',
    'requireLogin',
    'checkPermission',
    'requirePermission',
    'isSuperAdmin',
    'isAdmin',
    'isManager',
    'isStaff',
    'getCurrentUser',
    'setFlashMessage',
    'getFlashMessage',
    'attemptLogin',
    'logout',
    'hasPermission',
    'checkAuth'
];

foreach ($functions_to_test as $func) {
    if (function_exists($func)) {
        echo "<p style='color: green;'>✅ Function exists: $func()</p>";
    } else {
        echo "<p style='color: red;'>❌ Function missing: $func()</p>";
    }
}

echo "<h3>Current Session Status</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Test Login</h3>";
echo "<p><a href='login.php'>Go to Login Page</a></p>";
echo "<p><a href='dashboard.php'>Go to Dashboard</a></p>";
?>