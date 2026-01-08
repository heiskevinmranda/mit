<?php
// pages/admin/check_staff_profiles.php
require_once '../../includes/auth.php';
requirePermission('admin');

$pdo = getDBConnection();

// Get all staff users and their profile status
$stmt = $pdo->query("
    SELECT 
        u.id as user_id,
        u.email,
        u.user_type,
        u.is_active,
        sp.id as profile_id,
        sp.full_name,
        sp.employment_status,
        CASE 
            WHEN sp.id IS NULL THEN 'No Profile'
            WHEN sp.full_name IS NULL OR sp.full_name = '' THEN 'Missing Name'
            ELSE 'Complete'
        END as profile_status
    FROM users u
    LEFT JOIN staff_profiles sp ON sp.user_id = u.id
    WHERE u.user_type IN ('super_admin', 'admin', 'manager', 'support_tech', 'staff', 'engineer')
    ORDER BY 
        CASE 
            WHEN sp.id IS NULL THEN 0
            WHEN sp.full_name IS NULL OR sp.full_name = '' THEN 1
            ELSE 2
        END ASC,
        u.email
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count statistics
$total = count($users);
$no_profile = count(array_filter($users, fn($u) => $u['profile_status'] === 'No Profile'));
$missing_name = count(array_filter($users, fn($u) => $u['profile_status'] === 'Missing Name'));
$complete = count(array_filter($users, fn($u) => $u['profile_status'] === 'Complete'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profile Status Check</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <h1><i class="fas fa-user-check"></i> Staff Profile Status Check</h1>
            <p class="text-muted">Review which users need their staff profiles created or updated</p>
            
            <!-- Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?php echo $total; ?></h3>
                            <p class="mb-0">Total Staff Users</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-success text-white">
                        <div class="card-body">
                            <h3><?php echo $complete; ?></h3>
                            <p class="mb-0">Complete Profiles</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-warning">
                        <div class="card-body">
                            <h3><?php echo $missing_name; ?></h3>
                            <p class="mb-0">Missing Names</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center bg-danger text-white">
                        <div class="card-body">
                            <h3><?php echo $no_profile; ?></h3>
                            <p class="mb-0">No Profile</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Staff Users and Profile Status</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>User Type</th>
                                    <th>Full Name</th>
                                    <th>Status</th>
                                    <th>Employment Status</th>
                                    <th>Active</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr class="<?php 
                                    if ($user['profile_status'] === 'No Profile') echo 'table-danger';
                                    elseif ($user['profile_status'] === 'Missing Name') echo 'table-warning';
                                    else echo 'table-success';
                                ?>">
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['user_type']))); ?></td>
                                    <td><?php echo htmlspecialchars($user['full_name'] ?: '-'); ?></td>
                                    <td>
                                        <?php if ($user['profile_status'] === 'Complete'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Complete</span>
                                        <?php elseif ($user['profile_status'] === 'Missing Name'): ?>
                                            <span class="badge bg-warning"><i class="fas fa-exclamation-triangle"></i> Missing Name</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fas fa-times"></i> No Profile</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['employment_status'] ?: '-'); ?></td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="../users/edit.php?id=<?php echo $user['user_id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <h5><i class="fas fa-info-circle"></i> How to Fix</h5>
                <ol class="mb-0">
                    <li><strong>For users with "No Profile":</strong> Click "Edit" and fill in the staff profile section with their full name and other details.</li>
                    <li><strong>For users with "Missing Name":</strong> Click "Edit" and add their full name to the staff profile.</li>
                    <li><strong>Mark employment status as "Active"</strong> for users who should be available for ticket assignment.</li>
                </ol>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
