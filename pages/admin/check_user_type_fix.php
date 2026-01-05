<?php
// check_user_type_fix.php
session_start();

// Database connection
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=MSP_Application', 'MSPAppUser', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$email = 'agapitus@flashnet.co.tz';

// Check current status
$stmt = $pdo->prepare("
    SELECT u.email, u.user_type, r.role_name, r.permissions
    FROM users u
    LEFT JOIN user_roles r ON u.role_id = r.id
    WHERE u.email = ?
");
$stmt->execute([$email]);
$user = $stmt->fetch();

// Fix if needed
if (isset($_POST['fix'])) {
    $update = $pdo->prepare("UPDATE users SET user_type = 'Super Admin' WHERE email = ?");
    $update->execute([$email]);
    header("Location: ?fixed=1");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Fix User Type Issue</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .mismatch { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; }
        .match { color: #28a745; background: #d4edda; padding: 10px; border-radius: 5px; }
        .btn { background: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h2>Fix User Type: agapitus@flashnet.co.tz</h2>
    
    <?php if (isset($_GET['fixed'])): ?>
        <div class="match">✅ Fixed! Reloading...</div>
        <script>setTimeout(() => location.reload(), 1000);</script>
    <?php endif; ?>
    
    <?php if ($user): ?>
        <h3>Current Status:</h3>
        <table border="1" cellpadding="10">
            <tr>
                <td><strong>Email:</strong></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
            </tr>
            <tr>
                <td><strong>User Type:</strong></td>
                <td><code><?php echo htmlspecialchars($user['user_type']); ?></code></td>
            </tr>
            <tr>
                <td><strong>Role Name:</strong></td>
                <td><code><?php echo htmlspecialchars($user['role_name']); ?></code></td>
            </tr>
        </table>
        
        <?php 
        // Check if they match
        $user_type_normalized = strtolower(str_replace([' ', '_'], '', $user['user_type']));
        $role_name_normalized = strtolower(str_replace([' ', '_'], '', $user['role_name']));
        
        if ($user_type_normalized !== $role_name_normalized): 
        ?>
            <div class="mismatch">
                <h4>⚠️ MISMATCH DETECTED!</h4>
                <p>User Type (<code><?php echo htmlspecialchars($user['user_type']); ?></code>) doesn't match Role Name (<code><?php echo htmlspecialchars($user['role_name']); ?></code>)</p>
                
                <form method="POST">
                    <button type="submit" name="fix" class="btn">
                        🔧 Fix: Change user_type to 'Super Admin'
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="match">
                ✅ User Type and Role Name match!
            </div>
        <?php endif; ?>
        
        <h3>Permissions:</h3>
        <?php 
        $permissions = is_array($user['permissions']) ? $user['permissions'] : json_decode($user['permissions'], true);
        if (in_array('*', $permissions)): 
        ?>
            <div style="background: #28a745; color: white; padding: 10px; border-radius: 5px;">
                ⚡ WILDCARD PERMISSIONS: FULL SYSTEM ACCESS
            </div>
        <?php elseif (!empty($permissions)): ?>
            <div style="background: #17a2b8; color: white; padding: 10px; border-radius: 5px;">
                Permissions: <?php echo implode(', ', $permissions); ?>
            </div>
        <?php else: ?>
            <div style="background: #6c757d; color: white; padding: 10px; border-radius: 5px;">
                No permissions assigned
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="color: #dc3545;">User not found</div>
    <?php endif; ?>
    
    <hr>
    
    <h3>Common User Type Variations:</h3>
    <ul>
        <li><code>Super Admin</code> ← Recommended (capital S, space)</li>
        <li><code>super_admin</code> ← What Agapitus has (lowercase, underscore)</li>
        <li><code>SuperAdmin</code> ← CamelCase (no space)</li>
        <li><code>super admin</code> ← lowercase with space</li>
    </ul>
    
    <p>Your application code probably expects <code>'Super Admin'</code> with capital S and space.</p>
</body>
</html>