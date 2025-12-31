<?php
require_once 'config/database.php';

$pdo = getDBConnection();

echo "<h2>Checking Database Users</h2>";

// Check all users
$users = $pdo->query("SELECT id, email, password, user_type, is_active FROM users ORDER BY id")->fetchAll();

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Email</th><th>Password Hash</th><th>User Type</th><th>Active</th><th>Test Login</th></tr>";

foreach ($users as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['email']}</td>";
    echo "<td>" . substr($user['password'], 0, 20) . "...</td>";
    echo "<td>{$user['user_type']}</td>";
    echo "<td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>";
    
    // Test password
    $test_password = 'Admin@123';
    if ($user['email'] == 'engineer@msp.com') {
        $test_password = 'Engineer@123';
    } elseif ($user['email'] == 'manager@msp.com') {
        $test_password = 'Manager@123';
    }
    
    $password_match = password_verify($test_password, $user['password']);
    echo "<td>" . ($password_match ? '✅ Password OK' : '❌ Password FAIL') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check staff profiles
echo "<h2>Staff Profiles</h2>";
$staff = $pdo->query("SELECT sp.*, u.email FROM staff_profiles sp LEFT JOIN users u ON sp.user_id = u.id")->fetchAll();

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Staff ID</th><th>Full Name</th><th>Email</th><th>Designation</th><th>Status</th></tr>";

foreach ($staff as $s) {
    echo "<tr>";
    echo "<td>{$s['staff_id']}</td>";
    echo "<td>{$s['full_name']}</td>";
    echo "<td>{$s['email']}</td>";
    echo "<td>{$s['designation']}</td>";
    echo "<td>{$s['employment_status']}</td>";
    echo "</tr>";
}

echo "</table>";

// Check user roles
echo "<h2>User Roles</h2>";
$roles = $pdo->query("SELECT * FROM user_roles")->fetchAll();

echo "<pre>";
print_r($roles);
echo "</pre>";
?>