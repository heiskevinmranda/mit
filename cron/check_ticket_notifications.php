<?php
/**
 * Ticket Notification Cron Script
 * 
 * This script runs daily to check for stale tickets and send notification emails:
 * - 2-day rule: Email to ticket owner if ticket is unupdated for 2 days
 * - 5-day rule: Email to both manager and ticket owner if ticket is not closed for 5 days
 * 
 * Run via cron/Task Scheduler: php cron/check_ticket_notifications.php
 */
//schtasks /create /tn "MIT Ticket Notifications" /tr "c:\wamp64\www\mit\cron\run_notifications.bat" /sc daily /st 09:00
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set timezone
date_default_timezone_set('UTC');

// Define constant for base path
define('BASE_PATH', dirname(__DIR__));

// Include required files
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/email.php';
require_once BASE_PATH . '/includes/email_helper.php';

// Configuration
$config = [
    'stale_days' => NOTIFICATION_STALE_DAYS ?? 2,        // Days before stale update reminder
    'closed_days' => NOTIFICATION_CLOSED_DAYS ?? 5,     // Days before stale close reminder
    'base_url' => BASE_URL ?? 'http://localhost/mit'
];

// Logging
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$type] $message" . PHP_EOL;
    
    // Also write to log file
    $logFile = BASE_PATH . '/logs/notification_cron.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, "[$timestamp] [$type] $message" . PHP_EOL, FILE_APPEND);
}

/**
 * Get tickets that haven't been updated for specified days
 */
