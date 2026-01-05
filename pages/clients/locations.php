<?php
// pages/clients/locations.php
require_once '../../includes/auth.php';
requireLogin();

$pdo = getDBConnection();
$client_id = $_GET['client_id'] ?? 0;

if (!$client_id) {
    header('Location: index.php');
    exit;
}

// Get client info
$stmt = $pdo->prepare("SELECT id, company_name FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

if (!$client) {
    header('Location: index.php');
    exit;
}

// Get locations for this client
$stmt = $pdo->prepare("SELECT * FROM client_locations WHERE client_id = ? ORDER BY is_primary DESC, location_name");
$stmt->execute([$client_id]);
$locations = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            // Add new location
            $stmt = $pdo->prepare("
                INSERT INTO client_locations 
                (client_id, location_name, address, city, state, country, 
                 primary_contact, phone, email, is_primary) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $client_id,
                $_POST['location_name'],
                $_POST['address'],
                $_POST['city'] ?? '',
                $_POST['state'] ?? '',
                $_POST['country'] ?? '',
                $_POST['primary_contact'] ?? '',
                $_POST['phone'] ?? '',
                $_POST['email'] ?? '',
                isset($_POST['is_primary']) ? 1 : 0
            ]);
            
            setFlashMessage('success', 'Location added successfully!');
            
        } elseif ($action === 'update') {
            // Update existing location
            $location_id = $_POST['location_id'] ?? 0;
            
            $stmt = $pdo->prepare("
                UPDATE client_locations 
                SET location_name = ?, address = ?, city = ?, state = ?, country = ?,
                    primary_contact = ?, phone = ?, email = ?, is_primary = ?
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
                isset($_POST['is_primary']) ? 1 : 0,
                $location_id,
                $client_id
            ]);
            
            setFlashMessage('success', 'Location updated successfully!');
            
        } elseif ($action === 'delete') {
            // Delete location
            $location_id = $_POST['location_id'] ?? 0;
            
            $stmt = $pdo->prepare("DELETE FROM client_locations WHERE id = ? AND client_id = ?");
            $stmt->execute([$location_id, $client_id]);
            
            setFlashMessage('success', 'Location deleted successfully!');
        }
        
        // Refresh page
        header("Location: locations.php?client_id=$client_id");
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
    <title>Client Locations | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .location-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #004E89;
        }
        
        .location-card.primary {
            border-left-color: #FF6B35;
            background: #fff9f7;
        }
        
        .location-card h5 {
            color: #004E89;
            margin-bottom: 15px;
        }
        
        .location-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .info-item {
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: 500;
            color: #666;
            font-size: 14px;
        }
        
        .info-value {
            color: #333;
        }
        
        .badge-primary-location {
            background: #FF6B35;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-map-marker-alt"></i> Locations for: <?php echo htmlspecialchars($client['company_name']); ?></h1>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Clients
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                        <i class="fas fa-plus"></i> Add Location
                    </button>
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
            
            <!-- Locations List -->
            <div class="row">
                <?php if (empty($locations)): ?>
                <div class="col-md-12">
                    <div class="card text-center py-5">
                        <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                        <h4>No Locations Found</h4>
                        <p class="text-muted">This client doesn't have any locations yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                            <i class="fas fa-plus"></i> Add First Location
                        </button>
                        <p class="text-muted small mt-3">
                            <i class="fas fa-info-circle"></i> 
                            Locations are required for creating tickets with specific site information.
                        </p>
                    </div>
                </div>
                <?php else: ?>
                <?php foreach ($locations as $location): ?>
                <div class="col-md-6">
                    <div class="location-card <?php echo $location['is_primary'] ? 'primary' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <h5>
                                <?php echo htmlspecialchars($location['location_name']); ?>
                                <?php if ($location['is_primary']): ?>
                                <span class="badge-primary-location">Primary</span>
                                <?php endif; ?>
                            </h5>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editLocationModal"
                                        data-id="<?php echo $location['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($location['location_name']); ?>"
                                        data-address="<?php echo htmlspecialchars($location['address']); ?>"
                                        data-city="<?php echo htmlspecialchars($location['city']); ?>"
                                        data-state="<?php echo htmlspecialchars($location['state']); ?>"
                                        data-country="<?php echo htmlspecialchars($location['country']); ?>"
                                        data-contact="<?php echo htmlspecialchars($location['primary_contact']); ?>"
                                        data-phone="<?php echo htmlspecialchars($location['phone']); ?>"
                                        data-email="<?php echo htmlspecialchars($location['email']); ?>"
                                        data-primary="<?php echo $location['is_primary']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger" 
                                        onclick="confirmDelete(<?php echo $location['id']; ?>, '<?php echo htmlspecialchars($location['location_name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="location-info">
                            <?php if (!empty($location['address'])): ?>
                            <div class="info-item">
                                <div class="info-label">Address:</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($location['address'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($location['city'])): ?>
                            <div class="info-item">
                                <div class="info-label">City:</div>
                                <div class="info-value"><?php echo htmlspecialchars($location['city']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($location['primary_contact'])): ?>
                            <div class="info-item">
                                <div class="info-label">Contact Person:</div>
                                <div class="info-value"><?php echo htmlspecialchars($location['primary_contact']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($location['phone'])): ?>
                            <div class="info-item">
                                <div class="info-label">Phone:</div>
                                <div class="info-value"><?php echo htmlspecialchars($location['phone']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($location['email'])): ?>
                            <div class="info-item">
                                <div class="info-label">Email:</div>
                                <div class="info-value"><?php echo htmlspecialchars($location['email']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Add Location Modal -->
    <div class="modal fade" id="addLocationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add New Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="row g-3">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label required">Location Name</label>
                                    <input type="text" class="form-control" name="location_name" required 
                                           placeholder="e.g., Main Office, Branch Office, Warehouse">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label required">Address</label>
                                    <textarea class="form-control" name="address" rows="3" required 
                                              placeholder="Complete address including street, building, etc."></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" name="city" 
                                           placeholder="City">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">State/Region</label>
                                    <input type="text" class="form-control" name="state" 
                                           placeholder="State or Region">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" name="country" 
                                           placeholder="Country">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Primary Contact Person</label>
                                    <input type="text" class="form-control" name="primary_contact" 
                                           placeholder="Contact person name">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" 
                                           placeholder="Phone number">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" 
                                           placeholder="Email address">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary">
                                    <label class="form-check-label" for="is_primary">
                                        Set as primary location for this client
                                    </label>
                                    <div class="form-text">
                                        Primary location will be selected by default when creating tickets.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Location</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Location Modal -->
    <div class="modal fade" id="editLocationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="location_id" id="editLocationId">
                        
                        <div class="row g-3">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label required">Location Name</label>
                                    <input type="text" class="form-control" name="location_name" id="editLocationName" required>
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label required">Address</label>
                                    <textarea class="form-control" name="address" id="editAddress" rows="3" required></textarea>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" class="form-control" name="city" id="editCity">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">State/Region</label>
                                    <input type="text" class="form-control" name="state" id="editState">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Country</label>
                                    <input type="text" class="form-control" name="country" id="editCountry">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Primary Contact Person</label>
                                    <input type="text" class="form-control" name="primary_contact" id="editContact">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" name="phone" id="editPhone">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" id="editEmail">
                                </div>
                            </div>
                            
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary" id="editIsPrimary">
                                    <label class="form-check-label" for="editIsPrimary">
                                        Set as primary location for this client
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Location</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteLocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger"></i> Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="location_id" id="deleteLocationId">
                        
                        <p>Are you sure you want to delete location: <strong id="deleteLocationName"></strong>?</p>
                        <p class="text-danger">
                            <i class="fas fa-exclamation-circle"></i> 
                            This action cannot be undone. Any tickets associated with this location will lose their location reference.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Location</button>
                    </div>
                </form>
            </div>
        </div>
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
        
        // Edit modal handler
        const editModal = document.getElementById('editLocationModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                
                document.getElementById('editLocationId').value = button.getAttribute('data-id');
                document.getElementById('editLocationName').value = button.getAttribute('data-name');
                document.getElementById('editAddress').value = button.getAttribute('data-address');
                document.getElementById('editCity').value = button.getAttribute('data-city');
                document.getElementById('editState').value = button.getAttribute('data-state');
                document.getElementById('editCountry').value = button.getAttribute('data-country');
                document.getElementById('editContact').value = button.getAttribute('data-contact');
                document.getElementById('editPhone').value = button.getAttribute('data-phone');
                document.getElementById('editEmail').value = button.getAttribute('data-email');
                document.getElementById('editIsPrimary').checked = button.getAttribute('data-primary') === '1';
            });
        }
        
        // Delete confirmation
        function confirmDelete(locationId, locationName) {
            document.getElementById('deleteLocationId').value = locationId;
            document.getElementById('deleteLocationName').textContent = locationName;
            
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteLocationModal'));
            deleteModal.show();
        }
        
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.remove();
            });
        }, 5000);
    </script>
</body>
</html>