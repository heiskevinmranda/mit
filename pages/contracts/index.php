<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
require_once '../../config/database.php';
requireLogin();

$pdo = getDBConnection();

// Get all contracts with client information
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name
    FROM contracts c
    LEFT JOIN clients cl ON c.client_id = cl.id
    ORDER BY c.created_at DESC
");
$stmt->execute();
$contracts = $stmt->fetchAll();

$current_user = getCurrentUser();
$can_manage = hasPermission('manager') || hasPermission('admin');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contracts | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <!-- Sidebar Backdrop (mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>
    
    <div class="main-wrapper">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-file-contract"></i> Contracts</h1>
                    <p class="text-muted">Manage client contracts</p>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($current_user['email'], 0, 1)); ?>
                    </div>
                    <div>
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['staff_profile']['full_name'] ?? 'User'); ?></div>
                        <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($current_user['staff_profile']['designation'] ?? ucfirst($current_user['user_type'])); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Contracts</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Contract List</h2>
                <?php if ($can_manage): ?>
                <a href="../clients/index.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> View Clients
                </a>
                <?php endif; ?>
            </div>
            
            <?php if (empty($contracts)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                    <h4 class="text-muted">No Contracts Found</h4>
                    <p class="text-muted">Get started by creating your first contract</p>
                    <?php if ($can_manage): ?>
                    <a href="create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Contract
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Contract Number</th>
                                    <th>Client</th>
                                    <th>Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Amount (TZS)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contracts as $contract): 
                                    $start_date = new DateTime($contract['start_date']);
                                    $end_date = new DateTime($contract['end_date']);
                                    $now = new DateTime();
                                    $is_active = $contract['status'] === 'Active';
                                    $is_expired = $end_date < $now;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($contract['contract_number']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($contract['company_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($contract['contract_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($contract['start_date'])); ?></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($contract['end_date'])); ?>
                                        <?php if ($is_expired && $is_active): ?>
                                        <span class="badge bg-warning ms-1">Expired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($contract['status'] === 'Active'): ?>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($contract['status']); ?></span>
                                        <?php elseif ($contract['status'] === 'Expired'): ?>
                                        <span class="badge bg-warning"><?php echo htmlspecialchars($contract['status']); ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-<?php echo $contract['status'] === 'Pending' ? 'warning' : 'secondary'; ?>">
                                            <?php echo htmlspecialchars($contract['status']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($contract['monthly_amount']): ?>
                                        <?php echo 'TZS ' . number_format($contract['monthly_amount'], 2); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="<?php echo route('contracts.view', ['id' => $contract['id']]); ?>" 
                                               class="btn btn-primary btn-sm" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($can_manage): ?>
                                            <a href="<?php echo route('contracts.edit', ['id' => $contract['id']]); ?>" 
                                               class="btn btn-warning btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            
            if (sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                backdrop.classList.remove('active');
            } else {
                sidebar.classList.add('active');
                backdrop.classList.add('active');
            }
        }
        
        // Close sidebar when clicking on a link (mobile)
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 992) {
                    toggleSidebar();
                }
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>