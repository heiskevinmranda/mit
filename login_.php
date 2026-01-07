<?php
require_once 'includes/auth.php';

// Check if already logged in
if (checkAuth()) {
    header('Location: /mit/dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($email) && !empty($password)) {
        $result = attemptLogin($email, $password);
        
        if ($result['success']) {
            header('Location: /mit/dashboard');
            exit;
        } else {
            $error = $result['error'];
        }
    } else {
        $error = 'Please enter both email and password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MSP Application</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Additional login styles */
        .login-features {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 30px;
        }
        
        .feature-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            transition: transform 0.3s;
        }
        
        .feature-item:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.2);
        }
        
        .feature-item i {
            font-size: 24px;
            color: #FF6B35;
            margin-bottom: 10px;
        }
        
        .demo-credentials {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            border: 2px dashed rgba(255, 255, 255, 0.3);
        }
        
        .demo-credentials h4 {
            color: white;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .demo-credentials ul {
            list-style: none;
            padding: 0;
        }
        
        .demo-credentials li {
            margin-bottom: 8px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-left">
            <div style="max-width: 600px; margin: 0 auto;">
                <h1 style="font-size: 2.5rem; margin-bottom: 20px;">
                    <i class="fas fa-network-wired"></i> MSP Portal
                </h1>
                <p style="font-size: 1.1rem; margin-bottom: 30px;">
                    Centralized platform for managing clients, tickets, assets, and service delivery across your MSP operations.
                </p>
                
                <div class="login-features">
                    <div class="feature-item">
                        <i class="fas fa-users-cog"></i>
                        <div>Client Management</div>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-ticket-alt"></i>
                        <div>Ticket System</div>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-server"></i>
                        <div>Asset Tracking</div>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-chart-line"></i>
                        <div>Reporting & Analytics</div>
                    </div>
                </div>
                

            </div>
        </div>
        
        <div class="login-right">
            <div class="login-box">
                <h2><i class="fas fa-sign-in-alt"></i> Staff Login</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope" style="color: #FF6B35;"></i> Email Address
                        </label>
                        <input type="email" id="email" name="email" required 
                               placeholder="Enter your email">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock" style="color: #FF6B35;"></i> Password
                        </label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password">
                        <div style="text-align: right; margin-top: 8px;">
                            <button type="button" id="togglePassword" 
                                    style="background: none; border: none; color: #666; cursor: pointer; font-size: 14px;">
                                <i class="fas fa-eye"></i> Show Password
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-block" style="padding: 12px; font-size: 16px;">
                        <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                    </button>
                </form>
                
                <div style="margin-top: 20px; text-align: center;">
                    <p style="color: #666; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: #004E89;"></i> 
                        Forgot password? Contact your administrator
                    </p>
                </div>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                    <h4 style="color: #004E89; margin-bottom: 15px; text-align: center;">
                        <i class="fas fa-user-tag"></i> User Roles
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; font-size: 14px;">
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 3px solid #FF6B35;">
                            <i class="fas fa-user-shield"></i> Super Admin
                        </div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 3px solid #004E89;">
                            <i class="fas fa-user-cog"></i> Admin
                        </div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 3px solid #28a745;">
                            <i class="fas fa-user-tie"></i> Manager
                        </div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; border-left: 3px solid #17a2b8;">
                            <i class="fas fa-user-gear"></i> Support Tech
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.className = 'fas fa-eye-slash';
                this.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Password';
            } else {
                passwordInput.type = 'password';
                icon.className = 'fas fa-eye';
                this.innerHTML = '<i class="fas fa-eye"></i> Show Password';
            }
        });
        
        // Auto-focus email field
        document.getElementById('email').focus();
    </script>
</body>
</html>