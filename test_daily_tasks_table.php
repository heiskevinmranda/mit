<?php
require_once 'config/database.php';
$pdo = getDBConnection();
$stmt = $pdo->query('SELECT COUNT(*) as count FROM daily_tasks;');
$result = $stmt->fetch();
echo 'Daily tasks table exists and has ' . $result['count'] . ' records';
?>