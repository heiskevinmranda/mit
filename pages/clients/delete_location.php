<?php
// pages/clients/delete_location.php
require_once '../../includes/auth.php';
require_once '../../includes/client_functions.php';
require_once '../../includes/routes.php';
requireLogin();

$pdo = getDBConnection();
$location_id = $_GET['id'] ?? 0;
$client_id = $_GET['client_id'] ?? 0;

if (!$location_id || !$client_id) {
    $_SESSION['error'] = "Location ID and Client ID are required.";
    header("Location: " . route('clients.index'));
    exit;
}

// Get location info
$stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ? AND client_id = ?");
$stmt->execute([$location_id, $client_id]);
$location = $stmt->fetch();

if (!$location) {
    $_SESSION['error'] = "Location not found.";
    header("Location: " . route('clients.index'));
    exit;
}

// Get client info
$client_stmt = $pdo->prepare("SELECT id, company_name FROM clients WHERE id = ?");
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch();

if (!$client) {
    $_SESSION['error'] = "Client not found.";
    header("Location: " . route('clients.index'));
    exit;
}

// Check if user has permission to delete
if (!hasClientPermission('edit')) {
    $_SESSION['error'] = "You don't have permission to delete locations.";
    header("Location: " . route('clients.view', ['id' => $client_id]));
    exit;
}

// Perform the deletion
try {
    $stmt = $pdo->prepare("DELETE FROM client_locations WHERE id = ? AND client_id = ?");
    $stmt->execute([$location_id, $client_id]);
    
    $_SESSION['success'] = "Location deleted successfully!";
    
    // Redirect back to client view page
    header("Location: " . route('clients.view', ['id' => $client_id]));
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error deleting location: " . $e->getMessage();
    header("Location: " . route('clients.view', ['id' => $client_id]));
    exit;
}
?>