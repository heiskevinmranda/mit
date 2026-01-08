<?php
// pages/tickets/delete.php
session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/routes.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

// Check if user has permission to delete tickets (manager, admin, super_admin)
if (!canDeleteTickets()) {
    $_SESSION['error'] = "You don't have permission to delete tickets. Only managers, admins, and super admins can perform this action.";
    header("Location: " . route('tickets.index'));
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Ticket ID is required.";
    header("Location: " . route('tickets.index'));
    exit();
}

$ticket_id = $_GET['id'];
$pdo = getDBConnection();
$ticket = null;
$current_user = getCurrentUser();
$user_id = $current_user['id'];
$user_type = $current_user['user_type'];

// Fetch ticket details for confirmation
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.company_name,
               sp.full_name as assigned_to_name,
               u.email as created_by_email,
               cl.location_name
        FROM tickets t
        LEFT JOIN clients c ON t.client_id = c.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        LEFT JOIN users u ON t.created_by = u.id
        LEFT JOIN client_locations cl ON t.location_id = cl.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        $_SESSION['error'] = "Ticket not found.";
        header("Location: " . route('tickets.index'));
        exit();
    }
    
    // Check for related records
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ticket_attachments WHERE ticket_id = ? AND is_deleted = false");
    $stmt->execute([$ticket_id]);
    $attachments_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_logs WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $work_logs_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ticket_assignees WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $assignees_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->prepare("SELECT SUM(total_hours) as total_hours FROM work_logs WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $total_work_hours = $stmt->fetch(PDO::FETCH_ASSOC)['total_hours'] ?? 0;
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: " . route('tickets.view') . "?id=$ticket_id");
    exit();
}

