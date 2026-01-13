<?php
require_once '../../includes/auth.php';
requireLogin();

// Check permission - reports should be accessible to managers and above
if (!hasPermission('manager') && !hasPermission('admin') && !hasPermission('support_tech')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => 'You do not have permission to view reports.'
    ];
    header('Location: ../../dashboard.php');
    exit;
}

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get report type from query parameter
$report_type = $_GET['type'] ?? 'dashboard';
$time_period = $_GET['period'] ?? 'month';
$client_id = $_GET['client_id'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get overall statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM assets) as total_assets,
    (SELECT COUNT(*) FROM tickets) as total_tickets,
    (SELECT COUNT(*) FROM contracts) as total_contracts,
    (SELECT COUNT(*) FROM clients) as total_clients,
    (SELECT COUNT(*) FROM users) as total_users
";

$stmt = $pdo->query($stats_sql);
$stats = $stmt->fetch();

// Function to generate report based on type
function generateReport($type, $pdo, $params = []) {
    switch($type) {
        case 'client_overview':
            return getClientOverviewReport($pdo, $params);
        case 'service_expiry':
            return getServiceExpiryReport($pdo, $params);
        case 'contract_summary':
            return getContractSummaryReport($pdo, $params);
        case 'asset_inventory':
            return getAssetInventoryReport($pdo, $params);
        case 'security_services':
            return getSecurityServicesReport($pdo, $params);
        case 'ticket_volume':
            return getTicketVolumeReport($pdo, $params);
        case 'sla_compliance':
            return getSLAComplianceReport($pdo, $params);
        case 'staff_performance':
            return getStaffPerformanceReport($pdo, $params);
        case 'warranty_expiry':
            return getWarrantyExpiryReport($pdo, $params);
        case 'site_visit_summary':
            return getSiteVisitSummaryReport($pdo, $params);
        case 'monthly_revenue':
            return getMonthlyRevenueReport($pdo, $params);
        case 'dns_status':
            return getDNSStatusReport($pdo, $params);
        case 'user_activity':
            return getUserActivityReport($pdo, $params);
        case 'client_health':
            return getClientHealthDashboard($pdo, $params);
        default:
            return ['title' => 'Report', 'data' => []];
    }
}

// Report generation functions
function getClientOverviewReport($pdo, $params) {
    $sql = "SELECT 
        c.id,
        c.company_name,
        c.contact_person,
        c.email,
        c.phone,
        c.status as client_status,
        COUNT(DISTINCT cs.id) as service_count,
        COUNT(DISTINCT con.id) as contract_count,
        COUNT(DISTINCT loc.id) as location_count,
        COUNT(DISTINCT t.id) as active_tickets,
        COALESCE(AVG(t.rating), 0) as avg_rating,
        MAX(t.created_at) as last_ticket_date
    FROM clients c
    LEFT JOIN client_services cs ON cs.client_id = c.id AND cs.status = 'Active'
    LEFT JOIN contracts con ON con.client_id = c.id AND con.status = 'Active'
    LEFT JOIN client_locations loc ON loc.client_id = c.id
    LEFT JOIN tickets t ON t.client_id = c.id AND t.status IN ('Open', 'In Progress')
    WHERE c.status = 'Active'
    GROUP BY c.id, c.company_name, c.contact_person, c.email, c.phone, c.status
    ORDER BY c.company_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Client Overview Report',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Company', 'Contact', 'Services', 'Contracts', 'Locations', 'Active Tickets', 'Avg Rating']
    ];
}

function getServiceExpiryReport($pdo, $params) {
    $days = $params['days'] ?? 90;
    $sql = "SELECT 
        cs.id,
        c.company_name,
        cs.service_name,
        cs.service_category,
        cs.expiry_date,
        cs.auto_renew,
        cs.status,
        cs.monthly_price,
        cs.billing_cycle,
        DATEDIFF(cs.expiry_date, CURRENT_DATE) as days_until_expiry,
        sr.renewal_date as last_renewal,
        cs.notes
    FROM client_services cs
    JOIN clients c ON c.id = cs.client_id
    LEFT JOIN service_renewals sr ON sr.client_service_id = cs.id 
        AND sr.renewal_date = (SELECT MAX(renewal_date) FROM service_renewals WHERE client_service_id = cs.id)
    WHERE cs.status = 'Active'
        AND cs.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '$days days'
    ORDER BY cs.expiry_date";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => "Service Expiry Report (Next $days Days)",
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Service', 'Category', 'Expiry Date', 'Days Until', 'Auto Renew', 'Monthly Price']
    ];
}

function getContractSummaryReport($pdo, $params) {
    $sql = "SELECT 
        con.id,
        c.company_name,
        con.contract_number,
        con.contract_type,
        con.start_date,
        con.end_date,
        con.monthly_amount,
        con.response_time_hours,
        con.resolution_time_hours,
        con.status,
        COUNT(DISTINCT s.id) as sla_count,
        COALESCE(SUM(cs.monthly_price), 0) as total_service_value
    FROM contracts con
    JOIN clients c ON c.id = con.client_id
    LEFT JOIN sla_configurations s ON s.contract_id = con.id
    LEFT JOIN client_services cs ON cs.client_id = c.id AND cs.status = 'Active'
    WHERE con.status = 'Active'
    GROUP BY con.id, c.company_name, con.contract_number, con.contract_type, 
             con.start_date, con.end_date, con.monthly_amount, con.response_time_hours,
             con.resolution_time_hours, con.status
    ORDER BY con.end_date";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Contract Summary Report',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Contract #', 'Type', 'Start', 'End', 'Monthly Amount', 'SLA Count', 'Total Service Value']
    ];
}

