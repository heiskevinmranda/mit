<?php
// pages/tickets/view.php

// Include authentication
require_once '../../includes/auth.php';

// Check if user is logged in
requireLogin();

// Get current user
$current_user = getCurrentUser();
$pdo = getDBConnection();

$error = '';
$success = '';

// Get ticket ID from URL
$ticket_id = $_GET['id'] ?? null;
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Fetch ticket details
$ticket = [];
$attachments = [];
$logs = [];
$work_logs = [];
$assignees = [];
$client = [];
$location = [];
$created_by_user = [];
$total_work_hours = 0;
$total_logged_hours = 0;

try {
    // Get ticket details with related data
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.company_name, c.contact_person, c.email as client_email, c.phone as client_phone,
               c.address as client_address, c.city as client_city, c.state as client_state, c.country as client_country,
               cl.location_name, cl.address as location_address, cl.city as location_city,
               cl.state as location_state, cl.country as location_country,
               sp.full_name as assigned_to_name, sp.official_email as assigned_to_email,
               uc.email as created_by_email, uc.user_type as created_by_type,
               up.full_name as created_by_name
        FROM tickets t
        LEFT JOIN clients c ON t.client_id = c.id
        LEFT JOIN client_locations cl ON t.location_id = cl.id
        LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
        LEFT JOIN users uc ON t.created_by = uc.id
        LEFT JOIN staff_profiles up ON uc.id = up.user_id
        WHERE t.id = ?
    ");
    
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ticket) {
        throw new Exception("Ticket not found");
    }
    
    // Check if user has permission to view this ticket
    $is_creator = ($current_user['id'] == $ticket['created_by']);
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
    
    if (!$is_creator && !$is_admin_or_manager && !$is_assigned) {
        throw new Exception("You don't have permission to view this ticket");
    }
    
    // Get all assignees (multiple assignees)
    $stmt = $pdo->prepare("
        SELECT ta.*, sp.full_name, sp.official_email, sp.designation, sp.department
        FROM ticket_assignees ta
        LEFT JOIN staff_profiles sp ON ta.staff_id = sp.id
        WHERE ta.ticket_id = ?
        ORDER BY ta.is_primary DESC, ta.assigned_at ASC
    ");
    $stmt->execute([$ticket_id]);
    $assignees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attachments
    $stmt = $pdo->prepare("
        SELECT ta.*, sp.full_name as uploaded_by_name
        FROM ticket_attachments ta
        LEFT JOIN users u ON ta.uploaded_by = u.id
        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE ta.ticket_id = ? AND ta.is_deleted = false
        ORDER BY ta.upload_time DESC
    ");
    $stmt->execute([$ticket_id]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get ticket logs
    $stmt = $pdo->prepare("
        SELECT tl.*, sp.full_name as staff_name
        FROM ticket_logs tl
        LEFT JOIN staff_profiles sp ON tl.staff_id = sp.id
        WHERE tl.ticket_id = ?
        ORDER BY tl.created_at DESC
    ");
    $stmt->execute([$ticket_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get work logs
    $stmt = $pdo->prepare("
        SELECT wl.*, sp.full_name as staff_name
        FROM work_logs wl
        LEFT JOIN staff_profiles sp ON wl.staff_id = sp.id
        WHERE wl.ticket_id = ?
        ORDER BY wl.work_date ASC, wl.start_time ASC
    ");
    $stmt->execute([$ticket_id]);
    $work_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total logged hours
    $total_logged_hours = array_sum(array_column($work_logs, 'total_hours'));
    
} catch (Exception $e) {
    $error = $e->getMessage();
    $ticket = null;
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    if ($bytes === 0) return '0 Bytes';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Helper function to get priority badge class
function getPriorityBadge($priority) {
    $classes = [
        'Critical' => 'badge bg-danger',
        'High' => 'badge bg-warning text-dark',
        'Medium' => 'badge bg-info',
        'Low' => 'badge bg-secondary'
    ];
    return $classes[$priority] ?? 'badge bg-secondary';
}

// Helper function to get status badge class
function getStatusBadge($status) {
    $classes = [
        'Open' => 'badge bg-primary',
        'In Progress' => 'badge bg-info',
        'Waiting' => 'badge bg-warning text-dark',
        'Resolved' => 'badge bg-success',
        'Closed' => 'badge bg-secondary'
    ];
    return $classes[$status] ?? 'badge bg-secondary';
}

// Helper function to format time duration
function formatDuration($minutes) {
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    if ($remainingMinutes > 0) {
        return $hours . 'h ' . $remainingMinutes . 'm';
    }
    return $hours . 'h';
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
    <!-- Include jsPDF library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        .ticket-header {
            background: linear-gradient(135deg, #004E89 0%, #002D62 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .ticket-info-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            border-left: 4px solid #004E89;
        }
        
        .ticket-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #004E89;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }
        
        .info-value-large {
            font-size: 24px;
            font-weight: 700;
            color: #004E89;
        }
        
        /* Status and priority badges */
        .status-badge-large {
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Attachment styles */
        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .attachment-item:hover {
            background: #e8f4fd;
            border-color: #004E89;
            transform: translateY(-2px);
        }
        
        .attachment-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }
        
        .attachment-icon {
            font-size: 24px;
            color: #004E89;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .attachment-details {
            flex: 1;
        }
        
        .attachment-name {
            font-weight: 600;
            margin-bottom: 5px;
            word-break: break-all;
        }
        
        .attachment-meta {
            font-size: 12px;
            color: #666;
        }
        
        /* Log styles */
        .log-item {
            padding: 15px;
            border-left: 3px solid #dee2e6;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .log-item-success {
            border-left-color: #28a745;
            background: #f0fff4;
        }
        
        .log-item-warning {
            border-left-color: #ffc107;
            background: #fff9e6;
        }
        
        .log-item-danger {
            border-left-color: #dc3545;
            background: #fff0f0;
        }
        
        .log-item-info {
            border-left-color: #17a2b8;
            background: #e8f4f8;
        }
        
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .log-action {
            font-weight: 600;
            color: #333;
        }
        
        .log-time {
            font-size: 12px;
            color: #666;
        }
        
        .log-description {
            color: #555;
            margin-bottom: 5px;
        }
        
        .log-user {
            font-size: 12px;
            color: #777;
        }
        
        /* Work log styles */
        .work-log-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .work-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .work-log-date {
            font-weight: 600;
            color: #004E89;
        }
        
        .work-log-hours {
            background: #e8f4fd;
            color: #004E89;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .work-log-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 10px;
        }
        
        /* Assignee styles */
        .assignee-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .assignee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #004E89;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .assignee-info {
            flex: 1;
        }
        
        .assignee-name {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .assignee-role {
            font-size: 12px;
            color: #666;
        }
        
        .assignee-primary {
            background: #e8f4fd;
            border: 1px solid #004E89;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 25px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -25px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #004E89;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #dee2e6;
        }
        
        .timeline-dot.created { background: #28a745; }
        .timeline-dot.updated { background: #17a2b8; }
        .timeline-dot.assigned { background: #ffc107; }
        .timeline-dot.completed { background: #6c757d; }
        
        /* Progress bar */
        .progress-container {
            background: #f0f0f0;
            border-radius: 10px;
            height: 20px;
            margin: 15px 0;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            transition: width 0.3s ease;
        }
        
        /* Print styles */
        @media print {
            .sidebar, .btn-action, .no-print {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .ticket-header {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
                padding: 20px 0 !important;
            }
            
            .ticket-info-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .action-buttons {
                display: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .ticket-header {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                text-align: center;
            }
            
            .attachment-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .attachment-icon {
                margin-bottom: 10px;
            }
            
            .work-log-details {
                grid-template-columns: 1fr;
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
            <?php if ($error): ?>
            <!-- Error Message -->
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <!-- Success Message -->
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($ticket): ?>
            
            <!-- Ticket Header -->
            <div class="ticket-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2">
                            <i class="fas fa-ticket-alt"></i> Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?>
                        </h1>
                        <h2 class="h4 mb-3"><?php echo htmlspecialchars($ticket['title']); ?></h2>
                        <div class="d-flex flex-wrap gap-3 mb-3">
                            <span class="status-badge-large <?php echo str_replace('bg-', '', getStatusBadge($ticket['status'])); ?>">
                                <?php echo htmlspecialchars($ticket['status']); ?>
                            </span>
                            <span class="status-badge-large <?php echo str_replace('bg-', '', getPriorityBadge($ticket['priority'])); ?>">
                                <i class="fas fa-flag"></i> <?php echo htmlspecialchars($ticket['priority']); ?> Priority
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="action-buttons">
                            <button onclick="generatePDF()" class="btn btn-danger btn-action">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <button onclick="window.print()" class="btn btn-secondary btn-action">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <a href="index.php" class="btn btn-light btn-action">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ticket Information Grid -->
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Client</span>
                    <span class="info-value"><?php echo htmlspecialchars($ticket['company_name']); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Category</span>
                    <span class="info-value"><?php echo htmlspecialchars($ticket['category']); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Created</span>
                    <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Last Updated</span>
                    <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?></span>
                </div>
                
                <?php if ($ticket['work_start_time']): ?>
                <div class="info-item">
                    <span class="info-label">Scheduled Start</span>
                    <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['work_start_time'])); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($ticket['closed_at']): ?>
                <div class="info-item">
                    <span class="info-label">Closed</span>
                    <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['closed_at'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Two Column Layout -->
            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Description Section -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-align-left"></i> Description</h3>
                        <div class="ticket-info-card">
                            <p><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></p>
                        </div>
                    </div>
                    
                    <!-- Work Logs Section -->
                    <?php if (!empty($work_logs)): ?>
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-clock"></i> Work Logs</h3>
                        <div class="ticket-info-card">
                            <!-- Hours Summary -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <span class="info-label">Estimated Hours</span>
                                        <div class="info-value-large">
                                            <?php echo $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <span class="info-label">Logged Hours</span>
                                        <div class="info-value-large">
                                            <?php echo number_format($total_logged_hours, 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <span class="info-label">Remaining</span>
                                        <div class="info-value-large">
                                            <?php 
                                            $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
                                            echo number_format(max(0, $remaining), 2);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <?php if ($ticket['estimated_hours'] > 0): ?>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo min(100, ($total_logged_hours / $ticket['estimated_hours']) * 100); ?>%"></div>
                            </div>
                            <div class="text-center text-muted small mt-2">
                                <?php echo number_format(($total_logged_hours / $ticket['estimated_hours']) * 100, 1); ?>% Complete
                            </div>
                            <?php endif; ?>
                            
                            <!-- Work Log List -->
                            <div class="mt-4">
                                <?php foreach ($work_logs as $log): ?>
                                <div class="work-log-item">
                                    <div class="work-log-header">
                                        <div class="work-log-date">
                                            <i class="fas fa-calendar-day"></i> 
                                            <?php echo date('D, M j, Y', strtotime($log['work_date'])); ?>
                                        </div>
                                        <div class="work-log-hours">
                                            <?php echo number_format($log['total_hours'], 2); ?> hours
                                        </div>
                                    </div>
                                    
                                    <div class="work-log-details">
                                        <div>
                                            <span class="info-label">Start Time</span>
                                            <span class="info-value"><?php echo date('g:i A', strtotime($log['start_time'])); ?></span>
                                        </div>
                                        <div>
                                            <span class="info-label">End Time</span>
                                            <span class="info-value"><?php echo $log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing'; ?></span>
                                        </div>
                                        <div>
                                            <span class="info-label">Work Type</span>
                                            <span class="info-value"><?php echo htmlspecialchars($log['work_type']); ?></span>
                                        </div>
                                        <?php if ($log['staff_name']): ?>
                                        <div>
                                            <span class="info-label">Staff</span>
                                            <span class="info-value"><?php echo htmlspecialchars($log['staff_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <span class="info-label">Description</span>
                                        <p class="mt-1"><?php echo nl2br(htmlspecialchars($log['description'])); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Activity Logs Section -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-history"></i> Activity Log</h3>
                        <div class="ticket-info-card">
                            <div class="timeline">
                                <?php foreach ($logs as $log): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot 
                                        <?php 
                                        if (strpos($log['action'], 'Created') !== false) echo 'created';
                                        elseif (strpos($log['action'], 'Updated') !== false) echo 'updated';
                                        elseif (strpos($log['action'], 'Assigned') !== false) echo 'assigned';
                                        elseif (strpos($log['action'], 'Resolved') !== false || strpos($log['action'], 'Closed') !== false) echo 'completed';
                                        ?>">
                                    </div>
                                    
                                    <div class="log-item 
                                        <?php 
                                        if (strpos($log['action'], 'Created') !== false) echo 'log-item-success';
                                        elseif (strpos($log['action'], 'Updated') !== false) echo 'log-item-info';
                                        elseif (strpos($log['action'], 'Assigned') !== false) echo 'log-item-warning';
                                        elseif (strpos($log['action'], 'Resolved') !== false || strpos($log['action'], 'Closed') !== false) echo 'log-item-success';
                                        ?>">
                                        <div class="log-header">
                                            <span class="log-action">
                                                <i class="fas fa-circle"></i> <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                            <span class="log-time">
                                                <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                            </span>
                                        </div>
                                        <div class="log-description">
                                            <?php echo htmlspecialchars($log['description']); ?>
                                        </div>
                                        <?php if ($log['staff_name']): ?>
                                        <div class="log-user">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($log['staff_name']); ?>
                                            <?php if ($log['time_spent_minutes']): ?>
                                            • Time spent: <?php echo formatDuration($log['time_spent_minutes']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Assignees Section -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-users"></i> Assigned Staff</h3>
                        <div class="ticket-info-card">
                            <?php if (!empty($assignees)): ?>
                                <?php foreach ($assignees as $assignee): ?>
                                <div class="assignee-item <?php echo $assignee['is_primary'] ? 'assignee-primary' : ''; ?>">
                                    <div class="assignee-avatar">
                                        <?php echo strtoupper(substr($assignee['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="assignee-info">
                                        <div class="assignee-name">
                                            <?php echo htmlspecialchars($assignee['full_name']); ?>
                                            <?php if ($assignee['is_primary']): ?>
                                            <span class="badge bg-primary ms-2">Primary</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($assignee['designation']): ?>
                                        <div class="assignee-role"><?php echo htmlspecialchars($assignee['designation']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($assignee['department']): ?>
                                        <div class="assignee-role"><?php echo htmlspecialchars($assignee['department']); ?></div>
                                        <?php endif; ?>
                                        <div class="assignee-role">
                                            Assigned: <?php echo date('M j, Y', strtotime($assignee['assigned_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No staff assigned to this ticket</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Location Information -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-map-marker-alt"></i> Location</h3>
                        <div class="ticket-info-card">
                            <?php if ($ticket['location_manual']): ?>
                                <div class="mb-3">
                                    <span class="info-label">Manual Location</span>
                                    <p class="info-value"><?php echo htmlspecialchars($ticket['location_manual']); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['location_name']): ?>
                                <div class="mb-3">
                                    <span class="info-label">Location Name</span>
                                    <p class="info-value"><?php echo htmlspecialchars($ticket['location_name']); ?></p>
                                </div>
                                
                                <?php if ($ticket['location_address']): ?>
                                <div class="mb-3">
                                    <span class="info-label">Address</span>
                                    <p class="info-value"><?php echo nl2br(htmlspecialchars($ticket['location_address'])); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div class="row">
                                    <?php if ($ticket['location_city']): ?>
                                    <div class="col-6">
                                        <span class="info-label">City</span>
                                        <p class="info-value"><?php echo htmlspecialchars($ticket['location_city']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($ticket['location_state']): ?>
                                    <div class="col-6">
                                        <span class="info-label">State</span>
                                        <p class="info-value"><?php echo htmlspecialchars($ticket['location_state']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($ticket['location_country']): ?>
                                <div class="mb-3">
                                    <span class="info-label">Country</span>
                                    <p class="info-value"><?php echo htmlspecialchars($ticket['location_country']); ?></p>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-3">No location specified</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Client Information -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-building"></i> Client Details</h3>
                        <div class="ticket-info-card">
                            <div class="mb-3">
                                <span class="info-label">Company</span>
                                <p class="info-value"><?php echo htmlspecialchars($ticket['company_name']); ?></p>
                            </div>
                            
                            <?php if ($ticket['contact_person']): ?>
                            <div class="mb-3">
                                <span class="info-label">Contact Person</span>
                                <p class="info-value"><?php echo htmlspecialchars($ticket['contact_person']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ticket['client_address']): ?>
                            <div class="mb-3">
                                <span class="info-label">Address</span>
                                <p class="info-value"><?php echo nl2br(htmlspecialchars($ticket['client_address'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <?php if ($ticket['client_city'] || $ticket['client_state']): ?>
                                <div class="col-12 mb-3">
                                    <span class="info-label">City/State</span>
                                    <p class="info-value">
                                        <?php 
                                        echo ($ticket['client_city'] ? htmlspecialchars($ticket['client_city']) : '');
                                        echo ($ticket['client_city'] && $ticket['client_state']) ? ', ' : '';
                                        echo ($ticket['client_state'] ? htmlspecialchars($ticket['client_state']) : '');
                                        ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($ticket['client_email']): ?>
                                <div class="col-12 mb-3">
                                    <span class="info-label">Email</span>
                                    <p class="info-value">
                                        <?php echo htmlspecialchars($ticket['client_email']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($ticket['client_phone']): ?>
                                <div class="col-12">
                                    <span class="info-label">Phone</span>
                                    <p class="info-value">
                                        <?php echo htmlspecialchars($ticket['client_phone']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attachments Section -->
                    <div class="ticket-section">
                        <h3 class="section-title"><i class="fas fa-paperclip"></i> Attachments</h3>
                        <div class="ticket-info-card">
                            <?php if (!empty($attachments)): ?>
                                <?php foreach ($attachments as $attachment): ?>
                                <div class="attachment-item">
                                    <div class="attachment-info">
                                        <div class="attachment-icon">
                                            <?php 
                                            $extension = pathinfo($attachment['original_filename'], PATHINFO_EXTENSION);
                                            if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
                                                echo '<i class="fas fa-image"></i>';
                                            } elseif (strtolower($extension) === 'pdf') {
                                                echo '<i class="fas fa-file-pdf"></i>';
                                            } elseif (in_array(strtolower($extension), ['doc', 'docx'])) {
                                                echo '<i class="fas fa-file-word"></i>';
                                            } elseif (in_array(strtolower($extension), ['xls', 'xlsx'])) {
                                                echo '<i class="fas fa-file-excel"></i>';
                                            } else {
                                                echo '<i class="fas fa-file"></i>';
                                            }
                                            ?>
                                        </div>
                                        <div class="attachment-details">
                                            <div class="attachment-name">
                                                <?php echo htmlspecialchars($attachment['original_filename']); ?>
                                            </div>
                                            <div class="attachment-meta">
                                                <?php echo formatBytes($attachment['file_size']); ?> • 
                                                <?php echo date('M j, Y', strtotime($attachment['upload_time'])); ?>
                                                <?php if ($attachment['uploaded_by_name']): ?>
                                                • <?php echo htmlspecialchars($attachment['uploaded_by_name']); ?>
                                                <?php endif; ?>
                                            </div>
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
                                <p class="text-muted text-center py-3">No attachments</p>
                            <?php endif; ?>
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
    
    <!-- Modal for selecting PDF report type -->
    <div class="modal fade" id="pdfReportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generate PDF Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reportType" class="form-label">Report Type</label>
                        <select class="form-select" id="reportType">
                            <option value="summary">Summary Report (Compact)</option>
                            <option value="detailed" selected>Detailed Report (Full Details)</option>
                            <option value="work_logs">Work Logs Report</option>
                            <option value="activity">Activity Timeline Report</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reportOrientation" class="form-label">Page Orientation</label>
                        <select class="form-select" id="reportOrientation">
                            <option value="portrait">Portrait</option>
                            <option value="landscape">Landscape</option>
                        </select>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="includeAttachments" checked>
                        <label class="form-check-label" for="includeAttachments">
                            Include attachments list
                        </label>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="includeClientInfo" checked>
                        <label class="form-check-label" for="includeClientInfo">
                            Include client information
                        </label>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <small>The PDF will include all visible information from this page.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="createPDFReport()">
                        <i class="fas fa-file-pdf"></i> Generate PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show PDF report modal
        function generatePDF() {
            const modal = new bootstrap.Modal(document.getElementById('pdfReportModal'));
            modal.show();
        }
        
        // Create PDF report based on selected options
        async function createPDFReport() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: document.getElementById('reportOrientation').value,
                unit: 'mm',
                format: 'a4'
            });
            
            const reportType = document.getElementById('reportType').value;
            const includeAttachments = document.getElementById('includeAttachments').checked;
            const includeClientInfo = document.getElementById('includeClientInfo').checked;
            
            // Show loading message
            const modal = bootstrap.Modal.getInstance(document.getElementById('pdfReportModal'));
            modal.hide();
            
            // Add loading indicator
            document.body.insertAdjacentHTML('beforeend', 
                '<div id="pdfLoading" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:white;padding:30px;border-radius:10px;text-align:center;">' +
                '<i class="fas fa-spinner fa-spin fa-2x mb-3" style="color:#004E89;"></i>' +
                '<h5>Generating PDF Report...</h5>' +
                '<p>This may take a few moments</p>' +
                '</div></div>'
            );
            
            try {
                // Set document properties
                doc.setProperties({
                    title: 'Ticket Report - ' + "<?php echo $ticket['ticket_number']; ?>",
                    subject: 'Ticket Details',
                    author: 'MSP Application',
                    keywords: 'ticket, report, service',
                    creator: 'MSP Application'
                });
                
                // Add header
                doc.setFontSize(20);
                doc.setTextColor(0, 78, 137);
                doc.text('TICKET REPORT', 105, 20, { align: 'center' });
                
                doc.setFontSize(12);
                doc.setTextColor(100, 100, 100);
                doc.text('Ticket #' + "<?php echo $ticket['ticket_number']; ?>", 105, 28, { align: 'center' });
                
                doc.setFontSize(10);
                doc.text('Generated on: ' + new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString(), 105, 33, { align: 'center' });
                
                let yPosition = 45;
                
                // Add ticket summary section
                doc.setFontSize(14);
                doc.setTextColor(0, 78, 137);
                doc.text('TICKET SUMMARY', 14, yPosition);
                
                yPosition += 8;
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                
                // Ticket details table
                const ticketData = [
                    ['Ticket Number:', "<?php echo $ticket['ticket_number']; ?>"],
                    ['Title:', "<?php echo addslashes($ticket['title']); ?>"],
                    ['Status:', "<?php echo $ticket['status']; ?>"],
                    ['Priority:', "<?php echo $ticket['priority']; ?>"],
                    ['Category:', "<?php echo $ticket['category']; ?>"],
                    ['Created:', "<?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>"],
                    ['Last Updated:', "<?php echo date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?>"],
                    <?php if ($ticket['work_start_time']): ?>
                    ['Scheduled Start:', "<?php echo date('M j, Y g:i A', strtotime($ticket['work_start_time'])); ?>"],
                    <?php endif; ?>
                    <?php if ($ticket['closed_at']): ?>
                    ['Closed:', "<?php echo date('M j, Y g:i A', strtotime($ticket['closed_at'])); ?>"],
                    <?php endif; ?>
                ];
                
                ticketData.forEach(([label, value]) => {
                    doc.setFont('helvetica', 'bold');
                    doc.text(label, 20, yPosition);
                    doc.setFont('helvetica', 'normal');
                    const lines = doc.splitTextToSize(value, 120);
                    doc.text(lines, 60, yPosition);
                    yPosition += lines.length * 5 + 3;
                });
                
                // Add client information if selected
                if (includeClientInfo) {
                    yPosition += 5;
                    doc.setFontSize(14);
                    doc.setTextColor(0, 78, 137);
                    doc.text('CLIENT INFORMATION', 14, yPosition);
                    
                    yPosition += 8;
                    doc.setFontSize(10);
                    doc.setTextColor(0, 0, 0);
                    
                    const clientData = [
                        ['Company:', "<?php echo addslashes($ticket['company_name']); ?>"],
                        <?php if ($ticket['contact_person']): ?>
                        ['Contact Person:', "<?php echo addslashes($ticket['contact_person']); ?>"],
                        <?php endif; ?>
                        <?php if ($ticket['client_address']): ?>
                        ['Address:', "<?php echo addslashes($ticket['client_address']); ?>"],
                        <?php endif; ?>
                        <?php if ($ticket['client_email']): ?>
                        ['Email:', "<?php echo $ticket['client_email']; ?>"],
                        <?php endif; ?>
                        <?php if ($ticket['client_phone']): ?>
                        ['Phone:', "<?php echo $ticket['client_phone']; ?>"],
                        <?php endif; ?>
                    ];
                    
                    clientData.forEach(([label, value]) => {
                        doc.setFont('helvetica', 'bold');
                        doc.text(label, 20, yPosition);
                        doc.setFont('helvetica', 'normal');
                        const lines = doc.splitTextToSize(value, 120);
                        doc.text(lines, 60, yPosition);
                        yPosition += lines.length * 5 + 3;
                    });
                }
                
                // Add description
                yPosition += 5;
                doc.setFontSize(14);
                doc.setTextColor(0, 78, 137);
                doc.text('DESCRIPTION', 14, yPosition);
                
                yPosition += 8;
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                const descriptionLines = doc.splitTextToSize("<?php echo addslashes($ticket['description']); ?>", 180);
                descriptionLines.forEach(line => {
                    if (yPosition > 270) {
                        doc.addPage();
                        yPosition = 20;
                    }
                    doc.text(line, 20, yPosition);
                    yPosition += 5;
                });
                
                // Add assignees section
                yPosition += 5;
                doc.setFontSize(14);
                doc.setTextColor(0, 78, 137);
                doc.text('ASSIGNED STAFF', 14, yPosition);
                
                yPosition += 8;
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                
                <?php if (!empty($assignees)): ?>
                    <?php foreach ($assignees as $assignee): ?>
                    if (yPosition > 270) {
                        doc.addPage();
                        yPosition = 20;
                    }
                    doc.setFont('helvetica', 'bold');
                    doc.text('• ' + "<?php echo addslashes($assignee['full_name']); ?>" + 
                        ("<?php echo $assignee['is_primary']; ?>" == '1' ? ' (Primary)' : ''), 20, yPosition);
                    yPosition += 5;
                    
                    doc.setFont('helvetica', 'normal');
                    <?php if ($assignee['designation']): ?>
                    doc.text('  Designation: ' + "<?php echo addslashes($assignee['designation']); ?>", 25, yPosition);
                    yPosition += 5;
                    <?php endif; ?>
                    
                    <?php if ($assignee['department']): ?>
                    doc.text('  Department: ' + "<?php echo addslashes($assignee['department']); ?>", 25, yPosition);
                    yPosition += 5;
                    <?php endif; ?>
                    
                    doc.text('  Assigned: ' + "<?php echo date('M j, Y', strtotime($assignee['assigned_at'])); ?>", 25, yPosition);
                    yPosition += 8;
                    <?php endforeach; ?>
                <?php else: ?>
                    doc.text('No staff assigned', 20, yPosition);
                    yPosition += 5;
                <?php endif; ?>
                
                // Add work logs section
                <?php if (!empty($work_logs)): ?>
                yPosition += 5;
                doc.setFontSize(14);
                doc.setTextColor(0, 78, 137);
                doc.text('WORK LOGS', 14, yPosition);
                
                yPosition += 8;
                
                // Hours summary
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                doc.text('Total Estimated Hours: ' + ("<?php echo $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00'; ?>"), 20, yPosition);
                yPosition += 5;
                doc.text('Total Logged Hours: ' + ("<?php echo number_format($total_logged_hours, 2); ?>"), 20, yPosition);
                yPosition += 5;
                
                <?php 
                $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
                $completion = $ticket['estimated_hours'] > 0 ? ($total_logged_hours / $ticket['estimated_hours']) * 100 : 0;
                ?>
                doc.text('Remaining Hours: ' + ("<?php echo number_format(max(0, $remaining), 2); ?>"), 20, yPosition);
                yPosition += 5;
                doc.text('Completion: ' + ("<?php echo number_format($completion, 1); ?>") + '%', 20, yPosition);
                yPosition += 8;
                
                <?php foreach ($work_logs as $log): ?>
                if (yPosition > 250) {
                    doc.addPage();
                    yPosition = 20;
                }
                
                doc.setFont('helvetica', 'bold');
                doc.text('Date: ' + "<?php echo date('D, M j, Y', strtotime($log['work_date'])); ?>", 20, yPosition);
                yPosition += 5;
                
                doc.setFont('helvetica', 'normal');
                doc.text('Time: ' + "<?php echo date('g:i A', strtotime($log['start_time'])); ?>" + 
                    ' to ' + ("<?php echo $log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing'; ?>") + 
                    ' (' + "<?php echo number_format($log['total_hours'], 2); ?>" + ' hours)', 20, yPosition);
                yPosition += 5;
                
                <?php if ($log['staff_name']): ?>
                doc.text('Staff: ' + "<?php echo addslashes($log['staff_name']); ?>", 20, yPosition);
                yPosition += 5;
                <?php endif; ?>
                
                const workDescLines = doc.splitTextToSize("<?php echo addslashes($log['description']); ?>", 170);
                workDescLines.forEach(line => {
                    doc.text(line, 20, yPosition);
                    yPosition += 5;
                });
                
                yPosition += 5;
                <?php endforeach; ?>
                <?php endif; ?>
                
                // Add activity log
                <?php if (!empty($logs)): ?>
                yPosition += 5;
                if (yPosition > 250) {
                    doc.addPage();
                    yPosition = 20;
                }
                
                doc.setFontSize(14);
                doc.setTextColor(0, 78, 137);
                doc.text('ACTIVITY TIMELINE', 14, yPosition);
                
                yPosition += 8;
                doc.setFontSize(10);
                doc.setTextColor(0, 0, 0);
                
                <?php foreach ($logs as $log): ?>
                if (yPosition > 270) {
                    doc.addPage();
                    yPosition = 20;
                }
                
                doc.setFont('helvetica', 'bold');
                doc.text("<?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>", 20, yPosition);
                yPosition += 5;
                
                doc.setFont('helvetica', 'normal');
                doc.text("<?php echo addslashes($log['action']); ?>", 25, yPosition);
                yPosition += 5;
                
                const activityLines = doc.splitTextToSize("<?php echo addslashes($log['description']); ?>", 165);
                activityLines.forEach(line => {
                    doc.text(line, 25, yPosition);
                    yPosition += 5;
                });
                
                <?php if ($log['staff_name']): ?>
                doc.text('By: ' + "<?php echo addslashes($log['staff_name']); ?>", 25, yPosition);
                yPosition += 5;
                <?php endif; ?>
                
                yPosition += 5;
                <?php endforeach; ?>
                <?php endif; ?>
                
                // Add attachments if selected
                if (includeAttachments && <?php echo !empty($attachments) ? 'true' : 'false'; ?>) {
                    if (yPosition > 250) {
                        doc.addPage();
                        yPosition = 20;
                    }
                    
                    doc.setFontSize(14);
                    doc.setTextColor(0, 78, 137);
                    doc.text('ATTACHMENTS', 14, yPosition);
                    
                    yPosition += 8;
                    doc.setFontSize(10);
                    doc.setTextColor(0, 0, 0);
                    
                    <?php foreach ($attachments as $attachment): ?>
                    if (yPosition > 270) {
                        doc.addPage();
                        yPosition = 20;
                    }
                    
                    doc.text('• ' + "<?php echo addslashes($attachment['original_filename']); ?>", 20, yPosition);
                    yPosition += 5;
                    
                    doc.text('  Size: ' + "<?php echo formatBytes($attachment['file_size']); ?>" + 
                        ' | Uploaded: ' + "<?php echo date('M j, Y', strtotime($attachment['upload_time'])); ?>", 25, yPosition);
                    yPosition += 8;
                    <?php endforeach; ?>
                }
                
                // Add footer with page numbers
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.setTextColor(150, 150, 150);
                    doc.text('Page ' + i + ' of ' + pageCount, 105, 287, { align: 'center' });
                    doc.text('Confidential - MSP Ticket Report', 195, 287, { align: 'right' });
                }
                
                // Save the PDF
                doc.save('Ticket_<?php echo $ticket['ticket_number']; ?>_Report_' + new Date().toISOString().slice(0,10) + '.pdf');
                
            } catch (error) {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF report: ' + error.message);
            } finally {
                // Remove loading indicator
                const loadingDiv = document.getElementById('pdfLoading');
                if (loadingDiv) {
                    loadingDiv.remove();
                }
            }
        }
        
        // Copy ticket number to clipboard
        function copyTicketNumber() {
            const ticketNumber = "<?php echo $ticket['ticket_number']; ?>";
            navigator.clipboard.writeText(ticketNumber).then(() => {
                alert('Ticket number copied to clipboard: ' + ticketNumber);
            });
        }
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltips.forEach(tooltip => {
                new bootstrap.Tooltip(tooltip);
            });
        });
    </script>
</body>
</html>