<?php
// api/get_locations.php
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([]);
    exit;
}

if (!isset($_GET['client_id']) || empty($_GET['client_id'])) {
    echo json_encode([]);
    exit;
}

$client_id = intval($_GET['client_id']);
$pdo = getDBConnection();

try {
    $stmt = $pdo->prepare("SELECT id, location_name FROM client_locations WHERE client_id = ? ORDER BY location_name");
    $stmt->execute([$client_id]);
    $locations = $stmt->fetchAll();
    
    echo json_encode($locations);
} catch (Exception $e) {
    echo json_encode([]);
}
?>