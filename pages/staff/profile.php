<?php
require_once '../../includes/auth.php';
require_once '../../includes/routes.php';
requireLogin();

$page_title = 'My Profile';

$current_user = getCurrentUser();
$pdo = getDBConnection();

// Get staff profile
$stmt = $pdo->prepare("    SELECT sp.*, u.email as login_email, 
           rm.full_name as reporting_manager_name,
           rm.designation as reporting_manager_designation
    FROM staff_profiles sp
    LEFT JOIN users u ON sp.user_id = u.id
    LEFT JOIN staff_profiles rm ON sp.reporting_manager_id = rm.id
    WHERE sp.user_id = ?");
$stmt->execute([$current_user['id']]);
$staff = $stmt->fetch();

// Don't redirect if staff profile doesn't exist - allow access to profile page
// If profile doesn't exist, we'll show an incomplete profile message and direct to edit
$is_profile_complete = $staff !== false;

// Get assigned tickets
$ticket_count = 0;
$completed_this_month = 0;

if ($is_profile_complete && isset($staff['id'])) {
    $stmt = $pdo->prepare("    SELECT COUNT(*) as total_tickets 
        FROM tickets 
        WHERE assigned_to = ?");
    $stmt->execute([$staff['id']]);
    $ticket_count = $stmt->fetch()['total_tickets'];
    
    // Get completed tickets this month
    $stmt = $pdo->prepare("    SELECT COUNT(*) as completed_tickets 
        FROM tickets 
        WHERE assigned_to = ? 
        AND status = 'Closed' 
        AND EXTRACT(MONTH FROM closed_at) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM closed_at) = EXTRACT(YEAR FROM CURRENT_DATE)");
    $stmt->execute([$staff['id']]);
    $completed_this_month = $stmt->fetch()['completed_tickets'];
}

// Close PHP and start HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'MSP Application'; ?></title>
    <link rel="icon" type="image/png" href="/mit/assets/flashicon.png?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .main-content {
            padding: 1rem !important;
        }

        @media (min-width: 992px) {
            .main-content {
                padding: 1.5rem !important;
            }
        }

        .profile-header {
            background: linear-gradient(135deg, #004E89 0%, #003d6e 100%);
            color: white;
            padding: 40px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: #004E89;
            font-weight: bold;
            margin: 0 auto 20px;
            border: 5px solid rgba(255, 255, 255, 0.2);
        }

        .profile-info {
            text-align: center;
        }

        .profile-info h1 {
            margin-bottom: 10px;
            font-weight: 600;
        }

        .profile-info .designation {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .profile-info .department {
            font-size: 16px;
            opacity: 0.8;
            margin-bottom: 20px;
        }

        .profile-stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 20px;
        }

        .profile-stat {
            text-align: center;
        }

        .profile-stat .number {
            font-size: 24px;
            font-weight: bold;
            display: block;
        }

        .profile-stat .label {
            font-size: 14px;
            opacity: 0.8;
        }

        .info-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .info-card h3 {
            color: #004E89;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .info-row {
            display: flex;
            margin-bottom: 15px;
        }

        .info-label {
            flex: 0 0 200px;
            font-weight: 500;
            color: #555;
        }

        .info-value {
            flex: 1;
            color: #333;
        }

        @media (max-width: 768px) {
            .profile-stats {
                flex-direction: column;
                gap: 15px;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>


    <div class="dashboard-container">
        <?php include '../../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <div class="header d-flex justify-content-between align-items-center">
                <h1><i class="fas fa-user"></i> My Profile</h1>
                <a href="<?php echo route('staff.edit_profile'); ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
            </div>
            
            <?php if (!$is_profile_complete): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Your profile is incomplete!</strong> Please complete your profile information.
                <a href="<?php echo route('staff.edit_profile'); ?>" class="btn btn-sm btn-primary ms-2">Complete Profile Now</a>
            </div>
            <?php endif; ?>
            
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo $is_profile_complete ? strtoupper(substr($staff['full_name'], 0, 1)) : strtoupper(substr($current_user['email'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h1><?php echo $is_profile_complete ? htmlspecialchars($staff['full_name'] ?? '') : 'Incomplete Profile'; ?></h1>
                    <div class="designation"><?php echo $is_profile_complete ? htmlspecialchars($staff['designation'] ?? '') : 'Profile Incomplete'; ?></div>
                    <div class="department"><?php echo $is_profile_complete ? htmlspecialchars($staff['department'] ?? '') : 'Please complete your profile'; ?></div>
                    
                    <div class="profile-stats">
                        <div class="profile-stat">
                            <span class="number"><?php echo $is_profile_complete ? $ticket_count : '0'; ?></span>
                            <span class="label">Total Tickets</span>
                        </div>
                        <div class="profile-stat">
                            <span class="number"><?php echo $is_profile_complete ? $completed_this_month : '0'; ?></span>
                            <span class="label">Completed This Month</span>
                        </div>
                        <div class="profile-stat">
                            <span class="number"><?php echo $is_profile_complete ? ($staff['experience_years'] ?? '0') : '0'; ?> yrs</span>
                            <span class="label">Experience</span>
                        </div>
                        <div class="profile-stat">
                            <span class="number"><?php echo $is_profile_complete ? '92%' : 'N/A'; ?></span>
                            <span class="label">SLA Compliance</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="profile-content">
                <div class="row">
                    <div class="col-md-6">
                        <!-- Basic Information -->
                        <div class="info-card">
                            <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                            <div class="info-row">
                                <div class="info-label">Staff ID:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['staff_id'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['login_email'] ?? '') : htmlspecialchars($current_user['email']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Phone:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['phone_number'] ?? 'N/A') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Date of Joining:</div>
                                <div class="info-value">
                                    <?php 
                                    if ($is_profile_complete && !empty($staff['date_of_joining'])) {
                                        echo date('F d, Y', strtotime($staff['date_of_joining'])); 
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Employment Type:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['employment_type'] ?? '') : 'N/A'; ?></div>
                            </div>
                        </div>
                        
                        <!-- Job Information -->
                        <div class="info-card">
                            <h3><i class="fas fa-briefcase"></i> Job Information</h3>
                            <div class="info-row">
                                <div class="info-label">Role Category:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['role_category'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Skills:</div>
                                <div class="info-value">
                                    <?php 
                                    if ($is_profile_complete && !empty($staff['skills'])) {
                                        $skills = json_decode($staff['skills'], true);
                                        if (is_array($skills)) {
                                            echo implode(', ', array_map('htmlspecialchars', $skills));
                                        } else {
                                            echo htmlspecialchars($staff['skills']);
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Certifications:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['certifications'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Service Area:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['service_area'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">On-call Support:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? ($staff['on_call_support'] ? 'Yes' : 'No') : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <!-- Contact Information -->
                        <div class="info-card">
                            <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                            <div class="info-row">
                                <div class="info-label">Official Email:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['official_email'] ?? $staff['login_email'] ?? '') : htmlspecialchars($current_user['email']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Personal Email:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['personal_email'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Alternate Phone:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['alternate_phone'] ?? '') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Emergency Contact:</div>
                                <div class="info-value">
                                    <?php echo $is_profile_complete ? htmlspecialchars($staff['emergency_contact_name'] ?? '') : 'N/A'; ?>
                                    <?php if ($is_profile_complete && !empty($staff['emergency_contact_number'])): ?>
                                        (<?php echo htmlspecialchars($staff['emergency_contact_number'] ?? ''); ?>)
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Current Address:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? nl2br(htmlspecialchars($staff['current_address'] ?? '')) : 'N/A'; ?></div>
                            </div>
                        </div>
                        
                        <!-- System Access -->
                        <div class="info-card">
                            <h3><i class="fas fa-laptop"></i> System Access</h3>
                            <div class="info-row">
                                <div class="info-label">Username:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? htmlspecialchars($staff['username'] ?? $current_user['email']) : htmlspecialchars($current_user['email']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">User Role:</div>
                                <div class="info-value">
                                    <span class="badge bg-<?php 
                                        echo $current_user['user_type'] == 'super_admin' ? 'danger' : 
                                             ($current_user['user_type'] == 'admin' ? 'warning' : 
                                             ($current_user['user_type'] == 'manager' ? 'info' : 'primary')); 
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $current_user['user_type'])); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Company Laptop:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? ($staff['company_laptop_issued'] ? 'Yes' : 'No') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">VPN Access:</div>
                                <div class="info-value"><?php echo $is_profile_complete ? ($staff['vpn_access'] ? 'Yes' : 'No') : 'N/A'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Reporting Manager:</div>
                                <div class="info-value">
                                    <?php if ($is_profile_complete && !empty($staff['reporting_manager_name'])): ?>
                                        <?php echo htmlspecialchars($staff['reporting_manager_name'] ?? ''); ?>
                                        <?php if (!empty($staff['reporting_manager_designation'])): ?>
                                            <br><small><?php echo htmlspecialchars($staff['reporting_manager_designation'] ?? ''); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php echo $is_profile_complete ? 'Not assigned' : 'N/A'; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/main.js"></script>
    <script>
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>