function getAssetInventoryReport($pdo, $params) {
    $sql = "SELECT 
        a.id,
        c.company_name,
        loc.location_name,
        a.asset_type,
        a.manufacturer,
        a.model,
        a.serial_number,
        a.asset_tag,
        a.status,
        a.purchase_date,
        a.warranty_expiry,
        a.amc_expiry,
        a.license_expiry,
        a.subscription_expiry,
        a.is_managed_service,
        sp.full_name as assigned_to,
        CASE 
            WHEN a.warranty_expiry < CURRENT_DATE THEN 'Warranty Expired'
            WHEN a.amc_expiry < CURRENT_DATE THEN 'AMC Expired'
            WHEN a.license_expiry < CURRENT_DATE THEN 'License Expired'
            ELSE 'Active'
        END as compliance_status
    FROM assets a
    LEFT JOIN clients c ON c.id = a.client_id
    LEFT JOIN client_locations loc ON loc.id = a.location_id
    LEFT JOIN staff_profiles sp ON sp.id = a.assigned_to
    ORDER BY c.company_name, a.asset_type";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Asset Inventory Report',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Location', 'Asset Type', 'Manufacturer', 'Model', 'Serial', 'Status', 'Warranty Expiry', 'Assigned To']
    ];
}

function getSecurityServicesReport($pdo, $params) {
    $sql = "SELECT 
        ss.id,
        c.company_name,
        ss.service_type,
        ss.vendor,
        ss.product_name,
        ss.subscription_id,
        ss.seats,
        ss.start_date,
        ss.expiry_date,
        ss.renewal_date,
        ss.auto_renew,
        ss.status,
        ss.monthly_cost,
        ss.last_sync_date,
        ss.installation_date
    FROM security_services ss
    JOIN clients c ON c.id = ss.client_id
    WHERE ss.status = 'Active'
    ORDER BY ss.expiry_date";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Security Services Report',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Service Type', 'Vendor', 'Product', 'Seats', 'Expiry Date', 'Monthly Cost', 'Status']
    ];
}

function getTicketVolumeReport($pdo, $params) {
    $period = $params['period'] ?? 'month';
    $group_by = $period == 'week' ? "DATE_TRUNC('week', created_at)" : "DATE_TRUNC('month', created_at)";
    
    $sql = "SELECT 
        $group_by as period,
        COUNT(*) as total_tickets,
        COUNT(CASE WHEN status = 'Closed' THEN 1 END) as closed_tickets,
        COUNT(CASE WHEN status IN ('Open', 'In Progress') THEN 1 END) as open_tickets,
        COUNT(CASE WHEN priority = 'High' THEN 1 END) as high_priority,
        COUNT(CASE WHEN priority = 'Medium' THEN 1 END) as medium_priority,
        COUNT(CASE WHEN priority = 'Low' THEN 1 END) as low_priority,
        AVG(CASE WHEN status = 'Closed' THEN EXTRACT(EPOCH FROM (closed_at - created_at))/3600 END) as avg_resolution_hours
    FROM tickets
    WHERE created_at >= CURRENT_DATE - INTERVAL '6 months'
    GROUP BY period
    ORDER BY period";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => "Ticket Volume Report (Last 6 Months by $period)",
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Period', 'Total Tickets', 'Closed', 'Open', 'High Priority', 'Medium Priority', 'Low Priority', 'Avg Resolution Hours']
    ];
}

function getSLAComplianceReport($pdo, $params) {
    $sql = "SELECT 
        t.id,
        t.ticket_number,
        c.company_name,
        t.priority,
        t.status,
        t.created_at,
        t.closed_at,
        t.actual_resolution_time as actual_hours,
        s.resolution_time as sla_hours,
        CASE 
            WHEN t.actual_resolution_time <= s.resolution_time THEN 'Within SLA'
            ELSE 'SLA Breach'
        END as sla_status,
        t.rating,
        sp.full_name as assigned_engineer
    FROM tickets t
    JOIN clients c ON c.id = t.client_id
    LEFT JOIN contracts con ON con.client_id = c.id
    LEFT JOIN sla_configurations s ON s.contract_id = con.id AND s.priority = t.priority
    LEFT JOIN staff_profiles sp ON sp.id = t.assigned_to
    WHERE t.status = 'Closed'
        AND t.created_at >= CURRENT_DATE - INTERVAL '3 months'
    ORDER BY t.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'SLA Compliance Report (Last 3 Months)',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Ticket #', 'Client', 'Priority', 'Created', 'Closed', 'Actual Hours', 'SLA Hours', 'Status', 'Rating', 'Engineer']
    ];
}

