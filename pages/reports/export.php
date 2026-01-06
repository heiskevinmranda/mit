<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to export reports.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

// Get export type and parameters
$export_type = $_GET['type'] ?? 'dashboard';
$format = $_GET['format'] ?? 'csv'; // csv, pdf, excel
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$client_id = $_GET['client_id'] ?? '';
$asset_type = $_GET['asset_type'] ?? '';
$status = $_GET['status'] ?? '';

// Get database connection
$pdo = getDBConnection();

// Generate the report data based on type
switch ($export_type) {
    case 'asset':
        // Asset report data
        $sql = "SELECT a.*, c.company_name, cl.location_name
                FROM assets a
                LEFT JOIN clients c ON a.client_id = c.id
                LEFT JOIN client_locations cl ON a.location_id = cl.id
                WHERE a.created_at BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        
        if ($client_id) {
            $sql .= " AND a.client_id = ?";
            $params[] = $client_id;
        }
        
        if ($asset_type) {
            $sql .= " AND a.asset_type = ?";
            $params[] = $asset_type;
        }
        
        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY a.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $headers = ['ID', 'Asset Type', 'Manufacturer', 'Model', 'Serial Number', 'Asset Tag', 'Status', 'Client', 'Location', 'Created At'];
        break;
        
    case 'ticket':
        // Ticket report data
        $sql = "SELECT t.*, c.company_name, u.email as assigned_to
                FROM tickets t
                LEFT JOIN clients c ON t.client_id = c.id
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.created_at BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        
        if ($client_id) {
            $sql .= " AND t.client_id = ?";
            $params[] = $client_id;
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $headers = ['ID', 'Subject', 'Description', 'Priority', 'Status', 'Client', 'Assigned To', 'Created At', 'Resolved At'];
        break;
        
    case 'service':
        // Service contract report data
        $sql = "SELECT sc.*, c.company_name
                FROM contracts sc
                LEFT JOIN clients c ON sc.client_id = c.id
                WHERE sc.created_at BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        
        if ($client_id) {
            $sql .= " AND sc.client_id = ?";
            $params[] = $client_id;
        }
        
        $sql .= " ORDER BY sc.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $headers = ['ID', 'Service Type', 'Description', 'Monthly Amount', 'Annual Amount', 'Status', 'Client', 'Start Date', 'Expiry Date'];
        break;
        
    case 'dashboard':
    default:
        // Dashboard summary report
        $sql = "SELECT 
                    (SELECT COUNT(*) FROM assets) as total_assets,
                    (SELECT COUNT(*) FROM tickets) as total_tickets,
                    (SELECT COUNT(*) FROM contracts) as total_contracts,
                    (SELECT COUNT(*) FROM clients) as total_clients,
                    NOW() as report_date";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data = [$stmt->fetch(PDO::FETCH_ASSOC)];
        
        $headers = ['Total Assets', 'Total Tickets', 'Total Contracts', 'Total Clients', 'Report Date'];
        break;
}

// Function to generate CSV
function generateCSV($headers, $data) {
    $output = fopen('php://temp', 'r+');
    
    // Add headers
    fputcsv($output, $headers);
    
    // Add data rows
    foreach ($data as $row) {
        $csv_row = [];
        foreach ($headers as $header) {
            $header_key = array_search($header, $headers);
            if (isset($row[strtolower(str_replace(' ', '_', $header))])) {
                $csv_row[] = $row[strtolower(str_replace(' ', '_', $header))];
            } else {
                $csv_row[] = '';
            }
        }
        fputcsv($output, $csv_row);
    }
    
    rewind($output);
    return stream_get_contents($output);
}

// Generate and output the file based on format
$filename = $export_type . '_report_' . date('Y-m-d') . '.' . $format;

switch ($format) {
    case 'csv':
    default:
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo generateCSV($headers, $data);
        break;
}
exit;
?>