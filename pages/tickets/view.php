<?php
// pages/tickets/view.php

require_once '../../includes/auth.php';
requireLogin();

$pdo = getDBConnection();
$current_user = getCurrentUser();

$ticket_id = $_GET['id'] ?? null;
$error = '';
$success = '';

if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// Get ticket details
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.company_name,
               cl.location_name,
               sp.full_name as assigned_to_name,
               uc.email as created_by_email,
               uc.user_type as created_by_type
        FROM tickets t
        LEFT JOIN clients c ON t.client_id = c.id
        LEFT JOIN client_locations cl ON t.location_id = cl.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        LEFT JOIN users uc ON t.created_by = uc.id
        WHERE t.id = ?
    ");
    
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        $error = "Ticket not found";
    }
    
} catch (Exception $e) {
    $error = "Error loading ticket: " . $e->getMessage();
}

// Get ticket logs
$ticket_logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT tl.*, sp.full_name, sp.staff_id
        FROM ticket_logs tl
        LEFT JOIN staff_profiles sp ON tl.staff_id = sp.id
        WHERE tl.ticket_id = ?
        ORDER BY tl.created_at DESC
    ");
    
    $stmt->execute([$ticket_id]);
    $ticket_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log error but don't stop page loading
    error_log("Error loading ticket logs: " . $e->getMessage());
}

