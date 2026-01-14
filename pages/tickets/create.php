<?php
// pages/tickets/create.php

// Include authentication
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';

// Check if user is logged in
requireLogin();

// Get current user
$current_user = getCurrentUser();
$pdo = getDBConnection();

$error = '';
$success = '';

// Configuration for file uploads
$MAX_FILE_SIZE = 200 * 1024 * 1024; // 200MB in bytes
$MAX_TOTAL_SIZE = 500 * 1024 * 1024; // 500MB total limit
$ALLOWED_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'rtf', 'md',
    'zip', 'rar', '7z', 'tar', 'gz',
    'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv',
    'mp3', 'wav', 'ogg',
    'log', 'config', 'ini', 'xml', 'json', 'yaml', 'yml',
    'sql', 'dump', 'pcap', 'cap',
];

// Get form data (for repopulating on error)
$form_data = [
    'title' => $_POST['title'] ?? '',
    'description' => $_POST['description'] ?? '',
    'client_id' => $_POST['client_id'] ?? '',
    'location_id' => $_POST['location_id'] ?? '',
    'location_manual' => $_POST['location_manual'] ?? '',
    'category' => $_POST['category'] ?? 'General',
    'priority' => $_POST['priority'] ?? 'Medium',
    'assigned_to' => $_POST['assigned_to'] ?? [],
    'estimated_hours' => $_POST['estimated_hours'] ?? '',
    'work_start_time' => $_POST['work_start_time'] ?? '',
    'work_pattern' => $_POST['work_pattern'] ?? 'single',
    'expected_days' => $_POST['expected_days'] ?? 1,
    'work_days' => $_POST['work_days'] ?? [['date' => date('Y-m-d'), 'start' => '09:00', 'end' => '17:00', 'desc' => '', 'staff_id' => '']],
    'requested_by' => $_POST['requested_by'] ?? '',
    'requested_by_email' => $_POST['requested_by_email'] ?? '',
    'csr_sn' => $_POST['csr_sn'] ?? ''
];

// Get clients for dropdown
$clients = [];
$staff_members = [];
$client_locations = [];

