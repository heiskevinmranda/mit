<?php
// SIMPLE WORKING VERSION - pages/tickets/simple.php

// Start session
session_start();

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Simple database connection
try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=MSP_Application", "MSPAppUser", "2q+w7wQMH8xd");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get tickets
try {
    $stmt = $pdo->query("SELECT * FROM tickets ORDER BY created_at DESC LIMIT 20");
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {
    $tickets = [];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Tickets - Simple</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h1>Tickets</h1>
    <p>Logged in as: <?php echo $_SESSION['email'] ?? 'Unknown'; ?></p>
    
    <table>
        <tr>
            <th>Ticket #</th>
            <th>Title</th>
            <th>Status</th>
            <th>Created</th>
        </tr>
        <?php foreach ($tickets as $ticket): ?>
        <tr>
            <td><?php echo htmlspecialchars($ticket['ticket_number']); ?></td>
            <td><?php echo htmlspecialchars($ticket['title']); ?></td>
            <td><?php echo htmlspecialchars($ticket['status']); ?></td>
            <td><?php echo date('Y-m-d', strtotime($ticket['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <p><a href="../../dashboard.php">Back to Dashboard</a></p>
</body>
</html>