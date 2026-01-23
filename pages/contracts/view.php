<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
require_once '../../config/database.php';
requireLogin();

$pdo = getDBConnection();

// Extract contract ID from URL path or query parameter
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim(parse_url($request_uri, PHP_URL_PATH), '/'));
$current_script = basename($_SERVER['SCRIPT_NAME'], '.php');

// Find the position of 'view.php' or the current script in the path
$script_pos = array_search($current_script, $path_parts);

if ($script_pos !== false && isset($path_parts[$script_pos + 1])) {
    // ID comes from URL path segment after 'view'
    $contract_id = $path_parts[$script_pos + 1];
} else {
    // Fallback to query parameter
    $contract_id = $_GET['id'] ?? null;
}

if (!$contract_id) {
    header('Location: index.php');
    exit;
}

// Get contract details
$stmt = $pdo->prepare("
    SELECT c.*, 
           cl.company_name,
           cl.contact_person,
           cl.email as client_email,
           cl.phone as client_phone
    FROM contracts c
    LEFT JOIN clients cl ON c.client_id = cl.id
    WHERE c.id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch();

if (!$contract) {
    header('Location: index.php');
    exit;
}

$current_user = getCurrentUser();
$can_manage = hasPermission('manager') || hasPermission('admin');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Contract | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .contract-header {
            background: linear-gradient(135deg, #004E89, #0066CC);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        .contract-detail {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .contract-detail:last-child {
            border-bottom: none;
        }
    </style>
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
                    <h1><i class="fas fa-file-contract"></i> Contract Details</h1>
                    <p class="text-muted">View details for <?php echo htmlspecialchars($contract['contract_number']); ?></p>
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
                    <li class="breadcrumb-item"><a href="<?php echo route('dashboard'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo route('contracts.index'); ?>">Contracts</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($contract['contract_number']); ?></li>
                </ol>
            </nav>
            
            <div class="contract-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1"><?php echo htmlspecialchars($contract['contract_number']); ?></h2>
                        <p class="mb-0"><?php echo htmlspecialchars($contract['company_name'] ?? 'No Client'); ?></p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-<?php 
                            switch ($contract['status']) {
                                case 'Active': echo 'success'; break;
                                case 'Pending': echo 'warning'; break;
                                case 'Expired': echo 'danger'; break;
                                case 'Cancelled': echo 'secondary'; break;
                                default: echo 'primary';
                            }
                        ?> fs-6"><?php echo htmlspecialchars($contract['status']); ?></span>
                        <div class="mt-2">
                            <?php if ($contract['monthly_amount']): ?>
                                <strong>TZS <?php echo number_format($contract['monthly_amount'], 2); ?></strong>
                            <?php else: ?>
                                <strong>No amount specified</strong>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo htmlspecialchars($contract['contract_type'] ?? 'N/A'); ?></div>
                        <small>Type</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo date('M d, Y', strtotime($contract['start_date'])); ?></div>
                        <small>Start Date</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo date('M d, Y', strtotime($contract['end_date'])); ?></div>
                        <small>End Date</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo htmlspecialchars($contract['monthly_amount'] ? 'Monthly' : 'N/A'); ?></div>
                        <small>Payment Frequency</small>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Contract Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="contract-detail">
                                        <strong>Contract Number:</strong>
                                        <div><?php echo htmlspecialchars($contract['contract_number']); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Contract Type:</strong>
                                        <div><?php echo htmlspecialchars($contract['contract_type'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Status:</strong>
                                        <div>
                                            <span class="badge bg-<?php 
                                                switch ($contract['status']) {
                                                    case 'Active': echo 'success'; break;
                                                    case 'Pending': echo 'warning'; break;
                                                    case 'Expired': echo 'danger'; break;
                                                    case 'Cancelled': echo 'secondary'; break;
                                                    default: echo 'primary';
                                                }
                                            ?>"><?php echo htmlspecialchars($contract['status']); ?></span>
                                        </div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Client:</strong>
                                        <div><?php echo htmlspecialchars($contract['company_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Monthly Amount:</strong>
                                        <div>TZS <?php echo number_format($contract['monthly_amount'] ?? 0, 2); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Payment Frequency:</strong>
                                        <div><?php echo htmlspecialchars($contract['monthly_amount'] ? 'Monthly' : 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="contract-detail">
                                        <strong>Start Date:</strong>
                                        <div><?php echo date('M d, Y', strtotime($contract['start_date'])); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>End Date:</strong>
                                        <div><?php echo date('M d, Y', strtotime($contract['end_date'])); ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Response Time (Hours):</strong>
                                        <div><?php echo $contract['response_time_hours'] ?? 'N/A'; ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Resolution Time (Hours):</strong>
                                        <div><?php echo $contract['resolution_time_hours'] ?? 'N/A'; ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Created:</strong>
                                        <div><?php echo $contract['created_at'] ? date('M d, Y g:i A', strtotime($contract['created_at'])) : 'N/A'; ?></div>
                                    </div>
                                    <div class="contract-detail">
                                        <strong>Updated:</strong>
                                        <div><?php echo $contract['updated_at'] ? date('M d, Y g:i A', strtotime($contract['updated_at'])) : 'N/A'; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($contract['service_scope']): ?>
                            <div class="contract-detail">
                                <strong>Service Scope:</strong>
                                <div><?php echo nl2br(htmlspecialchars($contract['service_scope'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($contract['penalty_terms']): ?>
                            <div class="contract-detail">
                                <strong>Penalty Terms:</strong>
                                <div><?php echo nl2br(htmlspecialchars($contract['penalty_terms'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-cogs"></i> Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($can_manage): ?>
                                <a href="<?php echo route('contracts.edit', ['id' => $contract['id']]); ?>" class="btn btn-warning">
                                    <i class="fas fa-edit"></i> Edit Contract
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo route('contracts.index'); ?>" class="btn btn-secondary">
                                    <i class="fas fa-list"></i> View All Contracts
                                </a>
                                <a href="<?php echo route('clients.view', ['id' => $contract['client_id']]); ?>" class="btn btn-primary">
                                    <i class="fas fa-building"></i> View Client
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-calendar"></i> Duration Information</h5>
                        </div>
                        <div class="card-body text-center">
                            <?php 
                            $start_date = new DateTime($contract['start_date']);
                            $end_date = new DateTime($contract['end_date']);
                            $today = new DateTime();
                            
                            $interval = $start_date->diff($end_date);
                            $duration_days = $interval->days;
                            
                            $remaining_interval = $today->diff($end_date);
                            $remaining_days = $remaining_interval->days;
                            
                            if ($today > $end_date) {
                                $remaining_days = -$remaining_days;
                            }
                            
                            $class = '';
                            if ($remaining_days < 0) {
                                $class = 'text-danger';
                                $label = 'Expired';
                            } elseif ($remaining_days <= 30) {
                                $class = 'text-warning';
                                $label = 'Expiring Soon';
                            } else {
                                $class = 'text-success';
                                $label = 'Active';
                            }
                            ?>
                            <div class="h2 <?php echo $class; ?>"><?php echo abs($remaining_days); ?> days</div>
                            <div class="<?php echo $class; ?> mb-2"><?php echo $label; ?></div>
                            <div>Duration: <?php echo $duration_days; ?> days</div>
                        </div>
                    </div>
                </div>
            </div>
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