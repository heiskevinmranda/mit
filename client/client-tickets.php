<?php
// client-sidebar.php - Reusable sidebar component
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    return;
}

// Include common functions
require_once '../includes/routes.php';
require_once 'config/database.php';
require_once 'includes/client-functions.php';

$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

if (!$client_id) {
    return;
}

// Get client info
$client = getClientInfo($pdo, $client_id);

// Get counts for badges
$counts = [
    'open_tickets' => getClientCount($pdo, 
        "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status IN ('Open', 'In Progress')", 
        [$client_id]),
    'total_assets' => getClientCount($pdo, 
        "SELECT COUNT(*) FROM assets WHERE client_id = ?", 
        [$client_id]),
    'active_contracts' => getClientCount($pdo, 
        "SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'Active'", 
        [$client_id]),
    'pending_visits' => getClientCount($pdo, 
        "SELECT COUNT(*) FROM site_visits WHERE client_id = ? AND check_out_time IS NULL", 
        [$client_id]),
];

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="client-sidebar" id="sidebar">
    <div class="sidebar-header">
        <h4 class="mb-0"><i class="fas fa-building me-2"></i>Client Portal</h4>
        <small class="text-white-50"><?php echo htmlspecialchars($client['company_name'] ?? ''); ?></small>
    </div>
    
    <!-- User Profile -->
    <div class="user-profile">
        <div class="user-avatar">
            <?php 
            $initial = 'C';
            if (!empty($client['company_name'])) {
                $initial = strtoupper(substr($client['company_name'], 0, 1));
            }
            echo $initial;
            ?>
        </div>
        <div>
            <div class="fw-bold"><?php echo htmlspecialchars($client['contact_person'] ?? 'User'); ?></div>
            <small class="text-white-50"><?php echo htmlspecialchars($client['email'] ?? ''); ?></small>
        </div>
    </div>
    
    <!-- Navigation -->
    <ul class="nav flex-column pt-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-dashboard.php' ? 'active' : ''; ?>" 
               href="client-dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-tickets.php' ? 'active' : ''; ?>" 
               href="client-tickets.php">
                <i class="fas fa-ticket-alt"></i> Support Tickets
                <?php if ($counts['open_tickets'] > 0): ?>
                    <span class="badge bg-danger float-end"><?php echo $counts['open_tickets']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'new-ticket.php' ? 'active' : ''; ?>" 
               href="new-ticket.php">
                <i class="fas fa-plus-circle"></i> New Ticket
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-assets.php' ? 'active' : ''; ?>" 
               href="client-assets.php">
                <i class="fas fa-server"></i> IT Assets
                <span class="badge bg-info float-end"><?php echo $counts['total_assets']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-contracts.php' ? 'active' : ''; ?>" 
               href="client-contracts.php">
                <i class="fas fa-file-contract"></i> Contracts
                <span class="badge bg-success float-end"><?php echo $counts['active_contracts']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-site-visits.php' ? 'active' : ''; ?>" 
               href="client-site-visits.php">
                <i class="fas fa-map-marker-alt"></i> Site Visits
                <?php if ($counts['pending_visits'] > 0): ?>
                    <span class="badge bg-warning float-end"><?php echo $counts['pending_visits']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-reports.php' ? 'active' : ''; ?>" 
               href="client-reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-profile.php' ? 'active' : ''; ?>" 
               href="client-profile.php">
                <i class="fas fa-user-cog"></i> My Profile
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $current_page == 'client-change-password.php' ? 'active' : ''; ?>" 
               href="client-change-password.php">
                <i class="fas fa-key"></i> Change Password
            </a>
        </li>
        <li class="nav-item mt-4">
            <a class="nav-link text-danger" href="<?php echo route('logout'); ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</div>

<button class="mobile-menu-btn" id="mobileMenuBtn">
    <i class="fas fa-bars"></i>
</button>

<script>
    // Mobile menu toggle
    document.getElementById('mobileMenuBtn').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('active');
        this.innerHTML = sidebar.classList.contains('active') 
            ? '<i class="fas fa-times"></i>' 
            : '<i class="fas fa-bars"></i>';
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const menuBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 768 && 
            !sidebar.contains(event.target) && 
            !menuBtn.contains(event.target) && 
            sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            menuBtn.innerHTML = '<i class="fas fa-bars"></i>';
        }
    });
</script>