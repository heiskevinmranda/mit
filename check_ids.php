<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();
$s = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'tickets' AND column_name IN ('id', 'ticket_number')");
print_r($s->fetchAll(PDO::FETCH_ASSOC));

echo "\n\nticket_notifications:\n";
$s2 = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'ticket_notifications'");
print_r($s2->fetchAll(PDO::FETCH_ASSOC));
