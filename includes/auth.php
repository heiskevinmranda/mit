<?php
// includes/auth.php - COMPLETE VERSION

// Set timezone to Dar es Salaam
ini_set('date.timezone', 'Africa/Dar_es_Salaam');
// Prevent browser caching of authenticated pages
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Include database configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/error_handler.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========== AUTHENTICATION FUNCTIONS ==========

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Require user to be logged in, redirect if not
 */
function requireLogin() {
    if (!isLoggedIn() || !isSessionValid()) {
        handle401Error('You must be logged in to access this page.');
    }
}

/**
 * Check if user has specific permission
 */
function checkPermission($required_role) {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    // Role hierarchy (higher number = more permissions)
    $role_hierarchy = [
        'super_admin' => 5,
        'admin' => 4,
        'manager' => 3,
        'support_tech' => 2,
        'client' => 1
    ];
    
    $user_role = $_SESSION['user_type'];
    $user_level = isset($role_hierarchy[$user_role]) ? $role_hierarchy[$user_role] : 0;
    $required_level = isset($role_hierarchy[$required_role]) ? $role_hierarchy[$required_role] : 0;
    
    return $user_level >= $required_level;
}

/**
 * Require specific permission, redirect if not authorized
 */
function requirePermission($required_role) {
    if (!isLoggedIn() || !isSessionValid() || !checkPermission($required_role)) {
        if (!isLoggedIn() || !isSessionValid()) {
            handle401Error('You must be logged in to access this resource.');
        } else {
            handle403Error('You do not have permission to access this resource.');
        }
    }
}

/**
 * Check if user is Super Admin
 */
function isSuperAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin';
}

/**
 * Check if user is Admin or higher
 */
function isAdmin() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'super_admin']);
}

/**
 * Check if user has admin-level privileges or higher (based on role hierarchy)
 */
function hasAdminLevel() {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    // Role hierarchy (higher number = more permissions)
    $role_hierarchy = [
        'super_admin' => 5,
        'admin' => 4,
        'manager' => 3,
        'support_tech' => 2,
        'client' => 1
    ];
    
    $user_role = $_SESSION['user_type'];
    $user_level = isset($role_hierarchy[$user_role]) ? $role_hierarchy[$user_role] : 0;
    
    // Admin level is 4 or higher
    return $user_level >= 4;
}

/**
 * Check if user has support tech-level privileges or higher (based on role hierarchy)
 */
function hasSupportTechLevel() {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    // Role hierarchy (higher number = more permissions)
    $role_hierarchy = [
        'super_admin' => 5,
        'admin' => 4,
        'manager' => 3,
        'support_tech' => 2,
        'client' => 1
    ];
    
    $user_role = $_SESSION['user_type'];
    $user_level = isset($role_hierarchy[$user_role]) ? $role_hierarchy[$user_role] : 0;
    
    // Support tech level is 2 or higher
    return $user_level >= 2;
}

/**
 * Check if user is Manager or higher
 */
function isManager() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['manager', 'admin', 'super_admin']);
}

/**
 * Check if user has manager-level privileges or higher (based on role hierarchy)
 */
function hasManagerLevel() {
    if (!isset($_SESSION['user_type'])) {
        return false;
    }
    
    // Role hierarchy (higher number = more permissions)
    $role_hierarchy = [
        'super_admin' => 5,
        'admin' => 4,
        'manager' => 3,
        'support_tech' => 2,
        'client' => 1
    ];
    
    $user_role = $_SESSION['user_type'];
    $user_level = isset($role_hierarchy[$user_role]) ? $role_hierarchy[$user_role] : 0;
    
    // Manager level is 3 or higher
    return $user_level >= 3;
}

/**
 * Check if user is Staff/Engineer or higher
 */
function isStaff() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['staff', 'support_tech', 'engineer', 'manager', 'admin', 'super_admin']);
}

/**
 * Get current user information
 */
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'user_type' => $_SESSION['user_type'] ?? null,
        'staff_profile' => $_SESSION['staff_profile'] ?? null
    ];
}

/**
 * Set flash message for next request
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Attempt to login user
 */
function attemptLogin($email, $password) {
    $pdo = getDBConnection();
    
    try {
        // Get user from database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_active']) {
                // Update last login
                $stmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                // Get staff profile if exists
                $staff_profile = null;
                if (in_array($user['user_type'], ['super_admin', 'admin', 'manager', 'support_tech', 'staff', 'engineer'])) {
                    $stmt = $pdo->prepare("SELECT * FROM staff_profiles WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $staff_profile = $stmt->fetch();
                }
                
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['staff_profile'] = $staff_profile;
                
                // Store session validation data
                $_SESSION['last_activity'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                
                return ['success' => true, 'user' => $user];
            } else {
                return ['success' => false, 'error' => 'Account is deactivated'];
            }
        } else {
            return ['success' => false, 'error' => 'Invalid email or password'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Logout user
 */
function logout() {
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page directly instead of using route function to avoid issues after session destruction
    header('Location: /mit/login');
    exit;
}

/**
 * Validate session timeout
 */
function isSessionValid() {
    if (!isset($_SESSION['last_activity']) || !isset($_SESSION['user_ip'])) {
        // If these values don't exist, the session might be corrupted
        session_destroy();
        return false;
    }
    
    // Check if session has been active for too long (1 hour timeout)
    if (time() - $_SESSION['last_activity'] > 3600) {
        session_destroy();
        return false;
    }
    
    // Update last activity
    $_SESSION['last_activity'] = time();
    
    // Check if IP has changed (basic security check)
    if ($_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR']) {
        session_destroy();
        return false;
    }
    
    return true;
}

// ========== HELPER FUNCTIONS ==========

/**
 * Check if user can access a specific page
 * Alias for checkPermission for backward compatibility
 */
function hasPermission($required_role) {
    return checkPermission($required_role);
}

/**
 * Require login - alias for requireLogin for backward compatibility
 */
function checkAuth() {
    requireLogin();
}

/**
 * Get user's full name
 */
function getUserFullName() {
    $user = getCurrentUser();
    return $user['staff_profile']['full_name'] ?? $user['email'];
}

/**
 * Get user's designation
 */
function getUserDesignation() {
    $user = getCurrentUser();
    return $user['staff_profile']['designation'] ?? ucfirst($user['user_type']);
}

/**
 * Check if current user can edit a resource
 */
function canEdit($resource_user_id = null) {
    if (!isset($_SESSION['user_id'])) return false;
    
    // Super admin can edit everything
    if (isSuperAdmin()) return true;
    
    // Admin can edit everything except super admin
    if (isAdmin()) return true;
    
    // Users can edit their own resources
    if ($resource_user_id && $_SESSION['user_id'] == $resource_user_id) return true;
    
    return false;
}

/**
 * Check if current user can delete a resource
 */
function canDelete($resource_user_id = null) {
    // Only admins and super admins can delete
    return isAdmin();
}
?>