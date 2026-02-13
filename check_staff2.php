<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

echo "staff_profiles key columns:\n";
$s = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'staff_profiles' AND column_name IN ('id', 'user_id', 'full_name')");
print_r($s->fetchAll(PDO::FETCH_ASSOC));
