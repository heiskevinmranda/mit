<?php
/**
 * Diagnostic Test Script for Dashboard Issues
 * 
 * This script tests all the components needed for the dashboard to work properly.
 * Run this by navigating to: http://localhost/mit/test_dashboard_diagnostic.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Dashboard Diagnostic Test</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .test { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ccc; }
    .pass { border-left-color: #28a745; }
    .fail { border-left-color: #dc3545; }
    .warning { border-left-color: #ffc107; }
    h2 { color: #333; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
</style>";

// Test 1: Check if includes directory exists
echo "<div class='test " . (is_dir(__DIR__ . '/includes') ? 'pass' : 'fail') . "'>";
echo "<h2>Test 1: Includes Directory</h2>";
if (is_dir(__DIR__ . '/includes')) {
    echo "✓ PASS: includes/ directory exists<br>";
    echo "Path: " . __DIR__ . '/includes';
} else {
    echo "✗ FAIL: includes/ directory not found<br>";
    echo "Expected path: " . __DIR__ . '/includes';
}
echo "</div>";

// Test 2: Check required files exist
$required_files = [
    'includes/auth.php',
    'includes/routes.php',
    'includes/sidebar.php',
    'includes/profile_picture_helper.php',
    'config/database.php',
    'css/style.css'
];

echo "<div class='test'>";
echo "<h2>Test 2: Required Files</h2>";
$all_files_exist = true;
foreach ($required_files as $file) {
    $exists = file_exists(__DIR__ . '/' . $file);
    $all_files_exist = $all_files_exist && $exists;
    echo ($exists ? "✓" : "✗") . " " . $file . "<br>";
}
echo "</div>";

// Test 3: Try to include auth.php
echo "<div class='test'>";
echo "<h2>Test 3: Load auth.php</h2>";
try {
    require_once __DIR__ . '/includes/auth.php';
    echo "✓ PASS: auth.php loaded successfully<br>";
    
    // Check if session is started
    if (session_status() === PHP_SESSION_ACTIVE) {
        echo "✓ Session is active<br>";
    } else {
        echo "⚠ WARNING: Session is not active<br>";
    }
    
    // Check if required functions exist
    $auth_functions = ['isLoggedIn', 'getCurrentUser', 'requireLogin', 'attemptLogin'];
    foreach ($auth_functions as $func) {
        echo (function_exists($func) ? "✓" : "✗") . " Function: $func()<br>";
    }
} catch (Exception $e) {
    echo "✗ FAIL: Error loading auth.php<br>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "</div>";

// Test 4: Try to include routes.php
echo "<div class='test'>";
echo "<h2>Test 4: Load routes.php</h2>";
try {
    require_once __DIR__ . '/includes/routes.php';
    echo "✓ PASS: routes.php loaded successfully<br>";
    
    // Check if route function exists
    if (function_exists('route')) {
        echo "✓ Function route() exists<br>";
        
        // Test some routes
        $test_routes = ['dashboard', 'login', 'users.index', 'clients.index'];
        foreach ($test_routes as $route_name) {
            $route_url = route($route_name);
            echo "  - route('$route_name') = $route_url<br>";
        }
    } else {
        echo "✗ Function route() not found<br>";
    }
} catch (Exception $e) {
    echo "✗ FAIL: Error loading routes.php<br>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "</div>";

// Test 5: Database connection
echo "<div class='test'>";
echo "<h2>Test 5: Database Connection</h2>";
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = getDBConnection();
    echo "✓ PASS: Database connection successful<br>";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $count = $stmt->fetchColumn();
    echo "✓ Found $count users in database<br>";
} catch (Exception $e) {
    echo "✗ FAIL: Database connection error<br>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "</div>";

// Test 6: Profile picture helper
echo "<div class='test'>";
echo "<h2>Test 6: Profile Picture Helper</h2>";
try {
    require_once __DIR__ . '/includes/profile_picture_helper.php';
    echo "✓ PASS: profile_picture_helper.php loaded successfully<br>";
    
    if (function_exists('getProfilePictureHTML')) {
        echo "✓ Function getProfilePictureHTML() exists<br>";
    } else {
        echo "✗ Function getProfilePictureHTML() not found<br>";
    }
} catch (Exception $e) {
    echo "✗ FAIL: Error loading profile_picture_helper.php<br>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "</div>";

// Test 7: CSS file accessibility
echo "<div class='test'>";
echo "<h2>Test 7: CSS File</h2>";
$css_path = __DIR__ . '/css/style.css';
if (file_exists($css_path)) {
    echo "✓ PASS: CSS file exists<br>";
    echo "Path: $css_path<br>";
    echo "Size: " . filesize($css_path) . " bytes<br>";
    echo "URL: <a href='/mit/css/style.css' target='_blank'>/mit/css/style.css</a><br>";
} else {
    echo "✗ FAIL: CSS file not found<br>";
    echo "Expected path: $css_path";
}
echo "</div>";

// Test 8: Check PHP version and extensions
echo "<div class='test'>";
echo "<h2>Test 8: PHP Environment</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "PDO Extension: " . (extension_loaded('pdo') ? "✓ Loaded" : "✗ Not loaded") . "<br>";
echo "PDO PostgreSQL: " . (extension_loaded('pdo_pgsql') ? "✓ Loaded" : "✗ Not loaded") . "<br>";
echo "Session Support: " . (extension_loaded('session') ? "✓ Loaded" : "✗ Not loaded") . "<br>";
echo "</div>";

// Test 9: Check .htaccess and mod_rewrite
echo "<div class='test'>";
echo "<h2>Test 9: URL Rewriting</h2>";
if (file_exists(__DIR__ . '/.htaccess')) {
    echo "✓ .htaccess file exists<br>";
    
    // Check if mod_rewrite is enabled (Apache only)
    if (function_exists('apache_get_modules')) {
        $modules = apache_get_modules();
        if (in_array('mod_rewrite', $modules)) {
            echo "✓ mod_rewrite is enabled<br>";
        } else {
            echo "⚠ WARNING: mod_rewrite may not be enabled<br>";
        }
    } else {
        echo "⚠ Cannot check mod_rewrite status (not Apache or function disabled)<br>";
    }
} else {
    echo "✗ .htaccess file not found<br>";
}
echo "</div>";

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If all tests pass, the dashboard should work. If you see failures above, those are the issues to fix.</p>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Fix any failed tests above</li>";
echo "<li>Try accessing the dashboard at: <a href='/mit/dashboard'>/mit/dashboard</a></li>";
echo "<li>If the dashboard is still blank, check your browser's Developer Console (F12) for JavaScript errors</li>";
echo "<li>Check the Network tab in Developer Tools to see if CSS/JS files are loading (should be HTTP 200)</li>";
echo "</ol>";
?>
