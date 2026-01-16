<?php
// includes/client-functions.php - Common functions for client portal

// Prevent multiple inclusions
if (!defined('CLIENT_FUNCTIONS_INCLUDED')) {
    define('CLIENT_FUNCTIONS_INCLUDED', true);
    
    // Database count function
    if (!function_exists('getClientCount')) {
        function getClientCount($pdo, $query, $params = []) {
            try {
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                return $stmt->fetchColumn();
            } catch (Exception $e) {
                error_log("Count query error: " . $e->getMessage());
                return 0;
            }
        }
    }
    
    // Check if user is logged in as client
    if (!function_exists('checkClientLogin')) {
        function checkClientLogin() {
            session_start();
            if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
                header('Location: client-login.php');
                exit;
            }
            return $_SESSION['client_id'] ?? null;
        }
    }
    
    // Get client info
    if (!function_exists('getClientInfo')) {
        function getClientInfo($pdo, $client_id) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                return $stmt->fetch();
            } catch (Exception $e) {
                error_log("Client info error: " . $e->getMessage());
                return null;
            }
        }
    }
    
    // Format date
    if (!function_exists('formatDate')) {
        function formatDate($date, $format = 'M d, Y') {
            if (empty($date)) return 'N/A';
            return date($format, strtotime($date));
        }
    }
    
    // Get status badge class
    if (!function_exists('getStatusBadge')) {
        function getStatusBadge($status) {
            $status = strtolower($status);
            switch ($status) {
                case 'open':
                case 'new':
                    return 'bg-primary';
                case 'in progress':
                case 'processing':
                    return 'bg-warning';
                case 'closed':
                case 'resolved':
                case 'completed':
                    return 'bg-success';
                case 'pending':
                    return 'bg-info';
                case 'cancelled':
                case 'rejected':
                    return 'bg-danger';
                default:
                    return 'bg-secondary';
            }
        }
    }
    
    // Get priority badge class
    if (!function_exists('getPriorityBadge')) {
        function getPriorityBadge($priority) {
            $priority = strtolower($priority);
            switch ($priority) {
                case 'high':
                case 'critical':
                    return 'bg-danger';
                case 'medium':
                case 'normal':
                    return 'bg-warning';
                case 'low':
                    return 'bg-info';
                default:
                    return 'bg-secondary';
            }
        }
    }
}
?>