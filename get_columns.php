<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Get all columns from the staff_profiles table
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'staff_profiles' ORDER BY ordinal_position");
    
    $columns = [];
    while ($row = $stmt->fetch()) {
        $columns[] = $row['column_name'];
    }
    
    echo "Total columns in staff_profiles: " . count($columns) . "\n";
    echo "Columns:\n";
    foreach ($columns as $i => $col) {
        echo ($i + 1) . ". " . $col . "\n";
    }
    
    // Check for duplicates
    $duplicates = array();
    foreach (array_count_values($columns) as $val => $c) {
        if ($c > 1) $duplicates[] = $val;
    }
    
    if (!empty($duplicates)) {
        echo "\nDuplicates found: " . implode(', ', $duplicates) . "\n";
    } else {
        echo "\nNo duplicates found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}