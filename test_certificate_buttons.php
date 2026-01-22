<?php
// test_certificate_buttons.php
// Simple test page to verify certificate buttons work

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$current_user = getCurrentUser();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Certificate Button Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body data-current-user-id="<?php echo $current_user['id'] ?? ''; ?>" data-current-user-role="<?php echo $current_user['user_type'] ?? ''; ?>">
    <div class="container mt-5">
        <h1>Certificate Button Test Page</h1>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Certificate Upload Test</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-primary" onclick="initCertificateUpload('<?php echo $current_user['id']; ?>')">
                            <i class="fas fa-certificate me-1"></i>Upload Certificate
                        </button>
                        <p class="mt-3">Click this button to test certificate upload functionality.</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5>Debug Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Current User ID:</strong> <?php echo $current_user['id'] ?? 'N/A'; ?></p>
                        <p><strong>User Role:</strong> <?php echo $current_user['user_type'] ?? 'N/A'; ?></p>
                        <p><strong>JS Functions Loaded:</strong></p>
                        <ul>
                            <li>initCertificateUpload: <span id="initFunc">Checking...</span></li>
                            <li>loadCertificates: <span id="loadFunc">Checking...</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4">
            <div class="card">
                <div class="card-header">
                    <h5>Certificates Container</h5>
                </div>
                <div class="card-body">
                    <div id="certificatesContainer" data-certificates-container>
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin text-muted"></i> Loading certificates...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Certificate Components -->
    <?php include_once 'includes/certificate_components.php'; ?>
    
    <!-- Certificate Management JavaScript -->
    <script src="js/certificate_management.js"></script>
    
    <script>
        // Check if functions are available
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const initSpan = document.getElementById('initFunc');
                const loadSpan = document.getElementById('loadFunc');
                
                initSpan.textContent = typeof initCertificateUpload !== 'undefined' ? '✓ YES' : '✗ NO';
                initSpan.className = typeof initCertificateUpload !== 'undefined' ? 'text-success' : 'text-danger';
                
                loadSpan.textContent = typeof loadCertificates !== 'undefined' ? '✓ YES' : '✗ NO';
                loadSpan.className = typeof loadCertificates !== 'undefined' ? 'text-success' : 'text-danger';
                
                // Try to load certificates
                if (typeof loadCertificates === 'function') {
                    loadCertificates('<?php echo $current_user['id']; ?>');
                }
            }, 500);
        });
    </script>
</body>
</html>