<?php
// setup-client-users.php - Updated to use your config file
session_start();

// Check if your config file exists
$config_paths = [
    'config/database.php',
    'includes/config.php',
    'includes/database.php'
];

$config_file = null;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        $config_file = $path;
        break;
    }
}

if (!$config_file) {
    die("<h2>Configuration File Missing</h2>
         <p>Could not find any configuration file.</p>
         <p>Looking for: " . implode(', ', $config_paths) . "</p>");
}

require_once $config_file;

echo "<!DOCTYPE html>
<html>
<head>
    <title>Setup Client Users</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e8; border: 1px solid #c3e6cb; }
        .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; }
        .info { color: #856404; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; }
        pre { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>";

echo "<h2>Setting up Client User Accounts</h2>";
echo "<p>Using config file: <strong>{$config_file}</strong></p>";

try {
    // Get database connection using your function
    $pdo = getDBConnection();
    
    // Test connection
    $pdo->query("SELECT 1");
    echo "<div class='success'>✓ Database connection successful</div>";
    
    // Check if uuid_generate_v4 function exists
    try {
        $pdo->query("SELECT uuid_generate_v4()");
        echo "<div class='success'>✓ UUID function available</div>";
    } catch (Exception $e) {
        echo "<div class='error'>✗ UUID function not available. Creating it...</div>";
        
        // Create uuid function if needed
        $pdo->exec("
            CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";
        ");
        echo "<div class='success'>✓ UUID extension created</div>";
    }
    
    // Check if client_users table exists, create if not
    $table_check = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = 'client_users'
        )
    ")->fetchColumn();
    
    if (!$table_check) {
        echo "<div class='info'>Creating client_users table...</div>";
        
        $sql = "
            CREATE TABLE client_users (
                id UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
                client_id UUID NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
                user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role VARCHAR(50) DEFAULT 'primary',
                is_active BOOLEAN DEFAULT true,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id)
            );
        ";
        
        $pdo->exec($sql);
        
        // Create indexes
        $pdo->exec("CREATE INDEX idx_client_users_user_id ON client_users(user_id)");
        $pdo->exec("CREATE INDEX idx_client_users_client_id ON client_users(client_id)");
        
        // Create trigger function
        $pdo->exec("
            CREATE OR REPLACE FUNCTION update_client_users_updated_at()
            RETURNS TRIGGER AS \$\$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");
        
        // Create trigger
        $pdo->exec("
            CREATE TRIGGER update_client_users_updated_at 
                BEFORE UPDATE ON client_users 
                FOR EACH ROW EXECUTE FUNCTION update_client_users_updated_at();
        ");
        
        echo "<div class='success'>✓ client_users table created successfully</div>";
    } else {
        echo "<div class='info'>✓ client_users table already exists</div>";
    }
    
    // Default password for all clients
    $default_password = password_hash('qwert12345', PASSWORD_DEFAULT);
    
    // Get all clients with email addresses
    $stmt = $pdo->query("
        SELECT id, company_name, email, contact_person, phone
        FROM clients 
        WHERE email IS NOT NULL AND email != '' AND email LIKE '%@%'
        ORDER BY company_name
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($clients)) {
        echo "<div class='error'>No clients found with email addresses in the database.</div>";
        
        // Show what clients do exist
        $stmt = $pdo->query("SELECT id, company_name, email FROM clients LIMIT 5");
        $sample = $stmt->fetchAll();
        
        if (!empty($sample)) {
            echo "<h3>Clients in database (first 5):</h3>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Company</th><th>Email</th></tr>";
            foreach ($sample as $row) {
                echo "<tr>";
                echo "<td>" . substr($row['id'], 0, 8) . "...</td>";
                echo "<td>" . htmlspecialchars($row['company_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['email'] ?? 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        exit;
    }
    
    $total_clients = count($clients);
    $created_count = 0;
    $linked_count = 0;
    $skipped_count = 0;
    $errors = [];
    
    echo "<div class='info'>Found {$total_clients} clients with email addresses</div>";
    echo "<table>";
    echo "<tr><th>Company</th><th>Email</th><th>Status</th><th>Details</th></tr>";
    
    foreach ($clients as $client) {
        $client_id = $client['id'];
        $client_email = strtolower(trim($client['email']));
        $company_name = htmlspecialchars($client['company_name']);
        
        echo "<tr>";
        echo "<td><strong>{$company_name}</strong></td>";
        echo "<td>{$client_email}</td>";
        
        try {
            // Check if user already exists with this email
            $stmt = $pdo->prepare("SELECT id, user_type FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$client_email]);
            $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_user) {
                // User exists
                $user_id = $existing_user['id'];
                
                // Update user_type to client if needed
                if ($existing_user['user_type'] !== 'client') {
                    $stmt = $pdo->prepare("UPDATE users SET user_type = 'client' WHERE id = ?");
                    $stmt->execute([$user_id]);
                }
                
                // Check if link exists
                $stmt = $pdo->prepare("SELECT id FROM client_users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $existing_link = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing_link) {
                    // Create link
                    $stmt = $pdo->prepare("
                        INSERT INTO client_users (client_id, user_id, role) 
                        VALUES (?, ?, 'primary')
                    ");
                    $stmt->execute([$client_id, $user_id]);
                    $linked_count++;
                    echo "<td style='color: green;'>✓ Linked</td>";
                    echo "<td>Existing user linked to client</td>";
                } else {
                    $skipped_count++;
                    echo "<td style='color: orange;'>✓ Already linked</td>";
                    echo "<td>Already linked to client</td>";
                }
            } else {
                // Create new user
                $stmt = $pdo->query("SELECT uuid_generate_v4()");
                $user_id = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (id, email, password, user_type, is_active, created_at) 
                    VALUES (?, ?, ?, 'client', true, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$user_id, $client_email, $default_password]);
                
                // Create link
                $stmt = $pdo->prepare("
                    INSERT INTO client_users (client_id, user_id, role) 
                    VALUES (?, ?, 'primary')
                ");
                $stmt->execute([$client_id, $user_id]);
                
                $created_count++;
                echo "<td style='color: green;'>✓ Created</td>";
                echo "<td>New user account created</td>";
            }
        } catch (Exception $e) {
            $errors[] = "{$company_name}: " . $e->getMessage();
            echo "<td style='color: red;'>✗ Error</td>";
            echo "<td style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</td>";
        }
        
        echo "</tr>";
    }
    
    echo "</table>";
    
    // Summary
    echo "<h3>Setup Summary</h3>";
    echo "<table>";
    echo "<tr><th>Total Clients Processed:</th><td>{$total_clients}</td></tr>";
    echo "<tr><th>New User Accounts Created:</th><td>{$created_count}</td></tr>";
    echo "<tr><th>Existing Users Linked:</th><td>{$linked_count}</td></tr>";
    echo "<tr><th>Already Linked (Skipped):</th><td>{$skipped_count}</td></tr>";
    echo "</table>";
    
    // Default login info
    echo "<div style='margin-top: 20px; padding: 15px; background: #e7f3ff; border: 1px solid #b3d7ff;'>";
    echo "<h4>Default Login Information:</h4>";
    echo "<p><strong>Email:</strong> Use client's email address from database</p>";
    echo "<p><strong>Password:</strong> qwert12345 (all clients)</p>";
    echo "<p><strong>Login URL:</strong> <a href='client-login.php' target='_blank'>client-login.php</a></p>";
    echo "<p><strong>Note:</strong> Clients should change their password after first login.</p>";
    echo "</div>";
    
    if (!empty($errors)) {
        echo "<h3>Errors Encountered:</h3>";
        foreach ($errors as $error) {
            echo "<div class='error'>" . htmlspecialchars($error) . "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>Setup Failed:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    
    // Provide troubleshooting info
    echo "<h4>Database Information:</h4>";
    echo "<pre>";
    echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'Not defined') . "\n";
    echo "DB_PORT: " . (defined('DB_PORT') ? DB_PORT : 'Not defined') . "\n";
    echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'Not defined') . "\n";
    echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'Not defined') . "\n";
    echo "Config file: {$config_file}\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "</pre>";
    
    echo "</div>";
}

echo "</body></html>";
?>