<?php
// includes/permissions.php

// Include auth to get the functions
require_once __DIR__ . '/auth.php';

/**
 * Check if user can view clients
 */
function canViewClients() {
    return isLoggedIn(); // All logged in users can view clients
}

/**
 * Check if user can create clients
 */
function canCreateClients() {
    return isManager(); // Only manager and above
}

/**
 * Check if user can edit clients
 */
function canEditClients() {
    return isManager(); // Only manager and above
}

/**
 * Check if user can delete clients
 */
function canDeleteClients() {
    return isManager(); // Only manager and above
}

/**
 * Check if user can view tickets
 */
function canViewTickets() {
    return isLoggedIn(); // All logged in users
}

/**
 * Check if user can create tickets
 */
function canCreateTickets() {
    return isLoggedIn(); // All logged in users
}

/**
 * Check if user can assign tickets
 */
function canAssignTickets() {
    return isManager(); // Only manager and above
}

/**
 * Check if user can delete tickets
 */
function canDeleteTickets() {
    return isManager(); // Only manager and above (manager, admin, super_admin)
}

/**
 * Check if user can upload certificates
 */
function canUploadCertificates() {
    return isLoggedIn(); // All logged in users can upload certificates for themselves
}

/**
 * Check if user can view certificates
 */
function canViewCertificates() {
    return isLoggedIn(); // All logged in users can view their own certificates
}

/**
 * Check if user can manage certificates (approve/reject)
 */
function canManageCertificates() {
    $current_user = getCurrentUser();
    $user_type = $current_user['user_type'] ?? '';
    
    // Only super admin, admin, and manager can manage certificates
    return in_array($user_type, ['super_admin', 'admin', 'manager']);
}

/**
 * Check if user can delete certificates
 */
function canDeleteCertificates() {
    $current_user = getCurrentUser();
    $user_type = $current_user['user_type'] ?? '';
    
    // Users can delete their own certificates, admins/managers can delete any
    return in_array($user_type, ['super_admin', 'admin', 'manager']);
}

/**
 * Check if user can export certificates
 */
function canExportCertificates() {
    $current_user = getCurrentUser();
    $user_type = $current_user['user_type'] ?? '';
    
    // Only admins and above can export certificates
    return in_array($user_type, ['super_admin', 'admin', 'manager']);
}

/**
 * Check if user can view certificate management dashboard
 */
function canViewCertificateManagement() {
    $current_user = getCurrentUser();
    $user_type = $current_user['user_type'] ?? '';
    
    // Only admins and managers can view the certificate management dashboard
    return in_array($user_type, ['super_admin', 'admin', 'manager']);
}
?>