<?php
require_once 'routes.php';
$current_user = getCurrentUser();
$user_type = $current_user['user_type'] ?? 'client';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h3><i class="fas fa-network-wired"></i> MSP Portal</h3>
        <p><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? $current_user['email']); ?></p>
        <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user_type)); ?></span>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li><a href="<?php echo route('dashboard'); ?>" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a></li>
            
            <?php if (hasAdminLevel()): ?>
            <li><a href="<?php echo route('users.index'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'users/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-users-cog"></i> User Management
            </a></li>
            <?php endif; ?>
            
            <?php if (hasManagerLevel()): ?>
            <li><a href="<?php echo route('clients.index'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'clients/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-building"></i> Clients
            </a></li>
            
            <li><a href="<?php echo route('services.index'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'services/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-concierge-bell"></i> Services
            </a></li>
            
            <li><a href="<?php echo route('services.renewals'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'services/renewals') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-calendar-check"></i> Contract Renewals
            </a></li>
            <?php endif; ?>
            
            <li><a href="<?php echo route('tickets.index'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'tickets/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-ticket-alt"></i> Tickets
            </a></li>
            
            <li><a href="<?php echo route('assets.index'); ?>" <?php echo strpos($_SERVER['PHP_SELF'], 'assets/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-server"></i> Inventory
            </a></li>
            
            <?php if (hasManagerLevel()): ?>
            <li><a href="<?php echo route('reports.index'); ?>" <?php echo (strpos($_SERVER['PHP_SELF'], 'reports/') !== false || strpos($_SERVER['REQUEST_URI'], 'reports') !== false) ? 'class="active"' : ''; ?>>
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <?php endif; ?>
            
            <li><a href="<?php echo route('staff.profile'); ?>">
                <i class="fas fa-user"></i> My Profile
            </a></li>
            
            <li><a href="<?php echo route('logout'); ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    </nav>
</aside>