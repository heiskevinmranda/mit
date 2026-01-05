<?php
require_once '../includes/auth.php';
requireLogin();

if (isset($_GET['prefix'])) {
    $pdo = getDBConnection();
    $prefix = $_GET['prefix'];
    
    // Find the highest number with this prefix
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(asset_tag FROM ?) AS INTEGER)) as max_num 
                           FROM assets WHERE asset_tag LIKE ?");
    $pattern = strlen($prefix) + 1;
    $like_pattern = $prefix . '%';
    $stmt->execute([$pattern, $like_pattern]);
    $result = $stmt->fetch();
    
    $next_number = $result['max_num'] ? $result['max_num'] + 1 : 1;
    
    header('Content-Type: application/json');
    echo json_encode(['next_number' => $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT)]);
    exit;
}
?>