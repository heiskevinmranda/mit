<?php
// client-assets.php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: client-login.php');
    exit;
}

require_once 'config/database.php';
$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

// Get assets
$assets = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, cl.location_name, sp.full_name as assigned_to_name
        FROM assets a
        LEFT JOIN client_locations cl ON a.location_id = cl.id
        LEFT JOIN staff_profiles sp ON a.assigned_to = sp.id
        WHERE a.client_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $assets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Assets error: " . $e->getMessage());
}

// Get asset counts by status
$asset_counts = [
    'total' => count($assets),
    'active' => 0,
    'inactive' => 0
];

foreach ($assets as $asset) {
    if ($asset['status'] === 'Active') {
        $asset_counts['active']++;
    } else {
        $asset_counts['inactive']++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Assets | Client Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --client-primary: #28a745; }
        .asset-card { border-left: 5px solid var(--client-primary); }
        .asset-status-active { color: #28a745; }
        .asset-status-inactive { color: #6c757d; }
        .warranty-expiring { color: #dc3545; font-weight: bold; }
        .warranty-valid { color: #28a745; }
    </style>
</head>
<body>
    <?php include 'client-sidebar.php'; ?>
    
    <div class="client-main">
        <div class="data-card mb-4">
            <h1 class="mb-4"><i class="fas fa-server me-2"></i>My IT Assets</h1>
            
            <!-- Asset Summary -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <h2 class="text-primary"><?php echo $asset_counts['total']; ?></h2>
                            <p class="mb-0">Total Assets</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <h2 class="text-success"><?php echo $asset_counts['active']; ?></h2>
                            <p class="mb-0">Active Assets</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <h2 class="text-muted"><?php echo $asset_counts['inactive']; ?></h2>
                            <p class="mb-0">Inactive Assets</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Assets Table -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Type</th>
                            <th>Manufacturer</th>
                            <th>Model</th>
                            <th>Serial No.</th>
                            <th>Location</th>
                            <th>Warranty Expiry</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fas fa-server fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No assets found</h5>
                                    <p>Your IT assets will appear here once registered.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $asset): ?>
                                <?php
                                $warranty_class = 'warranty-valid';
                                if ($asset['warranty_expiry']) {
                                    $expiry = strtotime($asset['warranty_expiry']);
                                    $today = time();
                                    $days_left = ($expiry - $today) / (60 * 60 * 24);
                                    if ($days_left < 30 && $days_left > 0) {
                                        $warranty_class = 'warranty-expiring';
                                    } elseif ($days_left < 0) {
                                        $warranty_class = 'text-danger';
                                    }
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($asset['asset_tag']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($asset['asset_type']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['manufacturer']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['model']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['serial_number']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['location_name'] ?? 'Not assigned'); ?></td>
                                    <td class="<?php echo $warranty_class; ?>">
                                        <?php echo $asset['warranty_expiry'] ? date('M d, Y', strtotime($asset['warranty_expiry'])) : 'N/A'; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $asset['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo htmlspecialchars($asset['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Asset Types Summary -->
            <?php if (!empty($assets)): ?>
            <div class="mt-4">
                <h5 class="mb-3">Asset Types Summary</h5>
                <div class="row">
                    <?php
                    $asset_types = [];
                    foreach ($assets as $asset) {
                        $type = $asset['asset_type'] ?: 'Unknown';
                        $asset_types[$type] = ($asset_types[$type] ?? 0) + 1;
                    }
                    arsort($asset_types);
                    ?>
                    <?php foreach (array_slice($asset_types, 0, 6) as $type => $count): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($type); ?></h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="progress flex-grow-1 me-3" style="height: 10px;">
                                            <div class="progress-bar bg-success" 
                                                 style="width: <?php echo ($count / $asset_counts['total']) * 100; ?>%">
                                            </div>
                                        </div>
                                        <span class="badge bg-primary"><?php echo $count; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>