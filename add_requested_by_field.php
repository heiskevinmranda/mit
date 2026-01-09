<?php
// Migration script to add requested_by field to tickets table

require_once 'config/database.php';

$pdo = getDBConnection();

try {
    // Check if column already exists
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'tickets' 
        AND column_name = 'requested_by'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetch();

    if ($columnExists) {
        echo "Column 'requested_by' already exists in tickets table.\n";
    } else {
        // Add the requested_by column to the tickets table
        $alterStmt = $pdo->prepare("ALTER TABLE tickets ADD COLUMN requested_by VARCHAR(255)");
        $alterStmt->execute();
        echo "Successfully added 'requested_by' column to tickets table.\n";
    }

    // Also add a column for requested_by_email if needed
    $stmt2 = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'tickets' 
        AND column_name = 'requested_by_email'
    ");
    $stmt2->execute();
    $emailColumnExists = $stmt2->fetch();

    if ($emailColumnExists) {
        echo "Column 'requested_by_email' already exists in tickets table.\n";
    } else {
        // Add the requested_by_email column to the tickets table
        $alterStmt2 = $pdo->prepare("ALTER TABLE tickets ADD COLUMN requested_by_email VARCHAR(255)");
        $alterStmt2->execute();
        echo "Successfully added 'requested_by_email' column to tickets table.\n";
    }

    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>