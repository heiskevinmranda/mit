<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $result = $pdo->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'users'");
    
    echo "Users table structure:\n";
    while ($row = $result->fetch()) {
        echo '- ' . $row['column_name'] . ': ' . $row['data_type'] . ' (' . ($row['is_nullable'] === 'YES' ? 'nullable' : 'not nullable') . ') Default: ' . $row['column_default'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>