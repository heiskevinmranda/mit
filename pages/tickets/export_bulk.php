<?php
// pages/tickets/export_bulk.php

// Include authentication
require_once '../../includes/auth.php';

// Check if user is logged in
requireLogin();

// Get current user
$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get export type
$export_type = $_GET['type'] ?? 'excel';

// ========== FILTERS (same as index page) ==========
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$assigned_to = $_GET['assigned_to'] ?? '';
$client_id = $_GET['client_id'] ?? '';
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';

// Preserve filter parameters for potential error redirects
$filter_params = [];
if ($status) $filter_params['status'] = $status;
if ($priority) $filter_params['priority'] = $priority;
if ($assigned_to) $filter_params['assigned_to'] = $assigned_to;
if ($client_id) $filter_params['client_id'] = $client_id;
if ($category) $filter_params['category'] = $category;
if ($search) $filter_params['search'] = $search;

$redirect_params = !empty($filter_params) ? '?' . http_build_query($filter_params) : '';

// ========== BUILD QUERY ==========
$query = "SELECT 
            t.*, 
            c.company_name, 
            sp.full_name as primary_assignee_name, 
            u.email as created_by_email, 
            cl.location_name,
            (SELECT COUNT(*) FROM ticket_assignees ta WHERE ta.ticket_id = t.id) as assignee_count,
            (SELECT SUM(total_hours) FROM work_logs wl WHERE wl.ticket_id = t.id) as actual_hours,
            (SELECT COUNT(*) FROM ticket_attachments ta2 WHERE ta2.ticket_id = t.id AND ta2.is_deleted = false) as attachment_count
          FROM tickets t
          LEFT JOIN clients c ON t.client_id = c.id
          LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
          LEFT JOIN users u ON t.created_by = u.id
          LEFT JOIN client_locations cl ON t.location_id = cl.id
          WHERE 1=1";

$params = [];

// Apply filters based on user role
if (!isManager() && !isAdmin()) {
    // Regular staff can only see tickets assigned to them or created by them
    $staff_id = $current_user['staff_profile']['id'] ?? 0;
    if ($staff_id) {
        $query .= " AND (t.assigned_to = ? OR t.created_by = ? OR 
                EXISTS (SELECT 1 FROM ticket_assignees ta WHERE ta.ticket_id = t.id AND ta.staff_id = ?))";
        $params[] = $staff_id;
        $params[] = $current_user['id'];
        $params[] = $staff_id;
    } else {
        // If no staff profile, show only tickets created by user
        $query .= " AND t.created_by = ?";
        $params[] = $current_user['id'];
    }
}

// Add filters
if ($status && $status !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status;
}

if ($priority && $priority !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priority;
}

if ($assigned_to && $assigned_to !== 'all') {
    $query .= " AND t.assigned_to = ?";
    $params[] = $assigned_to;
}

if ($client_id && $client_id !== 'all') {
    $query .= " AND t.client_id = ?";
    $params[] = $client_id;
}

if ($category && $category !== 'all') {
    $query .= " AND t.category = ?";
    $params[] = $category;
}

