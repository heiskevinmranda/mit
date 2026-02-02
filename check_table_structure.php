<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Get detailed column information
    $stmt = $pdo->query("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'staff_profiles' ORDER BY ordinal_position");
    
    echo "Detailed staff_profiles table structure:\n";
    while ($row = $stmt->fetch()) {
        echo $row['column_name'] . " (" . $row['data_type'] . ", nullable: " . $row['is_nullable'] . ", default: " . $row['column_default'] . ")\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}