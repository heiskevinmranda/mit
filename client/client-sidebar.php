<?php
// client-sidebar.php - Reusable sidebar component
if (!isset($_SESSION['user_id'])) {
    return;
}

require_once '../includes/routes.php';
require_once 'config/database.php';
$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

// Get counts for badges
$counts = [
    'open_tickets' => getCount($pdo, "SELECT COUNT(*) FROM tickets WHERE client_id = ? AND status IN ('Open', 'In Progress')", [$client_id]),
    'total_assets' => getCount($pdo, "SELECT COUNT(*) FROM assets WHERE client_id = ?", [$client_id]),
    'active_contracts' => getCount($pdo, "SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'Active'", [$client_id]),
    'pending_visits' => getCount($pdo, "SELECT COUNT(*) FROM site_visits WHERE client_id = ? AND check_out_time IS NULL", [$client_id]),
];

// Get client info
$client = null;
if ($client_id) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
}

function getCount($pdo, $query, $params) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
?>
<div class="client-sidebar" id="sidebar">
    <div class="sidebar-header">
        <h4 class="mb-0"><i class="fas fa-building me-2"></i>Client Portal</h4>
        <small class="text-white-50"><?php echo htmlspecialchars($client['company_name'] ?? ''); ?></small>
    </div>
    
    <!-- User Profile -->
    <div class="user-profile">
        <div class="user-avatar">
            <?php echo strtoupper(substr($client['company_name'] ?? 'C', 0, 1)); ?>
        </div>
        <div>
            <div class="fw-bold"><?php echo htmlspecialchars($client['contact_person'] ?? 'User'); ?></div>
            <small class="text-white-50"><?php echo htmlspecialchars($client['email'] ?? ''); ?></small>
        </div>
    </div>
    
    <!-- Navigation -->
    <ul class="nav flex-column pt-3">
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-dashboard.php' ? 'active' : ''; ?>" href="client-dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-tickets.php' ? 'active' : ''; ?>" href="client-tickets.php">
                <i class="fas fa-ticket-alt"></i> Support Tickets
                <?php if ($counts['open_tickets'] > 0): ?>
                    <span class="badge bg-danger float-end"><?php echo $counts['open_tickets']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'new-ticket.php' ? 'active' : ''; ?>" href="new-ticket.php">
                <i class="fas fa-plus-circle"></i> New Ticket
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-assets.php' ? 'active' : ''; ?>" href="client-assets.php">
                <i class="fas fa-server"></i> IT Assets
                <span class="badge bg-info float-end"><?php echo $counts['total_assets']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-contracts.php' ? 'active' : ''; ?>" href="client-contracts.php">
                <i class="fas fa-file-contract"></i> Contracts
                <span class="badge bg-success float-end"><?php echo $counts['active_contracts']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-site-visits.php' ? 'active' : ''; ?>" href="client-site-visits.php">
                <i class="fas fa-map-marker-alt"></i> Site Visits
                <?php if ($counts['pending_visits'] > 0): ?>
                    <span class="badge bg-warning float-end"><?php echo $counts['pending_visits']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-reports.php' ? 'active' : ''; ?>" href="client-reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-profile.php' ? 'active' : ''; ?>" href="client-profile.php">
                <i class="fas fa-user-cog"></i> My Profile
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'client-change-password.php' ? 'active' : ''; ?>" href="client-change-password.php">
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
        document.getElementById('sidebar').classList.toggle('active');
        this.innerHTML = document.getElementById('sidebar').classList.contains('active') 
            ? '<i class="fas fa-times"></i>' 
            : '<i class="fas fa-bars"></i>';
    });
</script>