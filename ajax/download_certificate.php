<?php
// ajax/download_certificate.php
// Handles secure download of certificate files

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

try {
    if (!isLoggedIn()) {
        throw new Exception('Authentication required');
    }

    $pdo = getDBConnection();
    $current_user = getCurrentUser();
    
    $certificate_id = $_GET['id'] ?? null;
    
    if (!$certificate_id) {
        throw new Exception('Certificate ID is required');
    }

    // Get certificate info
    $stmt = $pdo->prepare("
        SELECT 
            c.file_path, c.file_name, c.mime_type, c.file_size, c.user_id,
            u.user_type
        FROM certificates c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$certificate_id]);
    $certificate = $stmt->fetch();

    if (!$certificate) {
        throw new Exception('Certificate not found');
    }

    // Check permissions
    $current_user_role = $current_user['user_type'] ?? '';
    $is_owner = $certificate['user_id'] === $current_user['id'];
    $is_admin = in_array($current_user_role, ['super_admin', 'admin', 'manager']);

    if (!$is_owner && !$is_admin) {
        throw new Exception('You do not have permission to download this certificate');
    }

    // Verify file exists
    $file_path = $certificate['file_path'];
    if (!file_exists($file_path)) {
        throw new Exception('Certificate file not found');
    }

    // Set headers for file download
    header('Content-Type: ' . $certificate['mime_type']);
    header('Content-Length: ' . $certificate['file_size']);
    header('Content-Disposition: attachment; filename="' . basename($certificate['file_name']) . '"');
    header('Cache-Control: private');
    header('Pragma: private');

    // Output file
    readfile($file_path);
    
} catch (Exception $e) {
    http_response_code(400);
    echo "Error: " . $e->getMessage();
}
?>