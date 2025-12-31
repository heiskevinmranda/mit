<?php
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
            <li><a href="../dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a></li>
            
            <?php if (checkPermission('admin')): ?>
            <li><a href="../pages/users/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'users/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-users-cog"></i> User Management
            </a></li>
            <?php endif; ?>
            
            <?php if (checkPermission('manager')): ?>
            <li><a href="../pages/clients/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'clients/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-building"></i> Clients
            </a></li>
            
            <li><a href="../pages/contracts/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'contracts/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-file-contract"></i> Contracts
            </a></li>
            <?php endif; ?>
            
            <li><a href="../pages/tickets/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'tickets/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-ticket-alt"></i> Tickets
            </a></li>
            
            <li><a href="../pages/assets/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'assets/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-server"></i> Assets
            </a></li>
            
            <?php if (checkPermission('manager')): ?>
            <li><a href="../pages/reports/index.php" <?php echo strpos($_SERVER['PHP_SELF'], 'reports/') !== false ? 'class="active"' : ''; ?>>
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <?php endif; ?>
            
            <li><a href="../pages/profile.php">
                <i class="fas fa-user"></i> My Profile
            </a></li>
            
            <li><a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    </nav>
</aside>