function getStaffPerformanceReport($pdo, $params) {
    $sql = "SELECT 
        sp.id,
        sp.staff_id,
        sp.full_name,
        sp.designation,
        sp.department,
        COUNT(DISTINCT t.id) as total_tickets_assigned,
        COUNT(DISTINCT CASE WHEN t.status = 'Closed' THEN t.id END) as tickets_closed,
        COUNT(DISTINCT wl.id) as work_logs_count,
        COALESCE(SUM(wl.total_hours), 0) as total_hours_logged,
        COUNT(DISTINCT sv.id) as site_visits_count,
        AVG(t.rating) as avg_client_rating,
        COUNT(DISTINCT CASE WHEN t.actual_resolution_time <= sla.resolution_time THEN t.id END) as sla_compliant_tickets,
        MAX(t.updated_at) as last_activity
    FROM staff_profiles sp
    LEFT JOIN tickets t ON (t.assigned_to = sp.id OR t.primary_assignee = sp.id)
        AND t.created_at >= CURRENT_DATE - INTERVAL '1 month'
    LEFT JOIN work_logs wl ON wl.staff_id = sp.id 
        AND wl.work_date >= CURRENT_DATE - INTERVAL '1 month'
    LEFT JOIN site_visits sv ON sv.engineer_id = sp.id
        AND sv.created_at >= CURRENT_DATE - INTERVAL '1 month'
    LEFT JOIN (
        SELECT sc.priority, sc.resolution_time, c.client_id
        FROM sla_configurations sc
        JOIN contracts c ON c.id = sc.contract_id
    ) sla ON sla.priority = t.priority AND sla.client_id = t.client_id
    WHERE sp.employment_status = 'Active'
    GROUP BY sp.id, sp.staff_id, sp.full_name, sp.designation, sp.department
    ORDER BY tickets_closed DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Staff Performance Report (Last Month)',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Staff ID', 'Name', 'Department', 'Tickets Assigned', 'Tickets Closed', 'Hours Logged', 'Site Visits', 'Avg Rating', 'SLA Compliant']
    ];
}

function getWarrantyExpiryReport($pdo, $params) {
    $days = $params['days'] ?? 60;
    $sql = "SELECT 
        a.id,
        c.company_name,
        a.asset_type,
        a.manufacturer,
        a.model,
        a.serial_number,
        a.warranty_expiry,
        a.amc_expiry,
        a.license_expiry,
        DATEDIFF(LEAST(
            COALESCE(a.warranty_expiry, '9999-12-31'),
            COALESCE(a.amc_expiry, '9999-12-31'),
            COALESCE(a.license_expiry, '9999-12-31')
        ), CURRENT_DATE) as days_until_expiry,
        CASE 
            WHEN a.warranty_expiry <= CURRENT_DATE + INTERVAL '$days days' THEN 'Warranty'
            WHEN a.amc_expiry <= CURRENT_DATE + INTERVAL '$days days' THEN 'AMC'
            WHEN a.license_expiry <= CURRENT_DATE + INTERVAL '$days days' THEN 'License'
            ELSE 'None'
        END as expiring_type,
        a.status,
        sp.full_name as assigned_to
    FROM assets a
    LEFT JOIN clients c ON c.id = a.client_id
    LEFT JOIN staff_profiles sp ON sp.id = a.assigned_to
    WHERE a.status = 'Active'
        AND (
            a.warranty_expiry <= CURRENT_DATE + INTERVAL '$days days'
            OR a.amc_expiry <= CURRENT_DATE + INTERVAL '$days days'
            OR a.license_expiry <= CURRENT_DATE + INTERVAL '$days days'
        )
    ORDER BY days_until_expiry";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => "Warranty/AMC/License Expiry Report (Next $days Days)",
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Asset Type', 'Manufacturer', 'Model', 'Serial', 'Warranty Expiry', 'AMC Expiry', 'License Expiry', 'Days Until', 'Expiring Type', 'Assigned To']
    ];
}

function getSiteVisitSummaryReport($pdo, $params) {
    $sql = "SELECT 
        sv.id,
        t.ticket_number,
        c.company_name,
        loc.location_name,
        sp.full_name as engineer,
        sv.check_in_time,
        sv.check_out_time,
        EXTRACT(EPOCH FROM (sv.check_out_time - sv.check_in_time))/3600 as duration_hours,
        sv.work_description,
        sv.parts_used,
        COUNT(DISTINCT svp.id) as photo_count,
        sv.created_at
    FROM site_visits sv
    LEFT JOIN tickets t ON t.id = sv.ticket_id
    LEFT JOIN clients c ON c.id = sv.client_id
    LEFT JOIN client_locations loc ON loc.id = sv.location_id
    LEFT JOIN staff_profiles sp ON sp.id = sv.engineer_id
    LEFT JOIN site_visit_photos svp ON svp.site_visit_id = sv.id
    WHERE sv.created_at >= CURRENT_DATE - INTERVAL '3 months'
    GROUP BY sv.id, t.ticket_number, c.company_name, loc.location_name, sp.full_name,
             sv.check_in_time, sv.check_out_time, sv.work_description, sv.parts_used, sv.created_at
    ORDER BY sv.check_in_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Site Visit Summary Report (Last 3 Months)',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Ticket #', 'Client', 'Location', 'Engineer', 'Check-in', 'Check-out', 'Duration (hours)', 'Work Description', 'Parts Used', 'Photos']
    ];
}

