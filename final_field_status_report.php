<?php
// FINAL COMPREHENSIVE FIELD STATUS REPORT
require_once 'config/database.php';

$pdo = getDBConnection();

echo "=== FINAL SYSTEM FIELD STATUS REPORT ===\n";
echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";

// Main focus: Tickets table fields
echo "=== TICKETS TABLE FIELD STATUS ===\n";
$required_ticket_fields = [
    'requested_by' => 'VARCHAR(255)',
    'requested_by_email' => 'VARCHAR(255)', 
    'csr_sn' => 'VARCHAR(255)',
    'pi_number' => 'VARCHAR(255)'
];

$missing_ticket_fields = [];

foreach ($required_ticket_fields as $field => $type) {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, character_maximum_length, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'tickets' 
        AND column_name = ?
    ");
    $stmt->execute([$field]);
    $result = $stmt->fetch();
    
    if ($result) {
        echo "✅ $field: {$result['data_type']}(" . ($result['character_maximum_length'] ?: 'N/A') . ") - " . 
             ($result['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    } else {
        echo "❌ $field: MISSING\n";
        $missing_ticket_fields[] = ['field' => $field, 'type' => $type];
    }
}

echo "\n=== OTHER TABLE FIELD STATUS ===\n";

// Check clients table
echo "\nClients table:\n";
$stmt = $pdo->prepare("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 'clients' 
    AND column_name = 'notes'
");
$stmt->execute();
if ($stmt->fetch()) {
    echo "✅ notes field: Present\n";
} else {
    echo "❌ notes field: MISSING\n";
}

// Check users table key fields
echo "\nUsers table key fields:\n";
$user_key_fields = ['first_name', 'last_name', 'phone', 'username'];
foreach ($user_key_fields as $field) {
    $stmt = $pdo->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'users' 
        AND column_name = ?
    ");
    $stmt->execute([$field]);
    if ($stmt->fetch()) {
        echo "✅ $field: Present\n";
    } else {
        echo "❌ $field: MISSING\n";
    }
}

// Generate final SQL script for all missing fields
echo "\n=== SQL SCRIPT TO FIX ALL MISSING FIELDS ===\n";
echo "-- RUN THIS AS DATABASE ADMINISTRATOR\n\n";

// Tickets fields (if any are missing)
if (!empty($missing_ticket_fields)) {
    echo "-- Ticket table fixes:\n";
    foreach ($missing_ticket_fields as $field_info) {
        echo "ALTER TABLE tickets ADD COLUMN {$field_info['field']} {$field_info['type']};\n";
    }
    echo "\n";
}

// Clients table fix
$stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'notes'");
$stmt->execute();
if (!$stmt->fetch()) {
    echo "-- Clients table fix:\n";
    echo "ALTER TABLE clients ADD COLUMN notes TEXT;\n\n";
}

// Users table fixes
echo "-- Users table fixes:\n";
$user_fields_needed = [
    'first_name' => 'CHARACTER VARYING(100)',
    'last_name' => 'CHARACTER VARYING(100)',
    'phone' => 'CHARACTER VARYING(50)',
    'username' => 'CHARACTER VARYING(100)'
];

foreach ($user_fields_needed as $field => $type) {
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name = ?");
    $stmt->execute([$field]);
    if (!$stmt->fetch()) {
        echo "ALTER TABLE users ADD COLUMN $field $type;\n";
    }
}

echo "\n-- Verification queries:\n";
echo "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'tickets' AND column_name IN ('requested_by', 'requested_by_email', 'csr_sn', 'pi_number');\n";
echo "SELECT column_name FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'notes';\n";
echo "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name IN ('first_name', 'last_name', 'phone', 'username');\n";

echo "\n=== SUMMARY ===\n";
echo "Tickets fields missing: " . count($missing_ticket_fields) . "\n";

$stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'clients' AND column_name = 'notes'");
$stmt->execute();
$clients_notes_missing = !$stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'users' AND column_name IN ('first_name', 'last_name', 'phone', 'username')");
$stmt->execute();
$users_fields_present = $stmt->fetchColumn();

echo "Clients notes field missing: " . ($clients_notes_missing ? 'YES' : 'NO') . "\n";
echo "Users key fields missing: " . (4 - $users_fields_present) . "\n";

$total_missing = count($missing_ticket_fields) + ($clients_notes_missing ? 1 : 0) + (4 - $users_fields_present);
echo "\nTotal missing fields requiring attention: $total_missing\n";

if ($total_missing == 0) {
    echo "\n🎉 ALL REQUIRED FIELDS ARE PRESENT!\n";
    echo "The system is properly configured with all necessary database fields.\n";
} else {
    echo "\n⚠️  ACTION REQUIRED: $total_missing fields need to be added to the database.\n";
    echo "Run the SQL script above with database administrator privileges.\n";
}

?>