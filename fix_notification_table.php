<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

echo "Altering ticket_notifications table...\n";

try {
    $pdo->exec("ALTER TABLE ticket_notifications ALTER COLUMN ticket_id TYPE UUID USING ticket_id::uuid;");
    echo "ticket_id column changed to UUID\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Also fix sent_to_user_id and sent_to_manager_id to be UUID
try {
    $pdo->exec("ALTER TABLE ticket_notifications ALTER COLUMN sent_to_user_id TYPE UUID USING NULL;");
} catch (Exception $e) {
    echo "sent_to_user_id: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE ticket_notifications ALTER COLUMN sent_to_manager_id TYPE UUID USING NULL;");
} catch (Exception $e) {
    echo "sent_to_manager_id: " . $e->getMessage() . "\n";
}

echo "\nVerifying structure:\n";
$s2 = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'ticket_notifications'");
print_r($s2->fetchAll(PDO::FETCH_ASSOC));
