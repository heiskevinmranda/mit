<?php
ob_start(); // Start output buffering to prevent any output before PDF headers
require_once '../../includes/auth.php';
require_once '../../includes/ticket_report_pdf_generator.php';
requireLogin();

// Check permission - reports should be accessible to managers and above
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to export reports.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$pdo = getDBConnection();

// Check if this is a PDF export request
$export_type = $_GET['export_type'] ?? $_POST['export_type'] ?? '';
if ($export_type !== 'pdf') {
    // Not a PDF export request, redirect to reports page
    header('Location: ../ticket_report.php');
    exit;
}

// Get export parameters - Support both GET and POST methods
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_POST['end_date'] ?? date('Y-m-d');
    $client_id = $_POST['client_id'] ?? '';
    $priority = $_POST['priority'] ?? '';
    $status = $_POST['status'] ?? '';
    $custom_suggestions = $_POST['custom_suggestions'] ?? '';
    $detailed_suggestion_title = $_POST['detailed_suggestion_title'] ?? '';
    $detailed_suggestion_description = $_POST['detailed_suggestion_description'] ?? '';
    $additional_comments = $_POST['additional_comments'] ?? '';
    
    // Handle file uploads
    $upload_dir = '../../uploads/reports/' . uniqid() . '/';
    if (!is_dir(dirname(__DIR__, 2) . '/uploads/reports/')) {
        mkdir(dirname(__DIR__, 2) . '/uploads/reports/', 0755, true);
    }
    
    $before_image_path = '';
    $after_image_path = '';
    
    if (isset($_FILES['before_image']) && $_FILES['before_image']['error'] === UPLOAD_ERR_OK) {
        $before_tmp_name = $_FILES['before_image']['tmp_name'];
        $before_name = $_FILES['before_image']['name'];
        $before_extension = pathinfo($before_name, PATHINFO_EXTENSION);
        $before_filename = 'before_' . uniqid() . '.' . $before_extension;
        
        if (!is_dir(dirname(__DIR__, 2) . '/' . $upload_dir)) {
            mkdir(dirname(__DIR__, 2) . '/' . $upload_dir, 0755, true);
        }
        
        if (move_uploaded_file($before_tmp_name, dirname(__DIR__, 2) . $upload_dir . $before_filename)) {
            $before_image_path = $upload_dir . $before_filename;
        }
    }
    
    if (isset($_FILES['after_image']) && $_FILES['after_image']['error'] === UPLOAD_ERR_OK) {
        $after_tmp_name = $_FILES['after_image']['tmp_name'];
        $after_name = $_FILES['after_image']['name'];
        $after_extension = pathinfo($after_name, PATHINFO_EXTENSION);
        $after_filename = 'after_' . uniqid() . '.' . $after_extension;
        
        if (!is_dir(dirname(__DIR__, 2) . '/' . $upload_dir)) {
            mkdir(dirname(__DIR__, 2) . '/' . $upload_dir, 0755, true);
        }
        
        if (move_uploaded_file($after_tmp_name, dirname(__DIR__, 2) . $upload_dir . $after_filename)) {
            $after_image_path = $upload_dir . $after_filename;
        }
    }
} else {
    // GET request
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $client_id = $_GET['client_id'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $status = $_GET['status'] ?? '';
    $custom_suggestions = $_GET['custom_suggestions'] ?? '';
    $detailed_suggestion_title = $_GET['detailed_suggestion_title'] ?? '';
    $detailed_suggestion_description = $_GET['detailed_suggestion_description'] ?? '';
    $additional_comments = $_GET['additional_comments'] ?? '';
    $before_image_path = '';
    $after_image_path = '';
}

// Build where clause for reports
$where_conditions = ['t.created_at BETWEEN ? AND ?'];
$params = [$start_date, $end_date];
$types = [PDO::PARAM_STR, PDO::PARAM_STR];

if ($client_id) {
    $where_conditions[] = "t.client_id = ?";
    $params[] = $client_id;
    $types[] = PDO::PARAM_STR;
}

if ($priority) {
    $where_conditions[] = "t.priority = ?";
    $params[] = $priority;
    $types[] = PDO::PARAM_STR;
}

if ($status) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status;
    $types[] = PDO::PARAM_STR;
}

$where_sql = implode(' AND ', $where_conditions);

