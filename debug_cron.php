<?php
/**
 * Debug the cron script
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/email.php';
require_once __DIR__ . '/includes/email_helper.php';

$config = [
    'stale_days' => NOTIFICATION_STALE_DAYS ?? 2,
    'closed_days' => NOTIFICATION_CLOSED_DAYS ?? 5,
    'base_url' => BASE_URL ?? 'http://localhost/mit'
];

$pdo = getDBConnection();

echo "Testing getStaleUpdateTickets query...\n";

try {
    $days = 2;
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.ticket_number,
            t.title,
            t.status,
            t.updated_at,
            t.created_by,
            u.email as owner_email,
            u.id as owner_user_id
        FROM tickets t
        JOIN users u ON t.created_by = u.id
        WHERE t.updated_at < NOW() - INTERVAL '$days days'
        AND t.status NOT IN ('Closed', 'Resolved', 'Completed')
        LIMIT 5
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($results) . " tickets\n";
    print_r($results);
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
