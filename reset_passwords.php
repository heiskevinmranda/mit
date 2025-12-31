<?php
require_once 'config/database.php';

$pdo = getDBConnection();

// Update all user passwords
$users = [
    ['email' => 'admin@msp.com', 'password' => 'Admin@123'],
    ['email' => 'engineer@msp.com', 'password' => 'Engineer@123'],
    ['email' => 'manager@msp.com', 'password' => 'Manager@123'],
    ['email' => 'engineer1@msp.com', 'password' => 'Engineer@123'],
    ['email' => 'engineer2@msp.com', 'password' => 'Engineer@123'],
    ['email' => 'client1@example.com', 'password' => 'Client@123'],
    ['email' => 'client2@example.com', 'password' => 'Client@123']
];

echo "<h2>Resetting Passwords</h2>";

foreach ($users as $user) {
    try {
        $password_hash = password_hash($user['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$password_hash, $user['email']]);
        
        $rows = $stmt->rowCount();
        echo "<p>" . ($rows > 0 ? "✅" : "❌") . " Updated password for: {$user['email']}</p>";
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Error updating {$user['email']}: " . $e->getMessage() . "</p>";
    }
}

// Verify passwords
echo "<h2>Verifying Passwords</h2>";

foreach ($users as $user) {
    $stmt = $pdo->prepare("SELECT password FROM users WHERE email = ?");
    $stmt->execute([$user['email']]);
    $db_user = $stmt->fetch();
    
    if ($db_user) {
        $match = password_verify($user['password'], $db_user['password']);
        echo "<p>" . ($match ? "✅" : "❌") . " Password verification for {$user['email']}: " . 
             ($match ? 'PASS' : 'FAIL') . "</p>";
    } else {
        echo "<p>❌ User not found: {$user['email']}</p>";
    }
}

echo "<h3>Done! Try logging in again.</h3>";
echo "<p><a href='login_fixed.php'>Go to Login Page</a></p>";
?>