<?php
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

// Get export parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$client_id = $_GET['client_id'] ?? '';
$priority = $_GET['priority'] ?? '';
$status = $_GET['status'] ?? '';

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

// Get ticket statistics
$tickets_sql = "SELECT 
    COUNT(*) as total_tickets,
    COUNT(CASE WHEN status = 'Open' THEN 1 END) as open_tickets,
    COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_tickets,
    COUNT(CASE WHEN status = 'Resolved' THEN 1 END) as resolved_tickets,
    COUNT(CASE WHEN status = 'Closed' THEN 1 END) as closed_tickets,
    COUNT(CASE WHEN priority = 'Critical' THEN 1 END) as critical_tickets,
    COUNT(CASE WHEN priority = 'High' THEN 1 END) as high_priority_tickets,
    CASE 
        WHEN COUNT(CASE WHEN status IN ('Resolved', 'Closed') THEN 1 END) > 0 THEN 
            AVG(CASE WHEN status IN ('Resolved', 'Closed') THEN EXTRACT(EPOCH FROM (closed_at - created_at))/86400.0 END)
        ELSE 0 
    END as avg_resolution_days
FROM tickets t
WHERE $where_sql";

$stmt = $pdo->prepare($tickets_sql);
for ($i = 0; $i < count($params); $i++) {
    $stmt->bindValue($i + 1, $params[$i], $types[$i]);
}
$stmt->execute();
$tickets_stats = $stmt->fetch();

// Get ticket distribution by priority
$duplicated_params = array_merge($params, $params);
$duplicated_types = array_merge($types, $types);
$priority_distribution_sql = "SELECT 
    priority,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tickets t WHERE $where_sql), 1) as percentage
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

// Get ticket distribution by status
$status_distribution_sql = "SELECT 
    status,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tickets t WHERE $where_sql), 1) as percentage
FROM tickets t
WHERE $where_sql
GROUP BY status
ORDER BY count DESC";

$stmt = $pdo->prepare($status_distribution_sql);
for ($i = 0; $i < count($duplicated_params); $i++) {
    $stmt->bindValue($i + 1, $duplicated_params[$i], $duplicated_types[$i]);
}
$stmt->execute();
$status_distribution = $stmt->fetchAll();

// Get recent tickets
$recent_tickets_sql = "SELECT 
    t.*,
    t.ticket_number,
    t.title,
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
    'status' => $status
];

// Generate PDF
$pdf_generator = new TicketReportPDFGenerator($report_data, $filters);
$pdf = $pdf_generator->generate();

// Output PDF
$pdf_filename = 'ticket_report_' . date('Y-m-d_H-i-s') . '.pdf';
$pdf->Output($pdf_filename, 'D'); // 'D' forces download
?>