<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
require_once '../../config/database.php';
requireLogin();

if (!hasPermission('admin') && !hasPermission('manager')) {
    header('Location: ../../dashboard.php');
    exit;
}

$pdo = getDBConnection();

// Extract contract ID from URL path or query parameter
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim(parse_url($request_uri, PHP_URL_PATH), '/'));
$current_script = basename($_SERVER['SCRIPT_NAME'], '.php');

// Find the position of 'edit.php' or the current script in the path
$script_pos = array_search($current_script, $path_parts);

if ($script_pos !== false && isset($path_parts[$script_pos + 1])) {
    // ID comes from URL path segment after 'edit'
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

// Get all clients for dropdown
$clients = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();

// Contract types
$contract_types = [
    'AMC' => 'Annual Maintenance Contract',
    'SLA' => 'Service Level Agreement', 
    'Pay-per-use' => 'Pay-per-use',
    'Project-based' => 'Project-based',
    'Retainer' => 'Retainer',
    'Support' => 'Technical Support',
    'Consulting' => 'Consulting Services',
    'Other' => 'Other'
];

// Payment terms
$payment_terms = [
    'Monthly' => 'Monthly',
    'Quarterly' => 'Quarterly',
    'Semi-Annual' => 'Semi-Annual',
    'Annual' => 'Annual',
    'One-time' => 'One-time',
    'Milestone-based' => 'Milestone-based'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contract_type = $_POST['contract_type'] ?? '';
    $client_id = $_POST['client_id'] ?? null;
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $service_scope = $_POST['service_scope'] ?? '';
    $monthly_amount = $_POST['monthly_amount'] ?? null;
    $status = $_POST['status'] ?? 'Draft';
    $response_time_hours = $_POST['response_time_hours'] ?? null;
    $resolution_time_hours = $_POST['resolution_time_hours'] ?? null;
    $penalty_terms = $_POST['penalty_terms'] ?? '';

    // Validate required fields
    if (empty($contract_type) || empty($client_id) || empty($start_date) || empty($end_date)) {
        $error = "Contract type, client, start date, and end date are required.";
    } elseif (empty($service_scope)) {
        $error = "Service scope is required.";
    } else {
        // Validate dates
        $start_dt = new DateTime($start_date);
        $end_dt = new DateTime($end_date);
        
        if ($end_dt <= $start_dt) {
            $error = "End date must be after start date.";
        } else {
            // Update contract
            $stmt = $pdo->prepare("
                UPDATE contracts 
                SET contract_type = ?, 
                    client_id = ?, 
                    start_date = ?, 
                    end_date = ?, 
                    service_scope = ?, 
                    monthly_amount = ?, 
                    status = ?, 
                    response_time_hours = ?, 
                    resolution_time_hours = ?, 
                    penalty_terms = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $contract_type, $client_id, $start_date, $end_date,
                $service_scope, $monthly_amount, $status,
                $response_time_hours, $resolution_time_hours, $penalty_terms, $contract_id
            ]);

            if ($result) {
                header('Location: ' . route('contracts.view', ['id' => $contract_id]) . '&updated=1');
                exit;
            } else {
                $error = "Failed to update contract.";
            }
        }
    }
}

$current_user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Contract | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
                    <h1><i class="fas fa-edit"></i> Edit Contract</h1>
                    <p class="text-muted">Update contract information for <?php echo htmlspecialchars($contract['contract_number']); ?></p>
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
                    <li class="breadcrumb-item"><a href="<?php echo route('contracts.view', ['id' => $contract_id]); ?>"><?php echo htmlspecialchars($contract['contract_number']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Contract</li>
                </ol>
            </nav>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Contract Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contract Type *</label>
                                    <select class="form-select" name="contract_type" required>
                                        <option value="">Select Contract Type</option>
                                        <?php foreach ($contract_types as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $contract['contract_type'] == $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Client *</label>
                                    <select class="form-select" name="client_id" required>
                                        <option value="">Select Client</option>
                                        <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" <?php echo $contract['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($contract['start_date']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">End Date *</label>
                                    <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($contract['end_date']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Monthly Amount (TZS)</label>
                                    <input type="number" class="form-control" name="monthly_amount" value="<?php echo $contract['monthly_amount'] ?? ''; ?>" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="Draft" <?php echo $contract['status'] == 'Draft' ? 'selected' : ''; ?>>Draft</option>
                                        <option value="Active" <?php echo $contract['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Pending" <?php echo $contract['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Expired" <?php echo $contract['status'] == 'Expired' ? 'selected' : ''; ?>>Expired</option>
                                        <option value="Cancelled" <?php echo $contract['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Payment Frequency</label>
                                    <select class="form-select" name="payment_frequency">
                                        <option value="Monthly" <?php echo $contract['monthly_amount'] ? 'selected' : ''; ?>>Monthly</option>
                                        <option value="" <?php echo !$contract['monthly_amount'] ? 'selected' : ''; ?>>None</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Response Time (Hours)</label>
                                    <input type="number" class="form-control" name="response_time_hours" value="<?php echo $contract['response_time_hours'] ?? ''; ?>" min="0">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Resolution Time (Hours)</label>
                                    <input type="number" class="form-control" name="resolution_time_hours" value="<?php echo $contract['resolution_time_hours'] ?? ''; ?>" min="0">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Penalty Terms</label>
                                    <textarea class="form-control" name="penalty_terms" rows="4"><?php echo htmlspecialchars($contract['penalty_terms'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Service Scope</label>
                            <textarea class="form-control" name="service_scope" rows="4" placeholder="Describe the services to be provided under this contract..."><?php echo htmlspecialchars($contract['service_scope']); ?></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route('contracts.view', ['id' => $contract_id]); ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Contract
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Contract
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize datepickers
        flatpickr('input[type="date"]', {
            dateFormat: "Y-m-d",
            allowInput: true
        });
    </script>
    
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