if ($search) {
    $query .= " AND (t.ticket_number ILIKE ? OR t.title ILIKE ? OR c.company_name ILIKE ? OR t.description ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Add ordering
$query .= " ORDER BY 
            CASE t.priority 
                WHEN 'Critical' THEN 1 
                WHEN 'High' THEN 2 
                WHEN 'Medium' THEN 3 
                WHEN 'Low' THEN 4 
                ELSE 5 
            END,
            t.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {
    $tickets = [];
    error_log("Error in bulk export: " . $e->getMessage());
    header('Location: index.php' . $redirect_params . '&error=' . urlencode('Export failed: ' . $e->getMessage()));
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

// Handle Excel export
if ($export_type === 'excel' || $export_type === 'csv') {
    $filename = "Tickets_Export_" . date('Y-m-d_H-i-s');
    
    if ($export_type === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<html>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<style>";
        echo "table { border-collapse: collapse; width: 100%; }";
        echo "th { background-color: #004E89; color: white; padding: 8px; text-align: left; border: 1px solid #ddd; }";
        echo "td { border: 1px solid #ddd; padding: 8px; }";
        echo ".header { background-color: #f2f2f2; font-weight: bold; }";
        echo "</style>";
        echo "</head>";
        echo "<body>";
        
        echo "<h2>TICKET EXPORT REPORT</h2>";
        echo "<p>Generated: " . date('M j, Y g:i A') . "</p>";
        echo "<p>Total Tickets: " . count($tickets) . "</p>";
        
        echo "<table>";
        echo "<tr>";
        echo "<th>Ticket #</th>";
        echo "<th>Title</th>";
        echo "<th>Client</th>";
        echo "<th>Category</th>";
        echo "<th>Status</th>";
        echo "<th>Priority</th>";
        echo "<th>Assignee</th>";
        echo "<th>Created Date</th>";
        echo "<th>Updated Date</th>";
        echo "<th>Estimated Hours</th>";
        echo "<th>Actual Hours</th>";
        echo "<th>Attachment Count</th>";
        echo "</tr>";
        
        foreach ($tickets as $ticket) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ticket['ticket_number']) . "</td>";
            echo "<td>" . htmlspecialchars($ticket['title']) . "</td>";
            echo "<td>" . htmlspecialchars($ticket['company_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($ticket['category']) . "</td>";
            echo "<td>" . htmlspecialchars($ticket['status']) . "</td>";
            echo "<td>" . htmlspecialchars($ticket['priority']) . "</td>";
            echo "<td>" . htmlspecialchars($ticket['primary_assignee_name'] ?? 'Unassigned') . "</td>";
            echo "<td>" . date('M j, Y g:i A', strtotime($ticket['created_at'])) . "</td>";
            echo "<td>" . date('M j, Y g:i A', strtotime($ticket['updated_at'])) . "</td>";
            echo "<td>" . ($ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00') . "</td>";
            echo "<td>" . ($ticket['actual_hours'] ? number_format($ticket['actual_hours'], 2) : '0.00') . "</td>";
            echo "<td>" . ($ticket['attachment_count'] ?? 0) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        echo "</body>";
        echo "</html>";
    } else { // CSV export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");
        
        // Header row
        fputcsv($output, [
            'Ticket #',
            'Title', 
            'Client',
            'Category',
            'Status',
            'Priority',
            'Assignee',
            'Created Date',
            'Updated Date',
            'Estimated Hours',
            'Actual Hours',
            'Attachment Count'
        ]);
        
        // Data rows
        foreach ($tickets as $ticket) {
            fputcsv($output, [
                $ticket['ticket_number'],
                $ticket['title'],
                $ticket['company_name'] ?? 'N/A',
                $ticket['category'],
                $ticket['status'],
                $ticket['priority'],
                $ticket['primary_assignee_name'] ?? 'Unassigned',
                date('M j, Y g:i A', strtotime($ticket['created_at'])),
                date('M j, Y g:i A', strtotime($ticket['updated_at'])),
                $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00',
                $ticket['actual_hours'] ? number_format($ticket['actual_hours'], 2) : '0.00',
                $ticket['attachment_count'] ?? 0
            ]);
        }
        
        fclose($output);
    }
    
    exit;
}

// Handle PDF export
if ($export_type === 'pdf') {
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        // Try to load TCPDF from vendor
        if (file_exists('../../vendor/autoload.php')) {
            require_once '../../vendor/autoload.php';
        }
    }
    
    if (!class_exists('TCPDF')) {
        // If TCPDF is not available, redirect back with error
        header('Location: index.php' . $redirect_params . '&error=' . urlencode('PDF export requires TCPDF library. Please install it via composer: composer require tecnickcom/tcpdf')); exit;
    }
    
    // Create PDF document
    /** @psalm-suppress UndefinedClass */
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('MSP Application');
    $pdf->SetAuthor('MSP Application');
    $pdf->SetTitle('Tickets Export Report');
    $pdf->SetSubject('Tickets Export');
    $pdf->SetKeywords('tickets, export, report, msp');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'TICKETS EXPORT REPORT', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Generated: ' . date('M j, Y g:i A'), 0, 1, 'C');
    $pdf->Cell(0, 5, 'Total Tickets: ' . count($tickets), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Set font for table
    $pdf->SetFont('helvetica', '', 8);
    
    // Table header
    $pdf->SetFillColor(0, 78, 137); // #004E89
    $pdf->SetTextColor(255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    
    $pdf->Cell(25, 8, 'Ticket #', 1, 0, 'C', true); // Reduced width
    $pdf->Cell(40, 8, 'Title', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Client', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Category', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Priority', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Assignee', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Created', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Updated', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Est. Hours', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Act. Hours', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Attachments', 1, 1, 'C', true);
    
    // Reset text color
    $pdf->SetTextColor(0);
    
    // Table rows
    $fill = false;
    foreach ($tickets as $ticket) {
        $pdf->Cell(25, 6, $ticket['ticket_number'], 1, 0, 'L', $fill);
        $pdf->Cell(40, 6, substr($ticket['title'], 0, 30) . (strlen($ticket['title']) > 30 ? '...' : ''), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, $ticket['company_name'] ?? 'N/A', 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $ticket['category'], 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $ticket['status'], 1, 0, 'L', $fill);
        $pdf->Cell(15, 6, $ticket['priority'], 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, $ticket['primary_assignee_name'] ?? 'Unassigned', 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('M j, Y', strtotime($ticket['created_at'])), 1, 0, 'L', $fill);
        $pdf->Cell(25, 6, date('M j, Y', strtotime($ticket['updated_at'])), 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : '0.00', 1, 0, 'R', $fill);
        $pdf->Cell(20, 6, $ticket['actual_hours'] ? number_format($ticket['actual_hours'], 2) : '0.00', 1, 0, 'R', $fill);
        $pdf->Cell(15, 6, $ticket['attachment_count'] ?? 0, 1, 1, 'C', $fill);
        
        $fill = !$fill;
    }
    
    // Output PDF
    $pdf->Output('Tickets_Export_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
    exit;
}

// If no valid export type, redirect back to index
header('Location: index.php' . $redirect_params);
exit;