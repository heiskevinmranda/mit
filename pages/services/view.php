<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

$pdo = getDBConnection();
$service_id = $_GET['id'] ?? null;

if (!$service_id) {
    header('Location: index.php');
    exit;
}

// Get service details
$stmt = $pdo->prepare("
    SELECT cs.*, 
           c.company_name,
           c.contact_person,
           c.email as client_email,
           c.phone as client_phone,
           (SELECT COUNT(*) FROM service_renewals sr WHERE sr.client_service_id = cs.id) as renewal_count
    FROM client_services cs
    LEFT JOIN clients c ON cs.client_id = c.id
    WHERE cs.id = ?
");
$stmt->execute([$service_id]);
$service = $stmt->fetch();

if (!$service) {
    header('Location: index.php');
    exit;
}

// Get renewal history
$renewals_stmt = $pdo->prepare("
    SELECT * FROM service_renewals 
    WHERE client_service_id = ? 
    ORDER BY renewal_date DESC
");
$renewals_stmt->execute([$service_id]);
$renewals = $renewals_stmt->fetchAll();

// Get service tickets
$tickets_stmt = $pdo->prepare("
    SELECT t.*, sp.full_name as assigned_to_name
    FROM tickets t
    LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id
    WHERE t.service_id = ?
    ORDER BY t.created_at DESC
");
$tickets_stmt->execute([$service_id]);
$tickets = $tickets_stmt->fetchAll();

$current_user = getCurrentUser();
$can_manage = hasPermission('manager') || hasPermission('admin');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Service | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .service-header {
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
        .service-detail {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .service-detail:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-concierge-bell"></i> Service Details</h1>
                    <p class="text-muted">View details for <?php echo htmlspecialchars($service['service_name']); ?></p>
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
                    <li class="breadcrumb-item"><a href="index.php">Services</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($service['service_name']); ?></li>
                </ol>
            </nav>
            
            <div class="service-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1"><?php echo htmlspecialchars($service['service_name']); ?></h2>
                        <p class="mb-0"><?php echo htmlspecialchars($service['company_name'] ?? 'No Client'); ?></p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-<?php 
                            switch ($service['status']) {
                                case 'Active': echo 'success'; break;
                                case 'Pending': echo 'warning'; break;
                                case 'Suspended': echo 'danger'; break;
                                case 'Cancelled': echo 'secondary'; break;
                                case 'Expired': echo 'dark'; break;
                                default: echo 'primary';
                            }
                        ?> fs-6"><?php echo htmlspecialchars($service['status']); ?></span>
                        <div class="mt-2">
                            <strong>$<?php echo number_format($service['monthly_price'] ?? 0, 2); ?>/mo</strong>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo htmlspecialchars($service['service_category'] ?? 'N/A'); ?></div>
                        <small>Category</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo $service['expiry_date'] ? date('M d, Y', strtotime($service['expiry_date'])) : 'N/A'; ?></div>
                        <small>Expiry Date</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo $service['renewal_count']; ?></div>
                        <small>Renewals</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="h4 mb-0"><?php echo count($tickets); ?></div>
                        <small>Tickets</small>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Service Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="service-detail">
                                        <strong>Service Name:</strong>
                                        <div><?php echo htmlspecialchars($service['service_name']); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Category:</strong>
                                        <div><?php echo htmlspecialchars($service['service_category'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Status:</strong>
                                        <div>
                                            <span class="badge bg-<?php 
                                                switch ($service['status']) {
                                                    case 'Active': echo 'success'; break;
                                                    case 'Pending': echo 'warning'; break;
                                                    case 'Suspended': echo 'danger'; break;
                                                    case 'Cancelled': echo 'secondary'; break;
                                                    case 'Expired': echo 'dark'; break;
                                                    default: echo 'primary';
                                                }
                                            ?>"><?php echo htmlspecialchars($service['status']); ?></span>
                                        </div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Client:</strong>
                                        <div><?php echo htmlspecialchars($service['company_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Monthly Price:</strong>
                                        <div>$<?php echo number_format($service['monthly_price'] ?? 0, 2); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Billing Cycle:</strong>
                                        <div><?php echo htmlspecialchars($service['billing_cycle'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="service-detail">
                                        <strong>Domain:</strong>
                                        <div><?php echo htmlspecialchars($service['domain_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Hosting Plan:</strong>
                                        <div><?php echo htmlspecialchars($service['hosting_plan'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Email Type:</strong>
                                        <div><?php echo htmlspecialchars($service['email_type'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Email Accounts:</strong>
                                        <div><?php echo $service['email_accounts'] ?? 'N/A'; ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Auto Renew:</strong>
                                        <div><?php echo $service['auto_renew'] ? 'Yes' : 'No'; ?></div>
                                    </div>
                                    <div class="service-detail">
                                        <strong>Created:</strong>
                                        <div><?php echo $service['created_at'] ? date('M d, Y g:i A', strtotime($service['created_at'])) : 'N/A'; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($service['description']): ?>
                            <div class="service-detail">
                                <strong>Description:</strong>
                                <div><?php echo nl2br(htmlspecialchars($service['description'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($renewals)): ?>
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-history"></i> Renewal History</h5>
                            <span class="badge bg-primary"><?php echo count($renewals); ?> renewals</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Period</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($renewals as $renewal): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($renewal['renewal_date'])); ?></td>
                                            <td>$<?php echo number_format($renewal['amount'], 2); ?></td>
                                            <td><?php echo $renewal['renewal_period']; ?> years</td>
                                            <td><?php echo htmlspecialchars($renewal['notes'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($tickets)): ?>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-ticket-alt"></i> Related Tickets</h5>
                            <span class="badge bg-primary"><?php echo count($tickets); ?> tickets</span>
                        </div>
                        <div class="card-body">
                            <div class="list-group">
                                <?php foreach ($tickets as $ticket): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">#<?php echo $ticket['id']; ?> - <?php echo htmlspecialchars($ticket['title']); ?></h6>
                                        <small><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-1"><?php echo htmlspecialchars($ticket['description']); ?></p>
                                    <small>
                                        Status: <span class="badge bg-<?php 
                                            switch ($ticket['status']) {
                                                case 'Open': echo 'warning'; break;
                                                case 'In Progress': echo 'info'; break;
                                                case 'Resolved': echo 'success'; break;
                                                case 'Closed': echo 'secondary'; break;
                                                default: echo 'primary';
                                            }
                                        ?>"><?php echo $ticket['status']; ?></span>
                                        <?php if ($ticket['assigned_to_name']): ?>
                                        | Assigned to: <?php echo htmlspecialchars($ticket['assigned_to_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-cogs"></i> Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($can_manage): ?>
                                <a href="<?php echo route('services.edit', ['id' => $service['id']]); ?>" class="btn btn-warning">
                                    <i class="fas fa-edit"></i> Edit Service
                                </a>
                                <button type="button" class="btn btn-success renew-btn" 
                                        data-id="<?php echo htmlspecialchars($service['id']); ?>"
                                        data-name="<?php echo htmlspecialchars($service['service_name']); ?>">
                                    <i class="fas fa-sync"></i> Renew Service
                                </button>
                                <?php endif; ?>
                                <a href="<?php echo route('services.renewals', ['service_id' => $service['id']]); ?>" class="btn btn-info">
                                    <i class="fas fa-history"></i> Renewal History
                                </a>
                                <a href="../../pages/tickets/create.php?service_id=<?php echo urlencode($service['id']); ?>" class="btn btn-primary">
                                    <i class="fas fa-ticket-alt"></i> Create Ticket
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-calendar"></i> Expiry Information</h5>
                        </div>
                        <div class="card-body text-center">
                            <?php 
                            $expiry_date = $service['expiry_date'] ? new DateTime($service['expiry_date']) : null;
                            $today = new DateTime();
                            
                            if ($expiry_date) {
                                $interval = $today->diff($expiry_date);
                                $days = $interval->days;
                                
                                if ($today > $expiry_date) {
                                    $days = -$days;
                                }
                                
                                $class = '';
                                if ($days < 0) {
                                    $class = 'text-danger';
                                    $label = 'Expired';
                                } elseif ($days <= 7) {
                                    $class = 'text-danger';
                                    $label = 'Expiring Soon';
                                } elseif ($days <= 30) {
                                    $class = 'text-warning';
                                    $label = 'Expiring Soon';
                                } else {
                                    $class = 'text-success';
                                    $label = 'Valid';
                                }
                            ?>
                            <div class="h2 <?php echo $class; ?>"><?php echo $days; ?> days</div>
                            <div class="<?php echo $class; ?> mb-2"><?php echo $label; ?></div>
                            <div>Expires: <?php echo $service['expiry_date'] ? date('M d, Y', strtotime($service['expiry_date'])) : 'N/A'; ?></div>
                            <?php } else { ?>
                            <div class="text-muted">No expiry date set</div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Renew Service Modal -->
    <div class="modal fade" id="renewModal" tabindex="-1" aria-labelledby="renewModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="renewModalLabel"><i class="fas fa-sync"></i> Renew Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="renewForm" method="POST" action="renew.php">
                    <input type="hidden" name="service_id" id="renewServiceId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <p>Renew service: <strong id="renewServiceName"></strong></p>
                        </div>
                        <div class="mb-3">
                            <label for="renewalPeriod" class="form-label">Renewal Period</label>
                            <select class="form-select" id="renewalPeriod" name="renewal_period">
                                <option value="1">1 Year</option>
                                <option value="2">2 Years</option>
                                <option value="3">3 Years</option>
                                <option value="5">5 Years</option>
                                <option value="10">10 Years</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="renewalAmount" class="form-label">Renewal Amount ($)</label>
                            <input type="number" class="form-control" id="renewalAmount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="renewalNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="renewalNotes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="updateExpiry" name="update_expiry" checked>
                            <label class="form-check-label" for="updateExpiry">
                                Update expiry date automatically
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Renew Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.renew-btn').on('click', function() {
                const serviceId = $(this).data('id');
                const serviceName = $(this).data('name');
                
                $('#renewServiceId').val(serviceId);
                $('#renewServiceName').text(serviceName);
                
                // Show modal
                const renewModal = new bootstrap.Modal(document.getElementById('renewModal'));
                renewModal.show();
            });
        });
    </script>
</body>
</html>