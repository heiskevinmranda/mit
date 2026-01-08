<?php
// Simple test script to demonstrate error pages
require_once 'includes/auth.php';
require_once 'includes/error_handler.php';

// Example usage of error handlers
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case '404':
            handle404Error("The requested page was not found.");
            break;
        case '403':
            handle403Error("Access to this resource is forbidden.");
            break;
        case '401':
            handle401Error("You need to log in to access this resource.");
            break;
        case '500':
            handle500Error("An internal server error occurred.");
            break;
        default:
            header("Location: " . route('dashboard'));
            exit();
    }
} else {
    // Show test page with links to trigger different errors
    require_once 'includes/header.php';
    $page_title = "Test Error Pages";
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">Test Error Pages</h1>
                
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Click on the links below to test different error pages:</h5>
                        
                        <div class="row">
                            <div class="col-md-6 col-lg-3 mb-3">
                                <a href="?error=404" class="btn btn-info w-100">
                                    <i class="fas fa-search me-2"></i>Test 404 Error
                                </a>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <a href="?error=403" class="btn btn-warning w-100">
                                    <i class="fas fa-lock me-2"></i>Test 403 Error
                                </a>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <a href="?error=401" class="btn btn-danger w-100">
                                    <i class="fas fa-user-times me-2"></i>Test 401 Error
                                </a>
                            </div>
                            
                            <div class="col-md-6 col-lg-3 mb-3">
                                <a href="?error=500" class="btn btn-dark w-100">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Test 500 Error
                                </a>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <a href="<?php echo route('dashboard'); ?>" class="btn btn-primary">
                                <i class="fas fa-home me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    require_once 'includes/footer.php';
}
?>