// Get ticket attachments - CORRECTED HERE: changed uploaded_at to upload_time
$ticket_attachments = [];
try {
    $stmt = $pdo->prepare("
        SELECT ta.*, u.email as uploaded_by_email
        FROM ticket_attachments ta
        LEFT JOIN users u ON ta.uploaded_by = u.id
        WHERE ta.ticket_id = ? AND ta.is_deleted = false
        ORDER BY ta.upload_time DESC  -- CORRECTED: upload_time instead of uploaded_at
    ");
    
    $stmt->execute([$ticket_id]);
    $ticket_attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log error but don't stop page loading
    error_log("Error loading attachments: " . $e->getMessage());
}

// Handle new comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    try {
        $comment = trim($_POST['comment']);
        $action = $_POST['action_type'] ?? 'Comment';
        $time_spent = $_POST['time_spent'] ?? 0;
        
        if (empty($comment)) {
            throw new Exception("Comment cannot be empty");
        }
        
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description, time_spent_minutes)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$ticket_id, $staff_id, $action, $comment, $time_spent]);
        
        // Update ticket's updated_at timestamp
        $stmt = $pdo->prepare("UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$ticket_id]);
        
        $success = "Comment added successfully";
        
        // Refresh page to show new comment
        header("Location: view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $new_status = $_POST['status'];
        $comment = "Status changed to: " . $new_status;
        
        if (!in_array($new_status, ['Open', 'In Progress', 'Waiting', 'Resolved', 'Closed'])) {
            throw new Exception("Invalid status");
        }
        
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        
        // Update ticket status
        $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_status, $ticket_id]);
        
        // Add log entry
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$ticket_id, $staff_id, 'Status Update', $comment]);
        
        // If closing ticket, set closed_at
        if ($new_status === 'Closed') {
            $stmt = $pdo->prepare("UPDATE tickets SET closed_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$ticket_id]);
        }
        
        $success = "Status updated to " . $new_status;
        
        // Refresh page
        header("Location: view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_ticket'])) {
    try {
        $assigned_to = $_POST['assigned_to'];
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        
        // Get assigned staff name
        $stmt = $pdo->prepare("SELECT full_name FROM staff_profiles WHERE id = ?");
        $stmt->execute([$assigned_to]);
        $assigned_staff = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update ticket
        $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$assigned_to, $ticket_id]);
        
        // Add log entry
        $comment = "Ticket assigned to " . ($assigned_staff['full_name'] ?? 'Unknown Staff');
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$ticket_id, $staff_id, 'Assignment', $comment]);
        
        $success = "Ticket assigned successfully";
        
        // Refresh page
        header("Location: view.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['new_attachment'])) {
    try {
        if ($_FILES['new_attachment']['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['new_attachment']['name'];
            $file_tmp = $_FILES['new_attachment']['tmp_name'];
            $file_size = $_FILES['new_attachment']['size'];
            $file_type = $_FILES['new_attachment']['type'];
            
            // Validate file size (200MB max)
            if ($file_size > 200 * 1024 * 1024) {
                throw new Exception("File exceeds 200MB limit");
            }
            
            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Create uploads directory if it doesn't exist
            $upload_dir = '../../uploads/tickets/' . $ticket_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $unique_name;
            
            // Move uploaded file
            if (!move_uploaded_file($file_tmp, $file_path)) {
                throw new Exception("Failed to upload file");
            }
            
            // Save to database
            $stmt = $pdo->prepare("
                INSERT INTO ticket_attachments 
                (ticket_id, original_filename, stored_filename, file_path, file_type, file_size, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $ticket_id,
                $file_name,
                $unique_name,
                str_replace('../../', '', $file_path),
                $file_type,
                $file_size,
                $current_user['id']
            ]);
            
            // Add log entry
            $staff_id = $current_user['staff_profile']['id'] ?? null;
            $stmt = $pdo->prepare("
                INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([$ticket_id, $staff_id, 'Attachment Added', 'Added file: ' . $file_name]);
            
            $success = "File uploaded successfully";
            
            // Refresh page
            header("Location: view.php?id=" . $ticket_id);
            exit;
        }
    } catch (Exception $e) {
        $error = "Upload error: " . $e->getMessage();
    }
}

// Get staff for assignment dropdown
$staff_members = [];
try {
    $stmt = $pdo->query("SELECT id, full_name FROM staff_profiles WHERE employment_status = 'Active' ORDER BY full_name");
    $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading staff: " . $e->getMessage());
}

// Helper function to format file size
function formatBytes($bytes, $precision = 2) {
    if ($bytes === 0) return '0 Bytes';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Helper function to get file icon
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $icons = [
        'pdf' => 'fa-file-pdf',
        'doc' => 'fa-file-word',
        'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'jpg' => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image',
        'gif' => 'fa-file-image',
        'zip' => 'fa-file-archive',
        'rar' => 'fa-file-archive',
        'txt' => 'fa-file-alt',
        'log' => 'fa-file-alt',
    ];
    
    return $icons[$ext] ?? 'fa-file';
}

// Helper function to get status badge color
function getStatusBadge($status) {
    $colors = [
        'Open' => 'bg-primary',
        'In Progress' => 'bg-info',
        'Waiting' => 'bg-warning',
        'Resolved' => 'bg-success',
        'Closed' => 'bg-secondary'
    ];
    
    return $colors[$status] ?? 'bg-secondary';
}

// Helper function to get priority badge color
function getPriorityBadge($priority) {
    $colors = [
        'Critical' => 'bg-danger',
        'High' => 'bg-warning',
        'Medium' => 'bg-info',
        'Low' => 'bg-secondary'
    ];
    
    return $colors[$priority] ?? 'bg-secondary';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo htmlspecialchars($ticket['ticket_number'] ?? ''); ?> | MSP Application</title>
    <link rel="stylesheet" href="../../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .ticket-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .ticket-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .status-badge, .priority-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
            color: white;
        }
        
        .comment-card {
            border-left: 4px solid #007bff;
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 0 5px 5px 0;
        }
        
        .comment-card.staff {
            border-left-color: #28a745;
        }
        
        .comment-card.client {
            border-left-color: #6c757d;
        }
        
        .attachment-card {
            display: flex;
            align-items: center;
            padding: 15px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .attachment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .file-icon {
            font-size: 24px;
            color: #666;
            margin-right: 15px;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 500;
            margin-bottom: 3px;
        }
        
        .file-meta {
            font-size: 12px;
            color: #666;
        }
        
        .sla-indicator {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
            margin-right: 10px;
        }
        
        .sla-ontrack { background: #d4edda; color: #155724; }
        .sla-warning { background: #fff3cd; color: #856404; }
        .sla-breach { background: #f8d7da; color: #721c24; }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -23px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #007bff;
            border: 2px solid white;
        }
        
        @media (max-width: 768px) {
            .ticket-header {
                padding: 20px;
            }
            
            .ticket-card {
                padding: 15px;
            }
            
            .attachment-card {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .file-icon {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Include Sidebar -->
        <?php 
        $sidebar = '
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-network-wired"></i> MSP Portal</h3>
                <p>' . htmlspecialchars($current_user['staff_profile']['full_name'] ?? $current_user['email']) . '</p>
                <span class="user-role">' . ucfirst(str_replace('_', ' ', $current_user['user_type'])) . '</span>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="../../dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a></li>
                    
                    <li><a href="index.php">
                        <i class="fas fa-ticket-alt"></i> Tickets
                    </a></li>
                    
                    <li><a href="create.php">
                        <i class="fas fa-plus-circle"></i> Create Ticket
                    </a></li>
                    
                    <li><a href="../clients/index.php">
                        <i class="fas fa-building"></i> Clients
                    </a></li>
                    
                    <li><a href="../../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a></li>
                </ul>
            </nav>
        </aside>';
        echo $sidebar;
        ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($ticket): ?>
            
            <!-- Ticket Header -->
            <div class="ticket-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h1><i class="fas fa-ticket-alt"></i> Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?></h1>
                        <h4><?php echo htmlspecialchars($ticket['title']); ?></h4>
                        <div class="mt-3">
                            <span class="status-badge <?php echo getStatusBadge($ticket['status']); ?> me-2">
                                <i class="fas fa-circle"></i> <?php echo htmlspecialchars($ticket['status']); ?>
                            </span>
                            <span class="priority-badge <?php echo getPriorityBadge($ticket['priority']); ?>">
                                <i class="fas fa-flag"></i> <?php echo htmlspecialchars($ticket['priority']); ?> Priority
                            </span>
                        </div>
                    </div>
                    <div class="text-end">
                        <a href="index.php" class="btn btn-light">
                            <i class="fas fa-arrow-left"></i> Back to Tickets
                        </a>
                        <a href="edit.php?id=<?php echo $ticket_id; ?>" class="btn btn-light ms-2">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Left Column - Ticket Details -->
                <div class="col-lg-8">
                    <!-- Ticket Information Card -->
                    <div class="ticket-card">
                        <h4><i class="fas fa-info-circle"></i> Ticket Information</h4>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Client</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-building"></i> 
                                        <?php echo htmlspecialchars($ticket['company_name'] ?? 'N/A'); ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Location</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?php echo htmlspecialchars($ticket['location_name'] ?? 'Not specified'); ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Category</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-folder"></i> 
                                        <?php echo htmlspecialchars($ticket['category']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Assigned To</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-user"></i> 
                                        <?php echo htmlspecialchars($ticket['assigned_to_name'] ?? 'Unassigned'); ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Created By</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-user-plus"></i> 
                                        <?php echo htmlspecialchars($ticket['created_by_email'] ?? 'Unknown'); ?>
                                        (<?php echo htmlspecialchars($ticket['created_by_type'] ?? 'Unknown'); ?>)
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Created Date</label>
                                    <div class="fw-bold">
                                        <i class="fas fa-calendar"></i> 
                                        <?php echo date('F j, Y, g:i a', strtotime($ticket['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Description -->
                        <div class="mt-4">
                            <label class="form-label text-muted">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments & Activity -->
                    <div class="ticket-card">
                        <h4><i class="fas fa-comments"></i> Comments & Activity</h4>
                        
                        <!-- Add Comment Form -->
                        <div class="mt-4 mb-4">
                            <form method="POST" id="commentForm">
                                <div class="mb-3">
                                    <label for="comment" class="form-label">Add Comment</label>
                                    <textarea class="form-control" id="comment" name="comment" rows="3" 
                                              placeholder="Type your comment here..." required></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="action_type" class="form-label">Action Type</label>
                                            <select class="form-select" id="action_type" name="action_type">
                                                <option value="Comment">Comment</option>
                                                <option value="Update">Update</option>
                                                <option value="Internal Note">Internal Note</option>
                                                <option value="Client Communication">Client Communication</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="time_spent" class="form-label">Time Spent (minutes)</label>
                                            <input type="number" class="form-control" id="time_spent" name="time_spent" 
                                                   min="0" value="0">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="add_comment" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Post Comment
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Activity Timeline -->
                        <div class="timeline mt-4">
                            <?php foreach ($ticket_logs as $log): ?>
                            <div class="timeline-item">
                                <div class="comment-card <?php echo $log['staff_id'] ? 'staff' : 'client'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="fw-bold">
                                            <i class="fas fa-user-circle"></i> 
                                            <?php echo htmlspecialchars($log['full_name'] ?? ($log['staff_id'] ? 'Staff' : 'Client')); ?>
                                            <?php if ($log['staff_id']): ?>
                                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($log['staff_id'] ?? ''); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('M j, Y g:i a', strtotime($log['created_at'])); ?>
                                        </small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($log['action']); ?></span>
                                        <?php if ($log['time_spent_minutes']): ?>
                                        <span class="badge bg-warning ms-1">
                                            <i class="fas fa-clock"></i> <?php echo $log['time_spent_minutes']; ?> min
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <?php echo nl2br(htmlspecialchars($log['description'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($ticket_logs)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-comments fa-2x mb-2"></i><br>
                                No activity yet. Be the first to comment!
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column - Actions & Attachments -->
                <div class="col-lg-4">
                    <!-- Quick Actions -->
                    <div class="ticket-card">
                        <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                        
                        <!-- Status Update Form -->
                        <form method="POST" class="mt-3">
                            <div class="mb-3">
                                <label for="status" class="form-label">Update Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Open" <?php echo $ticket['status'] == 'Open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="In Progress" <?php echo $ticket['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Waiting" <?php echo $ticket['status'] == 'Waiting' ? 'selected' : ''; ?>>Waiting</option>
                                    <option value="Resolved" <?php echo $ticket['status'] == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Closed" <?php echo $ticket['status'] == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                            <button type="submit" name="update_status" class="btn btn-primary w-100">
                                <i class="fas fa-sync-alt"></i> Update Status
                            </button>
                        </form>
                        
                        <!-- Assignment Form -->
                        <form method="POST" class="mt-3">
                            <div class="mb-3">
                                <label for="assigned_to" class="form-label">Assign To</label>
                                <select class="form-select" id="assigned_to" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($staff_members as $staff): ?>
                                    <option value="<?php echo htmlspecialchars($staff['id']); ?>" 
                                        <?php echo $ticket['assigned_to'] == $staff['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($staff['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="assign_ticket" class="btn btn-info w-100">
                                <i class="fas fa-user-check"></i> Assign Ticket
                            </button>
                        </form>
                    </div>
                    
                    <!-- Attachments -->
                    <div class="ticket-card">
                        <h4><i class="fas fa-paperclip"></i> Attachments</h4>
                        
                        <!-- Upload Form -->
                        <form method="POST" enctype="multipart/form-data" class="mt-3">
                            <div class="mb-3">
                                <label for="new_attachment" class="form-label">Add Attachment</label>
                                <input type="file" class="form-control" id="new_attachment" name="new_attachment">
                                <div class="form-text">Max 200MB per file</div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-upload"></i> Upload File
                            </button>
                        </form>
                        
                        <!-- Attachment List -->
                        <div class="mt-4">
                            <?php if (!empty($ticket_attachments)): ?>
                                <?php foreach ($ticket_attachments as $attachment): ?>
                                <div class="attachment-card">
                                    <div class="file-icon">
                                        <i class="fas <?php echo getFileIcon($attachment['original_filename']); ?>"></i>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name">
                                            <?php echo htmlspecialchars($attachment['original_filename']); ?>
                                        </div>
                                        <div class="file-meta">
                                            <?php echo formatBytes($attachment['file_size']); ?> • 
                                            <?php echo date('M j, Y', strtotime($attachment['upload_time'])); ?> • 
                                            Uploaded by: <?php echo htmlspecialchars($attachment['uploaded_by_email'] ?? 'Unknown'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-paperclip fa-2x mb-2"></i><br>
                                    No attachments yet
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- SLA Information -->
                    <div class="ticket-card">
                        <h4><i class="fas fa-clock"></i> SLA Information</h4>
                        
                        <div class="mt-3">
                            <?php if ($ticket['sla_start_time']): ?>
                            <div class="mb-2">
                                <small class="text-muted">SLA Started</small>
                                <div class="fw-bold">
                                    <?php echo date('M j, Y g:i a', strtotime($ticket['sla_start_time'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['sla_breach_time']): ?>
                            <div class="mb-2">
                                <small class="text-muted">SLA Breach Time</small>
                                <div class="fw-bold">
                                    <?php echo date('M j, Y g:i a', strtotime($ticket['sla_breach_time'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['actual_response_time']): ?>
                            <div class="mb-2">
                                <small class="text-muted">Response Time</small>
                                <div class="fw-bold">
                                    <?php echo $ticket['actual_response_time']; ?> minutes
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['actual_resolution_time']): ?>
                            <div class="mb-2">
                                <small class="text-muted">Resolution Time</small>
                                <div class="fw-bold">
                                    <?php echo $ticket['actual_resolution_time']; ?> minutes
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- SLA Status Indicator -->
                            <?php
                            $sla_status = 'ontrack';
                            $sla_message = 'SLA is on track';
                            
                            if ($ticket['sla_breach_time'] && strtotime($ticket['sla_breach_time']) < time()) {
                                $sla_status = 'breach';
                                $sla_message = 'SLA breached';
                            } elseif ($ticket['sla_breach_time'] && (strtotime($ticket['sla_breach_time']) - time()) < 3600) {
                                $sla_status = 'warning';
                                $sla_message = 'SLA warning - less than 1 hour';
                            }
                            ?>
                            
                            <div class="mt-3">
                                <span class="sla-indicator sla-<?php echo $sla_status; ?>">
                                    <i class="fas fa-clock"></i> <?php echo $sla_message; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <!-- Ticket Not Found -->
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error ?: "Ticket not found or you don't have permission to view it."); ?>
            </div>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Tickets
            </a>
            
            <?php endif; ?>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-resize textarea
        const commentTextarea = document.getElementById('comment');
        if (commentTextarea) {
            commentTextarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
        
        // Form validation
        document.getElementById('commentForm')?.addEventListener('submit', function(e) {
            const comment = document.getElementById('comment').value.trim();
            if (!comment) {
                e.preventDefault();
                alert('Please enter a comment');
                document.getElementById('comment').focus();
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';
            submitBtn.disabled = true;
        });
        
        // Download attachment with progress
        document.querySelectorAll('a[download]').forEach(link => {
            link.addEventListener('click', function(e) {
                const fileName = this.getAttribute('download') || this.href.split('/').pop();
                console.log('Downloading:', fileName);
                // You could add download tracking here
            });
        });
        
        // Auto-refresh page every 5 minutes if ticket is open
        <?php if ($ticket && in_array($ticket['status'], ['Open', 'In Progress', 'Waiting'])): ?>
        setTimeout(() => {
            window.location.reload();
        }, 300000); // 5 minutes
        <?php endif; ?>
    </script>
</body>
</html>