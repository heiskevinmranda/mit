<?php
require_once 'config/database.php';

$pdo = getDBConnection();
$stmt = $pdo->prepare('SELECT id, company_name, logo_path FROM clients WHERE company_name = ?');
$stmt->execute(['Fanix']);
$client = $stmt->fetch();

if ($client) {
    echo 'Client ID: ' . $client['id'] . "\n";
    echo 'Company Name: ' . $client['company_name'] . "\n";
    echo 'Logo Path: ' . ($client['logo_path'] ?? 'NULL') . "\n";
} else {
    echo 'Client not found.' . "\n";
    
    // Try with partial match
    $stmt = $pdo->prepare("SELECT id, company_name, logo_path FROM clients WHERE company_name ILIKE ?");
    $stmt->execute(['%Fanix%']);
    $clients = $stmt->fetchAll();
    
    if ($clients) {
        echo "Found similar clients:\n";
        foreach ($clients as $c) {
            echo "- ID: " . $c['id'] . ", Name: " . $c['company_name'] . ", Logo: " . ($c['logo_path'] ?? 'NULL') . "\n";
        }
    } else {
        echo "No clients found with similar names.\n";
    }
}
?>