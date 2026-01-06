<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

if (!hasPermission('admin') && !hasPermission('manager')) {
    header('Location: ../dashboard.php');
    exit;
}

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
           c.phone as client_phone
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

// Get all clients for dropdown
$clients = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'Active' ORDER BY company_name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_name = $_POST['service_name'] ?? '';
    $client_id = $_POST['client_id'] ?? null;
    $service_category = $_POST['service_category'] ?? '';
    $domain_name = $_POST['domain_name'] ?? '';
    $hosting_plan = $_POST['hosting_plan'] ?? '';
    $email_type = $_POST['email_type'] ?? '';
    $email_accounts = $_POST['email_accounts'] ?? null;
    $status = $_POST['status'] ?? '';
    $expiry_date = $_POST['expiry_date'] ?? null;
    $monthly_price = $_POST['monthly_price'] ?? 0;
    $billing_cycle = $_POST['billing_cycle'] ?? '';
    $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;
    $description = $_POST['description'] ?? '';

    // Validate required fields
    if (empty($service_name) || empty($client_id)) {
        $error = "Service name and client are required.";
    } else {
        // Update service
        $stmt = $pdo->prepare("
            UPDATE client_services 
            SET service_name = ?, 
                client_id = ?, 
                service_category = ?, 
                domain_name = ?, 
                hosting_plan = ?, 
                email_type = ?, 
                email_accounts = ?, 
                status = ?, 
                expiry_date = ?, 
                monthly_price = ?, 
                billing_cycle = ?, 
                auto_renew = ?, 
                description = ?, 
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $service_name, $client_id, $service_category, $domain_name,
            $hosting_plan, $email_type, $email_accounts, $status,
            $expiry_date, $monthly_price, $billing_cycle, $auto_renew,
            $description, $service_id
        ]);

        if ($result) {
            header('Location: ' . route('services.view', ['id' => $service_id]) . '&updated=1');
            exit;
        } else {
            $error = "Failed to update service.";
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
    <title>Edit Service | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header">
                <div>
                    <h1><i class="fas fa-edit"></i> Edit Service</h1>
                    <p class="text-muted">Update service information for <?php echo htmlspecialchars($service['service_name']); ?></p>
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
                    <li class="breadcrumb-item"><a href="<?php echo route('services.view', ['id' => $service_id]); ?>"><?php echo htmlspecialchars($service['service_name']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Service</li>
                </ol>
            </nav>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-edit"></i> Service Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Service Name *</label>
                                    <input type="text" class="form-control" name="service_name" value="<?php echo htmlspecialchars($service['service_name']); ?>" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Client *</label>
                                    <select class="form-select" name="client_id" required>
                                        <option value="">Select Client</option>
                                        <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" <?php echo $service['client_id'] == $client['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="service_category">
                                        <option value="">Select Category</option>
                                        <option value="Domain" <?php echo $service['service_category'] == 'Domain' ? 'selected' : ''; ?>>Domain</option>
                                        <option value="Hosting" <?php echo $service['service_category'] == 'Hosting' ? 'selected' : ''; ?>>Hosting</option>
                                        <option value="Email" <?php echo $service['service_category'] == 'Email' ? 'selected' : ''; ?>>Email</option>
                                        <option value="Security" <?php echo $service['service_category'] == 'Security' ? 'selected' : ''; ?>>Security</option>
                                        <option value="Subscription" <?php echo $service['service_category'] == 'Subscription' ? 'selected' : ''; ?>>Subscription</option>
                                        <option value="Other" <?php echo $service['service_category'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Domain Name</label>
                                    <input type="text" class="form-control" name="domain_name" value="<?php echo htmlspecialchars($service['domain_name'] ?? ''); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Hosting Plan</label>
                                    <input type="text" class="form-control" name="hosting_plan" value="<?php echo htmlspecialchars($service['hosting_plan'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Type</label>
                                    <select class="form-select" name="email_type">
                                        <option value="">Select Email Type</option>
                                        <option value="Shared" <?php echo $service['email_type'] == 'Shared' ? 'selected' : ''; ?>>Shared Hosting</option>
                                        <option value="Business" <?php echo $service['email_type'] == 'Business' ? 'selected' : ''; ?>>Business Email</option>
                                        <option value="G Suite" <?php echo $service['email_type'] == 'G Suite' ? 'selected' : ''; ?>>G Suite/Google Workspace</option>
                                        <option value="Office 365" <?php echo $service['email_type'] == 'Office 365' ? 'selected' : ''; ?>>Office 365</option>
                                        <option value="Custom" <?php echo $service['email_type'] == 'Custom' ? 'selected' : ''; ?>>Custom Solution</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Email Accounts</label>
                                    <input type="number" class="form-control" name="email_accounts" value="<?php echo $service['email_accounts'] ?? ''; ?>" min="0">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="Active" <?php echo $service['status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Pending" <?php echo $service['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Suspended" <?php echo $service['status'] == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        <option value="Cancelled" <?php echo $service['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="Expired" <?php echo $service['status'] == 'Expired' ? 'selected' : ''; ?>>Expired</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" class="form-control" name="expiry_date" value="<?php echo $service['expiry_date'] ?? ''; ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Monthly Price ($)</label>
                                    <input type="number" class="form-control" name="monthly_price" value="<?php echo $service['monthly_price'] ?? 0; ?>" step="0.01" min="0">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Billing Cycle</label>
                                    <select class="form-select" name="billing_cycle">
                                        <option value="Monthly" <?php echo $service['billing_cycle'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        <option value="Quarterly" <?php echo $service['billing_cycle'] == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                        <option value="Semi-Annually" <?php echo $service['billing_cycle'] == 'Semi-Annually' ? 'selected' : ''; ?>>Semi-Annually</option>
                                        <option value="Annually" <?php echo $service['billing_cycle'] == 'Annually' ? 'selected' : ''; ?>>Annually</option>
                                        <option value="Biennially" <?php echo $service['billing_cycle'] == 'Biennially' ? 'selected' : ''; ?>>Biennially</option>
                                        <option value="Triennially" <?php echo $service['billing_cycle'] == 'Triennially' ? 'selected' : ''; ?>>Triennially</option>
                                    </select>
                                </div>
                                
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="auto_renew" id="auto_renew" <?php echo $service['auto_renew'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="auto_renew">
                                        Auto Renew
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="4"><?php echo htmlspecialchars($service['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route('services.view', ['id' => $service_id]); ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Service
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Service
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>