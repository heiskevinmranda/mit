<?php
/**
 * Error Handler Utilities
 * 
 * Provides functions for handling different types of errors in the application
 * and redirecting to appropriate error pages using the routing system.
 */

/**
 * Handle 404 - Page Not Found error
 */
function handle404Error($message = "Page not found") {
    // Prevent redirect loops by displaying error directly if we're already on an error page
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/pages/errors/') !== false) {
        // We're already on an error page, just show the message
        http_response_code(404);
        echo "<h1>404 Page Not Found</h1><p>The requested page was not found.</p>";
        exit();
    }
    
    // Set session message if needed
    if (!headers_sent()) {
        $_SESSION['error'] = $message;
    }
    
    // Redirect to 404 error page using clean URL
    $error_url = '/mit/errors/404';
    header("Location: $error_url");
    exit();
}

/**
 * Handle 403 - Access Denied error
 */
function handle403Error($message = "Access denied") {
    // Prevent redirect loops by displaying error directly if we're already on an error page
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/pages/errors/') !== false) {
        // We're already on an error page, just show the message
        http_response_code(403);
        echo "<h1>403 Access Forbidden</h1><p>Access to this resource is forbidden.</p>";
        exit();
    }
    
    // Set session message if needed
    if (!headers_sent()) {
        $_SESSION['error'] = $message;
    }
    
    // Redirect to 403 error page using clean URL
    $error_url = '/mit/errors/403';
    header("Location: $error_url");
    exit();
}

/**
 * Handle 401 - Unauthorized error
 */
function handle401Error($message = "Unauthorized access") {
    // Prevent redirect loops by displaying error directly if we're already on an error page
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/pages/errors/') !== false) {
        // We're already on an error page, just show the message
        http_response_code(401);
        echo "<h1>401 Unauthorized</h1><p>You need to authenticate to access this resource.</p>";
        exit();
    }
    
    // Set session message if needed
    if (!headers_sent()) {
        $_SESSION['error'] = $message;
    }
    
    // Redirect to 401 error page using clean URL
    $error_url = '/mit/errors/401';
    header("Location: $error_url");
    exit();
}

/**
 * Handle 500 - Server Error
 */
function handle500Error($message = "Internal server error") {
    // Log the error for debugging
    error_log($message);
    
    // Prevent redirect loops by displaying error directly if we're already on an error page
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/pages/errors/') !== false) {
        // We're already on an error page, just show the message
        http_response_code(500);
        echo "<h1>500 Internal Server Error</h1><p>An internal server error occurred.</p>";
        exit();
    }
    
    // Set session message if needed
    if (!headers_sent()) {
        $_SESSION['error'] = $message;
    }
    
    // Redirect to 500 error page using clean URL
    $error_url = '/mit/errors/500';
    header("Location: $error_url");
    exit();
}

/**
 * Generic error handler that redirects to appropriate error page based on error code
 */
function handleError($error_code, $message = "") {
    switch ($error_code) {
        case 401:
            handle401Error($message ?: "Unauthorized access");
            break;
        case 403:
            handle403Error($message ?: "Access denied");
            break;
        case 404:
            handle404Error($message ?: "Page not found");
            break;
        case 500:
        default:
            handle500Error($message ?: "Internal server error");
            break;
    }
}

/**
 * Check if a route exists, if not handle 404 error
 */
function checkRouteExists($route_name) {
    if (class_exists('RouteManager') && RouteManager::hasRoute($route_name)) {
        // Route exists, do nothing
    } else {
        handle404Error("Route '$route_name' does not exist");
    }
}

/**
 * Function to display inline error messages in the application
 */
function displayError($message, $type = 'danger') {
    $type_class = "alert-$type";
    return '<div class="alert ' . $type_class . ' alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                ' . htmlspecialchars($message) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>';
}

/**
 * Function to handle exceptions and errors gracefully
 */
function handleException($exception) {
    $message = $exception->getMessage();
    $code = $exception->getCode();
    
    // Log the exception for debugging
    error_log("Exception caught: " . $message . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    // For specific exception types, you might want to customize the error handling
    if ($exception instanceof PDOException) {
        handle500Error("Database error occurred. Please contact the administrator.");
    } else {
        handle500Error("An unexpected error occurred: " . $message);
    }
}

// Set up global exception handler
set_exception_handler('handleException');

// Set up error handler for PHP errors
function handleErrorReporting($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting
        return false;
    }
    
    $error_types = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    
    $error_type = $error_types[$errno] ?? 'Unknown Error';
    $message = "$error_type: $errstr in $errfile on line $errline";
    
    error_log($message);
    
    // For development, you might want to show more details
    handle500Error("An internal error occurred");
    
    return true;
}

set_error_handler('handleErrorReporting');