<?php
/**
 * Application Routes Configuration
 * 
 * This file defines all application routes for the MSP Application
 * providing a centralized routing system for better maintainability
 */

class RouteManager 
{
    private static $base_path = '/mit/'; // Base path for the application

    private static $routes = [
        // Core routes
        'home' => '/',
        'login' => '/login.php',
        'logout' => '/logout.php',
        'dashboard' => '/dashboard.php',
        'setup' => '/setup.php',
        
        // User management routes
        'users.index' => '/pages/users/index.php',
        'users.create' => '/pages/users/create.php',
        'users.edit' => '/pages/users/edit.php',
        'users.view' => '/pages/users/view.php',
        'users.delete' => '/pages/users/delete.php',
        'users.batch_create' => '/pages/users/batch_create.php',
        
        // Client management routes
        'clients.index' => '/pages/clients/index.php',
        'clients.create' => '/pages/clients/create.php',
        'clients.edit' => '/pages/clients/edit.php',
        'clients.view' => '/pages/clients/view.php',
        'clients.delete' => '/pages/clients/delete.php',
        'clients.add_location' => '/pages/clients/add_location.php',
        'clients.locations' => '/pages/clients/locations.php',
        'clients.add_asset' => '/pages/clients/add_asset.php',
        'clients.add_contract' => '/pages/clients/add_contract.php',
        'clients.add_ticket' => '/pages/clients/add_ticket.php',
        
        // Ticket management routes
        'tickets.index' => '/pages/tickets/index.php',
        'tickets.create' => '/pages/tickets/create.php',
        'tickets.edit' => '/pages/tickets/edit.php',
        'tickets.view' => '/pages/tickets/view.php',
        'tickets.export' => '/pages/tickets/export.php',
        'tickets.work_log' => '/pages/tickets/work_log.php',
        'tickets.simple' => '/pages/tickets/simple.php',
        
        // Asset management routes
        'assets.index' => '/pages/assets/index.php',
        'assets.create' => '/pages/assets/create.php',
        'assets.edit' => '/pages/assets/edit.php',
        'assets.view' => '/pages/assets/view.php',
        'assets.delete' => '/pages/assets/delete.php',
        'assets.import' => '/pages/assets/import.php',
        'assets.reports' => '/pages/assets/reports.php',
        'assets.preview' => '/pages/assets/preview.php',
        
        // Contract management routes
        'contracts.index' => '/pages/services/index.php',
        'contracts.create' => '/pages/services/create.php',
        'contracts.edit' => '/pages/services/edit.php',
        'contracts.view' => '/pages/services/view.php',
        'contracts.renewals' => '/pages/services/renewals.php',
        
        // Report routes
        'reports.index' => '/pages/reports/index.php',
        
        // Staff routes
        'staff.profile' => '/pages/staff/profile.php',
        
        // API routes
        'api.get_locations' => '/api/get_locations.php',
        'api.get_next_asset_number' => '/api/get_next_asset_number.php',
        
        // File download routes
        'attachments.download' => '/download_attachment.php',
    ];
    
    /**
     * Get route URL by name
     */
    public static function getUrl($routeName, $params = []) 
    {
        if (!isset(self::$routes[$routeName])) {
            throw new Exception("Route not found: $routeName");
        }
        
        $url = self::$routes[$routeName];
        
        // Process parameters if provided
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $url = str_replace('{' . $key . '}', $value, $url);
            }
            
            // If there are remaining parameters, append them as query string
            $query_params = [];
            foreach ($params as $key => $value) {
                if (strpos($url, '{' . $key . '}') === false) {
                    $query_params[$key] = $value;
                }
            }
            
            if (!empty($query_params)) {
                $url .= '?' . http_build_query($query_params);
            }
        }
        
        // Prepend base path for all routes except root and external URLs
        if ($url !== '/' && !str_starts_with($url, 'http') && !str_starts_with($url, self::$base_path)) {
            // Remove leading slash from route path before adding base path
            $route_path = ltrim($url, '/');
            $url = self::$base_path . $route_path;
        }
        
        return $url;
    }
    
    /**
     * Generate URL with parameters
     */
    public static function generate($routeName, $params = []) 
    {
        return self::getUrl($routeName, $params);
    }
    
    /**
     * Get all routes
     */
    public static function getAllRoutes()
    {
        return self::$routes;
    }
    
    /**
     * Add a new route
     */
    public static function addRoute($name, $path)
    {
        self::$routes[$name] = $path;
    }
    
    /**
     * Check if route exists
     */
    public static function hasRoute($routeName)
    {
        return isset(self::$routes[$routeName]);
    }
}

// Helper functions for easier access
function route($name, $params = []) 
{
    return RouteManager::generate($name, $params);
}

function url($name, $params = []) 
{
    return RouteManager::getUrl($name, $params);
}