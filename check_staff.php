<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();

echo "staff_profiles columns:\n";
$s = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'staff_profiles' AND column_name LIKE '%email%'");
print_r($s->fetchAll(PDO::FETCH_ASSOC));

echo "\n\nusers columns:\n";
$s2 = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'");
print_r($s2->fetchAll(PDO::FETCH_ASSOC));
