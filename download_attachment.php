<?php
/**
 * Secure File Download Handler for Ticket Attachments
 * 
 * This file provides a secure way to download ticket attachments
 * by checking permissions and serving files without exposing the file system structure
 */

require_once 'includes/auth.php';
require_once 'config/database.php';

// Check if user is logged in
requireLogin();

$pdo = getDBConnection();
$current_user = getCurrentUser();

// Get attachment ID from URL
$attachment_id = $_GET['id'] ?? null;

if (!$attachment_id) {
    http_response_code(400);
    die('Attachment ID is required');
}

try {
    // Fetch attachment details with ticket information
    $stmt = $pdo->prepare("
        SELECT ta.*, t.id as ticket_id, t.client_id
        FROM ticket_attachments ta
        LEFT JOIN tickets t ON ta.ticket_id = t.id
        WHERE ta.id = ? AND ta.is_deleted = false
    ");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        die('Attachment not found');
    }

    // Check if user has permission to download this attachment
    // User must have permission to view the ticket that contains this attachment
    $ticket_id = $attachment['ticket_id'];
    $is_creator = ($current_user['id'] == $attachment['created_by'] ?? $attachment['uploaded_by']);
    $is_admin_or_manager = (isAdmin() || isManager());

    // Check if user is assigned to the ticket
    $is_assigned = false;
    if (isset($current_user['staff_profile']['id'])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM ticket_assignees 
            WHERE ticket_id = ? AND staff_id = ?
        ");
        $stmt->execute([$ticket_id, $current_user['staff_profile']['id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_assigned = ($result['count'] > 0);
    }

    // Check if user is client associated with the ticket
    $is_client = false;
    if ($current_user['user_type'] === 'client') {
        $stmt = $pdo->prepare("SELECT client_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ticket_info && $current_user['client_id'] == $ticket_info['client_id']) {
            $is_client = true;
        }
    }

    if (!$is_creator && !$is_admin_or_manager && !$is_assigned && !$is_client) {
        http_response_code(403);
        die('You do not have permission to download this attachment');
    }

    // Verify that the file exists
    $file_path = __DIR__ . '/uploads/tickets/' . $ticket_id . '/' . $attachment['stored_filename'];
    
    if (!file_exists($file_path)) {
        http_response_code(404);
        die('File not found on server');
    }

    // Set headers for file download
    $original_filename = $attachment['original_filename'];
    $file_size = $attachment['file_size'];
    $file_type = $attachment['file_type'] ?: mime_content_type($file_path);

    // Clean the filename to prevent security issues
    $original_filename = basename($original_filename);
    
    // Set appropriate headers
    header('Content-Type: ' . $file_type);
    header('Content-Disposition: attachment; filename="' . $original_filename . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private');
    header('Pragma: private');
    header('Expires: 0');

    // Log the download (optional)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO file_downloads (attachment_id, user_id, ip_address, download_time)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            $attachment_id,
            $current_user['id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        // If logging fails, continue with download anyway
        error_log("Failed to log file download: " . $e->getMessage());
    }

    // Read and output the file
    readfile($file_path);
    
} catch (Exception $e) {
    error_log("Error downloading attachment: " . $e->getMessage());
    http_response_code(500);
    die('An error occurred while downloading the file');
}