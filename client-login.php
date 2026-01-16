<?php
// client-login.php
$config_file = 'config/database.php';
if (file_exists($config_file)) {
    require_once $config_file;
} else {
    die("Configuration file not found. Please check your setup.");
}

session_start();

// Check if already logged in as client
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'client') {
    header('Location: client-dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password";
    } else {
        try {
            // Authenticate user
            $user = authenticateUser($email, $password);
            
            if ($user) {
                if ($user['user_type'] === 'client') {
                    // Set session for client
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_type'] = 'client';
                    $_SESSION['client_id'] = $user['linked_client_id'] ?? null;
                    $_SESSION['email'] = $user['email'];
                    
                    // Redirect to client dashboard
                    header('Location: client-dashboard.php');
                    exit;
                } else {
                    $error = "This account is not a client account. Please use staff login.";
                }
            } else {
                $error = "Invalid email or password";
            }
        } catch (Exception $e) {
            $error = "Login error: " . $e->getMessage();
        }
    }
}

// Authentication function (included here for simplicity)
function authenticateUser($email, $password) {
    $pdo = getDBConnection();
    if (!$pdo) return false;
    
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   CASE 
                       WHEN u.user_type = 'client' THEN cu.client_id
                       ELSE NULL 
                   END as linked_client_id
            FROM users u
            LEFT JOIN client_users cu ON u.id = cu.user_id
            WHERE u.email = ? AND u.is_active = true
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            return $user;
        }
        return false;
    } catch (Exception $e) {
        error_log("Authentication error: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Login | MSP Application</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .client-badge {
            display: inline-block;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background: #fee;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .login-links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 14px;
        }
        
        .login-links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 8px;
        }
        
        .login-links a:hover {
            text-decoration: underline;
        }
        
        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            display: block;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Client Portal <span class="client-badge">Client</span></h1>
            <p>Access your support tickets and assets</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" 
                       name="email" 
                       class="form-input" 
                       placeholder="your@company.com" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                       required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" 
                       name="password" 
                       class="form-input" 
                       placeholder="••••••••" 
                       required>
                <span class="password-hint">Default password: qwert12345</span>
            </div>
            
            <button type="submit" class="btn-login">Login to Client Portal</button>
        </form>
        
        <div class="login-links">
            <a href="client-change-password.php">Change Password</a> • 
            <a href="login.php">Staff Login</a>
        </div>
        
        <div style="margin-top: 25px; padding: 15px; background: #f8f9fa; border-radius: 10px;">
            <p style="font-size: 12px; color: #666; margin: 0;">
                <strong>Need help?</strong> Contact support: support@msp.com | Mon-Fri: 8AM-6PM
            </p>
        </div>
    </div>
    
    <script>
        // Simple form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = this.querySelector('[name="email"]').value.trim();
            const password = this.querySelector('[name="password"]').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            if (!email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
            
            return true;
        });
        
        // Add focus effect to inputs
        const inputs = document.querySelectorAll('.form-input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>