// Add the total count for percentage calculations
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $where_sql");
for ($i = 0; $i < count($params); $i++) {
    $total_stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$total_stmt->execute();
$total_count = $total_stmt->fetchColumn();

// Get ticket statistics
$tickets_sql = "SELECT 
    COUNT(*) as total_tickets,
    COUNT(CASE WHEN status = 'Open' THEN 1 END) as open_tickets,
    COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_tickets,
    COUNT(CASE WHEN status = 'Resolved' THEN 1 END) as resolved_tickets,
    COUNT(CASE WHEN status = 'Closed' THEN 1 END) as closed_tickets,
    COUNT(CASE WHEN priority = 'Critical' THEN 1 END) as critical_tickets,
    COUNT(CASE WHEN priority = 'High' THEN 1 END) as high_priority_tickets,
    0 as avg_resolution_days  -- Placeholder to avoid complex query issues
FROM tickets t
WHERE $where_sql";

$stmt = $pdo->prepare($tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$tickets_stats = $stmt->fetch();

// Get ticket distribution by priority
$duplicated_params = $params;
$duplicated_types = $types;
$priority_distribution_sql = "SELECT 
    priority,
    COUNT(*) as count
FROM tickets t
WHERE $where_sql
GROUP BY priority
ORDER BY count DESC";

$stmt = $pdo->prepare($priority_distribution_sql);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$priority_distribution = $stmt->fetchAll();

// Calculate percentages in PHP to avoid parameter binding issues
foreach ($priority_distribution as &$row) {
    $row['percentage'] = $total_count > 0 ? round(($row['count'] * 100.0) / $total_count, 1) : 0;
}

// Get ticket distribution by status
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count
FROM tickets t
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Calculate percentages in PHP to avoid parameter binding issues
foreach ($status_distribution as &$row) {
    $row['percentage'] = $total_count > 0 ? round(($row['count'] * 100.0) / $total_count, 1) : 0;
}

// Get recent tickets
$recent_tickets_sql = "SELECT 
    t.*,
    t.ticket_number,
    t.title,
    t.requested_by,
    c.company_name,
    sp.full_name as assigned_to
FROM tickets t
LEFT JOIN clients c ON t.client_id = c.id
LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
WHERE $where_sql
ORDER BY t.created_at DESC
LIMIT 50"; // Get more tickets for the report

$stmt = $pdo->prepare($recent_tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$recent_tickets = $stmt->fetchAll();

// Prepare report data
$report_data = [
    'stats' => $tickets_stats,
    'priority_distribution' => $priority_distribution,
    'status_distribution' => $status_distribution,
    'recent_tickets' => $recent_tickets
];

// Prepare filters
$filters = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'client_id' => $client_id,
    'priority' => $priority,
    'status' => $status,
    'custom_suggestions' => $custom_suggestions,
    'detailed_suggestion_title' => $detailed_suggestion_title,
    'detailed_suggestion_description' => $detailed_suggestion_description,
    'additional_comments' => $additional_comments,
    'before_image_path' => $before_image_path,
    'after_image_path' => $after_image_path
];

// Generate PDF
try {
    // Log the start of PDF generation
    error_log("Ticket Report Export: Starting PDF generation at " . date('Y-m-d H:i:s') . " - Parameters: " . json_encode([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'client_id' => $client_id,
        'total_tickets_found' => $tickets_stats['total_tickets'] ?? 0,
        'recent_tickets_count' => count($recent_tickets)
    ]));
    
    $pdf_generator = new TicketReportPDFGenerator($report_data, $filters);
    error_log("Ticket Report Export: PDF generator created successfully");
    
    $pdf = $pdf_generator->generate();
    error_log("Ticket Report Export: PDF generated successfully, size: " . ($pdf ? strlen($pdf->Output('', 'S')) : 'unknown') . " bytes");
    
    // Clean any previous output before sending PDF headers
    ob_end_clean();
    
    // Output PDF
    $pdf_filename = 'ticket_report_' . date('Y-m-d_H-i-s') . '.pdf';
    error_log("Ticket Report Export: Attempting to output PDF as: $pdf_filename");
    $pdf->Output($pdf_filename, 'D'); // 'D' forces download
    
} catch (Exception $e) {
    // Log detailed error information
    error_log("Ticket Report Export ERROR: " . $e->getMessage() . 
              " | File: " . $e->getFile() . 
              " | Line: " . $e->getLine() . 
              " | Trace: " . $e->getTraceAsString() .
              " | Report Data Keys: " . json_encode(array_keys($report_data)) .
              " | Filters: " . json_encode($filters));
    
    // Also log the raw error to a specific log file
    $error_details = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error_message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'params' => [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'client_id' => $client_id,
            'priority' => $priority,
            'status' => $status,
            'total_tickets' => $tickets_stats['total_tickets'] ?? 'unknown'
        ],
        'user' => $_SESSION['email'] ?? 'unknown'
    ];
    
    // Write to dedicated error log file
    $log_dir = __DIR__ . '/../../logs';
    $log_file = $log_dir . '/ticket_report_errors.log';
    
    // Ensure log directory exists
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_entry = "\n[" . date('Y-m-d H:i:s') . "] Ticket Report Export Error:\n" . 
                json_encode($error_details, JSON_PRETTY_PRINT) . "\n";
    
    if (is_writable($log_dir) || !file_exists($log_file) || is_writable($log_file)) {
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    } else {
        // If we can't write to the dedicated log, at least log to PHP error log
        error_log("Could not write to dedicated error log. Error details: " . json_encode($error_details));
    }
    
    // Clean the output buffer before redirecting
    ob_end_clean();
    
    // Redirect to 500 error page
    header('Location: ../errors/500.php');
    exit;
}
?>