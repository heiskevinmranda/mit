<?php
// client-contracts.php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: client-login.php');
    exit;
}

require_once 'config/database.php';
$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

// Get contracts
$contracts = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM sla_configurations WHERE contract_id = c.id) as sla_count
        FROM contracts c
        WHERE c.client_id = ?
        ORDER BY 
            CASE WHEN c.status = 'Active' THEN 1 ELSE 2 END,
            c.end_date DESC
    ");
    $stmt->execute([$client_id]);
    $contracts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Contracts error: " . $e->getMessage());
}

// Get SLA configurations for active contracts
$sla_configs = [];
foreach ($contracts as $contract) {
    if ($contract['status'] === 'Active') {
        try {
            $stmt = $pdo->prepare("SELECT * FROM sla_configurations WHERE contract_id = ?");
            $stmt->execute([$contract['id']]);
            $sla_configs[$contract['id']] = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("SLA error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Contracts | Client Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --client-primary: #28a745; }
        .contract-card { border-left: 5px solid; transition: all 0.3s; }
        .contract-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .contract-active { border-left-color: var(--client-primary); }
        .contract-expired { border-left-color: #dc3545; }
        .contract-pending { border-left-color: #ffc107; }
        .sla-badge { font-size: 0.8em; }
        .days-left { font-weight: bold; }
        .days-left.danger { color: #dc3545; }
        .days-left.warning { color: #ffc107; }
        .days-left.success { color: #28a745; }
    </style>
</head>
<body>
    <?php include 'client-sidebar.php'; ?>
    
    <div class="client-main">
        <div class="data-card">
            <h1 class="mb-4"><i class="fas fa-file-contract me-2"></i>My Service Contracts</h1>
            
            <?php if (empty($contracts)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-contract fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No contracts found</h5>
                    <p>Your service contracts will appear here once added by our team.</p>
                </div>
            <?php else: ?>
                <!-- Contract Stats -->
                <div class="row mb-4">
                    <?php
                    $stats = [
                        'active' => 0,
                        'expired' => 0,
                        'total' => count($contracts)
                    ];
                    
                    foreach ($contracts as $contract) {
                        if ($contract['status'] === 'Active') {
                            $stats['active']++;
                        } else {
                            $stats['expired']++;
                        }
                    }
                    ?>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h2><?php echo $stats['active']; ?></h2>
                                <p class="mb-0">Active Contracts</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body text-center">
                                <h2><?php echo $stats['expired']; ?></h2>
                                <p class="mb-0">Expired Contracts</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2><?php echo $stats['total']; ?></h2>
                                <p class="mb-0">Total Contracts</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contracts List -->
                <div class="row">
                    <?php foreach ($contracts as $contract): ?>
                        <?php
                        $days_left = 0;
                        $days_class = 'success';
                        if ($contract['end_date']) {
                            $end_date = strtotime($contract['end_date']);
                            $today = time();
                            $days_left = ceil(($end_date - $today) / (60 * 60 * 24));
                            
                            if ($days_left < 0) {
                                $days_class = 'danger';
                                $days_text = 'Expired ' . abs($days_left) . ' days ago';
                            } elseif ($days_left < 30) {
                                $days_class = 'danger';
                                $days_text = $days_left . ' days left';
                            } elseif ($days_left < 90) {
                                $days_class = 'warning';
                                $days_text = $days_left . ' days left';
                            } else {
                                $days_text = $days_left . ' days left';
                            }
                        }
                        
                        $card_class = 'contract-card ';
                        if ($contract['status'] === 'Active') {
                            $card_class .= 'contract-active';
                        } elseif ($contract['status'] === 'Expired') {
                            $card_class .= 'contract-expired';
                        } else {
                            $card_class .= 'contract-pending';
                        }
                        ?>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100 <?php echo $card_class; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <?php echo htmlspecialchars($contract['contract_number']); ?>
                                            </h5>
                                            <h6 class="card-subtitle text-muted">
                                                <?php echo htmlspecialchars($contract['contract_type']); ?>
                                            </h6>
                                        </div>
                                        <span class="badge <?php echo $contract['status'] === 'Active' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo htmlspecialchars($contract['status']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Service Scope:</small>
                                        <p class="card-text">
                                            <?php echo htmlspecialchars(substr($contract['service_scope'] ?? 'No description', 0, 150)); ?>
                                            <?php if (strlen($contract['service_scope'] ?? '') > 150): ?>...<?php endif; ?>
                                        </p>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Start Date</small>
                                            <strong><?php echo date('M d, Y', strtotime($contract['start_date'])); ?></strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">End Date</small>
                                            <strong><?php echo date('M d, Y', strtotime($contract['end_date'])); ?></strong>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block">Monthly Amount</small>
                                            <strong>$<?php echo number_format($contract['monthly_amount'], 2); ?></strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Time Remaining</small>
                                            <strong class="days-left <?php echo $days_class; ?>">
                                                <?php echo $days_text ?? 'N/A'; ?>
                                            </strong>
                                        </div>
                                    </div>
                                    
                                    <!-- SLA Information -->
                                    <?php if (!empty($sla_configs[$contract['id']])): ?>
                                        <div class="mt-3 pt-3 border-top">
                                            <small class="text-muted d-block mb-2">SLA Levels:</small>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($sla_configs[$contract['id']] as $sla): ?>
                                                    <span class="badge bg-info sla-badge" 
                                                          title="Response: <?php echo $sla['response_time']; ?>h, Resolution: <?php echo $sla['resolution_time']; ?>h">
                                                        <?php echo htmlspecialchars($sla['priority']); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3 pt-3 border-top">
                                        <small class="text-muted d-block mb-2">Contract Details:</small>
                                        <div class="d-flex justify-content-between">
                                            <span>
                                                <i class="fas fa-clock me-1"></i>
                                                Response: <?php echo $contract['response_time_hours'] ?? 'N/A'; ?>h
                                            </span>
                                            <span>
                                                <i class="fas fa-check-circle me-1"></i>
                                                Resolution: <?php echo $contract['resolution_time_hours'] ?? 'N/A'; ?>h
                                            </span>
                                            <span>
                                                <i class="fas fa-file-alt me-1"></i>
                                                SLA: <?php echo $contract['sla_count'] ?? 0; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent border-top-0">
                                    <small class="text-muted">
                                        Last updated: <?php echo date('M d, Y', strtotime($contract['updated_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>