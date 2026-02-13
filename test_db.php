<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    echo "<h1>Database Connection Successful</h1>";
    
    // Check if it's Postgres or MySQL
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "<p>Driver: " . htmlspecialchars($driver) . "</p>";
    
    if ($driver == 'pgsql') {
        $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    } else {
        $stmt = $pdo->query("SHOW TABLES");
    }
    
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('certificates', $tables)) {
        echo "<h1>Certificates Valid</h1>";
        // Describe table?
    } else {
        echo "<h1>Certificates Missing</h1>";
        echo "<h2>Existing Tables:</h2><ul>";
        foreach ($tables as $table) {
            echo "<li>" . htmlspecialchars($table) . "</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<h1>Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
