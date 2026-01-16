<?php
// test-db.php - Quick database test
echo "<h2>Database Connection Test</h2>";

// Find and use your config
$config_file = 'config/database.php';
if (file_exists($config_file)) {
    echo "✓ Found config file: {$config_file}<br>";
    require_once $config_file;
} else {
    die("✗ Config file not found: {$config_file}");
}

// Test connection
try {
    $pdo = getDBConnection();
    echo "✓ Database connection successful<br>";
    
    // Test some queries
    echo "<h3>Database Info:</h3>";
    
    // Check PostgreSQL version
    $version = $pdo->query("SELECT version()")->fetchColumn();
    echo "PostgreSQL: " . substr($version, 0, 50) . "...<br>";
    
    // Check tables
    $tables = ['users', 'clients', 'client_users'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)");
        $stmt->execute([$table]);
        $exists = $stmt->fetchColumn();
        echo ($exists ? "✓" : "✗") . " Table '{$table}' exists<br>";
    }
    
    // Count records
    echo "<h3>Record Counts:</h3>";
    try {
        $result = $pdo->query("
            SELECT 'users' as table, COUNT(*) as count FROM users
            UNION ALL SELECT 'clients', COUNT(*) FROM clients
            UNION ALL SELECT 'client_users', COUNT(*) FROM client_users
        ")->fetchAll();
        
        foreach ($result as $row) {
            echo "{$row['table']}: {$row['count']} records<br>";
        }
    } catch (Exception $e) {
        echo "Error counting records: " . $e->getMessage() . "<br>";
    }
    
    // Show sample data
    echo "<h3>Sample Clients:</h3>";
    $clients = $pdo->query("SELECT company_name, email FROM clients WHERE email IS NOT NULL LIMIT 5")->fetchAll();
    
    if (empty($clients)) {
        echo "No clients found or clients have no emails.<br>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Company</th><th>Email</th></tr>";
        foreach ($clients as $client) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($client['company_name']) . "</td>";
            echo "<td>" . htmlspecialchars($client['email']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    
    // Show connection details (without password)
    echo "<h3>Connection Details:</h3>";
    echo "Host: " . DB_HOST . "<br>";
    echo "Port: " . DB_PORT . "<br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "User: " . DB_USER . "<br>";
    echo "Password: " . (DB_PASS ? "***set***" : "not set") . "<br>";
}
?>