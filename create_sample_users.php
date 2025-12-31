<?php
require_once 'config/database.php';

$pdo = getDBConnection();

// Sample users data
$sample_users = [
    [
        'email' => 'manager@msp.com',
        'password' => 'Manager@123',
        'user_type' => 'manager',
        'full_name' => 'Sarah Manager',
        'designation' => 'Operations Manager',
        'department' => 'Management',
        'phone' => '+255712345678'
    ],
    [
        'email' => 'engineer1@msp.com',
        'password' => 'Engineer@123',
        'user_type' => 'support_tech',
        'full_name' => 'John Engineer',
        'designation' => 'Network Engineer',
        'department' => 'Technical Support',
        'phone' => '+255712345679'
    ],
    [
        'email' => 'engineer2@msp.com',
        'password' => 'Engineer@123',
        'user_type' => 'support_tech',
        'full_name' => 'Mike Technician',
        'designation' => 'CCTV Specialist',
        'department' => 'Technical Support',
        'phone' => '+255712345680'
    ],
    [
        'email' => 'client1@example.com',
        'password' => 'Client@123',
        'user_type' => 'client',
        'full_name' => 'ABC Corporation',
        'designation' => 'IT Manager',
        'department' => 'IT Department',
        'phone' => '+255712345681'
    ],
    [
        'email' => 'client2@example.com',
        'password' => 'Client@123',
        'user_type' => 'client',
        'full_name' => 'XYZ Enterprises',
        'designation' => 'Operations Head',
        'department' => 'Operations',
        'phone' => '+255712345682'
    ]
];

echo "<h2>Creating Sample Users...</h2>";

foreach ($sample_users as $user_data) {
    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$user_data['email']]);
        
        if ($stmt->fetch()) {
            echo "<p>User {$user_data['email']} already exists - skipping</p>";
            continue;
        }
        
        // Create user
        $password_hash = password_hash($user_data['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, user_type, is_active, email_verified) 
            VALUES (?, ?, ?, true, true)
            RETURNING id
        ");
        $stmt->execute([$user_data['email'], $password_hash, $user_data['user_type']]);
        $user_id = $stmt->fetch()['id'];
        
        echo "<p>Created user: {$user_data['email']}</p>";
        
        // Create staff profile if not client
        if ($user_data['user_type'] !== 'client') {
            $staff_id = 'MSP-' . strtoupper(uniqid());
            
            $stmt = $pdo->prepare("
                INSERT INTO staff_profiles 
                (user_id, staff_id, full_name, designation, department, phone_number, employment_status) 
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");
            $stmt->execute([
                $user_id,
                $staff_id,
                $user_data['full_name'],
                $user_data['designation'],
                $user_data['department'],
                $user_data['phone']
            ]);
            
            echo "<p>Created staff profile for: {$user_data['full_name']}</p>";
        }
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>Error creating {$user_data['email']}: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Sample Users Created Successfully!</h3>";
echo "<p><strong>Login Credentials:</strong></p>";
echo "<ul>";
foreach ($sample_users as $user) {
    echo "<li>{$user['email']} / {$user['password']} - " . ucfirst($user['user_type']) . "</li>";
}
echo "</ul>";
echo "<p><a href='login.php'>Go to Login Page</a></p>";
?>