<?php
// ajax/upload_certificate.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    if (!isLoggedIn()) {
        throw new Exception('Authentication required');
    }

    $pdo = getDBConnection();
    $current_user = getCurrentUser();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle file upload
        if (!isset($_FILES['certificate_file']) || $_FILES['certificate_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Certificate file is required');
        }

        $file = $_FILES['certificate_file'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];

        // Validate file type
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file['type'], $allowed_types) || !in_array($file_ext, $allowed_extensions)) {
            throw new Exception('Invalid file type. Only PDF, JPG, PNG, and GIF files are allowed.');
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception('File size exceeds 5MB limit');
        }

        // Prepare form data
        $user_id = $_POST['user_id'] ?? $current_user['id']; // Allow admin to upload for other users
        $certificate_name = trim($_POST['certificate_name'] ?? '');
        $certificate_type = trim($_POST['certificate_type'] ?? '');
        $issuing_organization = trim($_POST['issuing_organization'] ?? '');
        $issue_date = trim($_POST['issue_date'] ?? '');
        $expiry_date = trim($_POST['expiry_date'] ?? '');
        $certificate_number = trim($_POST['certificate_number'] ?? '');

        // Validate required fields
        if (empty($certificate_name)) {
            throw new Exception('Certificate name is required');
        }
        if (empty($certificate_type)) {
            throw new Exception('Certificate type is required');
        }

        // Validate dates if provided
        if (!empty($issue_date) && !strtotime($issue_date)) {
            throw new Exception('Invalid issue date');
        }
        if (!empty($expiry_date) && !strtotime($expiry_date)) {
            throw new Exception('Invalid expiry date');
        }

        // Check permissions - only admins/managers can upload for other users
        $current_user_role = $current_user['user_type'] ?? '';
        if ($user_id !== $current_user['id']) {
            if (!in_array($current_user_role, ['super_admin', 'admin', 'manager'])) {
                throw new Exception('You do not have permission to upload certificates for other users');
            }
        }

        // Create upload directory if it doesn't exist
        $upload_dir = __DIR__ . '/../uploads/certificates/' . $user_id;
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate unique filename
        $unique_filename = uniqid() . '_' . basename($file['name']);
        $upload_path = $upload_dir . '/' . $unique_filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            throw new Exception('Failed to save uploaded file');
        }

        // Insert certificate record into database
        $stmt = $pdo->prepare("
            INSERT INTO certificates (
                user_id, certificate_name, certificate_type, issuing_organization,
                issue_date, expiry_date, certificate_number, file_path, file_name, file_size, mime_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");

        $stmt->execute([
            $user_id, $certificate_name, $certificate_type, $issuing_organization,
            $issue_date ?: null, $expiry_date ?: null, $certificate_number,
            $upload_path, $file['name'], $file['size'], $file['type']
        ]);

        $certificate_id = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'message' => 'Certificate uploaded successfully',
            'certificate_id' => $certificate_id
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Check if a specific certificate ID is requested
        $certificate_id = $_GET['id'] ?? null;
        
        if ($certificate_id) {
            // Get specific certificate by ID
            $stmt = $pdo->prepare("
                SELECT 
                    c.id, c.user_id, c.certificate_name, c.certificate_type, c.issuing_organization,
                    c.issue_date, c.expiry_date, c.certificate_number, c.file_name, 
                    c.file_size, c.mime_type, c.status, c.approval_status, c.approval_notes,
                    c.rejection_reason, c.created_at, c.updated_at,
                    CASE 
                        WHEN c.approved_at IS NOT NULL THEN c.approved_at
                        WHEN c.rejected_at IS NOT NULL THEN c.rejected_at
                        ELSE NULL
                    END as status_changed_at
                FROM certificates c
                WHERE c.id = ?
            ");
            $stmt->execute([$certificate_id]);
            $certificate = $stmt->fetch();
            
            if (!$certificate) {
                throw new Exception('Certificate not found');
            }
            
            // Check permissions for the specific certificate
            $current_user_role = $current_user['user_type'] ?? '';
            $is_owner = $certificate['user_id'] === $current_user['id'];
            $is_admin = in_array($current_user_role, ['super_admin', 'admin', 'manager']);
            
            if (!$is_owner && !$is_admin) {
                throw new Exception('You do not have permission to view this certificate');
            }
            
            echo json_encode([
                'success' => true,
                'certificate' => $certificate
            ]);
        } else {
            // Get certificates for a user (existing functionality)
            $user_id = $_GET['user_id'] ?? $current_user['id'];
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);

            // Check permissions
            $current_user_role = $current_user['user_type'] ?? '';
            if ($user_id !== $current_user['id']) {
                if (!in_array($current_user_role, ['super_admin', 'admin', 'manager'])) {
                    throw new Exception('You do not have permission to view certificates for other users');
                }
            }

            $stmt = $pdo->prepare("
                SELECT 
                    c.id, c.certificate_name, c.certificate_type, c.issuing_organization,
                    c.issue_date, c.expiry_date, c.certificate_number, c.file_name, 
                    c.file_size, c.mime_type, c.status, c.approval_status, c.approval_notes,
                    c.created_at, c.updated_at,
                    CASE 
                        WHEN c.approved_at IS NOT NULL THEN c.approved_at
                        WHEN c.rejected_at IS NOT NULL THEN c.rejected_at
                        ELSE NULL
                    END as status_changed_at
                FROM certificates c
                WHERE c.user_id = ?
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?
            ");

            $stmt->execute([$user_id, $limit, $offset]);
            $certificates = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'certificates' => $certificates
            ]);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Delete certificate
        parse_str(file_get_contents("php://input"), $post_vars);
        $certificate_id = $post_vars['id'] ?? null;

        if (!$certificate_id) {
            throw new Exception('Certificate ID is required');
        }

        // Get certificate info to check ownership
        $stmt = $pdo->prepare("SELECT user_id FROM certificates WHERE id = ?");
        $stmt->execute([$certificate_id]);
        $certificate = $stmt->fetch();

        if (!$certificate) {
            throw new Exception('Certificate not found');
        }

        // Check permissions
        $current_user_role = $current_user['user_type'] ?? '';
        if ($certificate['user_id'] !== $current_user['id']) {
            if (!in_array($current_user_role, ['super_admin', 'admin', 'manager'])) {
                throw new Exception('You do not have permission to delete this certificate');
            }
        }

        // Delete file from disk
        $stmt = $pdo->prepare("SELECT file_path FROM certificates WHERE id = ?");
        $stmt->execute([$certificate_id]);
        $file_path = $stmt->fetchColumn();

        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }

        // Delete record from database
        $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
        $stmt->execute([$certificate_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Certificate deleted successfully'
        ]);

    } else {
        throw new Exception('Invalid request method');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>