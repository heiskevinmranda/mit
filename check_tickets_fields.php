<?php
require_once 'config/database.php';

$pdo = getDBConnection();

// Check all the required fields in tickets table
$required_fields = [
    'requested_by' => 'VARCHAR(255)',
    'requested_by_email' => 'VARCHAR(255)', 
    'csr_sn' => 'VARCHAR(255)',
    'pi_number' => 'VARCHAR(255)'
];

echo "Checking tickets table structure...\n\n";

foreach ($required_fields as $field => $expected_type) {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, character_maximum_length, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'tickets' 
        AND column_name = ?
    ");
    $stmt->execute([$field]);
    $result = $stmt->fetch();
    
    if ($result) {
        echo "✓ Field '$field' EXISTS\n";
        echo "  Type: {$result['data_type']}";
        if ($result['character_maximum_length']) {
            echo "({$result['character_maximum_length']})";
        }
        echo "\n  Nullable: {$result['is_nullable']}\n\n";
    } else {
        echo "✗ Field '$field' MISSING\n\n";
    }
}

// Show all current columns in tickets table
echo "\n--- Current tickets table columns ---\n";
$stmt = $pdo->prepare("
    SELECT column_name, data_type, character_maximum_length, is_nullable 
    FROM information_schema.columns 
    WHERE table_name = 'tickets' 
    ORDER BY ordinal_position
");
$stmt->execute();
$columns = $stmt->fetchAll();

foreach ($columns as $col) {
    $length = $col['character_maximum_length'] ? "({$col['character_maximum_length']})" : "";
    echo sprintf("%-25s %-15s %s %s\n", 
        $col['column_name'], 
        $col['data_type'] . $length,
        $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL',
        in_array($col['column_name'], array_keys($required_fields)) ? '<-- REQUIRED' : ''
    );
}
?>