// Handle form submission for deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Additional security check - verify user still has permission
    if (!canDeleteTickets()) {
        $_SESSION['error'] = "Permission denied. Your session may have changed.";
        header("Location: " . route('tickets.index'));
        exit();
    }
    
    $confirmation = $_POST['confirmation'] ?? '';
    
    if ($confirmation !== 'DELETE') {
        $_SESSION['error'] = "Please type 'DELETE' in the confirmation box.";
        header("Location: " . route('tickets.delete') . "?id=$ticket_id");
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Delete related records
        // 1. Mark attachments as deleted (soft delete)
        $stmt = $pdo->prepare("UPDATE ticket_attachments SET is_deleted = true WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        
        // 2. Delete work logs
        $stmt = $pdo->prepare("DELETE FROM work_logs WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        
        // 3. Delete ticket assignees
        $stmt = $pdo->prepare("DELETE FROM ticket_assignees WHERE ticket_id = ?");
        $stmt->execute([$ticket_id]);
        
        // 4. Delete the ticket
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        
        // Log the deletion in audit_logs table if exists
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (?, 'delete', 'ticket', ?, ?, ?, NOW())
            ");
            $user_email = $_SESSION['email'] ?? 'unknown';
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $details = "Ticket '{$ticket['ticket_number']}' - '{$ticket['title']}' (ID: $ticket_id) deleted by {$user_type}: $user_email";
            $stmt->execute([$user_id, $ticket_id, $details, $ip_address]);
        } catch (Exception $e) {
            // Silently fail if audit_logs table doesn't exist
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Ticket '{$ticket['ticket_number']}' has been permanently deleted.";
        header("Location: " . route('tickets.index'));
        exit();
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: " . route('tickets.delete') . "?id=$ticket_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Ticket - <?= htmlspecialchars($ticket['ticket_number']) ?> - MSP Application</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="/mit/css/style.css">
    
    <style>
        :root {
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --light-bg: #f8f9fa;
        }
        
        .delete-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
        }
        
        .danger-header {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        
        .danger-header i {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .ticket-info {
            background: white;
            padding: 30px;
            border: 2px solid var(--danger-color);
            border-top: none;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .info-value {
            color: #212529;
        }
        
        .warning-card {
            background: #fff3cd;
            border: 2px solid var(--warning-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .warning-card h5 {
            color: #856404;
            margin-bottom: 15px;
        }
        
        .warning-list {
            list-style: none;
            padding: 0;
        }
        
        .warning-list li {
            padding: 8px 0;
            color: #856404;
        }
        
        .warning-list i {
            margin-right: 10px;
            color: var(--warning-color);
        }
        
        .impact-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .impact-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .confirmation-box {
            background: white;
            border: 2px solid var(--danger-color);
            border-radius: 10px;
            padding: 30px;
        }
        
        .confirmation-box h5 {
            color: var(--danger-color);
            margin-bottom: 20px;
        }
        
        .confirmation-input {
            border: 2px solid var(--danger-color);
            padding: 12px;
            font-size: 16px;
            text-align: center;
            font-weight: bold;
        }
        
        .confirmation-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
        }
        
        .btn-danger-confirm {
            background: var(--danger-color);
            border: none;
            padding: 12px 30px;
            font-size: 18px;
            font-weight: bold;
        }
        
        .btn-danger-confirm:hover {
            background: #c82333;
        }
        
        .badge-priority {
            font-size: 14px;
            padding: 6px 12px;
        }
        
        .badge-status {
            font-size: 14px;
            padding: 6px 12px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="delete-container">
                <!-- Danger Header -->
                <div class="danger-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h2>Delete Ticket</h2>
                    <p class="mb-0">This action cannot be undone!</p>
                </div>
                
                <!-- Ticket Information -->
                <div class="ticket-info">
                    <h4 class="mb-4">Ticket Details</h4>
                    
                    <div class="info-row">
                        <span class="info-label">Ticket Number:</span>
                        <span class="info-value"><strong><?= htmlspecialchars($ticket['ticket_number']) ?></strong></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Title:</span>
                        <span class="info-value"><?= htmlspecialchars($ticket['title']) ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Client:</span>
                        <span class="info-value"><?= htmlspecialchars($ticket['company_name'] ?? 'N/A') ?></span>
                    </div>
                    
                    <?php if ($ticket['location_name']): ?>
                    <div class="info-row">
                        <span class="info-label">Location:</span>
                        <span class="info-value"><?= htmlspecialchars($ticket['location_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <span class="info-label">Priority:</span>
                        <span class="info-value">
                            <span class="badge bg-<?= $ticket['priority'] == 'Critical' ? 'danger' : ($ticket['priority'] == 'High' ? 'warning' : 'secondary') ?> badge-priority">
                                <?= htmlspecialchars($ticket['priority']) ?>
                            </span>
                        </span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <span class="badge bg-<?= $ticket['status'] == 'Open' ? 'primary' : ($ticket['status'] == 'Resolved' ? 'success' : 'secondary') ?> badge-status">
                                <?= htmlspecialchars($ticket['status']) ?>
                            </span>
                        </span>
                    </div>
                    
                    <?php if ($ticket['assigned_to_name']): ?>
                    <div class="info-row">
                        <span class="info-label">Assigned To:</span>
                        <span class="info-value"><?= htmlspecialchars($ticket['assigned_to_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-row">
                        <span class="info-label">Created:</span>
                        <span class="info-value"><?= date('F j, Y g:i A', strtotime($ticket['created_at'])) ?></span>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Created By:</span>
                        <span class="info-value"><?= htmlspecialchars($ticket['created_by_email'] ?? 'N/A') ?></span>
                    </div>
                </div>
                
                <!-- Warning -->
                <div class="warning-card">
                    <h5><i class="fas fa-exclamation-circle"></i> Warning: Permanent Deletion</h5>
                    <ul class="warning-list">
                        <li><i class="fas fa-check-circle"></i> This action will permanently delete the ticket from the database</li>
                        <li><i class="fas fa-check-circle"></i> All related data will be removed (attachments, work logs, assignees)</li>
                        <li><i class="fas fa-check-circle"></i> This action cannot be undone or reversed</li>
                        <li><i class="fas fa-check-circle"></i> Consider closing the ticket instead of deleting if you need to keep records</li>
                    </ul>
                </div>
                
                <!-- Related Records Impact -->
                <?php if ($attachments_count > 0 || $work_logs_count > 0 || $assignees_count > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-database"></i> Related Records That Will Be Deleted</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($attachments_count > 0): ?>
                        <div class="impact-item">
                            <div class="d-flex justify-content-between">
                                <span><i class="fas fa-paperclip text-info me-2"></i> Attachments</span>
                                <span class="badge bg-info"><?= $attachments_count ?> file(s)</span>
                            </div>
                            <small class="text-muted">All attached files will be marked as deleted</small>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($work_logs_count > 0): ?>
                        <div class="impact-item">
                            <div class="d-flex justify-content-between">
                                <span><i class="fas fa-clock text-warning me-2"></i> Work Logs</span>
                                <span class="badge bg-warning"><?= $work_logs_count ?> log(s)</span>
                            </div>
                            <small class="text-muted">Total hours logged: <?= number_format($total_work_hours, 2) ?> hours</small>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($assignees_count > 0): ?>
                        <div class="impact-item">
                            <div class="d-flex justify-content-between">
                                <span><i class="fas fa-users text-primary me-2"></i> Assignees</span>
                                <span class="badge bg-primary"><?= $assignees_count ?> assignee(s)</span>
                            </div>
                            <small class="text-muted">All ticket assignments will be removed</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Confirmation Form -->
                <div class="confirmation-box">
                    <h5><i class="fas fa-shield-alt"></i> Confirmation Required</h5>
                    <p class="text-muted">To confirm deletion, please type <strong>DELETE</strong> in the box below:</p>
                    
                    <form method="POST" id="deleteForm">
                        <div class="mb-4">
                            <input type="text" 
                                   class="form-control confirmation-input" 
                                   name="confirmation" 
                                   id="confirmationInput"
                                   placeholder="Type DELETE to confirm"
                                   autocomplete="off"
                                   required>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= route('tickets.view', ['id' => $ticket_id]) ?>" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            
                            <button type="submit" class="btn btn-danger btn-danger-confirm" id="deleteButton" disabled>
                                <i class="fas fa-trash"></i> Delete Ticket Permanently
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Additional Info -->
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i>
                    <strong>Alternative:</strong> Instead of deleting, you can close the ticket to keep it in the system for historical records.
                    <a href="<?= route('tickets.edit', ['id' => $ticket_id]) ?>" class="alert-link">Edit ticket to change status</a>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Enable delete button only when "DELETE" is typed correctly
        const confirmationInput = document.getElementById('confirmationInput');
        const deleteButton = document.getElementById('deleteButton');
        const deleteForm = document.getElementById('deleteForm');
        
        confirmationInput.addEventListener('input', function() {
            if (this.value === 'DELETE') {
                deleteButton.disabled = false;
                deleteButton.classList.add('pulse');
            } else {
                deleteButton.disabled = true;
                deleteButton.classList.remove('pulse');
            }
        });
        
        // Confirm before submitting
        deleteForm.addEventListener('submit', function(e) {
            if (!confirm('Are you absolutely sure you want to delete this ticket? This action cannot be undone!')) {
                e.preventDefault();
            }
        });
        
        // Add pulse animation for enabled button
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            .pulse {
                animation: pulse 2s infinite;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
