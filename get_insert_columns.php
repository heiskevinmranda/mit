<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Get all columns from the staff_profiles table, excluding 'id'
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'staff_profiles' AND column_name != 'id' ORDER BY ordinal_position");
    
    $columns = [];
    while ($row = $stmt->fetch()) {
        $columns[] = $row['column_name'];
    }
    
    echo "Columns to insert (excluding id): " . count($columns) . "\n";
    echo "Columns:\n";
    foreach ($columns as $i => $col) {
        echo ($i + 1) . ". " . $col . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}