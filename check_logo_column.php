<?php
require_once 'config/database.php';

$pdo = getDBConnection();
$stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'logo_path'");
$exists = $stmt->rowCount() > 0;

if ($exists) {
    echo "Column 'logo_path' exists in the clients table.\n";
} else {
    echo "Column 'logo_path' does not exist in the clients table.\n";
    echo "Attempting to add the column...\n";
    
    try {
        // Note: PostgreSQL doesn't support ALTER TABLE ... ADD COLUMN IF NOT EXISTS
        // So we'll try to add it and ignore errors if it already exists
        $pdo->exec("ALTER TABLE clients ADD COLUMN logo_path VARCHAR(500);");
        echo "Column 'logo_path' added successfully.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "Column 'logo_path' already exists.\n";
        } else {
            echo "Error adding column: " . $e->getMessage() . "\n";
        }
    }
}
?>