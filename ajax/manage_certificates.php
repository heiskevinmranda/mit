<?php
// ajax/manage_certificates.php
// Handles certificate management operations like approve/reject

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

try {
    if (!isLoggedIn()) {
        throw new Exception('Authentication required');
    }

    $pdo = getDBConnection();
    $current_user = getCurrentUser();
    
    // Check if user has admin privileges for certificate management
    $current_user_role = $current_user['user_type'] ?? '';
    if (!in_array($current_user_role, ['super_admin', 'admin', 'manager'])) {
        throw new Exception('You do not have permission to manage certificates');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'approve') {
            // Approve certificate
            $certificate_id = $_POST['certificate_id'] ?? null;
            $approval_notes = trim($_POST['approval_notes'] ?? '');

            if (!$certificate_id) {
                throw new Exception('Certificate ID is required');
            }

            $stmt = $pdo->prepare("
                UPDATE certificates 
                SET 
                    approval_status = 'approved',
                    status = 'approved',
                    approved_by = ?,
                    approval_notes = ?,
                    approved_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([$current_user['id'], $approval_notes, $certificate_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Certificate approved successfully'
            ]);

        } elseif ($action === 'reject') {
            // Reject certificate
            $certificate_id = $_POST['certificate_id'] ?? null;
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');

            if (!$certificate_id) {
                throw new Exception('Certificate ID is required');
            }
            if (empty($rejection_reason)) {
                throw new Exception('Rejection reason is required');
            }

            $stmt = $pdo->prepare("
                UPDATE certificates 
                SET 
                    approval_status = 'rejected',
                    status = 'rejected',
                    approved_by = ?,
                    rejection_reason = ?,
                    rejected_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([$current_user['id'], $rejection_reason, $certificate_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Certificate rejected successfully'
            ]);

        } elseif ($action === 'bulk_approve') {
            // Bulk approve certificates
            $certificate_ids = json_decode($_POST['certificate_ids'] ?? '[]', true);
            
            if (!is_array($certificate_ids) || empty($certificate_ids)) {
                throw new Exception('Certificate IDs are required');
            }

            $placeholders = str_repeat('?,', count($certificate_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                UPDATE certificates 
                SET 
                    approval_status = 'approved',
                    status = 'approved',
                    approved_by = ?,
                    approved_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id IN ($placeholders)
            ");
            
            $params = array_merge([$current_user['id']], $certificate_ids);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => count($certificate_ids) . ' certificates approved successfully'
            ]);

        } elseif ($action === 'bulk_reject') {
            // Bulk reject certificates
            $certificate_ids = json_decode($_POST['certificate_ids'] ?? '[]', true);
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');

            if (!is_array($certificate_ids) || empty($certificate_ids)) {
                throw new Exception('Certificate IDs are required');
            }
            if (empty($rejection_reason)) {
                throw new Exception('Rejection reason is required');
            }

            $placeholders = str_repeat('?,', count($certificate_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                UPDATE certificates 
                SET 
                    approval_status = 'rejected',
                    status = 'rejected',
                    approved_by = ?,
                    rejection_reason = ?,
                    rejected_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id IN ($placeholders)
            ");
            
            $params = array_merge([$current_user['id'], $rejection_reason], $certificate_ids);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => count($certificate_ids) . ' certificates rejected successfully'
            ]);

        } else {
            throw new Exception('Invalid action specified');
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get certificates for management (all pending/approved/rejected)
        $status = $_GET['status'] ?? 'all'; // all, pending, approved, rejected
        $user_id = $_GET['user_id'] ?? null; // Filter by specific user
        $limit = intval($_GET['limit'] ?? 50);
        $offset = intval($_GET['offset'] ?? 0);

        $where_conditions = [];
        $params = [];

        if ($status !== 'all') {
            $where_conditions[] = "c.approval_status = ?";
            $params[] = $status;
        }

        if ($user_id) {
            $where_conditions[] = "c.user_id = ?";
            $params[] = $user_id;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        $stmt = $pdo->prepare("
            SELECT 
                c.id, c.certificate_name, c.certificate_type, c.issuing_organization,
                c.issue_date, c.expiry_date, c.certificate_number, c.file_name, 
                c.file_size, c.mime_type, c.status, c.approval_status, c.approval_notes,
                c.rejection_reason, c.created_at, c.updated_at,
                u.email as user_email,
                sp.full_name as user_name,
                approver.full_name as approved_by_name
            FROM certificates c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN staff_profiles sp ON c.user_id = sp.user_id
            LEFT JOIN staff_profiles approver ON c.approved_by = approver.user_id
            $where_clause
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute(array_merge($params, [$limit, $offset]));
        $certificates = $stmt->fetchAll();

        // Get total count for pagination
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM certificates c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN staff_profiles sp ON c.user_id = sp.user_id
            $where_clause
        ");
        $count_stmt->execute($params);
        $total = $count_stmt->fetch()['total'];

        echo json_encode([
            'success' => true,
            'certificates' => $certificates,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
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