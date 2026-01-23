<?php
// pages/clients/edit_location.php
require_once '../../includes/auth.php';
require_once '../../includes/client_functions.php';
require_once '../../includes/routes.php';
requireLogin();

$pdo = getDBConnection();
$location_id = $_GET['id'] ?? 0;
$client_id = $_GET['client_id'] ?? 0;

if (!$location_id || !$client_id) {
    header('Location: ' . route('clients.index'));
    exit;
}

// Get location info
$stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ? AND client_id = ?");
$stmt->execute([$location_id, $client_id]);
$location = $stmt->fetch();

if (!$location) {
    // If location not found with client_id constraint, try without it to provide better error
    $stmt = $pdo->prepare("SELECT * FROM client_locations WHERE id = ?");
    $stmt->execute([$location_id]);
    $location_check = $stmt->fetch();
    
    if (!$location_check) {
        header('Location: ' . route('clients.index'));
        exit;
    } else {
        // Location exists but doesn't belong to the specified client
        header('Location: ' . route('clients.view', ['id' => $client_id]));
        exit;
    }
}

// Get client info
$client_stmt = $pdo->prepare("SELECT id, company_name FROM clients WHERE id = ?");
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch();

if (!$client) {
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // If setting as primary, first unset existing primary location for this client (except current one)
        if (!empty($_POST['is_primary'])) {
            $unset_stmt = $pdo->prepare("UPDATE client_locations SET is_primary = 0 WHERE client_id = ? AND is_primary = 1 AND id != ?");
            $unset_stmt->execute([$client_id, $location_id]);
        }
        
        $stmt = $pdo->prepare("
            UPDATE client_locations 
            SET location_name = ?, address = ?, city = ?, state = ?, 
                country = ?, primary_contact = ?, phone = ?, 
                email = ?, is_primary = ?
            WHERE id = ? AND client_id = ?
        ");
        
        $stmt->execute([
            $_POST['location_name'],
            $_POST['address'],
            $_POST['city'] ?? '',
            $_POST['state'] ?? '',
            $_POST['country'] ?? '',
            $_POST['primary_contact'] ?? '',
            $_POST['phone'] ?? '',
            $_POST['email'] ?? '',
            !empty($_POST['is_primary']) ? 1 : 0,
            $location_id,
            $client_id
        ]);
        
        setFlashMessage('success', 'Location updated successfully!');
        
        // Redirect back to client view page
        header("Location: " . route('clients.view', ['id' => $client_id]));
        exit;
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Location | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-map-marker-alt"></i> Edit Location for: <?php echo htmlspecialchars($client['company_name']); ?></h1>
                <div class="btn-group">
                    <a href="<?php echo route('clients.view', ['id' => $client_id]); ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Flash Messages -->
            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $flash['type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-edit"></i> Location Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label required">Location Name</label>
                                    <input type="text" class="form-control" name="location_name" required 
                                           value="<?php echo htmlspecialchars($location['location_name']); ?>"
                                           placeholder="e.g., Main Office, Branch Office, Warehouse">
                                </div>
                            </div>
                            

                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label required">Address</label>
                                    <textarea class="form-control" name="address" rows="3" required 
                                              placeholder="Complete address including street, building, etc;"><?php echo htmlspecialchars($location['address']); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" name="city" 
                                           value="<?php echo htmlspecialchars($location['city']); ?>"
                                           placeholder="City">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">State/Region</label>
                                    <input type="text" class="form-control" name="state" 
                                           value="<?php echo htmlspecialchars($location['state']); ?>"
                                           placeholder="State or Region">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" name="country" 
                                           value="<?php echo htmlspecialchars($location['country']); ?>"
                                           placeholder="Country">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Primary Contact Person</label>
                                    <input type="text" class="form-control" name="primary_contact" 
                                           value="<?php echo htmlspecialchars($location['primary_contact']); ?>"
                                           placeholder="Contact person name">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($location['phone']); ?>"
                                           placeholder="Phone number">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($location['email']); ?>"
                                           placeholder="Email address">
                                </div>
                            </div>
                            

                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary" 
                                           <?php echo $location['is_primary'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_primary">
                                        Set as primary location for this client
                                    </label>
                                    <div class="form-text">
                                        Primary location will be selected by default when creating tickets.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Location
                            </button>
                            <a href="<?php echo route('clients.view', ['id' => $client_id]); ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>