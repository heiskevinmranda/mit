<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="error-page text-center py-5">
                <div class="error-icon mb-4">
                    <i class="fas fa-exclamation-triangle text-warning display-1"></i>
                </div>
                
                <div class="error-content">
                    <h1 class="display-4 fw-bold text-danger"><?php echo $error_code; ?></h1>
                    <h2 class="mb-4"><?php echo htmlspecialchars($error_title); ?></h2>
                    <p class="lead mb-4"><?php echo htmlspecialchars($error_message); ?></p>
                    
                    <div class="error-actions mt-4">
                        <?php if ($error_code == 401): ?>
                            <a href="<?php echo route('login'); ?>" class="btn btn-primary btn-lg me-3">
                                <i class="fas fa-sign-in-alt me-2"></i>Login
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($show_home_link || $error_code != 401): ?>
                            <a href="<?php echo route('dashboard'); ?>" class="btn btn-secondary btn-lg me-3">
                                <i class="fas fa-home me-2"></i>Go to Dashboard
                            </a>
                        <?php endif; ?>
                        
                        <button onclick="window.history.back()" class="btn btn-outline-secondary btn-lg">
                            <i class="fas fa-arrow-left me-2"></i>Go Back
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.error-page {
    min-height: 70vh;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}

.error-icon {
    opacity: 0.7;
}

.error-content h1 {
    font-size: 5rem;
    line-height: 1;
}

.error-content h2 {
    font-size: 2.5rem;
    margin-bottom: 1.5rem;
}

.error-actions .btn {
    margin-bottom: 10px;
    min-width: 150px;
}

@media (max-width: 768px) {
    .error-content h1 {
        font-size: 3rem;
    }
    
    .error-content h2 {
        font-size: 1.8rem;
    }
    
    .error-actions .btn {
        display: block;
        width: 100%;
        margin-bottom: 10px;
        margin-right: 0 !important;
    }
}
</style>

<?php
require_once 'minimal_footer.php';
?>