<?php
// Migration script to add csr_sn field to tickets table

require_once 'config/database.php';

$pdo = getDBConnection();

try {
    // Check if column already exists
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'tickets' 
        AND column_name = 'csr_sn'
    ");
    $stmt->execute();
    $columnExists = $stmt->fetch();

    if ($columnExists) {
        echo "Column 'csr_sn' already exists in tickets table.\n";
    } else {
        // Add the csr_sn column to the tickets table
        $alterStmt = $pdo->prepare("ALTER TABLE tickets ADD COLUMN csr_sn VARCHAR(255)");
        $alterStmt->execute();
        echo "Successfully added 'csr_sn' column to tickets table.\n";
        
        // Add comment for documentation
        $commentStmt = $pdo->prepare("COMMENT ON COLUMN tickets.csr_sn IS 'Customer Service Report Serial Number (optional field)'");
        $commentStmt->execute();
        echo "Added comment for 'csr_sn' column.\n";
    }

    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>