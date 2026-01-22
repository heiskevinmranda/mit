<?php
// pages/certificates/export.php
// Export certificates to various formats (PDF, Excel, CSV)

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

$current_user = getCurrentUser();
$user_role = $current_user['user_type'] ?? '';

// Check if user has permission to export certificates
if (!in_array($user_role, ['super_admin', 'admin', 'manager'])) {
    $_SESSION['error'] = "You don't have permission to export certificates.";
    header("Location: ../../dashboard.php");
    exit();
}

$pdo = getDBConnection();

// Get export parameters
$format = $_GET['format'] ?? 'pdf';
$type = $_GET['type'] ?? 'all'; // all, pending, approved, rejected
$user_id = $_GET['user_id'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;

// Build query based on filters
$where_conditions = [];
$params = [];

if ($type !== 'all') {
    $where_conditions[] = "c.approval_status = ?";
    $params[] = $type;
}

if ($user_id) {
    $where_conditions[] = "c.user_id = ?";
    $params[] = $user_id;
}

if ($date_from) {
    $where_conditions[] = "c.created_at >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "c.created_at <= ?";
    $params[] = $date_to;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Query to get certificates data
$sql = "
    SELECT 
        c.id, c.certificate_name, c.certificate_type, c.issuing_organization,
        c.issue_date, c.expiry_date, c.certificate_number, c.file_name, 
        c.file_size, c.mime_type, c.status, c.approval_status, c.approval_notes,
        c.rejection_reason, c.created_at, c.updated_at,
        u.email as user_email,
        sp.full_name as user_name,
        sp.designation,
        sp.department
    FROM certificates c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN staff_profiles sp ON c.user_id = sp.user_id
    $where_clause
    ORDER BY c.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$certificates = $stmt->fetchAll();

if (empty($certificates)) {
    $_SESSION['error'] = 'No certificates found to export.';
    header("Location: admin_manage.php");
    exit();
}

// Generate filename
$timestamp = date('Y-m-d_H-i-s');
$filename_prefix = "certificates_export_{$timestamp}";

// Handle different export formats
if ($format === 'csv') {
    // CSV export
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename_prefix . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 to handle special characters in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, [
        'ID', 'Certificate Name', 'Type', 'Issuing Organization', 
        'Issue Date', 'Expiry Date', 'Certificate Number', 
        'File Name', 'File Size', 'MIME Type', 'Status', 
        'Approval Status', 'Approval Notes', 'Rejection Reason', 
        'Created At', 'Updated At', 'User Name', 'User Email', 
        'Designation', 'Department'
    ]);
    
    // Write data rows
    foreach ($certificates as $cert) {
        fputcsv($output, [
            $cert['id'],
            $cert['certificate_name'],
            $cert['certificate_type'],
            $cert['issuing_organization'],
            $cert['issue_date'],
            $cert['expiry_date'],
            $cert['certificate_number'],
            $cert['file_name'],
            $cert['file_size'],
            $cert['mime_type'],
            $cert['status'],
            $cert['approval_status'],
            $cert['approval_notes'],
            $cert['rejection_reason'],
            $cert['created_at'],
            $cert['updated_at'],
            $cert['user_name'],
            $cert['user_email'],
            $cert['designation'],
            $cert['department']
        ]);
    }
    
    fclose($output);
    exit;
    
} elseif ($format === 'excel') {
    // Excel export using PHPExcel or similar
    // Since we don't have PHPExcel installed, we'll create a simple CSV with Excel formatting
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename_prefix . '.xlsx"');
    
    // For now, we'll generate a CSV since we don't have Excel libraries
    // In a real implementation, we would use PhpSpreadsheet or similar
    
    // We'll output a CSV that Excel can read properly
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 to handle special characters in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header row
    fputcsv($output, [
        'ID', 'Certificate Name', 'Type', 'Issuing Organization', 
        'Issue Date', 'Expiry Date', 'Certificate Number', 
        'File Name', 'File Size', 'MIME Type', 'Status', 
        'Approval Status', 'Approval Notes', 'Rejection Reason', 
        'Created At', 'Updated At', 'User Name', 'User Email', 
        'Designation', 'Department'
    ]);
    
    // Write data rows
    foreach ($certificates as $cert) {
        fputcsv($output, [
            $cert['id'],
            $cert['certificate_name'],
            $cert['certificate_type'],
            $cert['issuing_organization'],
            $cert['issue_date'],
            $cert['expiry_date'],
            $cert['certificate_number'],
            $cert['file_name'],
            $cert['file_size'],
            $cert['mime_type'],
            $cert['status'],
            $cert['approval_status'],
            $cert['approval_notes'],
            $cert['rejection_reason'],
            $cert['created_at'],
            $cert['updated_at'],
            $cert['user_name'],
            $cert['user_email'],
            $cert['designation'],
            $cert['department']
        ]);
    }
    
    fclose($output);
    exit;
    
} else {
    // PDF export
    // Since we don't have TCPDF or similar library installed, we'll generate HTML that can be printed as PDF
    
    // First, let's check if TCPDF exists
    if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        // Try to use TCPDF if available
        try {
            if (class_exists('TCPDF')) {
                $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                
                // Set document information
                $pdf->SetCreator(PDF_CREATOR);
                $pdf->SetTitle('Certificate Export');
                $pdf->SetHeaderData('', 0, 'Certificate Export', '');
                $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
                $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
                $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
                $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
                
                // Add a page
                $pdf->AddPage();
                
                // Set font
                $pdf->SetFont('helvetica', '', 12);
                
                // Add title
                $pdf->Cell(0, 15, 'Certificate Export Report', 0, 1, 'C');
                $pdf->Ln(5);
                
                // Add filters info
                $filters = [];
                if ($type !== 'all') $filters[] = "Status: {$type}";
                if ($user_id) $filters[] = "User: {$user_id}";
                if ($date_from) $filters[] = "From: {$date_from}";
                if ($date_to) $filters[] = "To: {$date_to}";
                
                if (!empty($filters)) {
                    $pdf->SetFont('helvetica', 'I', 10);
                    $pdf->Cell(0, 10, 'Filters: ' . implode(', ', $filters), 0, 1, 'L');
                    $pdf->Ln(5);
                }
                
                // Add table header
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->Cell(20, 8, 'ID', 1, 0, 'C');
                $pdf->Cell(40, 8, 'Name', 1, 0, 'C');
                $pdf->Cell(25, 8, 'Type', 1, 0, 'C');
                $pdf->Cell(30, 8, 'Org', 1, 0, 'C');
                $pdf->Cell(20, 8, 'Issue', 1, 0, 'C');
                $pdf->Cell(20, 8, 'Expire', 1, 0, 'C');
                $pdf->Cell(25, 8, 'Status', 1, 0, 'C');
                $pdf->Cell(30, 8, 'User', 1, 1, 'C');
                
                // Add table data
                $pdf->SetFont('helvetica', '', 9);
                foreach ($certificates as $cert) {
                    $pdf->Cell(20, 6, $cert['id'], 1, 0, 'C');
                    $pdf->Cell(40, 6, $cert['certificate_name'], 1, 0, 'L');
                    $pdf->Cell(25, 6, $cert['certificate_type'], 1, 0, 'L');
                    $pdf->Cell(30, 6, $cert['issuing_organization'], 1, 0, 'L');
                    $pdf->Cell(20, 6, $cert['issue_date'], 1, 0, 'C');
                    $pdf->Cell(20, 6, $cert['expiry_date'], 1, 0, 'C');
                    $pdf->Cell(25, 6, $cert['approval_status'], 1, 0, 'C');
                    $pdf->Cell(30, 6, $cert['user_name'] ?: $cert['user_email'], 1, 1, 'L');
                }
                
                // Output PDF
                $pdf->Output($filename_prefix . '.pdf', 'D');
                exit;
            }
        } catch (Exception $e) {
            // TCPDF not available or error occurred, fall back to HTML
        }
    }
    
    // Fallback: Generate HTML that can be saved as PDF
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Certificate Export</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
            }
            .filters {
                margin-bottom: 15px;
                font-style: italic;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 12px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Certificate Export Report</h1>
            <p>Generated on <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <div class="filters">
            <strong>Filters Applied:</strong>
            <?php
            $filters = [];
            if ($type !== 'all') $filters[] = "Status: {$type}";
            if ($user_id) $filters[] = "User ID: {$user_id}";
            if ($date_from) $filters[] = "Date From: {$date_from}";
            if ($date_to) $filters[] = "Date To: {$date_to}";
            
            echo empty($filters) ? 'None' : implode(', ', $filters);
            ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Organization</th>
                    <th>Issue Date</th>
                    <th>Expiry Date</th>
                    <th>Number</th>
                    <th>Status</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certificates as $cert): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cert['id']); ?></td>
                    <td><?php echo htmlspecialchars($cert['certificate_name']); ?></td>
                    <td><?php echo htmlspecialchars($cert['certificate_type']); ?></td>
                    <td><?php echo htmlspecialchars($cert['issuing_organization']); ?></td>
                    <td><?php echo htmlspecialchars($cert['issue_date']); ?></td>
                    <td><?php echo htmlspecialchars($cert['expiry_date']); ?></td>
                    <td><?php echo htmlspecialchars($cert['certificate_number']); ?></td>
                    <td>
                        <span style="padding: 2px 6px; border-radius: 3px; 
                              <?php 
                              echo $cert['approval_status'] === 'approved' ? 'background-color: #d4edda; color: #155724;' : 
                                   ($cert['approval_status'] === 'rejected' ? 'background-color: #f8d7da; color: #721c24;' : 
                                   'background-color: #fff3cd; color: #856404;'); 
                              ?>">
                            <?php echo htmlspecialchars($cert['approval_status']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($cert['user_name'] ?: $cert['user_email']); ?></td>
                    <td><?php echo htmlspecialchars($cert['user_email']); ?></td>
                    <td><?php echo htmlspecialchars($cert['created_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>Total Records: <?php echo count($certificates); ?></p>
            <p>This document was generated by the MSP Application Certificate Management System</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>