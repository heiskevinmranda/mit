<?php
// PHP Migration Script to add missing fields to tickets table
require_once 'config/database.php';

$pdo = getDBConnection();

try {
    echo "Starting migration to add missing fields to tickets table...\n\n";
    
    // Fields to add
    $fields_to_add = [
        'requested_by' => 'VARCHAR(255)',
        'requested_by_email' => 'VARCHAR(255)',
        'csr_sn' => 'VARCHAR(255)',
        'pi_number' => 'VARCHAR(255)'
    ];
    
    $added_count = 0;
    
    foreach ($fields_to_add as $field => $type) {
        // Check if field already exists
        $check_stmt = $pdo->prepare("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'tickets' 
            AND column_name = ?
        ");
        $check_stmt->execute([$field]);
        
        if ($check_stmt->fetch()) {
            echo "Field '$field' already exists - skipping\n";
            continue;
        }
        
        // Add the field
        $alter_stmt = $pdo->prepare("ALTER TABLE tickets ADD COLUMN $field $type");
        $alter_stmt->execute();
        echo "✓ Added field '$field' ($type)\n";
        $added_count++;
        
        // Add comment (PostgreSQL)
        try {
            $comments = [
                'requested_by' => 'Name of person requesting the ticket',
                'requested_by_email' => 'Email of person requesting the ticket (optional)',
                'csr_sn' => 'Customer Service Report Serial Number (optional)',
                'pi_number' => 'Proforma Invoice Number (optional)'
            ];
            
            if (isset($comments[$field])) {
                $comment_stmt = $pdo->prepare("COMMENT ON COLUMN tickets.$field IS ?");
                $comment_stmt->execute([$comments[$field]]);
                echo "  Added comment for '$field'\n";
            }
        } catch (Exception $e) {
            // Comment might not be supported, continue anyway
            echo "  Note: Could not add comment for '$field' (may not be supported)\n";
        }
    }
    
    echo "\n--- Migration Summary ---\n";
    echo "Fields added: $added_count\n";
    echo "Total fields checked: " . count($fields_to_add) . "\n";
    
    if ($added_count > 0) {
        echo "\n--- Verification ---\n";
        // Verify the fields were added
        $verify_stmt = $pdo->prepare("
            SELECT column_name, data_type, character_maximum_length, is_nullable 
            FROM information_schema.columns 
            WHERE table_name = 'tickets' 
            AND column_name IN ('" . implode("','", array_keys($fields_to_add)) . "')
            ORDER BY ordinal_position
        ");
        $verify_stmt->execute();
        $results = $verify_stmt->fetchAll();
        
        echo "Added fields verification:\n";
        foreach ($results as $row) {
            $length = $row['character_maximum_length'] ? "({$row['character_maximum_length']})" : "";
            echo sprintf("  %-20s %-15s %s\n", 
                $row['column_name'], 
                $row['data_type'] . $length,
                $row['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL'
            );
        }
    }
    
    echo "\nMigration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
    echo "You may need to run the SQL script manually with higher privileges.\n";
}
?>