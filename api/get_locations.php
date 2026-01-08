<?php
require_once '../includes/auth.php';
requireLogin();

if (isset($_GET['client_id'])) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, location_name, city FROM client_locations WHERE client_id = ? ORDER BY location_name");
    $stmt->execute([$_GET['client_id']]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['locations' => $locations, 'client_id' => $_GET['client_id'], 'count' => count($locations)]);
    exit;
}
?>