try {
    // Get all clients - all users can create tickets for any client
    $stmt = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get staff members for assignment - prioritize those with complete profiles
    $stmt = $pdo->query("
        SELECT 
            COALESCE(sp.id, u.id) as id,
            CASE 
                WHEN sp.full_name IS NOT NULL AND sp.full_name != '' THEN sp.full_name
                ELSE CONCAT(u.email, ' (Profile Incomplete)')
            END as full_name,
            CASE WHEN sp.full_name IS NULL OR sp.full_name = '' THEN 1 ELSE 0 END as needs_profile
        FROM users u
        LEFT JOIN staff_profiles sp ON sp.user_id = u.id
        WHERE u.user_type IN ('super_admin', 'admin', 'manager', 'support_tech', 'staff', 'engineer')
          AND u.is_active = true
          AND (sp.employment_status = 'Active' OR sp.id IS NULL)
        ORDER BY needs_profile ASC, full_name ASC
    ");
    $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all locations
    $stmt = $pdo->query("SELECT id, client_id, location_name FROM client_locations ORDER BY location_name");
    $client_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error loading data: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['title', 'client_id'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Please fill in all required fields");
            }
        }
        
        // Validate location (either dropdown or manual)
        if (empty($_POST['location_id']) && empty($_POST['location_manual'])) {
            throw new Exception("Please either select a location or enter one manually");
        }
        
        // Generate ticket number
        $ticket_number = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Get creator ID
        $created_by = $current_user['id'];
        
        // Prepare ticket data
        $ticket_data = [
            'ticket_number' => $ticket_number,
            'title' => $_POST['title'],
            'description' => $_POST['description'] ?? '',
            'client_id' => $_POST['client_id'],
            'location_id' => !empty($_POST['location_id']) ? $_POST['location_id'] : null,
            'location_manual' => !empty($_POST['location_manual']) ? $_POST['location_manual'] : null,
            'category' => $_POST['category'] ?? 'General',
            'priority' => $_POST['priority'],
            'status' => 'Open',
            'created_by' => $created_by,
            'estimated_hours' => !empty($_POST['estimated_hours']) ? $_POST['estimated_hours'] : null,
            'work_start_time' => !empty($_POST['work_start_time']) ? $_POST['work_start_time'] : null,
            'requested_by' => !empty($_POST['requested_by']) ? $_POST['requested_by'] : null,
            'requested_by_email' => !empty($_POST['requested_by_email']) ? $_POST['requested_by_email'] : null,
            'csr_sn' => !empty($_POST['csr_sn']) ? $_POST['csr_sn'] : null
        ];
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Insert ticket and RETURN the UUID
        $stmt = $pdo->prepare(
            "INSERT INTO tickets (
                ticket_number, title, description, client_id, location_id, location_manual,
                category, priority, status, created_by, estimated_hours, work_start_time,
                requested_by, requested_by_email, csr_sn
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id"
        );
        
        $stmt->execute([
            $ticket_data['ticket_number'],
            $ticket_data['title'],
            $ticket_data['description'],
            $ticket_data['client_id'],
            $ticket_data['location_id'],
            $ticket_data['location_manual'],
            $ticket_data['category'],
            $ticket_data['priority'],
            $ticket_data['status'],
            $ticket_data['created_by'],
            $ticket_data['estimated_hours'],
            $ticket_data['work_start_time'],
                        $ticket_data['requested_by'],
                        $ticket_data['requested_by_email'],
                        $ticket_data['csr_sn']
        ]);
        
        // Get the UUID from the RETURNING clause
        $ticket_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket_result || !isset($ticket_result['id'])) {
            throw new Exception("Failed to create ticket");
        }
        $ticket_id = $ticket_result['id'];
        
        // Handle multiple assignees
        $assigned_staff = [];
        if (!empty($_POST['assigned_to']) && is_array($_POST['assigned_to'])) {
            try {
                $primary_set = false;
                foreach ($_POST['assigned_to'] as $index => $staff_id) {
                    if (!empty($staff_id)) {
                        $is_primary = (!$primary_set) ? 1 : 0; // First one is primary
                        $primary_set = true;
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO ticket_assignees (ticket_id, staff_id, assigned_at, assigned_by, is_primary)
                            VALUES (?, ?, NOW(), ?, ?)
                        ");
                        
                        // Execute with error handling
                        if (!$stmt->execute([$ticket_id, $staff_id, $created_by, $is_primary])) {
                            error_log("Failed to insert into ticket_assignees: " . implode(', ', $stmt->errorInfo()));
                            // Continue with other staff members instead of stopping
                            continue;
                        }
                        
                        // Also update the ticket with primary assignee
                        if ($is_primary) {
                            $stmt = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
                            $stmt->execute([$staff_id, $ticket_id]);
                        }
                        
                        $assigned_staff[] = $staff_id;
                        
                        // Log assignment (handle potential permission issues here too)
                        try {
                            $staff_stmt = $pdo->prepare("SELECT full_name FROM staff_profiles WHERE id = ?");
                            $staff_stmt->execute([$staff_id]);
                            $staff_name = $staff_stmt->fetchColumn();
                            
                            $log_stmt = $pdo->prepare("
                                INSERT INTO ticket_logs (ticket_id, staff_id, action, description) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $log_stmt->execute([
                                $ticket_id,
                                $staff_id,
                                'Assigned',
                                "Ticket assigned to " . ($staff_name ?: 'staff') . ($is_primary ? ' (Primary)' : '')
                            ]);
                        } catch (Exception $log_error) {
                            error_log("Failed to log assignment: " . $log_error->getMessage());
                            // Continue even if logging fails
                        }
                    }
                }
                
                // If no assignees could be added due to permissions, at least update the ticket
                if (empty($assigned_staff)) {
                    error_log("No assignees could be added due to permission issues");
                }
                
            } catch (Exception $assign_error) {
                // Log the error but don't stop ticket creation
                error_log("Error assigning staff: " . $assign_error->getMessage());
                // Continue with ticket creation even if assignment fails
            }
        }
        
        // Handle multi-day work logs
        $total_work_hours = 0;
        if ($_POST['work_pattern'] === 'multi' && !empty($_POST['work_days'])) {
            foreach ($_POST['work_days'] as $index => $day) {
                if (!empty($day['date']) && !empty($day['start']) && !empty($day['end']) && !empty($day['desc'])) {
                    // Calculate hours for this work day
                    $start_time = $day['start'];
                    $end_time = $day['end'];
                    $work_date = $day['date'];
                    
                    // Parse times and calculate hours
                    $start = DateTime::createFromFormat('H:i', $start_time);
                    $end = DateTime::createFromFormat('H:i', $end_time);
                    
                    // Handle overnight shifts
                    if ($end < $start) {
                        $end->modify('+1 day');
                    }
                    
                    $interval = $start->diff($end);
                    $day_hours = $interval->h + ($interval->i / 60);
                    $total_work_hours += $day_hours;
                    
                    // Use assigned staff for this day or default to primary assignee
                    $day_staff_id = !empty($day['staff_id']) ? $day['staff_id'] : 
                                   (!empty($assigned_staff[0]) ? $assigned_staff[0] : null);
                    
                    try {
                        // Insert work log
                        $stmt = $pdo->prepare("
                            INSERT INTO work_logs (ticket_id, staff_id, work_date, start_time, end_time, total_hours, description, work_type)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $ticket_id,
                            $day_staff_id,
                            $work_date,
                            $start_time,
                            $end_time,
                            $day_hours,
                            $day['desc'],
                            'Regular'
                        ]);
                        
                        // Log work entry
                        $log_stmt = $pdo->prepare("
                            INSERT INTO ticket_logs (ticket_id, staff_id, action, description) 
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        $staff_name = 'System';
                        if ($day_staff_id) {
                            $staff_stmt = $pdo->prepare("SELECT full_name FROM staff_profiles WHERE id = ?");
                            $staff_stmt->execute([$day_staff_id]);
                            $staff_name = $staff_stmt->fetchColumn() ?: 'Staff';
                        }
                        
                        $log_stmt->execute([
                            $ticket_id,
                            $day_staff_id,
                            'Work Logged',
                            "Work logged for Day " . ($index + 1) . " by " . $staff_name . " (" . number_format($day_hours, 2) . " hours)"
                        ]);
                    } catch (Exception $work_log_error) {
                        error_log("Failed to insert work log: " . $work_log_error->getMessage());
                        // Continue with other work days
                        continue;
                    }
                }
            }
            
            // Update ticket with total work hours
            if ($total_work_hours > 0) {
                try {
                    $stmt = $pdo->prepare("UPDATE tickets SET total_work_hours = ? WHERE id = ?");
                    $stmt->execute([$total_work_hours, $ticket_id]);
                } catch (Exception $update_error) {
                    error_log("Failed to update total work hours: " . $update_error->getMessage());
                }
            }
        }
        
        // Handle file uploads
        $uploaded_files = [];
        $total_upload_size = 0;
        
        if (!empty($_FILES['attachments']['name'][0])) {
            // Create uploads directory if it doesn't exist
            $upload_dir = '../../uploads/tickets/' . $ticket_id . '/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Add .htaccess to protect uploads
            $htaccess_content = "Order Deny,Allow\nDeny from all\n<FilesMatch \"\.(jpg|jpeg|png|gif|pdf|doc|docx|xls|xlsx)$\">\nAllow from all\n</FilesMatch>\n";
            file_put_contents($upload_dir . '.htaccess', $htaccess_content);
            
            // Process each file
            $file_count = count($_FILES['attachments']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['attachments']['name'][$i];
                    $file_tmp = $_FILES['attachments']['tmp_name'][$i];
                    $file_size = $_FILES['attachments']['size'][$i];
                    $file_type = $_FILES['attachments']['type'][$i];
                    
                    // Check total size
                    $total_upload_size += $file_size;
                    if ($total_upload_size > $MAX_TOTAL_SIZE) {
                        throw new Exception("Total upload size exceeds " . formatBytes($MAX_TOTAL_SIZE));
                    }
                    
                    // Validate file size
                    if ($file_size > $MAX_FILE_SIZE) {
                        throw new Exception("File '{$file_name}' exceeds maximum size of " . formatBytes($MAX_FILE_SIZE));
                    }
                    
                    // Get file extension
                    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                    $file_ext_lower = strtolower($file_ext);
                    
                    // Validate file type
                    if (!in_array($file_ext_lower, $ALLOWED_EXTENSIONS)) {
                        throw new Exception("File type '{$file_ext}' is not allowed for '{$file_name}'. Allowed: " . implode(', ', $ALLOWED_EXTENSIONS));
                    }
                    
                    // Security check: validate MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file_tmp);
                    finfo_close($finfo);
                    
                    // Generate unique filename
                    $unique_name = uniqid() . '_' . time() . '.' . $file_ext_lower;
                    $file_path = $upload_dir . $unique_name;
                    
                    // Move uploaded file
                    if (!move_uploaded_file($file_tmp, $file_path)) {
                        throw new Exception("Failed to upload file: {$file_name}. Please check directory permissions.");
                    }
                    
                    // Save file info to database
                    $stmt = $pdo->prepare("
                        INSERT INTO ticket_attachments 
                        (ticket_id, original_filename, stored_filename, file_path, file_type, file_size, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $ticket_id,
                        $file_name,
                        $unique_name,
                        $unique_name,
                        $mime_type,
                        $file_size,
                        $created_by
                    ]);
                    
                    $uploaded_files[] = [
                        'original_name' => $file_name,
                        'saved_name' => $unique_name,
                        'file_path' => $file_path,
                        'file_type' => $mime_type,
                        'file_size' => $file_size
                    ];
                    
                    // Log file upload
                    try {
                        $log_stmt = $pdo->prepare("
                            INSERT INTO ticket_logs (ticket_id, staff_id, action, description) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $log_stmt->execute([
                            $ticket_id,
                            $current_user['staff_profile']['id'] ?? null,
                            'File Uploaded',
                            "File uploaded: " . $file_name . " (" . formatBytes($file_size) . ")"
                        ]);
                    } catch (Exception $log_error) {
                        error_log("Failed to log file upload: " . $log_error->getMessage());
                        // Continue even if logging fails
                    }
                } elseif ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    // Handle upload errors - FIXED: Get file name from $_FILES array
                    $current_file_name = $_FILES['attachments']['name'][$i] ?? 'Unknown file';
                    
                    $upload_errors = [
                        UPLOAD_ERR_INI_SIZE => "File '{$current_file_name}' exceeds php.ini upload_max_filesize",
                        UPLOAD_ERR_FORM_SIZE => "File '{$current_file_name}' exceeds form MAX_FILE_SIZE",
                        UPLOAD_ERR_PARTIAL => "File '{$current_file_name}' was only partially uploaded",
                        UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
                        UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
                        UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload"
                    ];
                    
                    $error_msg = $upload_errors[$_FILES['attachments']['error'][$i]] ?? "Unknown upload error";
                    throw new Exception($error_msg);
                }
            }
        }
        
        // Create initial ticket log
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_logs (ticket_id, staff_id, action, description) 
                VALUES (?, ?, ?, ?)
            ");
            
            $staff_id = $current_user['staff_profile']['id'] ?? null;
            $action = 'Ticket Created';
            $description = "Ticket #{$ticket_number} created by " . ($current_user['staff_profile']['full_name'] ?? $current_user['email']);
            
            // Add location info
            if (!empty($ticket_data['location_manual'])) {
                $description .= " at location: " . $ticket_data['location_manual'];
            } elseif (!empty($ticket_data['location_id'])) {
                $description .= " at selected location";
            }
            
            // Add estimated hours
            if (!empty($ticket_data['estimated_hours'])) {
                $description .= ". Estimated: " . $ticket_data['estimated_hours'] . " hours";
            }
            
            // Add assignees info
            if (!empty($assigned_staff)) {
                $description .= ". Assigned to " . count($assigned_staff) . " staff member(s)";
            }
            
            if (!empty($uploaded_files)) {
                $description .= " with " . count($uploaded_files) . " attachment(s)";
            }
            
            if ($_POST['work_pattern'] === 'multi' && !empty($_POST['work_days'])) {
                $description .= ". " . count($_POST['work_days']) . " work day(s) scheduled";
            }
            
            $stmt->execute([$ticket_id, $staff_id, $action, $description]);
        } catch (Exception $log_error) {
            error_log("Failed to create ticket log: " . $log_error->getMessage());
            // Continue even if logging fails
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Set success message
        $success_msg = "Ticket #{$ticket_number} created successfully!";
        if (!empty($uploaded_files)) {
            $success_msg .= " (" . count($uploaded_files) . " file(s) uploaded)";
        }
        if (!empty($assigned_staff)) {
            $success_msg .= " (" . count($assigned_staff) . " staff assigned)";
        }
        if ($_POST['work_pattern'] === 'multi' && !empty($_POST['work_days'])) {
            $success_msg .= " (" . count($_POST['work_days']) . " work day(s) added)";
        }
        
        // Store success in session for display after redirect
        $_SESSION['success_message'] = $success_msg;
        
        // Redirect to ticket view
        header('Location: ' . route('tickets.view', ['id' => $ticket_id]));
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        // Keep form data for repopulation
        $form_data = $_POST;
        
        // Log error for debugging
        error_log("Ticket creation error: " . $error);
    }
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

