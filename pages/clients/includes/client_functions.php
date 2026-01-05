<?php
// pages/clients/includes/client_functions.php

/**
 * Check if user has permission for client actions
 */
function hasClientPermission($action) {
    global $user_role;
    
    if (!isset($user_role)) {
        $user_role = $_SESSION['user_type'] ?? null;
    }
    
    $allowed_roles = ['super_admin', 'admin', 'manager', 'support_tech', 'client'];
    
    // Everyone can view
    if ($action === 'view') {
        return in_array($user_role, $allowed_roles);
    }
    
    // Only manager and super_admin can create/edit/delete
    if (in_array($action, ['create', 'edit', 'delete'])) {
        return in_array($user_role, ['super_admin', 'manager', 'admin']);
    }
    
    return false;
}

/**
 * Get client by ID
 */
function getClientById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get client locations
 */
function getClientLocations($pdo, $client_id) {
    $stmt = $pdo->prepare("SELECT * FROM client_locations WHERE client_id = ? ORDER BY is_primary DESC");
    $stmt->execute([$client_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validate client data
 */
function validateClientData($data) {
    $errors = [];
    
    if (empty(trim($data['company_name']))) {
        $errors[] = "Company name is required";
    }
    
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (!empty($data['website']) && !filter_var($data['website'], FILTER_VALIDATE_URL)) {
        $errors[] = "Invalid website URL";
    }
    
    return $errors;
}

/**
 * Get all industries from clients
 */
function getAllIndustries($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT industry FROM clients WHERE industry IS NOT NULL AND industry != '' ORDER BY industry");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Count tickets for client
 */
function countClientTickets($pdo, $client_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE client_id = ?");
    $stmt->execute([$client_id]);
    return $stmt->fetchColumn();
}

/**
 * Count active contracts for client
 */
function countActiveContracts($pdo, $client_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'Active'");
    $stmt->execute([$client_id]);
    return $stmt->fetchColumn();
}

/**
 * Count assets for client
 */
function countClientAssets($pdo, $client_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE client_id = ? AND status = 'Active'");
    $stmt->execute([$client_id]);
    return $stmt->fetchColumn();
}

/**
 * Format phone number
 */
function formatPhone($phone) {
    if (empty($phone)) return '';
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Format based on length
    if (strlen($phone) === 10) {
        return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
    }
    
    return $phone;
}

/**
 * Get countries list
 */
function getCountries() {
    return [
        'United States',
        'Canada',
        'United Kingdom',
        'Australia',
        'India',
        'Germany',
        'France',
        'Japan',
        'China',
        'Singapore',
        'Malaysia',
        'UAE',
        'Saudi Arabia',
        'South Africa',
        'Brazil',
        'Mexico'
    ];
}

/**
 * Get client status options
 */
function getClientStatusOptions() {
    return [
        'Active' => 'Active',
        'Inactive' => 'Inactive',
        'Prospect' => 'Prospect',
        'Lead' => 'Lead',
        'Archived' => 'Archived'
    ];
}

/**
 * Check if client can be deleted
 */
function canDeleteClient($pdo, $client_id) {
    // Check if client has any tickets
    $ticketStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE client_id = ?");
    $ticketStmt->execute([$client_id]);
    $ticketCount = $ticketStmt->fetchColumn();
    
    // Check if client has any contracts
    $contractStmt = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE client_id = ?");
    $contractStmt->execute([$client_id]);
    $contractCount = $contractStmt->fetchColumn();
    
    // Check if client has any assets
    $assetStmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE client_id = ?");
    $assetStmt->execute([$client_id]);
    $assetCount = $assetStmt->fetchColumn();
    
    return ($ticketCount == 0 && $contractCount == 0 && $assetCount == 0);
}

/**
 * Export clients to CSV
 */
function exportClientsToCSV($pdo, $clients) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="clients_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, [
        'ID', 'Company Name', 'Contact Person', 'Email', 'Phone', 
        'Website', 'Industry', 'Status', 'Address', 'City', 
        'State', 'Country', 'Postal Code', 'Created At', 'Updated At'
    ]);
    
    // Data rows
    foreach ($clients as $client) {
        fputcsv($output, [
            $client['id'],
            $client['company_name'],
            $client['contact_person'],
            $client['email'],
            $client['phone'],
            $client['website'],
            $client['industry'],
            $client['status'],
            $client['address'],
            $client['city'],
            $client['state'],
            $client['country'],
            $client['postal_code'],
            $client['created_at'],
            $client['updated_at']
        ]);
    }
    
    fclose($output);
    exit;
}
?>