function getMonthlyRevenueReport($pdo, $params) {
    $sql = "SELECT 
        DATE_TRUNC('month', con.start_date) as month,
        c.company_name,
        con.contract_type,
        con.monthly_amount as contract_revenue,
        COALESCE(SUM(cs.monthly_price), 0) as service_revenue,
        COALESCE(SUM(ss.monthly_cost), 0) as security_revenue,
        con.monthly_amount + COALESCE(SUM(cs.monthly_price), 0) + COALESCE(SUM(ss.monthly_cost), 0) as total_revenue
    FROM contracts con
    JOIN clients c ON c.id = con.client_id
    LEFT JOIN client_services cs ON cs.client_id = c.id AND cs.status = 'Active'
    LEFT JOIN security_services ss ON ss.client_id = c.id AND ss.status = 'Active'
    WHERE con.status = 'Active'
        AND con.start_date >= CURRENT_DATE - INTERVAL '12 months'
    GROUP BY DATE_TRUNC('month', con.start_date), c.company_name, con.contract_type, con.monthly_amount
    ORDER BY month DESC, total_revenue DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Monthly Revenue Report (Last 12 Months)',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Month', 'Client', 'Contract Type', 'Contract Revenue', 'Service Revenue', 'Security Revenue', 'Total Revenue']
    ];
}

function getDNSStatusReport($pdo, $params) {
    $sql = "SELECT 
        dns.id,
        c.company_name,
        cs.service_name,
        dns.record_type,
        dns.host,
        dns.value,
        dns.ttl,
        dns.is_active,
        dns.last_updated,
        CASE 
            WHEN dns.last_updated < CURRENT_DATE - INTERVAL '30 days' THEN 'Stale'
            ELSE 'Current'
        END as update_status
    FROM dns_records dns
    JOIN client_services cs ON cs.id = dns.client_service_id
    JOIN clients c ON c.id = cs.client_id
    WHERE dns.is_active = true
    ORDER BY c.company_name, cs.service_name, dns.record_type";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'DNS Record Status Report',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Service', 'Record Type', 'Host', 'Value', 'TTL', 'Active', 'Last Updated', 'Update Status']
    ];
}

function getUserActivityReport($pdo, $params) {
    $sql = "SELECT 
        al.id,
        u.email,
        sp.full_name,
        al.action,
        al.entity_type,
        al.entity_id,
        al.details,
        al.ip_address,
        al.created_at
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN staff_profiles sp ON sp.user_id = al.user_id
    WHERE al.created_at >= CURRENT_DATE - INTERVAL '7 days'
    ORDER BY al.created_at DESC
    LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'User Activity Audit Log (Last 7 Days)',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['User', 'Name', 'Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address', 'Timestamp']
    ];
}

function getClientHealthDashboard($pdo, $params) {
    $sql = "SELECT 
        c.id,
        c.company_name,
        c.contact_person,
        c.status as client_status,
        COUNT(DISTINCT cs.id) as active_services,
        COUNT(DISTINCT CASE WHEN cs.expiry_date <= CURRENT_DATE + INTERVAL '30 days' THEN cs.id END) as expiring_services,
        COUNT(DISTINCT t.id) as active_tickets,
        COUNT(DISTINCT CASE WHEN t.status = 'Closed' AND t.rating <= 2 THEN t.id END) as low_rating_tickets,
        COUNT(DISTINCT CASE WHEN t.actual_resolution_time > sla.resolution_time THEN t.id END) as sla_breaches,
        AVG(t.rating) as avg_rating,
        CASE 
            WHEN COUNT(DISTINCT t.id) = 0 THEN 'No Activity'
            WHEN AVG(t.rating) < 3 OR COUNT(DISTINCT CASE WHEN t.actual_resolution_time > sla.resolution_time THEN t.id END) > 3 THEN 'High Risk'
            WHEN COUNT(DISTINCT CASE WHEN cs.expiry_date <= CURRENT_DATE + INTERVAL '30 days' THEN cs.id END) > 2 THEN 'Renewal Attention'
            WHEN AVG(t.rating) >= 4 AND COUNT(DISTINCT CASE WHEN t.actual_resolution_time > sla.resolution_time THEN t.id END) = 0 THEN 'Healthy'
            ELSE 'Needs Monitoring'
        END as health_status
    FROM clients c
    LEFT JOIN client_services cs ON cs.client_id = c.id AND cs.status = 'Active'
    LEFT JOIN tickets t ON t.client_id = c.id AND t.created_at >= CURRENT_DATE - INTERVAL '90 days'
    LEFT JOIN (
        SELECT sc.priority, sc.resolution_time, con.client_id
        FROM sla_configurations sc
        JOIN contracts con ON con.id = sc.contract_id
    ) sla ON sla.priority = t.priority AND sla.client_id = c.id
    WHERE c.status = 'Active'
    GROUP BY c.id, c.company_name, c.contact_person, c.status
    ORDER BY 
        CASE health_status
            WHEN 'High Risk' THEN 1
            WHEN 'Renewal Attention' THEN 2
            WHEN 'Needs Monitoring' THEN 3
            WHEN 'Healthy' THEN 4
            ELSE 5
        END,
        avg_rating";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return [
        'title' => 'Client Health Dashboard',
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'columns' => ['Client', 'Contact', 'Active Services', 'Expiring Services', 'Active Tickets', 'Low Rating Tickets', 'SLA Breaches', 'Avg Rating', 'Health Status']
    ];
}

