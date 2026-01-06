<?php
/**
 * Application Routes Configuration
 * 
 * This file defines all application routes for the MSP Application
 * providing a centralized routing system for better maintainability
 */

class RouteManager 
{
    private static $base_path = '/mit'; // Base path for the application

    private static $routes = [
        // Core routes
        'home' => '/',
        'login' => '/login',
        'logout' => '/logout',
        'dashboard' => '/dashboard',
        'setup' => '/setup',
        
        // User management routes
        'users.index' => '/users',
        'users.create' => '/users/create',
        'users.edit' => '/users/edit',
        'users.view' => '/users/view',
        'users.delete' => '/users/delete',
        'users.batch_create' => '/users/batch-create',
        
        // Client management routes
        'clients.index' => '/clients',
        'clients.create' => '/clients/create',
        'clients.edit' => '/clients/edit',
        'clients.view' => '/clients/view',
        'clients.delete' => '/clients/delete',
        'clients.add_location' => '/clients/add-location',
        'clients.locations' => '/clients/locations',
        'clients.add_asset' => '/clients/add-asset',
        'clients.add_contract' => '/clients/add-contract',
        'clients.add_ticket' => '/clients/add-ticket',
        
        // Ticket management routes
        'tickets.index' => '/tickets',
        'tickets.create' => '/tickets/create',
        'tickets.edit' => '/tickets/edit',
        'tickets.view' => '/tickets/view',
        'tickets.export' => '/tickets/export',
        'tickets.export_bulk' => '/tickets/export-bulk',
        'tickets.work_log' => '/tickets/work-log',
        'tickets.simple' => '/tickets/simple',
        
        // Inventory management routes
        'assets.index' => '/inventory',
        'assets.create' => '/inventory/create',
        'assets.edit' => '/inventory/edit',
        'assets.view' => '/inventory/view',
        'assets.delete' => '/inventory/delete',
        'assets.import' => '/inventory/import',
        'assets.reports' => '/inventory/reports',
        'assets.preview' => '/inventory/preview',
        
        // Service management routes
        'services.index' => '/services',
        'services.create' => '/services/create',
        'services.edit' => '/services/edit',
        'services.view' => '/services/view',
        'services.renewals' => '/services/renewals',
        'services.export' => '/services/export',
        'services.catalog' => '/services/catalog',
        
        // Contract management routes
        'contracts.index' => '/contracts',
        'contracts.create' => '/contracts/create',
        'contracts.edit' => '/contracts/edit',
        'contracts.view' => '/contracts/view',
        'contracts.renewals' => '/contracts/renewals',
        
        // Report routes
        'reports.index' => '/reports',
        'reports.asset' => '/reports/asset_report',
        'reports.ticket' => '/reports/ticket_report',
        'reports.service' => '/reports/service_report',
        
        // Staff routes
        'staff.profile' => '/staff/profile',
        
        // API routes
        'api.get_locations' => '/api/get-locations',
        'api.get_next_asset_number' => '/api/get-next-asset-number',
        
        // File download routes
        'attachments.download' => '/attachments/download',
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
            if (!empty($route_path)) {
                $url = self::$base_path . '/' . $route_path;
            } else {
                $url = self::$base_path . '/';
            }
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