function getStaleUpdateTickets($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.ticket_number,
            t.title,
            t.description,
            t.status,
            t.priority,
            t.created_at,
            t.updated_at,
            t.assigned_to,
            t.created_by,
            u.email as owner_email,
            u.id as owner_user_id,
            sp.full_name as owner_name,
            c.company_name as client_name
        FROM tickets t
        JOIN users u ON t.created_by = u.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        LEFT JOIN clients c ON t.client_id = c.id
        WHERE t.updated_at < NOW() - INTERVAL '" . (int)$days . " days'
        AND t.status NOT IN ('Closed', 'Resolved', 'Completed')
        AND NOT EXISTS (
            SELECT 1 FROM ticket_notifications 
            WHERE ticket_id::text = t.id::text
            AND notification_type = 'stale_update'
            AND sent_to_user_id::text = t.created_by::text
        )
        ORDER BY t.updated_at ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get tickets that haven't been closed for specified days
 */
function getStaleCloseTickets($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            t.id,
            t.ticket_number,
            t.title,
            t.description,
            t.status,
            t.priority,
            t.created_at,
            t.updated_at,
            t.assigned_to,
            t.created_by,
            u.email as owner_email,
            u.id as owner_user_id,
            sp.full_name as owner_name,
            sp.reporting_manager_id,
            sm.full_name as manager_name,
            mu.email as manager_email,
            c.company_name as client_name
        FROM tickets t
        JOIN users u ON t.created_by = u.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        LEFT JOIN staff_profiles sm ON sp.reporting_manager_id = sm.id
        LEFT JOIN users mu ON sm.user_id = mu.id
        LEFT JOIN clients c ON t.client_id = c.id
        WHERE t.created_at < NOW() - INTERVAL '" . (int)$days . " days'
        AND t.status NOT IN ('Closed', 'Resolved', 'Completed')
        AND NOT EXISTS (
            SELECT 1 FROM ticket_notifications 
            WHERE ticket_id::text = t.id::text
            AND notification_type = 'stale_close'
        )
        ORDER BY t.created_at ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record a sent notification in the database
 */
function recordNotification($pdo, $ticketId, $type, $userId, $managerId = null, $emailTo = '', $emailToManager = '') {
    $stmt = $pdo->prepare("
        INSERT INTO ticket_notifications (ticket_id, notification_type, sent_to_user_id, sent_to_manager_id, email_sent_to, email_sent_to_manager)
        VALUES (?::uuid, ?, ?, ?, ?, ?)
        ON CONFLICT (ticket_id, notification_type, sent_to_user_id) DO NOTHING
    ");
    return $stmt->execute([$ticketId, $type, $userId, $managerId, $emailTo, $emailToManager]);
}

/**
 * Send stale update reminder email (2-day rule)
 */
function sendStaleUpdateNotification($ticket, $config) {
    $ticketUrl = $config['base_url'] . '/pages/tickets/view.php?id=' . $ticket['id'];
    
    $subject = "Reminder: Ticket #{$ticket['ticket_number']} - No update in {$config['stale_days']} days";
    
    $content = "
        <p>Hello <strong>{$ticket['owner_name']}</strong>,</p>
        
        <p>This is a reminder that the following ticket has not been updated in {$config['stale_days']} days:</p>
        
        <div class='ticket-info'>
            <h3>Ticket #{$ticket['ticket_number']}</h3>
            <p><strong>Title:</strong> " . htmlspecialchars($ticket['title']) . "</p>
            <p><strong>Status:</strong> {$ticket['status']}</p>
            <p><strong>Priority:</strong> {$ticket['priority']}</p>
            <p><strong>Client:</strong> " . htmlspecialchars($ticket['client_name'] ?? 'N/A') . "</p>
            <p><strong>Last Updated:</strong> " . date('F j, Y, g:i a', strtotime($ticket['updated_at'])) . "</p>
            <p><strong>Days Stale:</strong> " . floor((time() - strtotime($ticket['updated_at'])) / 86400) . " days</p>
        </div>
        
        <p>Please update this ticket with the latest progress or relevant information.</p>
        
        <center>
            <a href='{$ticketUrl}' class='btn'>View Ticket</a>
        </center>
        
        <p>If this ticket has been resolved, please update its status to Closed.</p>
    ";
    
    $body = getEmailTemplate('Ticket Update Reminder', $content);
    
    return sendEmail($ticket['owner_email'], $subject, $body, $ticket['owner_name']);
}

/**
 * Send stale close reminder email (5-day rule)
 */
function sendStaleCloseNotification($ticket, $config, $notifyManager = true) {
    $ticketUrl = $config['base_url'] . '/pages/tickets/view.php?id=' . $ticket['id'];
    
    $subject = "Action Required: Ticket #{$ticket['ticket_number']} - Still open after {$config['closed_days']} days";
    
    $recipients = [];
    $sentEmails = [];
    
    // Add ticket owner
    if (!empty($ticket['owner_email'])) {
        $recipients[$ticket['owner_email']] = $ticket['owner_name'];
        $sentEmails[] = $ticket['owner_email'];
    }
    
    // Add manager if available and requested
    if ($notifyManager && !empty($ticket['manager_email'])) {
        $recipients[$ticket['manager_email']] = $ticket['manager_name'];
        $sentEmails[] = $ticket['manager_email'];
    }
    
    if (empty($recipients)) {
        logMessage("No recipients found for ticket #{$ticket['ticket_number']}", 'WARNING');
        return false;
    }
    
    $daysOpen = floor((time() - strtotime($ticket['created_at'])) / 86400);
    
    $content = "
        <p>Hello,</p>
        
        <p>This is an urgent notification that the following ticket has been open for <strong>{$daysOpen} days</strong> without being closed:</p>
        
        <div class='ticket-info'>
            <h3>Ticket #{$ticket['ticket_number']}</h3>
            <p><strong>Title:</strong> " . htmlspecialchars($ticket['title']) . "</p>
            <p><strong>Status:</strong> {$ticket['status']}</p>
            <p><strong>Priority:</strong> {$ticket['priority']}</p>
            <p><strong>Client:</strong> " . htmlspecialchars($ticket['client_name'] ?? 'N/A') . "</p>
            <p><strong>Assigned To:</strong> " . htmlspecialchars($ticket['owner_name'] ?? 'Unassigned') . "</p>
            <p><strong>Created:</strong> " . date('F j, Y, g:i a', strtotime($ticket['created_at'])) . "</p>
            <p><strong>Days Open:</strong> {$daysOpen} days</p>
        </div>
        
        <p><strong>Action Required:</strong> Please review this ticket and either:</p>
        <ul>
            <li>Update it with the latest progress</li>
            <li>Close it if the issue has been resolved</li>
            <li>Add a comment explaining the delay</li>
        </ul>
        
        <center>
            <a href='{$ticketUrl}' class='btn'>View Ticket</a>
        </center>
    ";
    
    $body = getEmailTemplate('Ticket Action Required', $content);
    
    // Send to all recipients
    $success = sendEmailToMultiple($recipients, $subject, $body);
    
    return $success > 0;
}

/**
 * Main execution
 */
function main() {
    global $config;
    
    logMessage("========================================");
    logMessage("Starting Ticket Notification Check");
    logMessage("========================================");
    
    // Check if emails are enabled
    if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED) {
        logMessage("Email notifications are disabled. Exiting.", 'WARNING');
        return;
    }
    
    // Connect to database
    try {
        $pdo = getDBConnection();
        logMessage("Database connection established");
    } catch (Exception $e) {
        logMessage("Database connection failed: " . $e->getMessage(), 'ERROR');
        exit(1);
    }
    
    $notificationsSent = 0;
    $errors = 0;
    
    // Process 2-day stale update notifications
    logMessage("Checking for tickets unupdated for {$config['stale_days']} days...");
    $staleUpdateTickets = getStaleUpdateTickets($pdo, $config['stale_days']);
    logMessage("Found " . count($staleUpdateTickets) . " tickets needing stale update notification");
    
    foreach ($staleUpdateTickets as $ticket) {
        logMessage("Processing stale update for ticket #{$ticket['ticket_number']}");
        
        try {
            $success = sendStaleUpdateNotification($ticket, $config);
            
            if ($success) {
                recordNotification(
                    $pdo, 
                    $ticket['id'], 
                    'stale_update', 
                    $ticket['owner_user_id'],
                    null,
                    $ticket['owner_email']
                );
                $notificationsSent++;
                logMessage("Sent stale update notification for ticket #{$ticket['ticket_number']} to {$ticket['owner_email']}");
            } else {
                $errors++;
                logMessage("Failed to send stale update notification for ticket #{$ticket['ticket_number']}", 'ERROR');
            }
        } catch (Exception $e) {
            $errors++;
            logMessage("Error sending stale update notification: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Process 5-day stale close notifications
    logMessage("Checking for tickets unclosed for {$config['closed_days']} days...");
    $staleCloseTickets = getStaleCloseTickets($pdo, $config['closed_days']);
    logMessage("Found " . count($staleCloseTickets) . " tickets needing stale close notification");
    
    foreach ($staleCloseTickets as $ticket) {
        logMessage("Processing stale close for ticket #{$ticket['ticket_number']}");
        
        try {
            $success = sendStaleCloseNotification($ticket, $config, true);
            
            if ($success) {
                // Get manager user ID if available
                $managerUserId = null;
                if (!empty($ticket['reporting_manager_id'])) {
                    $managerStmt = $pdo->prepare("SELECT id FROM users WHERE staff_profile_id = ?");
                    $managerStmt->execute([$ticket['reporting_manager_id']]);
                    $managerUser = $managerStmt->fetch();
                    $managerUserId = $managerUser['id'] ?? null;
                }
                
                recordNotification(
                    $pdo, 
                    $ticket['id'], 
                    'stale_close', 
                    $ticket['owner_user_id'],
                    $managerUserId,
                    $ticket['owner_email'],
                    $ticket['manager_email'] ?? ''
                );
                $notificationsSent++;
                logMessage("Sent stale close notification for ticket #{$ticket['ticket_number']}");
            } else {
                $errors++;
                logMessage("Failed to send stale close notification for ticket #{$ticket['ticket_number']}", 'ERROR');
            }
        } catch (Exception $e) {
            $errors++;
            logMessage("Error sending stale close notification: " . $e->getMessage(), 'ERROR');
        }
    }
    
    logMessage("========================================");
    logMessage("Ticket Notification Check Complete");
    logMessage("Notifications sent: $notificationsSent");
    logMessage("Errors: $errors");
    logMessage("========================================");
}

// Run the main function
main();
