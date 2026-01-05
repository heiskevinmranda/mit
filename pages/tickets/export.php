<?php
// pages/tickets/export.php

// Include authentication
require_once '../../includes/auth.php';

// Check if user is logged in
requireLogin();

// Get current user
$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get ticket ID from URL
$ticket_id = $_GET['id'] ?? null;
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// Get export type
$export_type = $_GET['type'] ?? 'pdf';

// Fetch ticket details
$ticket = [];
$attachments = [];
$logs = [];
$work_logs = [];
$assignees = [];
$client = [];
$location = [];
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
    header('Location: view.php?id=' . $ticket_id . '&error=' . urlencode($e->getMessage()));
    exit;
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

// Handle PDF export
if ($export_type === 'pdf') {
    require_once '../../vendor/autoload.php'; // For TCPDF or similar
    
    // Create PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('MSP Application');
    $pdf->SetAuthor('MSP Application');
    $pdf->SetTitle('Ticket Report - ' . $ticket['ticket_number']);
    $pdf->SetSubject('Ticket Details');
    $pdf->SetKeywords('ticket, report, service, msp');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Company Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'MSP SERVICE TICKET REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Professional IT Service Management', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Ticket Header
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'TICKET #' . $ticket['ticket_number'], 0, 1, 'L');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, $ticket['title'], 0, 1, 'L');
    $pdf->Ln(5);
    
    // Status and Priority
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 6, 'Status:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 6, $ticket['status'], 0, 0, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 6, 'Priority:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 6, $ticket['priority'], 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 6, 'Category:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 6, $ticket['category'], 0, 0, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(40, 6, 'Created:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(50, 6, date('M j, Y g:i A', strtotime($ticket['created_at'])), 0, 1, 'L');
    
    if ($ticket['closed_at']) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(40, 6, 'Closed:', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(50, 6, date('M j, Y g:i A', strtotime($ticket['closed_at'])), 0, 1, 'L');
    }
    
    $pdf->Ln(10);
    
    // Client Information
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'CLIENT INFORMATION', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(40, 6, 'Company:', 0, 0, 'L');
    $pdf->Cell(0, 6, $ticket['company_name'], 0, 1, 'L');
    
    if ($ticket['contact_person']) {
        $pdf->Cell(40, 6, 'Contact Person:', 0, 0, 'L');
        $pdf->Cell(0, 6, $ticket['contact_person'], 0, 1, 'L');
    }
    
    if ($ticket['client_email']) {
        $pdf->Cell(40, 6, 'Email:', 0, 0, 'L');
        $pdf->Cell(0, 6, $ticket['client_email'], 0, 1, 'L');
    }
    
    if ($ticket['client_phone']) {
        $pdf->Cell(40, 6, 'Phone:', 0, 0, 'L');
        $pdf->Cell(0, 6, $ticket['client_phone'], 0, 1, 'L');
    }
    
    $pdf->Ln(5);
    
    // Location Information
    if ($ticket['location_name'] || $ticket['location_manual']) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'LOCATION', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        
        if ($ticket['location_manual']) {
            $pdf->MultiCell(0, 6, $ticket['location_manual'], 0, 'L');
        }
        
        if ($ticket['location_name']) {
            $pdf->Cell(0, 6, $ticket['location_name'], 0, 1, 'L');
        }
        
        if ($ticket['location_address']) {
            $pdf->MultiCell(0, 6, $ticket['location_address'], 0, 'L');
        }
        
        $location_line = '';
        if ($ticket['location_city']) $location_line .= $ticket['location_city'];
        if ($ticket['location_state']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_state'];
        if ($ticket['location_country']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_country'];
        
        if ($location_line) {
            $pdf->Cell(0, 6, $location_line, 0, 1, 'L');
        }
        
        $pdf->Ln(5);
    }
    
    // Assigned Staff
    if (!empty($assignees)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'ASSIGNED STAFF', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        
        foreach ($assignees as $assignee) {
            $pdf->Cell(5, 6, '', 0, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, $assignee['full_name'] . ($assignee['is_primary'] ? ' (Primary)' : ''), 0, 1, 'L');
            
            $pdf->SetFont('helvetica', '', 9);
            if ($assignee['designation']) {
                $pdf->Cell(10, 5, '', 0, 0, 'L');
                $pdf->Cell(0, 5, 'Designation: ' . $assignee['designation'], 0, 1, 'L');
            }
            
            if ($assignee['department']) {
                $pdf->Cell(10, 5, '', 0, 0, 'L');
                $pdf->Cell(0, 5, 'Department: ' . $assignee['department'], 0, 1, 'L');
            }
            
            $pdf->Cell(10, 5, '', 0, 0, 'L');
            $pdf->Cell(0, 5, 'Assigned: ' . date('M j, Y', strtotime($assignee['assigned_at'])), 0, 1, 'L');
            
            $pdf->Ln(3);
        }
        $pdf->Ln(5);
    }
    
    // Description
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'DESCRIPTION', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell(0, 6, $ticket['description'], 0, 'L');
    $pdf->Ln(10);
    
    // Work Logs
    if (!empty($work_logs)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'WORK LOGS', 0, 1, 'L');
        
        // Hours Summary
        $pdf->SetFont('helvetica', '', 10);
        $estimated_hours = $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00';
        $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
        $completion = $ticket['estimated_hours'] > 0 ? ($total_logged_hours / $ticket['estimated_hours']) * 100 : 0;
        
        $pdf->Cell(60, 6, 'Estimated Hours:', 0, 0, 'L');
        $pdf->Cell(30, 6, $estimated_hours, 0, 0, 'L');
        $pdf->Cell(60, 6, 'Logged Hours:', 0, 0, 'L');
        $pdf->Cell(0, 6, number_format($total_logged_hours, 2), 0, 1, 'L');
        
        $pdf->Cell(60, 6, 'Remaining Hours:', 0, 0, 'L');
        $pdf->Cell(30, 6, number_format(max(0, $remaining), 2), 0, 0, 'L');
        $pdf->Cell(60, 6, 'Completion:', 0, 0, 'L');
        $pdf->Cell(0, 6, number_format($completion, 1) . '%', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        // Individual Work Logs
        foreach ($work_logs as $log) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, date('D, M j, Y', strtotime($log['work_date'])), 0, 1, 'L');
            
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(10, 5, '', 0, 0, 'L');
            $pdf->Cell(60, 5, 'Time: ' . date('g:i A', strtotime($log['start_time'])) . 
                ' to ' . ($log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing'), 0, 0, 'L');
            $pdf->Cell(40, 5, 'Hours: ' . number_format($log['total_hours'], 2), 0, 0, 'L');
            if ($log['staff_name']) {
                $pdf->Cell(0, 5, 'Staff: ' . $log['staff_name'], 0, 1, 'L');
            } else {
                $pdf->Cell(0, 5, '', 0, 1, 'L');
            }
            
            if ($log['description']) {
                $pdf->Cell(10, 5, '', 0, 0, 'L');
                $pdf->MultiCell(0, 5, 'Description: ' . $log['description'], 0, 'L');
            }
            
            $pdf->Ln(5);
        }
        
        $pdf->Ln(5);
    }
    
    // Activity Logs
    if (!empty($logs)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'ACTIVITY TIMELINE', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($logs as $log) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 5, date('M j, Y g:i A', strtotime($log['created_at'])), 0, 1, 'L');
            
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(5, 4, '', 0, 0, 'L');
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(30, 4, $log['action'] . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 4, $log['description'], 0, 'L');
            
            if ($log['staff_name']) {
                $pdf->Cell(5, 4, '', 0, 0, 'L');
                $pdf->Cell(0, 4, 'By: ' . $log['staff_name'], 0, 1, 'L');
            }
            
            if ($log['time_spent_minutes']) {
                $pdf->Cell(5, 4, '', 0, 0, 'L');
                $pdf->Cell(0, 4, 'Time Spent: ' . formatDuration($log['time_spent_minutes']), 0, 1, 'L');
            }
            
            $pdf->Ln(3);
        }
        
        $pdf->Ln(5);
    }
    
    // Attachments
    if (!empty($attachments)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'ATTACHMENTS', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        foreach ($attachments as $attachment) {
            $pdf->Cell(5, 5, '', 0, 0, 'L');
            $pdf->Cell(0, 5, $attachment['original_filename'], 0, 1, 'L');
            
            $pdf->Cell(10, 4, '', 0, 0, 'L');
            $pdf->Cell(0, 4, formatBytes($attachment['file_size']) . 
                ' | Uploaded: ' . date('M j, Y', strtotime($attachment['upload_time'])), 0, 1, 'L');
            
            if ($attachment['uploaded_by_name']) {
                $pdf->Cell(10, 4, '', 0, 0, 'L');
                $pdf->Cell(0, 4, 'By: ' . $attachment['uploaded_by_name'], 0, 1, 'L');
            }
            
            $pdf->Ln(3);
        }
    }
    
    // Footer
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Generated on ' . date('M j, Y g:i A') . ' | MSP Ticket Management System', 0, 0, 'C');
    
    // Output PDF
    $pdf->Output('Ticket_' . $ticket['ticket_number'] . '_Report.pdf', 'D');
    exit;
}

