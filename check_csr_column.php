<?php
require_once 'config/database.php';

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT column_name, data_type, is_nullable 
    FROM information_schema.columns 
    WHERE table_name = 'tickets' 
    AND column_name = 'csr_sn'
");
$stmt->execute();
$result = $stmt->fetchAll();

if (count($result) > 0) {
    echo "Column csr_sn exists:\n";
    print_r($result);
} else {
    echo "Column csr_sn does not exist in tickets table.\n";
}
?>