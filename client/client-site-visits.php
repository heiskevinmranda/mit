<?php
// client-site-visits.php
require_once 'includes/client-functions.php';
$client_id = checkClientLogin();

require_once 'config/database.php';
$pdo = getDBConnection();

// Get site visits
$site_visits = [];
try {
    $stmt = $pdo->prepare("
        SELECT sv.*, 
               sp.full_name as engineer_name,
               cl.location_name,
               t.ticket_number
        FROM site_visits sv
        LEFT JOIN staff_profiles sp ON sv.engineer_id = sp.id
        LEFT JOIN client_locations cl ON sv.location_id = cl.id
        LEFT JOIN tickets t ON sv.ticket_id = t.id
        WHERE sv.client_id = ?
        ORDER BY sv.check_in_time DESC
    ");
    $stmt->execute([$client_id]);
    $site_visits = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Site visits error: " . $e->getMessage());
}

// Get client info for header
$client = getClientInfo($pdo, $client_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Site Visits | Client Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --client-primary: #28a745;
            --client-secondary: #20c997;
        }
        
        .visit-card {
            border-left: 5px solid var(--client-primary);
            transition: all 0.3s;
        }
        
        .visit-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .visit-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .visit-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .visit-ongoing {
            background: #fff3cd;
            color: #856404;
        }
        
        .visit-scheduled {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .engineer-badge {
            background: var(--client-primary);
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <?php include 'client-sidebar.php'; ?>
    
    <div class="client-main">
        <div class="data-card">
            <h1 class="mb-4"><i class="fas fa-map-marker-alt me-2"></i>Site Visits</h1>
            
            <?php if (empty($site_visits)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No site visits recorded</h5>
                    <p>Your site visits will appear here when our engineers visit your location.</p>
                </div>
            <?php else: ?>
                <!-- Stats -->
                <div class="row mb-4">
                    <?php
                    $visit_stats = [
                        'total' => count($site_visits),
                        'completed' => 0,
                        'ongoing' => 0,
                        'scheduled' => 0
                    ];
                    
                    foreach ($site_visits as $visit) {
                        if ($visit['check_out_time']) {
                            $visit_stats['completed']++;
                        } elseif ($visit['check_in_time']) {
                            $visit_stats['ongoing']++;
                        } else {
                            $visit_stats['scheduled']++;
                        }
                    }
                    ?>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center py-3">
                                <h4 class="mb-0"><?php echo $visit_stats['total']; ?></h4>
                                <small>Total Visits</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center py-3">
                                <h4 class="mb-0"><?php echo $visit_stats['completed']; ?></h4>
                                <small>Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center py-3">
                                <h4 class="mb-0"><?php echo $visit_stats['ongoing']; ?></h4>
                                <small>In Progress</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center py-3">
                                <h4 class="mb-0"><?php echo $visit_stats['scheduled']; ?></h4>
                                <small>Scheduled</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Visits List -->
                <div class="row">
                    <?php foreach ($site_visits as $visit): ?>
                        <?php
                        $status_class = 'visit-scheduled';
                        $status_text = 'Scheduled';
                        
                        if ($visit['check_out_time']) {
                            $status_class = 'visit-completed';
                            $status_text = 'Completed';
                        } elseif ($visit['check_in_time']) {
                            $status_class = 'visit-ongoing';
                            $status_text = 'In Progress';
                        }
                        ?>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100 visit-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <?php echo $visit['ticket_number'] ? 'Ticket: ' . htmlspecialchars($visit['ticket_number']) : 'Site Visit'; ?>
                                            </h5>
                                            <?php if ($visit['location_name']): ?>
                                                <h6 class="card-subtitle text-muted">
                                                    <i class="fas fa-location-dot me-1"></i>
                                                    <?php echo htmlspecialchars($visit['location_name']); ?>
                                                </h6>
                                            <?php endif; ?>
                                        </div>
                                        <span class="visit-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($visit['engineer_name']): ?>
                                        <div class="mb-3">
                                            <span class="engineer-badge">
                                                <i class="fas fa-user-hard-hat me-1"></i>
                                                <?php echo htmlspecialchars($visit['engineer_name']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($visit['work_description']): ?>
                                        <div class="mb-3">
                                            <small class="text-muted d-block mb-1">Work Description:</small>
                                            <p class="card-text">
                                                <?php echo htmlspecialchars(substr($visit['work_description'], 0, 150)); ?>
                                                <?php if (strlen($visit['work_description']) > 150): ?>...<?php endif; ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <?php if ($visit['check_in_time']): ?>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Check-in</small>
                                                <strong>
                                                    <?php echo date('M d, Y H:i', strtotime($visit['check_in_time'])); ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($visit['check_out_time']): ?>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Check-out</small>
                                                <strong>
                                                    <?php echo date('M d, Y H:i', strtotime($visit['check_out_time'])); ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($visit['parts_used']): ?>
                                        <div class="mt-3 pt-3 border-top">
                                            <small class="text-muted d-block mb-1">Parts Used:</small>
                                            <small><?php echo htmlspecialchars($visit['parts_used']); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($visit['job_report_url']): ?>
                                        <div class="mt-3 pt-3 border-top">
                                            <a href="<?php echo htmlspecialchars($visit['job_report_url']); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-file-pdf me-1"></i> View Report
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-transparent">
                                    <small class="text-muted">
                                        Visit ID: <?php echo substr($visit['id'], 0, 8); ?>...
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>