// Handle Excel export
elseif ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Ticket_' . $ticket['ticket_number'] . '_Report.xls"');
    
    echo "<html>";
    echo "<head>";
    echo "<style>";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th { background-color: #004E89; color: white; padding: 8px; text-align: left; }";
    echo "td { border: 1px solid #ddd; padding: 8px; }";
    echo ".header { background-color: #f2f2f2; font-weight: bold; }";
    echo ".section { background-color: #e8f4fd; font-weight: bold; margin-top: 20px; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>MSP SERVICE TICKET REPORT</h2>";
    echo "<h3>Ticket #" . $ticket['ticket_number'] . " - " . $ticket['title'] . "</h3>";
    echo "<p>Generated: " . date('M j, Y g:i A') . "</p>";
    
    echo "<table>";
    
    // Ticket Information
    echo "<tr class='section'><td colspan='4'>TICKET INFORMATION</td></tr>";
    echo "<tr><td class='header'>Ticket Number</td><td>" . $ticket['ticket_number'] . "</td>";
    echo "<td class='header'>Status</td><td>" . $ticket['status'] . "</td></tr>";
    
    echo "<tr><td class='header'>Title</td><td colspan='3'>" . $ticket['title'] . "</td></tr>";
    echo "<tr><td class='header'>Priority</td><td>" . $ticket['priority'] . "</td>";
    echo "<td class='header'>Category</td><td>" . $ticket['category'] . "</td></tr>";
    
    echo "<tr><td class='header'>Created</td><td>" . date('M j, Y g:i A', strtotime($ticket['created_at'])) . "</td>";
    echo "<td class='header'>Last Updated</td><td>" . date('M j, Y g:i A', strtotime($ticket['updated_at'])) . "</td></tr>";
    
    if ($ticket['work_start_time']) {
        echo "<tr><td class='header'>Scheduled Start</td><td>" . date('M j, Y g:i A', strtotime($ticket['work_start_time'])) . "</td>";
    }
    
    if ($ticket['closed_at']) {
        echo "<td class='header'>Closed</td><td>" . date('M j, Y g:i A', strtotime($ticket['closed_at'])) . "</td></tr>";
    }
    
    // Client Information
    echo "<tr class='section'><td colspan='4'>CLIENT INFORMATION</td></tr>";
    echo "<tr><td class='header'>Company</td><td colspan='3'>" . $ticket['company_name'] . "</td></tr>";
    
    if ($ticket['contact_person']) {
        echo "<tr><td class='header'>Contact Person</td><td colspan='3'>" . $ticket['contact_person'] . "</td></tr>";
    }
    
    if ($ticket['client_email']) {
        echo "<tr><td class='header'>Email</td><td>" . $ticket['client_email'] . "</td>";
    }
    
    if ($ticket['client_phone']) {
        echo "<td class='header'>Phone</td><td>" . $ticket['client_phone'] . "</td></tr>";
    }
    
    // Location Information
    if ($ticket['location_name'] || $ticket['location_manual']) {
        echo "<tr class='section'><td colspan='4'>LOCATION INFORMATION</td></tr>";
        
        if ($ticket['location_manual']) {
            echo "<tr><td class='header'>Manual Location</td><td colspan='3'>" . $ticket['location_manual'] . "</td></tr>";
        }
        
        if ($ticket['location_name']) {
            echo "<tr><td class='header'>Location Name</td><td colspan='3'>" . $ticket['location_name'] . "</td></tr>";
        }
        
        if ($ticket['location_address']) {
            echo "<tr><td class='header'>Address</td><td colspan='3'>" . $ticket['location_address'] . "</td></tr>";
        }
        
        $location_line = '';
        if ($ticket['location_city']) $location_line .= $ticket['location_city'];
        if ($ticket['location_state']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_state'];
        if ($ticket['location_country']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_country'];
        
        if ($location_line) {
            echo "<tr><td class='header'>City/State/Country</td><td colspan='3'>" . $location_line . "</td></tr>";
        }
    }
    
    // Assigned Staff
    if (!empty($assignees)) {
        echo "<tr class='section'><td colspan='4'>ASSIGNED STAFF</td></tr>";
        foreach ($assignees as $assignee) {
            echo "<tr><td class='header'>Name</td><td>" . $assignee['full_name'] . ($assignee['is_primary'] ? ' (Primary)' : '') . "</td>";
            echo "<td class='header'>Designation</td><td>" . ($assignee['designation'] ?? 'N/A') . "</td></tr>";
            
            echo "<tr><td class='header'>Department</td><td>" . ($assignee['department'] ?? 'N/A') . "</td>";
            echo "<td class='header'>Assigned Date</td><td>" . date('M j, Y', strtotime($assignee['assigned_at'])) . "</td></tr>";
        }
    }
    
    // Description
    echo "<tr class='section'><td colspan='4'>DESCRIPTION</td></tr>";
    echo "<tr><td colspan='4'>" . nl2br($ticket['description']) . "</td></tr>";
    
    // Work Logs
    if (!empty($work_logs)) {
        echo "<tr class='section'><td colspan='4'>WORK LOGS SUMMARY</td></tr>";
        
        $estimated_hours = $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00';
        $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
        $completion = $ticket['estimated_hours'] > 0 ? ($total_logged_hours / $ticket['estimated_hours']) * 100 : 0;
        
        echo "<tr><td class='header'>Estimated Hours</td><td>" . $estimated_hours . "</td>";
        echo "<td class='header'>Logged Hours</td><td>" . number_format($total_logged_hours, 2) . "</td></tr>";
        
        echo "<tr><td class='header'>Remaining Hours</td><td>" . number_format(max(0, $remaining), 2) . "</td>";
        echo "<td class='header'>Completion</td><td>" . number_format($completion, 1) . "%</td></tr>";
        
        echo "<tr class='section'><td colspan='4'>WORK LOG DETAILS</td></tr>";
        echo "<tr><th>Date</th><th>Time</th><th>Hours</th><th>Description</th></tr>";
        
        foreach ($work_logs as $log) {
            echo "<tr>";
            echo "<td>" . date('M j, Y', strtotime($log['work_date'])) . "</td>";
            echo "<td>" . date('g:i A', strtotime($log['start_time'])) . " - " . 
                ($log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing') . "</td>";
            echo "<td>" . number_format($log['total_hours'], 2) . "</td>";
            echo "<td>" . $log['description'] . "</td>";
            echo "</tr>";
        }
    }
    
    // Activity Logs
    if (!empty($logs)) {
        echo "<tr class='section'><td colspan='4'>ACTIVITY LOGS</td></tr>";
        echo "<tr><th>Date/Time</th><th>Action</th><th>Description</th><th>Staff</th></tr>";
        
        foreach ($logs as $log) {
            echo "<tr>";
            echo "<td>" . date('M j, Y g:i A', strtotime($log['created_at'])) . "</td>";
            echo "<td>" . $log['action'] . "</td>";
            echo "<td>" . $log['description'] . "</td>";
            echo "<td>" . ($log['staff_name'] ?? 'System') . "</td>";
            echo "</tr>";
        }
    }
    
    // Attachments
    if (!empty($attachments)) {
        echo "<tr class='section'><td colspan='4'>ATTACHMENTS</td></tr>";
        echo "<tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>Uploaded By</th></tr>";
        
        foreach ($attachments as $attachment) {
            echo "<tr>";
            echo "<td>" . $attachment['original_filename'] . "</td>";
            echo "<td>" . formatBytes($attachment['file_size']) . "</td>";
            echo "<td>" . date('M j, Y', strtotime($attachment['upload_time'])) . "</td>";
            echo "<td>" . ($attachment['uploaded_by_name'] ?? 'System') . "</td>";
            echo "</tr>";
        }
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit;
}

// Handle CSV export
elseif ($export_type === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Ticket_' . $ticket['ticket_number'] . '_Report.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");
    
    // Header
    fputcsv($output, ['MSP SERVICE TICKET REPORT']);
    fputcsv($output, ['Ticket #' . $ticket['ticket_number'], $ticket['title']]);
    fputcsv($output, ['Generated', date('M j, Y g:i A')]);
    fputcsv($output, []);
    
    // Ticket Information
    fputcsv($output, ['TICKET INFORMATION']);
    fputcsv($output, ['Ticket Number', $ticket['ticket_number']]);
    fputcsv($output, ['Title', $ticket['title']]);
    fputcsv($output, ['Status', $ticket['status']]);
    fputcsv($output, ['Priority', $ticket['priority']]);
    fputcsv($output, ['Category', $ticket['category']]);
    fputcsv($output, ['Created', date('M j, Y g:i A', strtotime($ticket['created_at']))]);
    fputcsv($output, ['Last Updated', date('M j, Y g:i A', strtotime($ticket['updated_at']))]);
    
    if ($ticket['work_start_time']) {
        fputcsv($output, ['Scheduled Start', date('M j, Y g:i A', strtotime($ticket['work_start_time']))]);
    }
    
    if ($ticket['closed_at']) {
        fputcsv($output, ['Closed', date('M j, Y g:i A', strtotime($ticket['closed_at']))]);
    }
    
    fputcsv($output, []);
    
    // Client Information
    fputcsv($output, ['CLIENT INFORMATION']);
    fputcsv($output, ['Company', $ticket['company_name']]);
    
    if ($ticket['contact_person']) {
        fputcsv($output, ['Contact Person', $ticket['contact_person']]);
    }
    
    if ($ticket['client_email']) {
        fputcsv($output, ['Email', $ticket['client_email']]);
    }
    
    if ($ticket['client_phone']) {
        fputcsv($output, ['Phone', $ticket['client_phone']]);
    }
    
    fputcsv($output, []);
    
    // Location Information
    if ($ticket['location_name'] || $ticket['location_manual']) {
        fputcsv($output, ['LOCATION INFORMATION']);
        
        if ($ticket['location_manual']) {
            fputcsv($output, ['Manual Location', $ticket['location_manual']]);
        }
        
        if ($ticket['location_name']) {
            fputcsv($output, ['Location Name', $ticket['location_name']]);
        }
        
        if ($ticket['location_address']) {
            fputcsv($output, ['Address', $ticket['location_address']]);
        }
        
        $location_line = '';
        if ($ticket['location_city']) $location_line .= $ticket['location_city'];
        if ($ticket['location_state']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_state'];
        if ($ticket['location_country']) $location_line .= ($location_line ? ', ' : '') . $ticket['location_country'];
        
        if ($location_line) {
            fputcsv($output, ['City/State/Country', $location_line]);
        }
        
        fputcsv($output, []);
    }
    
    // Assigned Staff
    if (!empty($assignees)) {
        fputcsv($output, ['ASSIGNED STAFF']);
        fputcsv($output, ['Name', 'Role', 'Designation', 'Department', 'Assigned Date']);
        
        foreach ($assignees as $assignee) {
            fputcsv($output, [
                $assignee['full_name'] . ($assignee['is_primary'] ? ' (Primary)' : ''),
                $assignee['is_primary'] ? 'Primary' : 'Secondary',
                $assignee['designation'] ?? '',
                $assignee['department'] ?? '',
                date('M j, Y', strtotime($assignee['assigned_at']))
            ]);
        }
        
        fputcsv($output, []);
    }
    
    // Description
    fputcsv($output, ['DESCRIPTION']);
    fputcsv($output, [$ticket['description']]);
    fputcsv($output, []);
    
    // Work Logs
    if (!empty($work_logs)) {
        fputcsv($output, ['WORK LOGS SUMMARY']);
        
        $estimated_hours = $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00';
        $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
        $completion = $ticket['estimated_hours'] > 0 ? ($total_logged_hours / $ticket['estimated_hours']) * 100 : 0;
        
        fputcsv($output, ['Estimated Hours', $estimated_hours]);
        fputcsv($output, ['Logged Hours', number_format($total_logged_hours, 2)]);
        fputcsv($output, ['Remaining Hours', number_format(max(0, $remaining), 2)]);
        fputcsv($output, ['Completion %', number_format($completion, 1)]);
        
        fputcsv($output, []);
        fputcsv($output, ['WORK LOG DETAILS']);
        fputcsv($output, ['Date', 'Start Time', 'End Time', 'Hours', 'Description', 'Staff']);
        
        foreach ($work_logs as $log) {
            fputcsv($output, [
                date('M j, Y', strtotime($log['work_date'])),
                date('g:i A', strtotime($log['start_time'])),
                $log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing',
                number_format($log['total_hours'], 2),
                $log['description'],
                $log['staff_name'] ?? ''
            ]);
        }
        
        fputcsv($output, []);
    }
    
    // Activity Logs
    if (!empty($logs)) {
        fputcsv($output, ['ACTIVITY LOGS']);
        fputcsv($output, ['Date/Time', 'Action', 'Description', 'Staff', 'Time Spent']);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                date('M j, Y g:i A', strtotime($log['created_at'])),
                $log['action'],
                $log['description'],
                $log['staff_name'] ?? 'System',
                $log['time_spent_minutes'] ? formatDuration($log['time_spent_minutes']) : ''
            ]);
        }
        
        fputcsv($output, []);
    }
    
    // Attachments
    if (!empty($attachments)) {
        fputcsv($output, ['ATTACHMENTS']);
        fputcsv($output, ['Filename', 'Size', 'Upload Date', 'Uploaded By']);
        
        foreach ($attachments as $attachment) {
            fputcsv($output, [
                $attachment['original_filename'],
                formatBytes($attachment['file_size']),
                date('M j, Y', strtotime($attachment['upload_time'])),
                $attachment['uploaded_by_name'] ?? 'System'
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// Handle Print view
elseif ($export_type === 'print') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Print Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?></title>
        <style>
            @media print {
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 20px;
                }
                
                .no-print {
                    display: none !important;
                }
                
                .page-break {
                    page-break-before: always;
                }
                
                h1, h2, h3 {
                    color: #004E89;
                    margin-bottom: 10px;
                }
                
                .ticket-header {
                    background: #f8f9fa;
                    padding: 20px;
                    border: 2px solid #004E89;
                    border-radius: 5px;
                    margin-bottom: 20px;
                }
                
                .info-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 15px;
                    margin-bottom: 20px;
                }
                
                .info-item {
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                }
                
                .info-label {
                    font-weight: bold;
                    color: #666;
                    font-size: 12px;
                    text-transform: uppercase;
                    margin-bottom: 5px;
                }
                
                .info-value {
                    font-size: 14px;
                }
                
                .section {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 2px solid #004E89;
                }
                
                .section-title {
                    font-size: 16px;
                    font-weight: bold;
                    color: #004E89;
                    margin-bottom: 15px;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                
                th {
                    background: #004E89;
                    color: white;
                    padding: 8px;
                    text-align: left;
                    font-size: 12px;
                }
                
                td {
                    border: 1px solid #ddd;
                    padding: 8px;
                    font-size: 12px;
                }
                
                .badge {
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 12px;
                    font-size: 11px;
                    font-weight: bold;
                    margin-right: 5px;
                }
                
                .badge-primary { background: #007bff; color: white; }
                .badge-success { background: #28a745; color: white; }
                .badge-warning { background: #ffc107; color: #212529; }
                .badge-danger { background: #dc3545; color: white; }
                .badge-info { background: #17a2b8; color: white; }
                
                .work-log-item {
                    border: 1px solid #ddd;
                    padding: 10px;
                    margin-bottom: 10px;
                    border-radius: 5px;
                }
                
                .work-log-header {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 10px;
                }
                
                .work-log-hours {
                    font-weight: bold;
                    color: #004E89;
                }
                
                .attachment-list {
                    list-style: none;
                    padding: 0;
                }
                
                .attachment-item {
                    display: flex;
                    justify-content: space-between;
                    padding: 5px 0;
                    border-bottom: 1px solid #eee;
                }
                
                .footer {
                    margin-top: 50px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 10px;
                    color: #666;
                    text-align: center;
                }
            }
            
            @media screen {
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 20px;
                    background: #f5f5f5;
                }
                
                .print-container {
                    background: white;
                    padding: 30px;
                    box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    border-radius: 10px;
                }
                
                .print-actions {
                    text-align: center;
                    margin-bottom: 20px;
                    padding: 20px;
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                
                .btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    font-weight: bold;
                    margin: 0 10px;
                }
                
                .btn-print {
                    background: #004E89;
                    color: white;
                }
                
                .btn-back {
                    background: #6c757d;
                    color: white;
                }
                
                .btn:hover {
                    opacity: 0.9;
                }
            }
        </style>
        <script>
            function printDocument() {
                window.print();
            }
            
            function goBack() {
                window.history.back();
            }
        </script>
    </head>
    <body>
        <div class="no-print print-actions">
            <h2>Print Preview</h2>
            <p>Review the ticket details below before printing.</p>
            <button class="btn btn-print" onclick="printDocument()">
                <i class="fas fa-print"></i> Print Document
            </button>
            <button class="btn btn-back" onclick="goBack()">
                <i class="fas fa-arrow-left"></i> Back to Ticket
            </button>
        </div>
        
        <div class="print-container">
            <!-- Header -->
            <div style="text-align: center; margin-bottom: 30px; border-bottom: 3px solid #004E89; padding-bottom: 20px;">
                <h1 style="color: #004E89; margin-bottom: 5px;">MSP SERVICE TICKET REPORT</h1>
                <p style="color: #666; margin-bottom: 10px;">Professional IT Service Management</p>
                <p style="font-size: 12px; color: #999;">Generated: <?php echo date('M j, Y g:i A'); ?></p>
            </div>
            
            <!-- Ticket Header -->
            <div class="ticket-header">
                <h2 style="margin-top: 0;">Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?></h2>
                <h3 style="color: #333;"><?php echo htmlspecialchars($ticket['title']); ?></h3>
                
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <span class="badge 
                        <?php 
                        if ($ticket['status'] == 'Open') echo 'badge-primary';
                        elseif ($ticket['status'] == 'In Progress') echo 'badge-info';
                        elseif ($ticket['status'] == 'Resolved') echo 'badge-success';
                        elseif ($ticket['status'] == 'Closed') echo 'badge-secondary';
                        else echo 'badge-warning';
                        ?>">
                        Status: <?php echo $ticket['status']; ?>
                    </span>
                    
                    <span class="badge 
                        <?php 
                        if ($ticket['priority'] == 'Critical') echo 'badge-danger';
                        elseif ($ticket['priority'] == 'High') echo 'badge-warning';
                        elseif ($ticket['priority'] == 'Medium') echo 'badge-info';
                        else echo 'badge-secondary';
                        ?>">
                        Priority: <?php echo $ticket['priority']; ?>
                    </span>
                    
                    <span class="badge badge-info">
                        Category: <?php echo $ticket['category']; ?>
                    </span>
                </div>
            </div>
            
            <!-- Ticket Information -->
            <div class="section">
                <div class="section-title">TICKET INFORMATION</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Created</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Last Updated</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?></div>
                    </div>
                    
                    <?php if ($ticket['work_start_time']): ?>
                    <div class="info-item">
                        <div class="info-label">Scheduled Start</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['work_start_time'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['closed_at']): ?>
                    <div class="info-item">
                        <div class="info-label">Closed</div>
                        <div class="info-value"><?php echo date('M j, Y g:i A', strtotime($ticket['closed_at'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Client Information -->
            <div class="section">
                <div class="section-title">CLIENT INFORMATION</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Company</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['company_name']); ?></div>
                    </div>
                    
                    <?php if ($ticket['contact_person']): ?>
                    <div class="info-item">
                        <div class="info-label">Contact Person</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['contact_person']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['client_email']): ?>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['client_email']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['client_phone']): ?>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['client_phone']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Location Information -->
            <?php if ($ticket['location_name'] || $ticket['location_manual']): ?>
            <div class="section">
                <div class="section-title">LOCATION</div>
                <div class="info-grid">
                    <?php if ($ticket['location_manual']): ?>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">Manual Location</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['location_manual']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['location_name']): ?>
                    <div class="info-item">
                        <div class="info-label">Location Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['location_name']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['location_address']): ?>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">Address</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($ticket['location_address'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['location_city']): ?>
                    <div class="info-item">
                        <div class="info-label">City</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['location_city']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['location_state']): ?>
                    <div class="info-item">
                        <div class="info-label">State</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['location_state']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($ticket['location_country']): ?>
                    <div class="info-item">
                        <div class="info-label">Country</div>
                        <div class="info-value"><?php echo htmlspecialchars($ticket['location_country']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Assigned Staff -->
            <?php if (!empty($assignees)): ?>
            <div class="section">
                <div class="section-title">ASSIGNED STAFF</div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Assigned Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignees as $assignee): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($assignee['full_name']); ?></td>
                            <td><?php echo $assignee['is_primary'] ? 'Primary' : 'Secondary'; ?></td>
                            <td><?php echo htmlspecialchars($assignee['designation'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($assignee['department'] ?? ''); ?></td>
                            <td><?php echo date('M j, Y', strtotime($assignee['assigned_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Description -->
            <div class="section">
                <div class="section-title">DESCRIPTION</div>
                <div style="padding: 15px; background: #f8f9fa; border-radius: 5px;">
                    <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                </div>
            </div>
            
            <!-- Work Logs -->
            <?php if (!empty($work_logs)): ?>
            <div class="section">
                <div class="section-title">WORK LOGS</div>
                
                <!-- Hours Summary -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; text-align: center;">
                    <div style="padding: 10px; background: #e8f4fd; border-radius: 5px;">
                        <div style="font-size: 11px; color: #666;">Estimated Hours</div>
                        <div style="font-size: 18px; font-weight: bold; color: #004E89;">
                            <?php echo $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00'; ?>
                        </div>
                    </div>
                    
                    <div style="padding: 10px; background: #e8f4fd; border-radius: 5px;">
                        <div style="font-size: 11px; color: #666;">Logged Hours</div>
                        <div style="font-size: 18px; font-weight: bold; color: #004E89;">
                            <?php echo number_format($total_logged_hours, 2); ?>
                        </div>
                    </div>
                    
                    <div style="padding: 10px; background: #e8f4fd; border-radius: 5px;">
                        <div style="font-size: 11px; color: #666;">Remaining Hours</div>
                        <div style="font-size: 18px; font-weight: bold; color: #004E89;">
                            <?php 
                            $remaining = ($ticket['estimated_hours'] ?? 0) - $total_logged_hours;
                            echo number_format(max(0, $remaining), 2);
                            ?>
                        </div>
                    </div>
                    
                    <div style="padding: 10px; background: #e8f4fd; border-radius: 5px;">
                        <div style="font-size: 11px; color: #666;">Completion</div>
                        <div style="font-size: 18px; font-weight: bold; color: #004E89;">
                            <?php 
                            $completion = $ticket['estimated_hours'] > 0 ? ($total_logged_hours / $ticket['estimated_hours']) * 100 : 0;
                            echo number_format($completion, 1); ?>%
                        </div>
                    </div>
                </div>
                
                <!-- Individual Work Logs -->
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Hours</th>
                            <th>Description</th>
                            <th>Staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($work_logs as $log): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($log['work_date'])); ?></td>
                            <td>
                                <?php echo date('g:i A', strtotime($log['start_time'])); ?> - 
                                <?php echo $log['end_time'] ? date('g:i A', strtotime($log['end_time'])) : 'Ongoing'; ?>
                            </td>
                            <td><?php echo number_format($log['total_hours'], 2); ?></td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td><?php echo htmlspecialchars($log['staff_name'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Activity Logs -->
            <?php if (!empty($logs)): ?>
            <div class="section page-break">
                <div class="section-title">ACTIVITY TIMELINE</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>Staff</th>
                            <th>Time Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td><?php echo htmlspecialchars($log['staff_name'] ?? 'System'); ?></td>
                            <td><?php echo $log['time_spent_minutes'] ? formatDuration($log['time_spent_minutes']) : ''; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Attachments -->
            <?php if (!empty($attachments)): ?>
            <div class="section">
                <div class="section-title">ATTACHMENTS</div>
                <table>
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th>Uploaded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachments as $attachment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($attachment['original_filename']); ?></td>
                            <td><?php echo formatBytes($attachment['file_size']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($attachment['upload_time'])); ?></td>
                            <td><?php echo htmlspecialchars($attachment['uploaded_by_name'] ?? 'System'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="footer">
                <p>MSP Ticket Management System | Generated on <?php echo date('M j, Y g:i A'); ?></p>
                <p>This document contains confidential information. Unauthorized distribution is prohibited.</p>
            </div>
        </div>
        
        <!-- Include FontAwesome for icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </body>
    </html>
    <?php
    exit;
}

// If no valid export type, redirect back to view
header('Location: view.php?id=' . $ticket_id);
exit;