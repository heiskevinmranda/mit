<?php
require_once 'routes.php';
require_once 'profile_picture_helper.php';
$current_user = getCurrentUser();
$user_type = $current_user['user_type'] ?? 'client';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3><i class="fas fa-network-wired"></i> MSP Portal</h3>
        <?php echo getProfilePictureHTML($current_user['id'], $current_user['email'], 'md', 'sidebar-profile-pic'); ?>
        <p><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? $current_user['email']); ?></p>
        <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user_type)); ?></span>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li><a href="<?php echo route('dashboard'); ?>" <?php
                                                            $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                            $is_dashboard_page = strpos($_SERVER['SCRIPT_NAME'], 'dashboard.php') !== false;
                                                            echo $is_dashboard_page ? 'class="active"' : ''; ?>>
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a></li>

            <?php if (hasAdminLevel()): ?>
                <li><a href="<?php echo route('users.index'); ?>" <?php
                                                                    $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                    $is_users_page = strpos($_SERVER['SCRIPT_NAME'], '/users/') !== false &&
                                                                        strpos($_SERVER['SCRIPT_NAME'], '/clients/') === false;
                                                                    echo $is_users_page ? 'class="active"' : ''; ?>>
                        <i class="fas fa-users-cog"></i> User Management
                    </a></li>
            <?php endif; ?>

            <?php if (hasManagerLevel()): ?>
                <li><a href="<?php echo route('clients.index'); ?>" <?php
                                                                    $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                    $request_uri = $_SERVER['REQUEST_URI'];
                                                                    $is_clients_page = strpos($_SERVER['SCRIPT_NAME'], '/clients/') !== false ||
                                                                        in_array($current_page, ['locations.php', 'add_asset.php', 'add_contract.php', 'add_ticket.php']) ||
                                                                        strpos($request_uri, 'add-contract') !== false;
                                                                    echo $is_clients_page ? 'class="active"' : ''; ?>>
                        <i class="fas fa-building"></i> Clients
                    </a></li>

                <li><a href="<?php echo route('services.index'); ?>" <?php
                                                                        $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                        $is_services_page = strpos($_SERVER['SCRIPT_NAME'], '/services/') !== false &&
                                                                            strpos($_SERVER['SCRIPT_NAME'], '/clients/') === false;
                                                                        echo $is_services_page ? 'class="active"' : ''; ?>>
                        <i class="fas fa-concierge-bell"></i> Services
                    </a></li>

            <?php endif; ?>

            <li><a href="<?php echo route('tickets.index'); ?>" <?php
                                                                $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                $is_tickets_page = strpos($_SERVER['SCRIPT_NAME'], '/tickets/') !== false &&
                                                                    strpos($_SERVER['SCRIPT_NAME'], '/clients/') === false;
                                                                echo $is_tickets_page ? 'class="active"' : ''; ?>>
                    <i class="fas fa-ticket-alt"></i> Tickets
                </a></li>

            <li><a href="<?php echo route('assets.index'); ?>" <?php
                                                                $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                $is_assets_page = strpos($_SERVER['SCRIPT_NAME'], '/assets/') !== false &&
                                                                    strpos($_SERVER['SCRIPT_NAME'], '/clients/') === false;
                                                                echo $is_assets_page ? 'class="active"' : ''; ?>>
                    <i class="fas fa-server"></i> Assets
                </a></li>


            <?php if (hasManagerLevel()): ?>
                <li><a href="<?php echo route('reports.index'); ?>" <?php
                                                                    $current_page = basename($_SERVER['SCRIPT_NAME']);
                                                                    $is_reports_page = (strpos($_SERVER['SCRIPT_NAME'], '/reports/') !== false || strpos($_SERVER['REQUEST_URI'], 'reports') !== false) &&
                                                                        strpos($_SERVER['SCRIPT_NAME'], '/clients/') === false;
                                                                    echo $is_reports_page ? 'class="active"' : ''; ?>>
                        <i class="fas fa-chart-bar"></i> Reports
                    </a></li>
            <?php endif; ?>

            <?php if (function_exists('canViewCertificateManagement') && canViewCertificateManagement()): ?>
                <li><a href="<?php echo route('certificates.admin'); ?>">
                        <i class="fas fa-certificate"></i> Certificate Management
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