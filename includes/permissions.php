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
?>