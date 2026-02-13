<?php
/**
 * Database Structure Checker
 * Run this to see the actual structure of the staff_profiles table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

echo "<h1>Database Structure Analysis</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
    th { background: #667eea; color: white; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
    .error { color: #dc3545; }
    .success { color: #28a745; }
</style>";

try {
    $pdo = getDBConnection();
    echo "<div class='section'>";
    echo "<h2 class='success'>✓ Database Connection Successful</h2>";
    echo "</div>";
    
    // Check if staff_profiles table exists
    echo "<div class='section'>";
    echo "<h2>Staff Profiles Table Structure</h2>";
    
    $stmt = $pdo->query("
        SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'staff_profiles'
        ORDER BY ordinal_position
    ");
    
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        echo "<table>";
        echo "<tr><th>Column Name</th><th>Data Type</th><th>Max Length</th><th>Nullable</th><th>Default</th></tr>";
        
        $has_profile_picture = false;
        foreach ($columns as $col) {
            if ($col['column_name'] === 'profile_picture') {
                $has_profile_picture = true;
                echo "<tr style='background: #d4edda;'>";
            } else {
                echo "<tr>";
            }
            echo "<td><strong>" . htmlspecialchars($col['column_name']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($col['data_type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['character_maximum_length'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($col['is_nullable']) . "</td>";
            echo "<td>" . htmlspecialchars($col['column_default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if ($has_profile_picture) {
            echo "<p class='success'>✓ <strong>profile_picture</strong> column EXISTS in staff_profiles table</p>";
        } else {
            echo "<p class='error'>✗ <strong>profile_picture</strong> column DOES NOT EXIST in staff_profiles table</p>";
            echo "<p>You need to add this column. Here's the SQL:</p>";
            echo "<pre>ALTER TABLE staff_profiles ADD COLUMN profile_picture VARCHAR(255);</pre>";
        }
    } else {
        echo "<p class='error'>✗ staff_profiles table not found</p>";
    }
    echo "</div>";
    
    // Check if profile table exists
    echo "<div class='section'>";
    echo "<h2>Profile Table (if exists)</h2>";
    
    $stmt = $pdo->query("
        SELECT column_name, data_type, character_maximum_length, is_nullable
        FROM information_schema.columns
        WHERE table_name = 'profile'
        ORDER BY ordinal_position
    ");
    
    $profile_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($profile_columns) > 0) {
        echo "<p class='success'>✓ 'profile' table exists</p>";
        echo "<table>";
        echo "<tr><th>Column Name</th><th>Data Type</th><th>Max Length</th><th>Nullable</th></tr>";
        foreach ($profile_columns as $col) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($col['column_name']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($col['data_type']) . "</td>";
            echo "<td>" . htmlspecialchars($col['character_maximum_length'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($col['is_nullable']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No 'profile' table found in database</p>";
    }
    echo "</div>";
    
    // List all tables
    echo "<div class='section'>";
    echo "<h2>All Tables in Database</h2>";
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
    
    // Sample data from staff_profiles
    echo "<div class='section'>";
    echo "<h2>Sample Staff Profiles Data (first 3 rows)</h2>";
    $stmt = $pdo->query("SELECT * FROM staff_profiles LIMIT 3");
    $sample_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($sample_data) > 0) {
        echo "<table>";
        echo "<tr>";
        foreach (array_keys($sample_data[0]) as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
        foreach ($sample_data as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No data in staff_profiles table</p>";
    }
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='section'>";
    echo "<h2 class='error'>✗ Error</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "</div>";
}
?>