// Generate report if requested
if ($report_type !== 'dashboard') {
    $report = generateReport($report_type, $pdo, [
        'days' => $_GET['days'] ?? 90,
        'period' => $time_period,
        'client_id' => $client_id,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
}

// Helper functions
function formatNumber($number) {
    return number_format($number);
}

function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

function getHealthStatusClass($status) {
    $classes = [
        'Healthy' => 'status-active',
        'Needs Monitoring' => 'status-maintenance',
        'Renewal Attention' => 'status-warning',
        'High Risk' => 'status-inactive',
        'No Activity' => 'status-retired'
    ];
    return $classes[$status] ?? 'status-retired';
}

function getSLAStatusClass($status) {
    return $status == 'Within SLA' ? 'status-active' : 'status-inactive';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        }
        .report-card h3 {
            color: #004E89;
            border-bottom: 2px solid #f0f8ff;
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 1.4rem;
            font-weight: 600;
        }
        .report-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .report-type-item {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .report-type-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #004E89;
        }
        .report-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, #004E89, #1a6cb0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 1.8rem;
            color: white;
        }
        .report-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        .report-desc {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 15px;
            flex-grow: 1;
        }
        .report-category {
            font-size: 0.75rem;
            color: #004E89;
            background: #e8f4ff;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 10px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        .data-table-container {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-top: 20px;
        }
        .data-table th {
            position: sticky;
            top: 0;
            background: #004E89;
            color: white;
            z-index: 10;
            text-align: left;
            padding: 12px;
        }
        .data-table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
        }
        .data-table tr:hover {
            background-color: #f8f9fa;
        }
        .export-options {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .status-warning { background: #fff3cd; color: #856404; }
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        .nav-tabs .nav-link {
            color: #666;
            font-weight: 500;
            border: none;
            padding: 10px 20px;
        }
        .nav-tabs .nav-link.active {
            color: #004E89;
            border-bottom: 3px solid #004E89;
            background: transparent;
        }
        .report-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f8ff;
        }
        .date-range {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #004E89;
            margin: 10px 0;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .trend-indicator {
            font-size: 0.9rem;
            margin-top: 5px;
        }
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .insight-card {
            background: linear-gradient(135deg, #004E89, #1a6cb0);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .report-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            .data-table-container {
                max-height: none;
                overflow: visible;
            }
        }
        .pagination {
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="header">
                <div>
                    <h1><i class="fas fa-chart-bar"></i> Reports Dashboard</h1>
                    <p class="text-muted">Comprehensive reports and analytics for your MSP</p>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($current_user['staff_profile']['designation'] ?? ucfirst($current_user['user_type'])); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reports</li>
                </ol>
            </nav>
            
            <?php if ($report_type === 'dashboard'): ?>
            <!-- Dashboard View -->
            <div class="insight-card">
                <div class="row">
                    <div class="col-md-8">
                        <h3 class="text-white"><i class="fas fa-lightbulb"></i> Executive Insights</h3>
                        <p class="text-white-50 mb-0">
                            • <?php echo formatNumber($stats['total_assets']); ?> assets under management<br>
                            • <?php echo formatNumber($stats['total_tickets']); ?> support tickets handled<br>
                            • <?php echo formatNumber($stats['total_contracts']); ?> active service contracts<br>
                            • <?php echo formatNumber($stats['total_clients']); ?> clients served
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="display-4 text-white">87</div>
                        <small class="text-white-50">Overall Health Score</small>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: 87%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo formatNumber($stats['total_clients']); ?></div>
                        <div class="stat-label">Active Clients</div>
                        <div class="trend-indicator trend-up">
                            <i class="fas fa-arrow-up"></i> 12% from last month
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo formatNumber($stats['total_tickets']); ?></div>
                        <div class="stat-label">Total Tickets</div>
                        <div class="trend-indicator trend-up">
                            <i class="fas fa-arrow-up"></i> 8% from last month
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value">94%</div>
                        <div class="stat-label">SLA Compliance</div>
                        <div class="trend-indicator trend-up">
                            <i class="fas fa-arrow-up"></i> 2% improvement
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value">4.2</div>
                        <div class="stat-label">Avg Client Rating</div>
                        <div class="trend-indicator trend-neutral">
                            <i class="fas fa-minus"></i> Stable
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Report Categories -->
            <div class="report-card">
                <h3><i class="fas fa-folder"></i> Report Categories</h3>
                
                <!-- Category Tabs -->
                <ul class="nav nav-tabs mb-4" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="client-tab" data-bs-toggle="tab" data-bs-target="#client" type="button">Client & Service</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="ticket-tab" data-bs-toggle="tab" data-bs-target="#ticket" type="button">Ticket & Support</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="staff-tab" data-bs-toggle="tab" data-bs-target="#staff" type="button">Staff Performance</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="asset-tab" data-bs-toggle="tab" data-bs-target="#asset" type="button">Asset & Inventory</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button">Financial</button>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content" id="reportTabsContent">
                    <!-- Client & Service Reports -->
                    <div class="tab-pane fade show active" id="client" role="tabpanel">
                        <div class="report-type-grid">
                            <!-- Client Overview -->
                            <a href="?type=client_overview" class="report-type-item text-decoration-none">
                                <span class="report-category">Client & Service</span>
                                <div class="report-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="report-title">Client Overview Report</div>
                                <div class="report-desc">List all active clients with services, contracts, and locations</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Essential</span>
                                    <span class="badge badge-secondary">Account Management</span>
                                </div>
                            </a>
                            
                            <!-- Service Expiry -->
                            <a href="?type=service_expiry&days=30" class="report-type-item text-decoration-none">
                                <span class="report-category">Client & Service</span>
                                <div class="report-icon">
                                    <i class="fas fa-calendar-times"></i>
                                </div>
                                <div class="report-title">Service Expiry Report</div>
                                <div class="report-desc">Services expiring in next 30/60/90 days</div>
                                <div class="mt-2">
                                    <span class="badge badge-warning">Renewal</span>
                                    <span class="badge badge-secondary">Sales</span>
                                </div>
                            </a>
                            
                            <!-- Contract Summary -->
                            <a href="?type=contract_summary" class="report-type-item text-decoration-none">
                                <span class="report-category">Client & Service</span>
                                <div class="report-icon">
                                    <i class="fas fa-file-contract"></i>
                                </div>
                                <div class="report-title">Contract Summary</div>
                                <div class="report-desc">Active contracts with SLAs and monthly amounts</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Management</span>
                                </div>
                            </a>
                            
                            <!-- Client Health Dashboard -->
                            <a href="?type=client_health" class="report-type-item text-decoration-none">
                                <span class="report-category">Client & Service</span>
                                <div class="report-icon">
                                    <i class="fas fa-heartbeat"></i>
                                </div>
                                <div class="report-title">Client Health Dashboard</div>
                                <div class="report-desc">Monthly health status per client with risk indicators</div>
                                <div class="mt-2">
                                    <span class="badge badge-danger">High Priority</span>
                                    <span class="badge badge-info">Executive</span>
                                </div>
                            </a>
                            
                            <!-- Security Services -->
                            <a href="?type=security_services" class="report-type-item text-decoration-none">
                                <span class="report-category">Client & Service</span>
                                <div class="report-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="report-title">Security Services Report</div>
                                <div class="report-desc">Subscription status, license counts, renewal dates</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Security</span>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Ticket & Support Reports -->
                    <div class="tab-pane fade" id="ticket" role="tabpanel">
                        <div class="report-type-grid">
                            <!-- Ticket Volume -->
                            <a href="?type=ticket_volume&period=month" class="report-type-item text-decoration-none">
                                <span class="report-category">Ticket & Support</span>
                                <div class="report-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="report-title">Ticket Volume Analysis</div>
                                <div class="report-desc">Weekly/monthly ticket counts by priority and status</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Operations</span>
                                </div>
                            </a>
                            
                            <!-- SLA Compliance -->
                            <a href="?type=sla_compliance" class="report-type-item text-decoration-none">
                                <span class="report-category">Ticket & Support</span>
                                <div class="report-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="report-title">SLA Compliance Report</div>
                                <div class="report-desc">Tickets resolved within SLA vs breached</div>
                                <div class="mt-2">
                                    <span class="badge badge-warning">Performance</span>
                                </div>
                            </a>
                            
                            <!-- Average Resolution Time -->
                            <div class="report-type-item">
                                <span class="report-category">Ticket & Support</span>
                                <div class="report-icon">
                                    <i class="fas fa-stopwatch"></i>
                                </div>
                                <div class="report-title">Resolution Time Analysis</div>
                                <div class="report-desc">By staff, department, or client (Coming Soon)</div>
                                <div class="mt-2">
                                    <span class="badge badge-secondary">Planned</span>
                                </div>
                            </div>
                            
                            <!-- Client Feedback -->
                            <div class="report-type-item">
                                <span class="report-category">Ticket & Support</span>
                                <div class="report-icon">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="report-title">Client Feedback Report</div>
                                <div class="report-desc">Average ratings per client/engineer (Coming Soon)</div>
                                <div class="mt-2">
                                    <span class="badge badge-secondary">Planned</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Staff Performance Reports -->
                    <div class="tab-pane fade" id="staff" role="tabpanel">
                        <div class="report-type-grid">
                            <!-- Staff Performance -->
                            <a href="?type=staff_performance" class="report-type-item text-decoration-none">
                                <span class="report-category">Staff Performance</span>
                                <div class="report-icon">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                                <div class="report-title">Staff Performance Report</div>
                                <div class="report-desc">Tickets closed, hours logged, site visits, ratings</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">HR</span>
                                    <span class="badge badge-secondary">Management</span>
                                </div>
                            </a>
                            
                            <!-- Engineer Utilization -->
                            <div class="report-type-item">
                                <span class="report-category">Staff Performance</span>
                                <div class="report-icon">
                                    <i class="fas fa-chart-pie"></i>
                                </div>
                                <div class="report-title">Engineer Utilization</div>
                                <div class="report-desc">Hours worked vs tickets assigned (Coming Soon)</div>
                                <div class="mt-2">
                                    <span class="badge badge-secondary">Planned</span>
                                </div>
                            </div>
                            
                            <!-- Department Performance -->
                            <div class="report-type-item">
                                <span class="report-category">Staff Performance</span>
                                <div class="report-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="report-title">Department Performance</div>
                                <div class="report-desc">Closure rates, SLA compliance (Coming Soon)</div>
                                <div class="mt-2">
                                    <span class="badge badge-secondary">Planned</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Asset & Inventory Reports -->
                    <div class="tab-pane fade" id="asset" role="tabpanel">
                        <div class="report-type-grid">
                            <!-- Asset Inventory -->
                            <a href="?type=asset_inventory" class="report-type-item text-decoration-none">
                                <span class="report-category">Asset & Inventory</span>
                                <div class="report-icon">
                                    <i class="fas fa-server"></i>
                                </div>
                                <div class="report-title">Asset Inventory Report</div>
                                <div class="report-desc">All assets per client with warranty/AMC details</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Inventory</span>
                                </div>
                            </a>
                            
                            <!-- Warranty Expiry -->
                            <a href="?type=warranty_expiry&days=60" class="report-type-item text-decoration-none">
                                <span class="report-category">Asset & Inventory</span>
                                <div class="report-icon">
                                    <i class="fas fa-calendar-exclamation"></i>
                                </div>
                                <div class="report-title">Warranty Expiry Report</div>
                                <div class="report-desc">Assets with expiring warranties or AMCs</div>
                                <div class="mt-2">
                                    <span class="badge badge-warning">Renewal</span>
                                </div>
                            </a>
                            
                            <!-- Site Visit Summary -->
                            <a href="?type=site_visit_summary" class="report-type-item text-decoration-none">
                                <span class="report-category">Asset & Inventory</span>
                                <div class="report-icon">
                                    <i class="fas fa-tools"></i>
                                </div>
                                <div class="report-title">Site Visit Summary</div>
                                <div class="report-desc">Visits per engineer/client with parts usage</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Field Service</span>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Financial Reports -->
                    <div class="tab-pane fade" id="financial" role="tabpanel">
                        <div class="report-type-grid">
                            <!-- Monthly Revenue -->
                            <a href="?type=monthly_revenue" class="report-type-item text-decoration-none">
                                <span class="report-category">Financial</span>
                                <div class="report-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="report-title">Monthly Revenue Report</div>
                                <div class="report-desc">By client, service type, and contract</div>
                                <div class="mt-2">
                                    <span class="badge badge-info">Finance</span>
                                    <span class="badge badge-secondary">Executive</span>
                                </div>
                            </a>
                            
                            <!-- Renewal Forecast -->
                            <div class="report-type-item">
                                <span class="report-category">Financial</span>
                                <div class="report-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="report-title">Renewal Revenue Forecast</div>
                                <div class="report-desc">Upcoming renewals and amounts (Coming Soon)</div>
                                <div class="mt-2">
                                    <span class="badge badge-secondary">Planned</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Reports -->
            <div class="report-card">
                <h3><i class="fas fa-cogs"></i> Additional Reports</h3>
                <div class="row">
                    <div class="col-md-4">
                        <a href="?type=dns_status" class="report-type-item text-decoration-none">
                            <span class="report-category">Security</span>
                            <div class="report-icon">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div class="report-title">DNS Record Status</div>
                            <div class="report-desc">Active/inactive records per client service</div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="?type=user_activity" class="report-type-item text-decoration-none">
                            <span class="report-category">Audit</span>
                            <div class="report-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="report-title">User Activity Audit</div>
                            <div class="report-desc">Actions taken by users (from audit_logs)</div>
                        </a>
                    </div>
                    <div class="col-md-4">
                        <div class="report-type-item">
                            <span class="report-category">Analytical</span>
                            <div class="report-icon">
                                <i class="fas fa-chart-scatter"></i>
                            </div>
                            <div class="report-title">Custom Analytics</div>
                            <div class="report-desc">Client satisfaction vs ticket volume (Coming Soon)</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Specific Report View -->
            <div class="report-actions no-print">
                <div>
                    <a href="?type=dashboard" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left"></i> Back to Reports Dashboard
                    </a>
                    <h3 class="d-inline-block ms-3 mb-0"><?php echo htmlspecialchars($report['title']); ?></h3>
                </div>
                <div class="export-options">
                    <button onclick="window.print()" class="btn btn-outline-secondary">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="exportToCSV()" class="btn btn-outline-success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-outline-primary">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
            
            <!-- Report Filters -->
            <div class="filter-section no-print">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                    
                    <?php if (in_array($report_type, ['service_expiry', 'warranty_expiry'])): ?>
                    <div class="col-md-3">
                        <label class="form-label">Days Ahead</label>
                        <select name="days" class="form-select" onchange="this.form.submit()">
                            <option value="30" <?php echo ($_GET['days'] ?? 90) == 30 ? 'selected' : ''; ?>>30 Days</option>
                            <option value="60" <?php echo ($_GET['days'] ?? 90) == 60 ? 'selected' : ''; ?>>60 Days</option>
                            <option value="90" <?php echo ($_GET['days'] ?? 90) == 90 ? 'selected' : ''; ?>>90 Days</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report_type == 'ticket_volume'): ?>
                    <div class="col-md-3">
                        <label class="form-label">Period</label>
                        <select name="period" class="form-select" onchange="this.form.submit()">
                            <option value="week" <?php echo $time_period == 'week' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="month" <?php echo $time_period == 'month' ? 'selected' : ''; ?>>Monthly</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Clients</option>
                            <?php
                            $clients = $pdo->query("SELECT id, company_name FROM clients WHERE status='Active' ORDER BY company_name")->fetchAll();
                            foreach ($clients as $client) {
                                $selected = ($client_id == $client['id']) ? 'selected' : '';
                                echo "<option value='{$client['id']}' $selected>{$client['company_name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?type=<?php echo htmlspecialchars($report_type); ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Report Summary Stats -->
            <?php if (!empty($report['data'])): ?>
            <div class="row mb-4 no-print">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($report['data']); ?></div>
                        <div class="stat-label">Total Records</div>
                    </div>
                </div>
                <?php if ($report_type == 'client_health'): ?>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value">
                            <?php 
                            $healthy = array_filter($report['data'], fn($row) => $row['health_status'] == 'Healthy');
                            echo count($healthy);
                            ?>
                        </div>
                        <div class="stat-label">Healthy Clients</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value">
                            <?php 
                            $risk = array_filter($report['data'], fn($row) => $row['health_status'] == 'High Risk');
                            echo count($risk);
                            ?>
                        </div>
                        <div class="stat-label">High Risk Clients</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Report Data Table -->
            <div class="report-card">
                <h3><?php echo htmlspecialchars($report['title']); ?></h3>
                <div class="data-table-container">
                    <table class="table data-table">
                        <thead>
                            <tr>
                                <?php if (!empty($report['columns'])): ?>
                                    <?php foreach ($report['columns'] as $column): ?>
                                        <th><?php echo htmlspecialchars($column); ?></th>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($report['data'])): ?>
                                    <?php foreach (array_keys($report['data'][0]) as $column): ?>
                                        <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $column))); ?></th>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['data'])): ?>
                                <tr>
                                    <td colspan="100" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No data found for the selected criteria</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($report['data'] as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $key => $value): ?>
                                            <td>
                                                <?php 
                                                // Format based on column type
                                                if (strpos($key, 'date') !== false || strpos($key, 'Date') !== false) {
                                                    echo $value ? date('M d, Y', strtotime($value)) : '-';
                                                } elseif (strpos($key, 'time') !== false || strpos($key, 'Time') !== false) {
                                                    echo $value ? date('M d, Y H:i', strtotime($value)) : '-';
                                                } elseif (strpos($key, 'amount') !== false || strpos($key, 'price') !== false || strpos($key, 'cost') !== false || strpos($key, 'revenue') !== false) {
                                                    echo $value ? formatCurrency($value) : '-';
                                                } elseif ($key == 'health_status') {
                                                    echo "<span class='badge " . getHealthStatusClass($value) . "'>$value</span>";
                                                } elseif ($key == 'sla_status') {
                                                    echo "<span class='badge " . getSLAStatusClass($value) . "'>$value</span>";
                                                } elseif ($key == 'status' || $key == 'client_status') {
                                                    echo "<span class='badge " . ($value == 'Active' ? 'badge-success' : 'badge-secondary') . "'>$value</span>";
                                                } elseif (is_numeric($value) && !in_array($key, ['id', 'client_id', 'service_id'])) {
                                                    echo formatNumber($value);
                                                } else {
                                                    echo htmlspecialchars($value ?? '-');
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if (count($report['data']) > 50): ?>
                <div class="no-print">
                    <nav aria-label="Report pagination">
                        <ul class="pagination">
                            <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                            <li class="page-item active"><a class="page-link" href="#">1</a></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item"><a class="page-link" href="#">Next</a></li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Functions -->
            <script>
                function exportToCSV() {
                    const table = document.querySelector('.data-table');
                    const rows = table.querySelectorAll('tr');
                    const csv = [];
                    
                    rows.forEach(row => {
                        const rowData = [];
                        row.querySelectorAll('th, td').forEach(cell => {
                            // Remove HTML tags and get text content
                            const text = cell.innerText.replace(/,/g, ';');
                            rowData.push(`"${text}"`);
                        });
                        csv.push(rowData.join(','));
                    });
                    
                    const csvContent = csv.join('\n');
                    const blob = new Blob([csvContent], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = '<?php echo str_replace(" ", "_", $report["title"]); ?>_<?php echo date("Y-m-d"); ?>.csv';
                    a.click();
                }
                
                function exportToExcel() {
                    alert('Excel export would be implemented with a library like SheetJS');
                    // For production, you would use SheetJS or similar library
                    // window.location.href = 'export.php?type=excel&report=<?php echo $report_type; ?>';
                }
            </script>
            <?php endif; ?>
            
        </main>
    </div>
    
    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('select').select2({
                width: '100%'
            });
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Auto-refresh for certain reports
            const autoRefreshReports = ['client_health', 'ticket_volume'];
            const currentReport = '<?php echo $report_type; ?>';
            
            if (autoRefreshReports.includes(currentReport)) {
                setTimeout(() => {
                    location.reload();
                }, 300000); // Refresh every 5 minutes
            }
        });
    </script>
</body>
</html>