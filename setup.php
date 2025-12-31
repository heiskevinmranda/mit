<?php
require_once 'config/database.php';

$pdo = getDBConnection();

try {
    // Create super admin user
    $password_hash = password_hash('Admin@123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, user_type, is_active, email_verified) 
        VALUES (?, ?, 'super_admin', true, true)
        ON CONFLICT (email) DO NOTHING
        RETURNING id
    ");
    $stmt->execute(['admin@msp.com', $password_hash]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Create staff profile for super admin
        $staff_id = 'MSP-' . strtoupper(uniqid());
        
        $stmt = $pdo->prepare("
            INSERT INTO staff_profiles (
                user_id, staff_id, full_name, designation, department, 
                employment_type, date_of_joining, official_email, phone_number,
                role_category, role_level, username
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user['id'],
            $staff_id,
            'System Administrator',
            'Super Admin',
            'Administration',
            'Full-time',
            date('Y-m-d'),
            'admin@msp.com',
            '+255123456789',
            'Admin',
            'super_admin',
            'admin'
        ]);
        
        echo "<h2>Setup Complete!</h2>";
        echo "<p>Super Admin account created:</p>";
        echo "<p><strong>Email:</strong> admin@msp.com</p>";
        echo "<p><strong>Password:</strong> Admin@123</p>";
        echo "<p><strong>Staff ID:</strong> $staff_id</p>";
        echo "<p><a href='login.php'>Go to Login Page</a></p>";
        
        // Create some sample data for testing
        createSampleData($pdo);
    } else {
        echo "<p>Super admin already exists or could not be created.</p>";
        echo "<p><a href='login.php'>Go to Login Page</a></p>";
    }
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}

function createSampleData($pdo) {
    // Create sample client
    $stmt = $pdo->prepare("
        INSERT INTO clients (company_name, contact_person, email, phone, address, city, country) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        'Demo Client Ltd',
        'John Doe',
        'contact@democlient.com',
        '+255987654321',
        '123 Business Street',
        'Dar es Salaam',
        'Tanzania'
    ]);
    $client = $stmt->fetch();
    
    if ($client) {
        // Create sample contract
        $stmt = $pdo->prepare("
            INSERT INTO contracts (client_id, contract_number, contract_type, start_date, end_date, service_scope) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $client['id'],
            'CON-' . date('Y') . '-001',
            'AMC',
            date('Y-m-d'),
            date('Y-m-d', strtotime('+1 year')),
            'Full IT Support including network, servers, and endpoints'
        ]);
        
        // Create sample ticket
        $stmt = $pdo->prepare("
            INSERT INTO tickets (ticket_number, client_id, title, description, priority, status) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'TKT-' . date('Ymd') . '-001',
            $client['id'],
            'Network connectivity issue',
            'Users reporting slow internet and intermittent connectivity',
            'High',
            'Open'
        ]);
    }
}
?>