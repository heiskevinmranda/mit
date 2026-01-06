<?php
// Start session
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'MSP_Application');
define('DB_USER', 'MSPAppUser');
define('DB_PASS', '2q+w7wQMH8xd');

// Create connection
function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /mit/dashboard');
    exit;
}

$pdo = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    echo "<!-- Debug: Email = $email, Password provided = " . (!empty($password) ? 'Yes' : 'No') . " -->";
    
    if (!empty($email) && !empty($password)) {
        try {
            // Get user from database
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            echo "<!-- Debug: User found = " . ($user ? 'Yes' : 'No') . " -->";
            
            if ($user) {
                echo "<!-- Debug: Password hash in DB: " . substr($user['password'], 0, 20) . "... -->";
                echo "<!-- Debug: Password verify result: " . (password_verify($password, $user['password']) ? 'true' : 'false') . " -->";
                
                if (password_verify($password, $user['password'])) {
                    if ($user['is_active']) {
                        // Update last login
                        $stmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        
                        // Get staff profile if exists
                        $staff_profile = null;
                        if (in_array($user['user_type'], ['super_admin', 'admin', 'manager', 'support_tech', 'staff'])) {
                            $stmt = $pdo->prepare("SELECT * FROM staff_profiles WHERE user_id = ?");
                            $stmt->execute([$user['id']]);
                            $staff_profile = $stmt->fetch();
                        }
                        
                        echo "<!-- Debug: Staff profile found = " . ($staff_profile ? 'Yes' : 'No') . " -->";
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['user_type'] = $user['user_type'];
                        $_SESSION['staff_profile'] = $staff_profile;
                        
                        echo "<!-- Debug: Session set for user ID: " . $user['id'] . " -->";
                        
                        // Redirect based on user type
                        header('Location: /mit/dashboard');
                        exit;
                    } else {
                        $error = 'Your account is deactivated. Please contact administrator.';
                    }
                } else {
                    $error = 'Invalid email or password.';
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #FF6B35 0%, #004E89 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .login-box h1 {
            color: #004E89;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .login-box p {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #FF6B35;
            box-shadow: 0 0 0 2px rgba(255,107,53,0.2);
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: #FF6B35;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #e55a2b;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .demo-credentials {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            border: 1px dashed #004E89;
        }
        
        .demo-credentials h4 {
            color: #004E89;
            margin-bottom: 10px;
        }
        
        .demo-credentials ul {
            list-style: none;
            padding-left: 0;
        }
        
        .demo-credentials li {
            margin-bottom: 5px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1><i class="fas fa-network-wired"></i> MSP Portal</h1>
            <p>Managed Service Provider Management System</p>
            
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required 
                           placeholder="Enter your email" value="admin@msp.com">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter your password" value="Admin@123">
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <div class="demo-credentials">
                <h4><i class="fas fa-key"></i> Test Credentials</h4>
                <ul>
                    <li><strong>Super Admin:</strong> admin@msp.com / Admin@123</li>
                    <li><strong>Engineer:</strong> engineer@msp.com / Engineer@123</li>
                    <li><strong>Manager:</strong> manager@msp.com / Manager@123</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #666;">
                <i class="fas fa-info-circle"></i> View page source for debug info
            </div>
        </div>
    </div>
    
    <!-- Font Awesome -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
</body>
</html>