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
        'users.edit' => '/users/edit/{id}',
        'users.view' => '/users/view/{id}',
        'users.delete' => '/users/delete/{id}',
        'users.batch_create' => '/users/batch-create',
        
        // Client management routes
        'clients.index' => '/clients',
        'clients.create' => '/clients/create',
        'clients.edit' => '/clients/edit/{id}',
        'clients.view' => '/clients/view/{id}',
        'clients.delete' => '/clients/delete/{id}',
        'clients.add_location' => '/clients/add-location',
        'clients.locations' => '/clients/locations',
        'clients.add_asset' => '/clients/add-asset',
        'clients.add_contract' => '/clients/add-contract',
        'clients.add_ticket' => '/clients/add-ticket',
        
        // Ticket management routes
        'tickets.index' => '/tickets',
        'tickets.create' => '/tickets/create',
        'tickets.edit' => '/tickets/edit/{id}',
        'tickets.view' => '/tickets/view/{id}',
        'tickets.delete' => '/tickets/delete/{id}',
        'tickets.export' => '/tickets/export',
        'tickets.export_bulk' => '/tickets/export-bulk',
        'tickets.work_log' => '/tickets/work-log',
        'tickets.simple' => '/tickets/simple',
        
        // Inventory management routes
        'assets.index' => '/inventory',
        'assets.create' => '/inventory/create',
        'assets.edit' => '/inventory/edit/{id}',
        'assets.view' => '/inventory/view/{id}',
        'assets.delete' => '/inventory/delete/{id}',
        'assets.import' => '/inventory/import',
        'assets.reports' => '/inventory/reports',
        'assets.preview' => '/inventory/preview',
        
        // Service management routes
        'services.index' => '/services',
        'services.create' => '/services/create',
        'services.edit' => '/services/edit/{id}',
        'services.view' => '/services/view/{id}',
        'services.delete' => '/services/delete/{id}',
        'services.renewals' => '/services/renewals',
        'services.export' => '/services/export',
        'services.catalog' => '/services/catalog',
        
        // Contract management routes
        'contracts.index' => '/contracts',
        'contracts.create' => '/contracts/create',
        'contracts.edit' => '/contracts/edit/{id}',
        'contracts.view' => '/contracts/view/{id}',
        'contracts.delete' => '/contracts/delete/{id}',
        'contracts.renewals' => '/contracts/renewals',
        
        // ===========================================
        // DAILY STANDUP / MEETING ROUTES - NEW SECTION
        // ===========================================
        'daily_standups.index' => '/daily-standups',
        'daily_standups.create' => '/daily-standups/create',
        'daily_standups.view' => '/daily-standups/view/{id}',
        'daily_standups.edit' => '/daily-standups/edit/{id}',
        'daily_standups.delete' => '/daily-standups/delete/{id}',
        'daily_standups.response_form' => '/daily-standups/response/{standup_id}',
        'daily_standups.response_edit' => '/daily-standups/response/edit/{id}',
        'daily_standups.submit' => '/daily-standups/submit',
        'daily_standups.update' => '/daily-standups/update',
        'daily_standups.calendar' => '/daily-standups/calendar',
        'daily_standups.reports' => '/daily-standups/reports',
        'daily_standups.export' => '/daily-standups/export/{id}',
        'daily_standups.attachments' => '/daily-standups/attachments/{id}',
        
        // Action Items routes
        'standup_actions.create' => '/standup-actions/create',
        'standup_actions.edit' => '/standup-actions/edit/{id}',
        'standup_actions.delete' => '/standup-actions/delete/{id}',
        'standup_actions.complete' => '/standup-actions/complete/{id}',
        'standup_actions.list' => '/standup-actions',
        'standup_actions.mine' => '/standup-actions/mine',
        
        // Meeting Notes & Minutes
        'meeting_notes.create' => '/meeting-notes/create',
        'meeting_notes.edit' => '/meeting-notes/edit/{id}',
        'meeting_notes.view' => '/meeting-notes/view/{id}',
        'meeting_notes.download' => '/meeting-notes/download/{id}',
        
        // Meeting Templates
        'meeting_templates.index' => '/meeting-templates',
        'meeting_templates.create' => '/meeting-templates/create',
        'meeting_templates.edit' => '/meeting-templates/edit/{id}',
        'meeting_templates.apply' => '/meeting-templates/apply/{id}',
        
        // Daily Tasks & Meeting Minutes
        'daily_tasks.index' => '/daily-tasks',
        'daily_tasks.create' => '/daily-tasks/create',
        'daily_tasks.view' => '/daily-tasks/view/{id}',
        'daily_tasks.edit' => '/daily-tasks/edit/{id}',
        'daily_tasks.delete' => '/daily-tasks/delete/{id}',
        'daily_tasks.update_status' => '/daily-tasks/update-status',
        
        // ===========================================
        // END OF DAILY STANDUP ROUTES
        // ===========================================
        
        // Report routes
        'reports.index' => '/reports',
        'reports.ticket' => '/reports/ticket_report',
        'reports.ticket_export' => '/reports/ticket_report_export',
        'reports.asset' => '/reports/asset_report',
        'reports.service' => '/reports/service_report',
        
        // Staff routes
        'staff.profile' => '/staff/profile',
        'staff.edit_profile' => '/staff/edit-profile',
        
        // Certificate management routes
        'certificates.manage' => '/certificates/manage',
        'certificates.admin' => '/certificates/admin',
        'certificates.export' => '/certificates/export',
        
        // API routes
        'api.get_locations' => '/api/get-locations',
        'api.get_next_asset_number' => '/api/get-next-asset-number',
        
        // DAILY STANDUP API ROUTES
        'api.daily_standups.list' => '/api/daily-standups/list',
        'api.daily_standups.stats' => '/api/daily-standups/stats',
        'api.daily_standups.create' => '/api/daily-standups/create',
        'api.daily_standups.update' => '/api/daily-standups/update/{id}',
        'api.daily_standups.response' => '/api/daily-standups/response',
        'api.daily_standups.attendance' => '/api/daily-standups/attendance/{id}',
        'api.daily_standups.reminder' => '/api/daily-standups/send-reminder',
        'api.daily_standups.auto_save' => '/api/daily-standups/auto-save',
        
        // File download routes
        'attachments.download' => '/attachments/download',
        
        // Error routes
        'errors.404' => '/errors/404',
        'errors.403' => '/errors/403',
        'errors.500' => '/errors/500',
        'errors.401' => '/errors/401',
    ];
    
    /**
     * Get route URL by name
     */
    public static function getUrl($routeName, $params = []) 
    {
        if (!isset(self::$routes[$routeName])) {
            // Log missing route for debugging
            error_log("Route not found: $routeName");
            
            // Return a safe fallback URL instead of throwing exception
            return self::$base_path . '/errors/404';
        }
        
        $url = self::$routes[$routeName];
        
        // Process parameters if provided
        if (!empty($params)) {
            $used_params = [];
            foreach ($params as $key => $value) {
                $placeholder = '{' . $key . '}';
                if (strpos($url, $placeholder) !== false) {
                    $url = str_replace($placeholder, $value, $url);
                    $used_params[$key] = $value;
                }
            }
            
            // If there are remaining parameters, append them as query string
            $unused_params = array_diff_key($params, $used_params);
            
            if (!empty($unused_params)) {
                $url .= '?' . http_build_query($unused_params);
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
    
    /**
     * Get route name from current URL
     */
    public static function getCurrentRoute()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $base_path_length = strlen(self::$base_path);
        
        // Remove base path from request URI
        if (str_starts_with($request_uri, self::$base_path)) {
            $path = substr($request_uri, $base_path_length);
        } else {
            $path = $request_uri;
        }
        
        // Remove query string
        $path = strtok($path, '?');
        
        // Normalize path
        $path = '/' . ltrim($path, '/');
        
        // Find matching route
        foreach (self::$routes as $name => $route) {
            $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $route);
            $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';
            
            if (preg_match($pattern, $path)) {
                return $name;
            }
        }
        
        return null;
    }
    
    /**
     * Check if current route matches given pattern
     */
    public static function isCurrentRoute($routeName)
    {
        $current = self::getCurrentRoute();
        return $current === $routeName;
    }
    
    /**
     * Check if current route starts with given prefix
     */
    public static function isRoutePrefix($prefix)
    {
        $current = self::getCurrentRoute();
        if ($current === null) return false;
        
        return str_starts_with($current, $prefix);
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

function is_current_route($routeName)
{
    return RouteManager::isCurrentRoute($routeName);
}

function is_route_prefix($prefix)
{
    return RouteManager::isRoutePrefix($prefix);
}