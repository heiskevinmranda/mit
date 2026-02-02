<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'staff_profiles' ORDER BY ordinal_position");
    
    echo "Columns in staff_profiles table:\n";
    while ($row = $stmt->fetch()) {
        echo "- " . $row['column_name'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}