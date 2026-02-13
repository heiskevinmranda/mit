<?php
/**
 * Check and create ticket_notifications table if needed
 */

require_once __DIR__ . '/config/database.php';

$pdo = getDBConnection();

// Check if table exists
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_name = 'ticket_notifications'");
$tableExists = $stmt->fetch();

if (!$tableExists) {
    echo "Creating ticket_notifications table...\n";
    
    $sql = "
        CREATE TABLE IF NOT EXISTS ticket_notifications (
            id SERIAL PRIMARY KEY,
            ticket_id INTEGER NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            sent_to_user_id INTEGER,
            sent_to_manager_id INTEGER,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            email_sent_to VARCHAR(255),
            email_sent_to_manager VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT unique_ticket_notification UNIQUE (ticket_id, notification_type, sent_to_user_id)
        );
        
        CREATE INDEX IF NOT EXISTS idx_ticket_notifications_ticket_id ON ticket_notifications(ticket_id);
        CREATE INDEX IF NOT EXISTS idx_ticket_notifications_type ON ticket_notifications(notification_type);
    ";
    
    $pdo->exec($sql);
    echo "Table created successfully!\n";
} else {
    echo "Table already exists.\n";
}

echo "\n=== Checking for stale tickets ===\n";

// Check for tickets that need notifications
$stale2Days = $pdo->query("
    SELECT COUNT(*) as count FROM tickets 
    WHERE updated_at < NOW() - INTERVAL '2 days' 
    AND status NOT IN ('Closed', 'Resolved', 'Completed')
")->fetch();

$stale5Days = $pdo->query("
    SELECT COUNT(*) as count FROM tickets 
    WHERE created_at < NOW() - INTERVAL '5 days' 
    AND status NOT IN ('Closed', 'Resolved', 'Completed')
")->fetch();

echo "Tickets unupdated for 2+ days: " . $stale2Days['count'] . "\n";
echo "Tickets unclosed for 5+ days: " . $stale5Days['count'] . "\n";