// Helper function to calculate hours
function calculateHours($start_time, $end_time) {
    if (empty($start_time) || empty($end_time)) return '0.00';
    
    $start = DateTime::createFromFormat('H:i', $start_time);
    $end = DateTime::createFromFormat('H:i', $end_time);
    
    if ($end < $start) {
        $end->modify('+1 day');
    }
    
    $interval = $start->diff($end);
    $hours = $interval->h + ($interval->i / 60);
    return number_format($hours, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Ticket | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .form-section h4 {
            color: #004E89;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .required-label::after {
            content: " *";
            color: #dc3545;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 500;
            margin-right: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .priority-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .priority-badge.selected {
            border-color: #004E89;
        }
        
        .badge-critical { background: #dc3545; color: white; }
        .badge-high { background: #fd7e14; color: white; }
        .badge-medium { background: #ffc107; color: #212529; }
        .badge-low { background: #6c757d; color: white; }
        
        .char-count {
            text-align: right;
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        /* File Upload Styles */
        .file-upload-container {
            border: 2px dashed #ddd;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .file-upload-container:hover {
            border-color: #004E89;
            background: #e8f4fd;
        }
        
        .file-upload-container.dragover {
            border-color: #FF6B35;
            background: #fff9f7;
            transform: scale(1.02);
        }
        
        .file-upload-icon {
            font-size: 48px;
            color: #004E89;
            margin-bottom: 15px;
        }
        
        .file-list {
            margin-top: 20px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 15px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }
        
        .file-icon {
            font-size: 20px;
            color: #666;
        }
        
        .file-details {
            flex: 1;
        }
        
        .file-name {
            font-weight: 500;
            margin-bottom: 2px;
            word-break: break-all;
        }
        
        .file-size {
            font-size: 12px;
            color: #666;
        }
        
        .file-remove {
            color: #dc3545;
            cursor: pointer;
            background: none;
            border: none;
            padding: 5px;
        }
        
        .file-remove:hover {
            color: #bd2130;
        }
        
        .file-type-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 10px;
        }
        
        .badge-image { background: #4CAF50; color: white; }
        .badge-pdf { background: #f44336; color: white; }
        .badge-doc { background: #2196F3; color: white; }
        .badge-excel { background: #4CAF50; color: white; }
        .badge-zip { background: #FF9800; color: white; }
        .badge-text { background: #9E9E9E; color: white; }
        .badge-video { background: #E91E63; color: white; }
        .badge-audio { background: #9C27B0; color: white; }
        .badge-other { background: #607D8B; color: white; }
        
        /* Progress bar */
        .upload-progress {
            margin-top: 20px;
            display: none;
        }
        
        .progress-container {
            background: #f0f0f0;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .progress-bar {
            height: 20px;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }
        
        /* Large file warning */
        .large-file-warning {
            background: #ffebee;
            border: 1px solid #ffcdd2;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            color: #c62828;
        }
        
        /* Work pattern toggle */
        .work-pattern-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .pattern-option {
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        
        .pattern-option:hover {
            border-color: #004E89;
            background: #e8f4fd;
        }
        
        .pattern-option.selected {
            border-color: #004E89;
            background: #e8f4fd;
        }
        
        .pattern-icon {
            font-size: 24px;
            color: #004E89;
            margin-bottom: 10px;
        }
        
        /* Work log section */
        .work-day-form {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .work-day-form .card-header {
            background: #f8f9fa;
        }
        
        .total-hours-display {
            font-size: 20px;
            font-weight: bold;
            color: #004E89;
            text-align: center;
            margin: 10px 0;
        }
        
        /* Multi-select styling */
        select[multiple] {
            height: auto;
            min-height: 120px;
        }
        
        .selected-assignees {
            margin-top: 10px;
        }
        
        .assignee-tag {
            display: inline-block;
            background: #e8f4fd;
            border: 1px solid #004E89;
            border-radius: 20px;
            padding: 5px 12px;
            margin: 2px 5px 2px 0;
            font-size: 14px;
        }
        
        .assignee-tag .badge {
            margin-left: 5px;
            font-size: 11px;
        }
        
        @media (max-width: 768px) {
            .form-card {
                padding: 20px;
            }
            
            .priority-badge {
                display: block;
                margin-right: 0;
                text-align: center;
            }
            
            .file-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .file-info {
                width: 100%;
            }
            
            .file-remove {
                align-self: flex-end;
            }
            
            .pattern-option {
                padding: 10px;
            }
            
            .work-day-form .row > div {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php require_once '../../includes/routes.php'; include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-plus-circle"></i> Create New Ticket</h1>
                <a href="<?php echo route('tickets.index'); ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Tickets
                </a>
            </div>
            
            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Create Ticket Form -->
            <div class="form-card">
                <form method="POST" id="ticketForm" enctype="multipart/form-data">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="title" class="form-label required-label">Ticket Title</label>
                                    <input type="text" class="form-control" id="title" name="title" 
                                           value="<?php echo htmlspecialchars($form_data['title']); ?>"
                                           required placeholder="Brief description of the issue" maxlength="150">
                                    <div class="char-count" id="titleCount">0/150 characters</div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="category" class="form-label">Category</label>
                                    <select class="form-select" id="category" name="category">
                                        <option value="General" <?php echo $form_data['category'] == 'General' ? 'selected' : ''; ?>>General</option>
                                        <option value="Network" <?php echo $form_data['category'] == 'Network' ? 'selected' : ''; ?>>Network</option>
                                        <option value="Hardware" <?php echo $form_data['category'] == 'Hardware' ? 'selected' : ''; ?>>Hardware</option>
                                        <option value="Software" <?php echo $form_data['category'] == 'Software' ? 'selected' : ''; ?>>Software</option>
                                        <option value="Email" <?php echo $form_data['category'] == 'Email' ? 'selected' : ''; ?>>Email</option>
                                        <option value="Security" <?php echo $form_data['category'] == 'Security' ? 'selected' : ''; ?>>Security</option>
                                        <option value="CCTV" <?php echo $form_data['category'] == 'CCTV' ? 'selected' : ''; ?>>CCTV</option>
                                        <option value="Biometric" <?php echo $form_data['category'] == 'Biometric' ? 'selected' : ''; ?>>Biometric</option>
                                        <option value="Server" <?php echo $form_data['category'] == 'Server' ? 'selected' : ''; ?>>Server</option>
                                        <option value="Firewall" <?php echo $form_data['category'] == 'Firewall' ? 'selected' : ''; ?>>Firewall</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Detailed Description</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="5" placeholder="Provide detailed information about the issue..." maxlength="2000"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                            <div class="char-count" id="descCount">0/2000 characters</div>
                        </div>
                    </div>
                    
                    <!-- Client Information Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-building"></i> Client Information</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="client_id" class="form-label required-label">Client</label>
                                    <select class="form-select" id="client_id" name="client_id" required>
                                        <option value="">Select a client</option>
                                        <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo htmlspecialchars($client['id']); ?>" 
                                                <?php echo $form_data['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($clients)): ?>
                                    <div class="text-muted small mt-1">
                                        No clients available. <a href="../clients/create.php">Add a client first</a>.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="location_id" class="form-label">Select Location (Optional)</label>
                                    <select class="form-select" id="location_id" name="location_id">
                                        <option value="">Select location</option>
                                        <?php 
                                        if (!empty($form_data['client_id'])) {
                                            foreach ($client_locations as $location) {
                                                if ($location['client_id'] == $form_data['client_id']) {
                                                    echo '<option value="' . htmlspecialchars($location['id']) . '" ' . 
                                                         ($form_data['location_id'] == $location['id'] ? 'selected' : '') . '>' .
                                                         htmlspecialchars($location['location_name']) . '</option>';
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="requested_by" class="form-label">Requested By</label>
                                    <input type="text" class="form-control" id="requested_by" name="requested_by" 
                                           value="<?php echo htmlspecialchars($form_data['requested_by'] ?? ''); ?>"
                                           placeholder="Name of person requesting the ticket">
                                </div>
                            </div>
                                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="requested_by_email" class="form-label">Requester Email (Optional)</label>
                                    <input type="email" class="form-control" id="requested_by_email" name="requested_by_email" 
                                           value="<?php echo htmlspecialchars($form_data['requested_by_email'] ?? ''); ?>"
                                           placeholder="Email of person requesting the ticket">
                                </div>
                            </div>
                        </div>
                                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="csr_sn" class="form-label">CSR S/N (Customer Service Report Serial Number)</label>
                                    <input type="text" class="form-control" id="csr_sn" name="csr_sn" 
                                           value="<?php echo htmlspecialchars($form_data['csr_sn'] ?? ''); ?>"
                                           placeholder="Enter CSR Serial Number (optional)">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Manual Location Input -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="location_manual" class="form-label">Or Enter Location Manually</label>
                                    <input type="text" class="form-control" id="location_manual" name="location_manual" 
                                           value="<?php echo htmlspecialchars($form_data['location_manual']); ?>"
                                           placeholder="e.g., Floor 5, Server Room, Building A, etc.">
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-info-circle"></i> 
                                        Use this if location is not in the dropdown. This will override the selected location.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Work Assignment & Time Tracking Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-clock"></i> Work Assignment & Time Tracking</h4>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="assigned_to" class="form-label">Assign To (Multiple Selection)</label>
                                    <select class="form-select" id="assigned_to" name="assigned_to[]" multiple size="5">
                                        <option value="">Select staff members...</option>
                                        <?php foreach ($staff_members as $staff): ?>
                                        <option value="<?php echo htmlspecialchars($staff['id']); ?>" 
                                                <?php 
                                                if (isset($form_data['assigned_to'])) {
                                                    if (is_array($form_data['assigned_to'])) {
                                                        echo in_array($staff['id'], $form_data['assigned_to']) ? 'selected' : '';
                                                    } else {
                                                        echo $form_data['assigned_to'] == $staff['id'] ? 'selected' : '';
                                                    }
                                                }
                                                ?>>
                                            <?php echo htmlspecialchars($staff['full_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($staff_members)): ?>
                                    <div class="text-danger small mt-1">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        No active staff members found. Please ensure staff profiles are created and marked as 'Active'.
                                    </div>
                                    <?php else: ?>
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-info-circle"></i> 
                                        Hold Ctrl (or Cmd on Mac) to select multiple staff members. First selected will be primary.
                                    </div>
                                    <?php endif; ?>
                                    <div class="selected-assignees mt-2" id="selectedAssignees">
                                        <!-- Selected assignees will appear here -->
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="estimated_hours" class="form-label">Estimated Work Hours</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="estimated_hours" name="estimated_hours" 
                                               value="<?php echo htmlspecialchars($form_data['estimated_hours']); ?>"
                                               min="0" step="0.5" placeholder="e.g., 2.5">
                                        <span class="input-group-text">hours</span>
                                    </div>
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-info-circle"></i> 
                                        Estimated time to complete this task
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="work_start_time" class="form-label">Schedule Start Time (Optional)</label>
                                    <input type="datetime-local" class="form-control" id="work_start_time" name="work_start_time"
                                           value="<?php echo htmlspecialchars($form_data['work_start_time']); ?>">
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-info-circle"></i> 
                                        When work should begin on this ticket
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notify_client" name="notify_client" checked>
                                        <label class="form-check-label" for="notify_client">
                                            <i class="fas fa-bell"></i> Notify client about this ticket
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="urgent" name="urgent">
                                        <label class="form-check-label" for="urgent">
                                            <i class="fas fa-bolt"></i> Mark as urgent (SLA starts immediately)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Work Pattern Selection -->
                        <div class="work-pattern-card">
                            <label class="form-label mb-3">Work Pattern</label>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="pattern-option <?php echo $form_data['work_pattern'] == 'single' ? 'selected' : ''; ?>" 
                                         onclick="selectWorkPattern('single')">
                                        <div class="pattern-icon">
                                            <i class="fas fa-calendar-day"></i>
                                        </div>
                                        <h5>Single Session</h5>
                                        <p class="text-muted small">Work will be completed in one continuous session</p>
                                        <input type="radio" name="work_pattern" value="single" 
                                               <?php echo $form_data['work_pattern'] == 'single' ? 'checked' : ''; ?> style="display: none;">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="pattern-option <?php echo $form_data['work_pattern'] == 'multi' ? 'selected' : ''; ?>" 
                                         onclick="selectWorkPattern('multi')">
                                        <div class="pattern-icon">
                                            <i class="fas fa-calendar-week"></i>
                                        </div>
                                        <h5>Multiple Sessions</h5>
                                        <p class="text-muted small">Work will be spread across multiple days/sessions</p>
                                        <input type="radio" name="work_pattern" value="multi" 
                                               <?php echo $form_data['work_pattern'] == 'multi' ? 'checked' : ''; ?> style="display: none;">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Multi-day Work Configuration -->
                        <div id="multiDayConfig" style="display: <?php echo $form_data['work_pattern'] == 'multi' ? 'block' : 'none'; ?>;">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="expected_days" class="form-label">Number of Work Days</label>
                                    <input type="number" class="form-control" id="expected_days" name="expected_days" 
                                           min="1" max="30" value="<?php echo htmlspecialchars($form_data['expected_days']); ?>"
                                           onchange="updateWorkDayForms()">
                                </div>
                                <div class="col-md-8 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-primary" onclick="addWorkDay()">
                                        <i class="fas fa-plus"></i> Add Another Day
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Work Days Container -->
                            <div id="workDaysContainer">
                                <!-- Day forms will be added here dynamically -->
                                <?php
                                $work_days = $form_data['work_days'];
                                foreach ($work_days as $index => $day): 
                                ?>
                                <div class="work-day-form card mb-3" data-day="<?php echo $index + 1; ?>">
                                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">
                                            <i class="fas fa-calendar-day"></i> Work Day <?php echo $index + 1; ?>
                                        </h6>
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-day-btn" 
                                                onclick="removeWorkDay(this)" <?php echo $index === 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-times"></i> Remove
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Date</label>
                                                    <input type="date" class="form-control work-date" name="work_days[<?php echo $index; ?>][date]" 
                                                           value="<?php echo htmlspecialchars($day['date'] ?? date('Y-m-d')); ?>"
                                                           onchange="calculateDayHours(this)">
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="mb-3">
                                                    <label class="form-label">Start Time</label>
                                                    <input type="time" class="form-control work-start" name="work_days[<?php echo $index; ?>][start]" 
                                                           value="<?php echo htmlspecialchars($day['start'] ?? '09:00'); ?>"
                                                           onchange="calculateDayHours(this)">
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="mb-3">
                                                    <label class="form-label">End Time</label>
                                                    <input type="time" class="form-control work-end" name="work_days[<?php echo $index; ?>][end]" 
                                                           value="<?php echo htmlspecialchars($day['end'] ?? '17:00'); ?>"
                                                           onchange="calculateDayHours(this)">
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="mb-3">
                                                    <label class="form-label">Hours</label>
                                                    <input type="text" class="form-control work-hours" readonly 
                                                           value="<?php echo calculateHours($day['start'] ?? '09:00', $day['end'] ?? '17:00'); ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Staff (Optional)</label>
                                                    <select class="form-select work-staff" name="work_days[<?php echo $index; ?>][staff_id]">
                                                        <option value="">Select staff</option>
                                                        <?php foreach ($staff_members as $staff): ?>
                                                        <option value="<?php echo htmlspecialchars($staff['id']); ?>"
                                                            <?php echo ($day['staff_id'] ?? '') == $staff['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($staff['full_name']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="mb-3">
                                                    <label class="form-label">Work Description</label>
                                                    <textarea class="form-control work-description" name="work_days[<?php echo $index; ?>][desc]" 
                                                              rows="2" placeholder="Describe work for this day..."><?php echo htmlspecialchars($day['desc'] ?? ''); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Total Hours Display -->
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-calculator"></i> Total Estimated Hours for All Days:</span>
                                            <span class="total-hours-display" id="totalWorkHours"><?php 
                                                $total = 0;
                                                foreach ($work_days as $day) {
                                                    $total += calculateHours($day['start'] ?? '09:00', $day['end'] ?? '17:00');
                                                }
                                                echo number_format($total, 2);
                                            ?> hours</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Priority Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-flag"></i> Priority</h4>
                        
                        <div class="mb-3">
                            <input type="hidden" id="priority" name="priority" value="<?php echo htmlspecialchars($form_data['priority']); ?>">
                            
                            <span class="priority-badge badge-critical <?php echo $form_data['priority'] == 'Critical' ? 'selected' : ''; ?>" 
                                  data-value="Critical" onclick="selectPriority('Critical')">
                                <i class="fas fa-exclamation-triangle"></i> Critical
                            </span>
                            
                            <span class="priority-badge badge-high <?php echo $form_data['priority'] == 'High' ? 'selected' : ''; ?>" 
                                  data-value="High" onclick="selectPriority('High')">
                                <i class="fas fa-exclamation-circle"></i> High
                            </span>
                            
                            <span class="priority-badge badge-medium <?php echo $form_data['priority'] == 'Medium' ? 'selected' : ''; ?>" 
                                  data-value="Medium" onclick="selectPriority('Medium')">
                                <i class="fas fa-info-circle"></i> Medium
                            </span>
                            
                            <span class="priority-badge badge-low <?php echo $form_data['priority'] == 'Low' ? 'selected' : ''; ?>" 
                                  data-value="Low" onclick="selectPriority('Low')">
                                <i class="fas fa-arrow-down"></i> Low
                            </span>
                            
                            <div class="text-muted small mt-2">
                                <i class="fas fa-info-circle"></i> 
                                Critical: Immediate attention needed (Response within 1 hour)<br>
                                High: Resolve within 4 hours<br>
                                Medium: Resolve within 24 hours<br>
                                Low: Resolve within 3 days
                            </div>
                        </div>
                    </div>
                    
                    <!-- File Attachments Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-paperclip"></i> Attachments (Optional)</h4>
                        <p class="text-muted mb-3">
                            Upload supporting files. Max <?php echo ini_get('upload_max_filesize'); ?> per file, <?php echo ini_get('post_max_size'); ?> total.
                            <button type="button" class="btn btn-link btn-sm p-0" onclick="showSupportedFormats()">
                                View supported formats
                            </button>
                        </p>
                        
                        <!-- File Upload Area -->
                        <div class="file-upload-container" id="fileDropArea">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <h5>Drag & Drop Files Here</h5>
                            <p class="text-muted">or click to browse (up to <?php echo ini_get('upload_max_filesize'); ?> per file)</p>
                            <input type="file" id="fileInput" name="attachments[]" multiple 
                                   style="display: none;"
                                   accept="<?php echo '.' . implode(',.', $ALLOWED_EXTENSIONS); ?>">
                            <button type="button" class="btn btn-outline-primary mt-2" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-folder-open"></i> Select Files
                            </button>
                            <div class="text-muted small mt-2">
                                <i class="fas fa-info-circle"></i> 
                                Upload progress will be shown for large files
                            </div>
                        </div>
                        
                        <!-- Upload Progress -->
                        <div class="upload-progress" id="uploadProgress">
                            <div class="progress-container">
                                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                            </div>
                            <div class="progress-info">
                                <span id="progressText">0%</span>
                                <span id="speedText">-</span>
                                <span id="timeRemaining">-</span>
                            </div>
                        </div>
                        
                        <!-- File List -->
                        <div class="file-list" id="fileList">
                            <div class="text-muted text-center py-3">No files selected</div>
                        </div>
                        
                        <!-- Upload Stats -->
                        <div class="mt-3" id="uploadStats">
                            <div class="text-muted small">
                                <i class="fas fa-chart-bar"></i>
                                <span id="totalFiles">0 files</span> | 
                                <span id="totalSize">0 B</span> | 
                                <span id="remainingSpace"><?php echo ini_get('post_max_size'); ?> available</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-redo"></i> Reset Form
                                </button>
                                
                                <div>
                                    <a href="<?php echo route('tickets.index'); ?>" class="btn btn-outline-secondary me-2">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="fas fa-save"></i> Create Ticket
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
    
    <!-- Modal for supported formats -->
    <div class="modal fade" id="formatsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Supported File Formats</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-image text-success"></i> Images</h6>
                            <ul class="list-unstyled">
                                <li>• JPG, JPEG</li>
                                <li>• PNG</li>
                                <li>• GIF</li>
                                <li>• BMP</li>
                                <li>• WEBP</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-file-pdf text-danger"></i> Documents</h6>
                            <ul class="list-unstyled">
                                <li>• PDF</li>
                                <li>• DOC, DOCX</li>
                                <li>• XLS, XLSX</li>
                                <li>• PPT, PPTX</li>
                            </ul>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-file-archive text-warning"></i> Archives</h6>
                            <ul class="list-unstyled">
                                <li>• ZIP</li>
                                <li>• RAR</li>
                                <li>• 7Z</li>
                                <li>• TAR, GZ</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-file-video text-primary"></i> Media</h6>
                            <ul class="list-unstyled">
                                <li>• MP4, AVI, MOV</li>
                                <li>• MP3, WAV, OGG</li>
                            </ul>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-file-code text-info"></i> Text/Config</h6>
                            <ul class="list-unstyled">
                                <li>• TXT, CSV, RTF</li>
                                <li>• LOG, CONFIG, INI</li>
                                <li>• XML, JSON, YAML</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-database text-secondary"></i> Other</h6>
                            <ul class="list-unstyled">
                                <li>• SQL, DUMP</li>
                                <li>• PCAP, CAP</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // Helper function to parse PHP size notation to bytes
    function parse_size($size) {
        $unit = strtoupper(substr($size, -1));
        $value = (int)$size;
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }
    ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Configuration - Match PHP upload limits
        const MAX_FILE_SIZE = <?php echo min(parse_size(ini_get('upload_max_filesize')), 2097152); ?>; // Current PHP limit or 2MB
        const MAX_TOTAL_SIZE = <?php echo min(parse_size(ini_get('post_max_size')), 8388608); ?>; // Current PHP limit or 8MB
        let uploadedFiles = [];
        
        // Parse size string to bytes
        function parseSize(size) {
            const units = {'K': 1024, 'M': 1024*1024, 'G': 1024*1024*1024};
            const match = size.toString().match(/(\d+)([KMG]?)/i);
            if (!match) return 0;
            return parseInt(match[1]) * (units[match[2].toUpperCase()] || 1);
        }
        
        // Format bytes
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }
        
        // Update upload stats
        function updateUploadStats() {
            let totalSize = 0;
            uploadedFiles.forEach(file => {
                totalSize += file.size;
            });
            
            const remaining = MAX_TOTAL_SIZE - totalSize;
            
            document.getElementById('totalFiles').textContent = uploadedFiles.length + ' file' + (uploadedFiles.length !== 1 ? 's' : '');
            document.getElementById('totalSize').textContent = formatBytes(totalSize);
            
            if (remaining >= 0) {
                document.getElementById('remainingSpace').textContent = formatBytes(remaining) + ' available';
                document.getElementById('remainingSpace').style.color = '#666';
            } else {
                document.getElementById('remainingSpace').innerHTML = 
                    '<i class="fas fa-exclamation-triangle"></i> Exceeds limit by ' + formatBytes(Math.abs(remaining));
                document.getElementById('remainingSpace').style.color = '#dc3545';
            }
        }
        
        // Handle file selection
        function handleFiles(files) {
            let totalNewSize = 0;
            
            // Calculate total size of new files
            for (let file of files) {
                totalNewSize += file.size;
            }
            
            // Check total size limit
            let currentTotalSize = uploadedFiles.reduce((sum, file) => sum + file.size, 0);
            if (currentTotalSize + totalNewSize > MAX_TOTAL_SIZE) {
                alert('Adding these files would exceed the ' + formatBytes(MAX_TOTAL_SIZE) + ' total upload limit.');
                return;
            }
            
            // Process each file
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // Check individual file size
                if (file.size > MAX_FILE_SIZE) {
                    alert(`File "${file.name}" exceeds the ${formatBytes(MAX_FILE_SIZE)} per-file limit.`);
                    continue;
                }
                
                // Create unique ID for file
                const fileId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                
                // Add to uploaded files
                uploadedFiles.push({
                    id: fileId,
                    file: file,
                    name: file.name,
                    size: file.size,
                    type: file.type,
                    extension: file.name.split('.').pop().toLowerCase()
                });
                
                // Add to file list
                addFileToList(fileId, file);
            }
            
            updateUploadStats();
            updateFileInput();
        }
        
        // Add file to list
        function addFileToList(id, file) {
            const fileList = document.getElementById('fileList');
            
            // Remove "no files" message if present
            const noFilesMsg = fileList.querySelector('.text-muted.text-center');
            if (noFilesMsg) {
                noFilesMsg.remove();
            }
            
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.dataset.id = id;
            
            // Create preview based on file type
            let preview = '';
            const extension = file.name.split('.').pop().toLowerCase();
            
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(extension)) {
                preview = `<div style="width:60px;height:60px;background:#4CAF50;color:white;display:flex;align-items:center;justify-content:center;border-radius:5px;margin-right:10px;"><i class="fas fa-image fa-lg"></i></div>`;
            } else if (['mp4', 'avi', 'mov', 'wmv'].includes(extension)) {
                preview = '<div style="width:60px;height:60px;background:#E91E63;color:white;display:flex;align-items:center;justify-content:center;border-radius:5px;margin-right:10px;"><i class="fas fa-film fa-lg"></i></div>';
            } else if (extension === 'pdf') {
                preview = '<div style="width:60px;height:60px;background:#f44336;color:white;display:flex;align-items:center;justify-content:center;border-radius:5px;margin-right:10px;"><i class="fas fa-file-pdf fa-lg"></i></div>';
            } else {
                preview = '<div style="width:60px;height:60px;background:#666;color:white;display:flex;align-items:center;justify-content:center;border-radius:5px;margin-right:10px;"><i class="fas fa-file fa-lg"></i></div>';
            }
            
            fileItem.innerHTML = `
                <div class="file-info">
                    ${preview}
                    <div class="file-details">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatBytes(file.size)}</div>
                    </div>
                    <span class="file-type-badge ${getFileBadgeClass(extension)}">${extension.toUpperCase()}</span>
                </div>
                <button type="button" class="file-remove" onclick="removeFile('${id}')">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            fileList.appendChild(fileItem);
        }
        
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Get badge class for file type
        function getFileBadgeClass(extension) {
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(extension)) {
                return 'badge-image';
            } else if (extension === 'pdf') {
                return 'badge-pdf';
            } else if (['doc', 'docx'].includes(extension)) {
                return 'badge-doc';
            } else if (['xls', 'xlsx'].includes(extension)) {
                return 'badge-excel';
            } else if (['zip', 'rar', '7z', 'tar', 'gz'].includes(extension)) {
                return 'badge-zip';
            } else if (['mp4', 'avi', 'mov', 'wmv'].includes(extension)) {
                return 'badge-video';
            } else if (['mp3', 'wav', 'ogg'].includes(extension)) {
                return 'badge-audio';
            } else {
                return 'badge-text';
            }
        }
        
        // Remove file
        function removeFile(id) {
            uploadedFiles = uploadedFiles.filter(file => file.id !== id);
            
            const fileItem = document.querySelector(`.file-item[data-id="${id}"]`);
            if (fileItem) {
                fileItem.remove();
            }
            
            // Show "no files" message if empty
            if (uploadedFiles.length === 0) {
                const fileList = document.getElementById('fileList');
                fileList.innerHTML = '<div class="text-muted text-center py-3">No files selected</div>';
            }
            
            updateUploadStats();
            updateFileInput();
        }
        
        // Update file input
        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            uploadedFiles.forEach(fileObj => {
                dataTransfer.items.add(fileObj.file);
            });
            
            document.getElementById('fileInput').files = dataTransfer.files;
        }
        
        // Show supported formats modal
        function showSupportedFormats() {
            new bootstrap.Modal(document.getElementById('formatsModal')).show();
        }
        
        // Drag and drop functionality
        const fileDropArea = document.getElementById('fileDropArea');
        const fileInput = document.getElementById('fileInput');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileDropArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            fileDropArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            fileDropArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            fileDropArea.classList.add('dragover');
        }
        
        function unhighlight() {
            fileDropArea.classList.remove('dragover');
        }
        
        fileDropArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        
        // File input change event
        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });
        
        // Priority selection
        function selectPriority(priority) {
            document.getElementById('priority').value = priority;
            document.querySelectorAll('.priority-badge').forEach(badge => {
                badge.classList.remove('selected');
            });
            document.querySelector(`.priority-badge[data-value="${priority}"]`).classList.add('selected');
        }
        
        // Work pattern selection
        function selectWorkPattern(pattern) {
            // Update radio button
            const radioButton = document.querySelector(`input[name="work_pattern"][value="${pattern}"]`);
            if (radioButton) {
                radioButton.checked = true;
            }
            
            // Update UI - find and select the clicked pattern option
            document.querySelectorAll('.pattern-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to the clicked pattern
            const clickedPattern = document.querySelector(`.pattern-option input[value="${pattern}"]`);
            if (clickedPattern) {
                clickedPattern.closest('.pattern-option').classList.add('selected');
            }
            
            // Show/hide multi-day configuration
            const multiDayConfig = document.getElementById('multiDayConfig');
            if (pattern === 'multi') {
                multiDayConfig.style.display = 'block';
                updateTotalWorkHours(); // Calculate total hours
            } else {
                multiDayConfig.style.display = 'none';
            }
        }
        
        // Calculate hours for a single day
        function calculateDayHours(inputElement) {
            const dayForm = inputElement.closest('.work-day-form');
            const startInput = dayForm.querySelector('.work-start');
            const endInput = dayForm.querySelector('.work-end');
            const hoursInput = dayForm.querySelector('.work-hours');
            
            const startTime = startInput.value;
            const endTime = endInput.value;
            
            if (startTime && endTime) {
                const start = new Date(`2000-01-01T${startTime}`);
                const end = new Date(`2000-01-01T${endTime}`);
                
                // Handle overnight shifts
                if (end < start) {
                    end.setDate(end.getDate() + 1);
                }
                
                const diffMs = end - start;
                const diffHours = diffMs / (1000 * 60 * 60);
                
                // Update display
                hoursInput.value = diffHours.toFixed(2);
                
                // Update total hours
                updateTotalWorkHours();
                
                return diffHours;
            }
            hoursInput.value = '0.00';
            updateTotalWorkHours();
            return 0;
        }
        
        // Update total work hours
        function updateTotalWorkHours() {
            let total = 0;
            document.querySelectorAll('.work-day-form').forEach(dayForm => {
                const hoursInput = dayForm.querySelector('.work-hours');
                if (hoursInput && hoursInput.value) {
                    total += parseFloat(hoursInput.value) || 0;
                }
            });
            
            document.getElementById('totalWorkHours').textContent = total.toFixed(2) + ' hours';
            
            // Update estimated hours if not set or lower than total
            const estimatedHoursInput = document.getElementById('estimated_hours');
            if (!estimatedHoursInput.value || parseFloat(estimatedHoursInput.value) < total) {
                estimatedHoursInput.value = total.toFixed(2);
            }
        }
        
        // Add new work day
        function addWorkDay() {
            const container = document.getElementById('workDaysContainer');
            const dayForms = container.querySelectorAll('.work-day-form');
            const dayNumber = dayForms.length + 1;
            
            // Get staff members HTML
            let staffOptions = '<option value="">Select staff</option>';
            <?php foreach ($staff_members as $staff): ?>
            staffOptions += '<option value="<?php echo htmlspecialchars($staff['id']); ?>"><?php echo htmlspecialchars($staff['full_name']); ?></option>';
            <?php endforeach; ?>
            
            const newDay = document.createElement('div');
            newDay.className = 'work-day-form card mb-3';
            newDay.dataset.day = dayNumber;
            newDay.innerHTML = `
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-calendar-day"></i> Work Day ${dayNumber}
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-day-btn" onclick="removeWorkDay(this)">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control work-date" name="work_days[${dayNumber - 1}][date]" 
                                       value="${new Date().toISOString().split('T')[0]}" onchange="calculateDayHours(this)">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label class="form-label">Start Time</label>
                                <input type="time" class="form-control work-start" name="work_days[${dayNumber - 1}][start]" 
                                       value="09:00" onchange="calculateDayHours(this)">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label class="form-label">End Time</label>
                                <input type="time" class="form-control work-end" name="work_days[${dayNumber - 1}][end]" 
                                       value="17:00" onchange="calculateDayHours(this)">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-3">
                                <label class="form-label">Hours</label>
                                <input type="text" class="form-control work-hours" readonly value="8.00">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Staff (Optional)</label>
                                <select class="form-select work-staff" name="work_days[${dayNumber - 1}][staff_id]">
                                    ${staffOptions}
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Work Description</label>
                                <textarea class="form-control work-description" name="work_days[${dayNumber - 1}][desc]" 
                                          rows="2" placeholder="Describe work for this day..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.appendChild(newDay);
            updateTotalWorkHours();
            
            // Update expected days count
            document.getElementById('expected_days').value = dayNumber;
        }
        
        // Remove work day
        function removeWorkDay(button) {
            const dayForm = button.closest('.work-day-form');
            const dayNumber = parseInt(dayForm.dataset.day);
            
            if (dayNumber === 1) {
                alert('Cannot remove the first work day.');
                return;
            }
            
            if (confirm('Are you sure you want to remove this work day?')) {
                dayForm.remove();
                
                // Renumber remaining days
                const container = document.getElementById('workDaysContainer');
                const dayForms = container.querySelectorAll('.work-day-form');
                
                dayForms.forEach((form, index) => {
                    form.dataset.day = index + 1;
                    const header = form.querySelector('h6');
                    header.innerHTML = `<i class="fas fa-calendar-day"></i> Work Day ${index + 1}`;
                    
                    // Update input names
                    const inputs = form.querySelectorAll('input, select, textarea');
                    inputs.forEach(input => {
                        const name = input.getAttribute('name');
                        if (name && name.includes('work_days[')) {
                            input.setAttribute('name', name.replace(/work_days\[\d+\]/, `work_days[${index}]`));
                        }
                    });
                    
                    // Disable remove button for first day
                    const removeBtn = form.querySelector('.remove-day-btn');
                    if (index === 0) {
                        removeBtn.disabled = true;
                    } else {
                        removeBtn.disabled = false;
                    }
                });
                
                updateTotalWorkHours();
                
                // Update expected days count
                document.getElementById('expected_days').value = dayForms.length;
            }
        }
        
        // Update work day forms based on expected days
        function updateWorkDayForms() {
            const expectedDays = parseInt(document.getElementById('expected_days').value) || 1;
            const container = document.getElementById('workDaysContainer');
            const currentDays = container.querySelectorAll('.work-day-form').length;
            
            if (expectedDays > currentDays) {
                // Add more days
                for (let i = currentDays; i < expectedDays; i++) {
                    addWorkDay();
                }
            } else if (expectedDays < currentDays) {
                // Remove extra days (keep at least 1)
                const daysToRemove = currentDays - expectedDays;
                for (let i = 0; i < daysToRemove; i++) {
                    const lastDay = container.lastElementChild;
                    if (parseInt(lastDay.dataset.day) > 1) {
                        lastDay.remove();
                    }
                }
            }
        }
        
        // Update selected assignees display
        function updateSelectedAssignees() {
            const select = document.getElementById('assigned_to');
            const container = document.getElementById('selectedAssignees');
            container.innerHTML = '';
            
            const selectedOptions = Array.from(select.selectedOptions);
            selectedOptions.forEach((option, index) => {
                if (option.value) {
                    const tag = document.createElement('span');
                    tag.className = 'assignee-tag';
                    tag.innerHTML = `
                        ${option.text}
                        <span class="badge ${index === 0 ? 'bg-primary' : 'bg-secondary'}">
                            ${index === 0 ? 'Primary' : 'Secondary'}
                        </span>
                    `;
                    container.appendChild(tag);
                }
            });
        }
        
        // Form submission
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            // Basic validation
            const title = document.getElementById('title').value.trim();
            const client = document.getElementById('client_id').value;
            
            if (!title) {
                e.preventDefault();
                alert('Please enter a ticket title');
                document.getElementById('title').focus();
                return;
            }
            
            if (!client) {
                e.preventDefault();
                alert('Please select a client');
                document.getElementById('client_id').focus();
                return;
            }
            
            // Validate location
            const locationId = document.getElementById('location_id').value;
            const locationManual = document.getElementById('location_manual').value;
            if (!locationId && !locationManual) {
                e.preventDefault();
                alert('Please either select a location from dropdown or enter one manually');
                return;
            }
            
            // Check total file size
            const totalSize = uploadedFiles.reduce((sum, file) => sum + file.size, 0);
            if (totalSize > MAX_TOTAL_SIZE) {
                e.preventDefault();
                alert('Total file size exceeds 500MB limit. Please remove some files.');
                return;
            }
            
            // Validate multi-day work logs if selected
            const workPattern = document.querySelector('input[name="work_pattern"]:checked').value;
            if (workPattern === 'multi') {
                const workDays = document.querySelectorAll('.work-day-form');
                let hasEmptyFields = false;
                
                workDays.forEach((dayForm, index) => {
                    const date = dayForm.querySelector('.work-date').value;
                    const start = dayForm.querySelector('.work-start').value;
                    const end = dayForm.querySelector('.work-end').value;
                    const desc = dayForm.querySelector('.work-description').value.trim();
                    
                    if (!date || !start || !end || !desc) {
                        hasEmptyFields = true;
                    }
                });
                
                if (hasEmptyFields) {
                    e.preventDefault();
                    alert('Please fill in all fields for each work day (date, start time, end time, and description).');
                    return;
                }
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Ticket...';
            submitBtn.disabled = true;
            
            // Show progress for large files
            if (totalSize > 10 * 1024 * 1024) {
                document.getElementById('uploadProgress').style.display = 'block';
                simulateUploadProgress();
            }
        });
        
        // Simulate upload progress
        function simulateUploadProgress() {
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            let progress = 0;
            
            const interval = setInterval(() => {
                progress += 5;
                if (progress >= 100) {
                    progress = 100;
                    clearInterval(interval);
                }
                
                progressBar.style.width = progress + '%';
                progressText.textContent = progress + '%';
            }, 100);
        }
        
        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.getElementById('ticketForm').reset();
                uploadedFiles = [];
                document.getElementById('fileList').innerHTML = '<div class="text-muted text-center py-3">No files selected</div>';
                document.getElementById('uploadProgress').style.display = 'none';
                updateUploadStats();
                selectPriority('Medium');
                selectWorkPattern('single');
                
                // Reset work days to just one
                const container = document.getElementById('workDaysContainer');
                container.innerHTML = '';
                
                // Add first day back
                let staffOptions = '<option value="">Select staff</option>';
                <?php foreach ($staff_members as $staff): ?>
                staffOptions += '<option value="<?php echo htmlspecialchars($staff['id']); ?>"><?php echo htmlspecialchars($staff['full_name']); ?></option>';
                <?php endforeach; ?>
                
                container.innerHTML = `
                    <div class="work-day-form card mb-3" data-day="1">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-calendar-day"></i> Work Day 1
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-day-btn" disabled>
                                <i class="fas fa-times"></i> Remove
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Date</label>
                                        <input type="date" class="form-control work-date" name="work_days[0][date]" 
                                               value="${new Date().toISOString().split('T')[0]}" onchange="calculateDayHours(this)">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Start Time</label>
                                        <input type="time" class="form-control work-start" name="work_days[0][start]" 
                                               value="09:00" onchange="calculateDayHours(this)">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">End Time</label>
                                        <input type="time" class="form-control work-end" name="work_days[0][end]" 
                                               value="17:00" onchange="calculateDayHours(this)">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">Hours</label>
                                        <input type="text" class="form-control work-hours" readonly value="8.00">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Staff (Optional)</label>
                                        <select class="form-select work-staff" name="work_days[0][staff_id]">
                                            ${staffOptions}
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label class="form-label">Work Description</label>
                                        <textarea class="form-control work-description" name="work_days[0][desc]" 
                                                  rows="2" placeholder="Describe work for this day..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                updateTotalWorkHours();
                updateSelectedAssignees();
                document.getElementById('expected_days').value = 1;
                
                // Clear draft
                localStorage.removeItem('ticketDraft');
                
                // Re-enable submit button
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Create Ticket';
                submitBtn.disabled = false;
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize file list message
            const fileList = document.getElementById('fileList');
            if (!fileList.querySelector('.file-item')) {
                fileList.innerHTML = '<div class="text-muted text-center py-3">No files selected</div>';
            }
            
            // Update selected assignees display
            updateSelectedAssignees();
            
            // Listen for changes in assignee selection
            document.getElementById('assigned_to').addEventListener('change', updateSelectedAssignees);
            
            // Initialize work day hours calculation
            document.querySelectorAll('.work-day-form').forEach(dayForm => {
                calculateDayHours(dayForm.querySelector('.work-start'));
            });
            
            // Load draft if exists
            const draft = localStorage.getItem('ticketDraft');
            if (draft) {
                if (confirm('You have a saved draft. Load it?')) {
                    const draftData = JSON.parse(draft);
                    Object.keys(draftData).forEach(key => {
                        const element = document.querySelector(`[name="${key}"]`);
                        if (element) {
                            element.value = draftData[key];
                            if (element.tagName === 'SELECT') {
                                element.dispatchEvent(new Event('change'));
                            }
                        }
                    });
                    
                    // Update work pattern UI
                    if (draftData.work_pattern) {
                        selectWorkPattern(draftData.work_pattern);
                    }
                    
                    // Update selected assignees
                    updateSelectedAssignees();
                }
            }
            
            // Auto-save draft
            const form = document.getElementById('ticketForm');
            const saveDraft = debounce(() => {
                const formData = new FormData(form);
                const draft = {};
                
                for (let [key, value] of formData.entries()) {
                    if (key !== 'attachments[]' && !key.startsWith('work_days[')) {
                        draft[key] = value;
                    }
                }
                
                // Save work days data
                const workDays = [];
                document.querySelectorAll('.work-day-form').forEach(dayForm => {
                    const dayData = {
                        date: dayForm.querySelector('.work-date').value,
                        start: dayForm.querySelector('.work-start').value,
                        end: dayForm.querySelector('.work-end').value,
                        desc: dayForm.querySelector('.work-description').value,
                        staff_id: dayForm.querySelector('.work-staff').value
                    };
                    workDays.push(dayData);
                });
                draft.work_days = workDays;
                
                localStorage.setItem('ticketDraft', JSON.stringify(draft));
            }, 2000);
            
            form.addEventListener('input', saveDraft);
            form.addEventListener('change', saveDraft);
            
            // Clear draft on successful submission
            form.addEventListener('submit', () => {
                localStorage.removeItem('ticketDraft');
            });
            
            // Update locations when client changes
            document.getElementById('client_id').addEventListener('change', function() {
                const clientId = this.value;
                const locationSelect = document.getElementById('location_id');
                
                console.log('Client selected:', clientId);
                
                // Clear current options
                locationSelect.innerHTML = '<option value="">Loading locations...</option>';
                
                if (!clientId) {
                    locationSelect.innerHTML = '<option value="">Select location</option>';
                    return;
                }
                
                // Fetch locations for the selected client
                const url = `/mit/api/get_locations.php?client_id=${clientId}`;
                console.log('Fetching from:', url);
                
                fetch(url)
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Received data:', data);
                        locationSelect.innerHTML = '<option value="">Select location</option>';
                        
                        if (data.locations && data.locations.length > 0) {
                            console.log('Adding', data.locations.length, 'locations');
                            data.locations.forEach(location => {
                                const option = document.createElement('option');
                                option.value = location.id;
                                option.textContent = location.location_name;
                                if (location.city) {
                                    option.textContent += ' - ' + location.city;
                                }
                                locationSelect.appendChild(option);
                                console.log('Added location:', location.location_name);
                            });
                        } else {
                            console.log('No locations found for client:', clientId);
                            locationSelect.innerHTML = '<option value="">No locations found for this client</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching locations:', error);
                        locationSelect.innerHTML = '<option value="">Error loading locations</option>';
                        alert('Error loading locations: ' + error.message + '\nPlease check the browser console for details.');
                    });
            });
        });
        
        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Character counters
        function updateCharCount(input, counter, max) {
            const length = input.value.length;
            counter.textContent = `${length}/${max} characters`;
            if (length > max * 0.9) {
                counter.style.color = '#dc3545';
            } else if (length > max * 0.7) {
                counter.style.color = '#ffc107';
            } else {
                counter.style.color = '#666';
            }
        }
        
        // Initialize character counters
        const titleInput = document.getElementById('title');
        const descInput = document.getElementById('description');
        const titleCount = document.getElementById('titleCount');
        const descCount = document.getElementById('descCount');
        
        if (titleInput && titleCount) {
            titleInput.addEventListener('input', () => updateCharCount(titleInput, titleCount, 150));
            updateCharCount(titleInput, titleCount, 150);
        }
        
        if (descInput && descCount) {
            descInput.addEventListener('input', () => updateCharCount(descInput, descCount, 2000));
            updateCharCount(descInput, descCount, 2000);
        }
    </script>
</body>
</html>