<?php
// PHP Script to Apply Missing Field Fixes
require_once 'config/database.php';

$pdo = getDBConnection();

echo "=== APPLYING MISSING FIELD FIXES ===\n\n";

$fixes_applied = 0;
$errors = [];

try {
    // Fix 1: Add 'notes' field to clients table
    echo "1. Checking clients table 'notes' field...\n";
    
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'clients' 
        AND column_name = 'notes'
    ");
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        $alter_stmt = $pdo->prepare("ALTER TABLE clients ADD COLUMN notes TEXT");
        $alter_stmt->execute();
        echo "   ✓ Added 'notes' field to clients table\n";
        $fixes_applied++;
    } else {
        echo "   ✓ 'notes' field already exists in clients table\n";
    }
    
    // Fix 2: Add missing fields to users table
    $user_fields = [
        'first_name' => 'CHARACTER VARYING(100)',
        'last_name' => 'CHARACTER VARYING(100)',
        'phone' => 'CHARACTER VARYING(50)',
        'username' => 'CHARACTER VARYING(100)'
    ];
    
    echo "\n2. Checking users table fields...\n";
    
    foreach ($user_fields as $field => $type) {
        $stmt = $pdo->prepare("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'users' 
            AND column_name = ?
        ");
        $stmt->execute([$field]);
        
        if (!$stmt->fetch()) {
            try {
                $alter_stmt = $pdo->prepare("ALTER TABLE users ADD COLUMN $field $type");
                $alter_stmt->execute();
                echo "   ✓ Added '$field' field to users table\n";
                $fixes_applied++;
            } catch (PDOException $e) {
                echo "   ⚠️  Failed to add '$field': " . $e->getMessage() . "\n";
                $errors[] = "Failed to add '$field' to users table: " . $e->getMessage();
            }
        } else {
            echo "   ✓ '$field' field already exists in users table\n";
        }
    }
    
    // Verify tickets fields (our main concern)
    echo "\n3. Verifying tickets table fields...\n";
    $ticket_fields = ['requested_by', 'requested_by_email', 'csr_sn', 'pi_number'];
    
    foreach ($ticket_fields as $field) {
        $stmt = $pdo->prepare("
            SELECT column_name, data_type, is_nullable 
            FROM information_schema.columns 
            WHERE table_name = 'tickets' 
            AND column_name = ?
        ");
        $stmt->execute([$field]);
        $result = $stmt->fetch();
        
        if ($result) {
            echo "   ✓ '$field' exists: {$result['data_type']} (nullable: {$result['is_nullable']})\n";
        } else {
            echo "   ❌ '$field' is MISSING from tickets table!\n";
            $errors[] = "'$field' missing from tickets table";
        }
    }
    
    // Show current structure of key tables
    echo "\n=== CURRENT TABLE STRUCTURES ===\n";
    
    $tables_to_check = ['tickets', 'clients', 'users', 'staff_profiles', 'contracts'];
    foreach ($tables_to_check as $table) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as column_count
            FROM information_schema.columns 
            WHERE table_name = ?
        ");
        $stmt->execute([$table]);
        $count = $stmt->fetchColumn();
        echo "$table: $count columns\n";
    }
    
    // Final summary
    echo "\n=== FIX SUMMARY ===\n";
    echo "Fixes applied: $fixes_applied\n";
    echo "Errors encountered: " . count($errors) . "\n";
    
    if (!empty($errors)) {
        echo "\nErrors:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
    }
    
    if ($fixes_applied > 0) {
        echo "\n🎉 Successfully applied $fixes_applied database fixes!\n";
    } else {
        echo "\n✅ No fixes were needed - all fields are properly configured.\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    echo "You may need to run the SQL script manually with higher privileges.\n";
}
?>