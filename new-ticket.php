<?php
// new-ticket.php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'client') {
    header('Location: client-login.php');
    exit;
}

require_once 'config/database.php';
$pdo = getDBConnection();
$client_id = $_SESSION['client_id'] ?? null;

// Get client locations
$locations = [];
try {
    $stmt = $pdo->prepare("SELECT id, location_name FROM client_locations WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $locations = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Locations error: " . $e->getMessage());
}

// Get client assets
$assets = [];
try {
    $stmt = $pdo->prepare("SELECT id, asset_tag, asset_type FROM assets WHERE client_id = ? AND status = 'Active'");
    $stmt->execute([$client_id]);
    $assets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Assets error: " . $e->getMessage());
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';
    $category = $_POST['category'] ?? '';
    $location_id = $_POST['location_id'] ?? null;
    $asset_id = $_POST['asset_id'] ?? null;
    $contact_method = $_POST['contact_method'] ?? 'Email';
    
    if (empty($title) || empty($description)) {
        $error = "Title and description are required";
    } else {
        try {
            // Generate ticket number
            $ticket_number = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Insert ticket
            $stmt = $pdo->prepare("
                INSERT INTO tickets (
                    ticket_number, client_id, location_id, title, description, 
                    priority, category, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            
            $stmt->execute([
                $ticket_number,
                $client_id,
                $location_id ?: null,
                $title,
                $description,
                $priority,
                $category,
                $_SESSION['user_id']
            ]);
            
            $ticket_id = $pdo->lastInsertId();
            
            // Link asset if selected
            if ($asset_id) {
                $stmt = $pdo->prepare("
                    UPDATE assets SET notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?
                ");
                $stmt->execute(["\nLinked to ticket $ticket_number on " . date('Y-m-d'), $asset_id]);
            }
            
            $success = "Ticket #$ticket_number created successfully! Our team will contact you soon.";
            
            // Clear form on success
            if ($success) {
                $_POST = [];
            }
            
        } catch (Exception $e) {
            $error = "Error creating ticket: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>New Support Ticket | Client Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --client-primary: #28a745; }
        .form-card { max-width: 800px; margin: 0 auto; }
        .priority-high { border-left: 5px solid #dc3545; }
        .priority-medium { border-left: 5px solid #ffc107; }
        .priority-low { border-left: 5px solid #17a2b8; }
    </style>
</head>
<body>
    <?php include 'client-sidebar.php'; ?>
    
    <div class="client-main">
        <div class="data-card form-card">
            <h1 class="mb-4"><i class="fas fa-plus-circle me-2"></i>New Support Ticket</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                    <div class="mt-3">
                        <a href="client-tickets.php" class="btn btn-success me-2">
                            <i class="fas fa-ticket-alt me-1"></i> View All Tickets
                        </a>
                        <a href="new-ticket.php" class="btn btn-outline-success">
                            <i class="fas fa-plus me-1"></i> Create Another
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
            <form method="POST" id="ticketForm">
                <div class="row">
                    <div class="col-md-8">
                        <!-- Ticket Details -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Ticket Details</label>
                            <div class="mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-control form-control-lg" 
                                       placeholder="Brief description of the issue" 
                                       value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                                       required>
                                <small class="text-muted">Be specific about the issue</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Description *</label>
                                <textarea name="description" class="form-control" rows="6" 
                                          placeholder="Please provide detailed information about the issue..." 
                                          required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                <small class="text-muted">Include error messages, steps to reproduce, and impact</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <option value="">Select category</option>
                                    <option value="Hardware" <?php echo ($_POST['category'] ?? '') == 'Hardware' ? 'selected' : ''; ?>>Hardware Issue</option>
                                    <option value="Software" <?php echo ($_POST['category'] ?? '') == 'Software' ? 'selected' : ''; ?>>Software Issue</option>
                                    <option value="Network" <?php echo ($_POST['category'] ?? '') == 'Network' ? 'selected' : ''; ?>>Network Issue</option>
                                    <option value="Email" <?php echo ($_POST['category'] ?? '') == 'Email' ? 'selected' : ''; ?>>Email Problem</option>
                                    <option value="Access" <?php echo ($_POST['category'] ?? '') == 'Access' ? 'selected' : ''; ?>>Access Request</option>
                                    <option value="Other" <?php echo ($_POST['category'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Additional Information -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Additional Information</label>
                            
                            <div class="mb-3">
                                <label class="form-label">Priority *</label>
                                <select name="priority" class="form-select" required>
                                    <option value="Low" <?php echo ($_POST['priority'] ?? 'Medium') == 'Low' ? 'selected' : ''; ?>>Low - Minor issue, no work disruption</option>
                                    <option value="Medium" <?php echo ($_POST['priority'] ?? 'Medium') == 'Medium' ? 'selected' : ''; ?>>Medium - Normal issue, some impact</option>
                                    <option value="High" <?php echo ($_POST['priority'] ?? 'Medium') == 'High' ? 'selected' : ''; ?>>High - Critical issue, work disruption</option>
                                </select>
                            </div>
                            
                            <?php if (!empty($locations)): ?>
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <select name="location_id" class="form-select">
                                    <option value="">Select location</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['id']; ?>" 
                                            <?php echo ($_POST['location_id'] ?? '') == $location['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($location['location_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($assets)): ?>
                            <div class="mb-3">
                                <label class="form-label">Related Asset (Optional)</label>
                                <select name="asset_id" class="form-select">
                                    <option value="">Select asset</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?php echo $asset['id']; ?>">
                                            <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Preferred Contact Method</label>
                                <select name="contact_method" class="form-select">
                                    <option value="Email" <?php echo ($_POST['contact_method'] ?? 'Email') == 'Email' ? 'selected' : ''; ?>>Email</option>
                                    <option value="Phone" <?php echo ($_POST['contact_method'] ?? 'Email') == 'Phone' ? 'selected' : ''; ?>>Phone Call</option>
                                    <option value="Teams" <?php echo ($_POST['contact_method'] ?? 'Email') == 'Teams' ? 'selected' : ''; ?>>Microsoft Teams</option>
                                    <option value="WhatsApp" <?php echo ($_POST['contact_method'] ?? 'Email') == 'WhatsApp' ? 'selected' : ''; ?>>WhatsApp</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Support Information -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-1"></i>Support Information</h6>
                            <small class="d-block mb-1">• Response time: 2 hours for High priority</small>
                            <small class="d-block mb-1">• Business hours: Mon-Fri 8AM-6PM</small>
                            <small class="d-block">• Emergency: Call +255 123 456 789</small>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Buttons -->
                <div class="d-flex justify-content-between mt-4 pt-4 border-top">
                    <a href="client-dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-paper-plane me-2"></i> Submit Ticket
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Form validation
        document.getElementById('ticketForm')?.addEventListener('submit', function(e) {
            const title = this.querySelector('[name="title"]').value.trim();
            const description = this.querySelector('[name="description"]').value.trim();
            
            if (!title || !description) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }
            
            if (title.length < 10) {
                e.preventDefault();
                alert('Please provide a more descriptive title (minimum 10 characters)');
                return false;
            }
            
            if (description.length < 20) {
                e.preventDefault();
                alert('Please provide more details in the description (minimum 20 characters)');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>