<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once 'includes/auth.php';
require_once 'includes/routes.php';
requireLogin();

$pdo = getDBConnection();
$current_user = getCurrentUser();
$staff_id = $current_user['staff_profile']['id'] ?? null;

// Get standup ID
$standup_id = $_GET['id'] ?? null;
if (!$standup_id) {
    header("Location: " . route('daily_standups.index'));
    exit;
}

// Get standup details
$stmt = $pdo->prepare("
    SELECT ds.*, sp.full_name as facilitator_name, sp.email as facilitator_email
    FROM daily_standups ds
    LEFT JOIN staff_profiles sp ON ds.facilitator_id = sp.id
    WHERE ds.id = ?
");
$stmt->execute([$standup_id]);
$standup = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$standup) {
    header("Location: " . route('daily_standups.index'));
    exit;
}

// Get all responses
$stmt = $pdo->prepare("
    SELECT sr.*, sp.full_name, sp.designation, sp.department, u.email,
           CASE 
               WHEN sr.mood = 'excellent' THEN 5
               WHEN sr.mood = 'good' THEN 4
               WHEN sr.mood = 'okay' THEN 3
               WHEN sr.mood = 'frustrated' THEN 2
               WHEN sr.mood = 'blocked' THEN 1
               ELSE 3
           END as mood_score
    FROM standup_responses sr
    LEFT JOIN staff_profiles sp ON sr.staff_id = sp.id
    LEFT JOIN users u ON sp.user_id = u.id
    WHERE sr.standup_id = ?
    ORDER BY 
        CASE WHEN sr.attendance_status = 'present' THEN 1 ELSE 2 END,
        sr.submitted_at DESC
");
$stmt->execute([$standup_id]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get action items
$stmt = $pdo->prepare("
    SELECT ai.*, 
           creator.full_name as creator_name,
           assignee.full_name as assignee_name
    FROM standup_action_items ai
    LEFT JOIN staff_profiles creator ON ai.created_by = creator.id
    LEFT JOIN staff_profiles assignee ON ai.assigned_to = assignee.id
    WHERE ai.standup_id = ?
    ORDER BY 
        CASE ai.priority 
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
            ELSE 5
        END,
        ai.due_date
");
$stmt->execute([$standup_id]);
$action_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attachments
$stmt = $pdo->prepare("
    SELECT sa.*, sp.full_name as uploader_name
    FROM standup_attachments sa
    LEFT JOIN staff_profiles sp ON sa.uploaded_by = sp.id
    WHERE sa.standup_id = ?
    ORDER BY sa.uploaded_at DESC
");
$stmt->execute([$standup_id]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_responses = count($responses);
$present_count = count(array_filter($responses, fn($r) => $r['attendance_status'] === 'present'));
$late_count = count(array_filter($responses, fn($r) => $r['attendance_status'] === 'late'));
$blocker_count = count(array_filter($responses, fn($r) => !empty(trim($r['blockers']))));
$avg_mood = $total_responses > 0 ? 
    array_sum(array_column($responses, 'mood_score')) / $total_responses : 3;

// Handle action item creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_action'])) {
    $description = $_POST['description'] ?? '';
    $assigned_to = $_POST['assigned_to'] ?? null;
    $priority = $_POST['priority'] ?? 'medium';
    $due_date = $_POST['due_date'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO standup_action_items 
        (standup_id, description, assigned_to, created_by, priority, due_date)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$standup_id, $description, $assigned_to, $staff_id, $priority, $due_date]);
    
    header("Location: " . route('daily_standups.view', ['id' => $standup_id]));
    exit;
}

// Handle meeting status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $status = $_POST['status'] ?? $standup['status'];
    
    $stmt = $pdo->prepare("
        UPDATE daily_standups 
        SET status = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$status, $standup_id]);
    
    header("Location: " . route('daily_standups.view', ['id' => $standup_id]));
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($standup['title']); ?> | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .meeting-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        .response-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.2s;
            background: white;
        }
        .response-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .blocker-alert {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .action-item {
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .action-critical { border-left-color: #dc3545; background: #f8d7da; }
        .action-high { border-left-color: #fd7e14; background: #ffe5d0; }
        .action-medium { border-left-color: #ffc107; background: #fff3cd; }
        .action-low { border-left-color: #17a2b8; background: #d1ecf1; }
        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .mood-indicator {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .tab-content {
            background: white;
            border-radius: 0 0 10px 10px;
            padding: 25px;
            border: 1px solid #dee2e6;
            border-top: none;
        }
        .nav-tabs .nav-link.active {
            background: white;
            border-bottom-color: white;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="container-fluid py-4">
            <!-- Meeting Header -->
            <div class="meeting-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h1 class="mb-2">
                            <i class="fas fa-users me-2"></i>
                            <?php echo htmlspecialchars($standup['title']); ?>
                        </h1>
                        <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                            <span class="badge bg-light text-dark">
                                <i class="far fa-calendar me-1"></i>
                                <?php echo date('F j, Y', strtotime($standup['meeting_date'])); ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('g:i A', strtotime($standup['start_time'])); ?>
                                <?php if ($standup['end_time']): ?>
                                    - <?php echo date('g:i A', strtotime($standup['end_time'])); ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($standup['location']): ?>
                                <span class="badge bg-light text-dark">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    <?php echo htmlspecialchars($standup['location']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge bg-<?php 
                                echo $standup['status'] === 'completed' ? 'success' : 
                                     ($standup['status'] === 'in_progress' ? 'warning' : 
                                     ($standup['status'] === 'cancelled' ? 'danger' : 'primary'));
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $standup['status'])); ?>
                            </span>
                        </div>
                        <p class="mb-0">
                            <i class="fas fa-user-tie me-2"></i>
                            Facilitated by: <strong><?php echo htmlspecialchars($standup['facilitator_name']); ?></strong>
                        </p>
                    </div>
                    <div class="text-end">
                        <!-- Status Update Form (for facilitators) -->
                        <?php if ($standup['facilitator_id'] == $staff_id || $current_user['user_type'] == 'admin'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="update_status" value="1">
                            <select name="status" class="form-select form-select-sm d-inline w-auto" 
                                    onchange="this.form.submit()">
                                <option value="scheduled" <?php echo $standup['status'] == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="in_progress" <?php echo $standup['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo $standup['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $standup['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </form>
                        <?php endif; ?>
                        
                        <!-- Action Buttons -->
                        <div class="mt-3">
                            <?php 
                            // Check if user has submitted response
                            $user_response = array_filter($responses, fn($r) => $r['staff_id'] == $staff_id);
                            $has_responded = !empty($user_response);
                            ?>
                            
                            <?php if (!$has_responded && $standup['status'] !== 'completed'): ?>
                                <a href="<?php echo route('daily_standups.response_form', ['standup_id' => $standup_id]); ?>" 
                                   class="btn btn-success">
                                    <i class="fas fa-edit me-2"></i>Submit Response
                                </a>
                            <?php elseif ($has_responded): ?>
                                <?php $response_id = current($user_response)['id']; ?>
                                <a href="<?php echo route('daily_standups.response', ['id' => $response_id]); ?>" 
                                   class="btn btn-outline-light">
                                    <i class="fas fa-pen me-2"></i>Edit Response
                                </a>
                            <?php endif; ?>
                            
                            <button class="btn btn-light ms-2" data-bs-toggle="modal" data-bs-target="#exportModal">
                                <i class="fas fa-download me-2"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Row -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="mood-indicator">
                            <?php 
                            $mood_icon = 'fa-smile';
                            $mood_color = '#28a745';
                            if ($avg_mood < 2) {
                                $mood_icon = 'fa-exclamation-triangle';
                                $mood_color = '#dc3545';
                            } elseif ($avg_mood < 3) {
                                $mood_icon = 'fa-frown';
                                $mood_color = '#fd7e14';
                            } elseif ($avg_mood < 4) {
                                $mood_icon = 'fa-meh';
                                $mood_color = '#ffc107';
                            } elseif ($avg_mood < 5) {
                                $mood_icon = 'fa-smile';
                                $mood_color = '#17a2b8';
                            }
                            ?>
                            <i class="fas <?php echo $mood_icon; ?>" style="color: <?php echo $mood_color; ?>;"></i>
                        </div>
                        <h3><?php echo number_format($avg_mood, 1); ?>/5</h3>
                        <p class="text-muted mb-0">Team Mood</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="mood-indicator">
                            <i class="fas fa-users" style="color: #667eea;"></i>
                        </div>
                        <h3><?php echo $present_count; ?>/<?php echo $total_responses; ?></h3>
                        <p class="text-muted mb-0">Attendance</p>
                        <?php if ($late_count > 0): ?>
                            <small class="text-warning">
                                <i class="fas fa-clock me-1"></i>
                                <?php echo $late_count; ?> late
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="mood-indicator">
                            <i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i>
                        </div>
                        <h3><?php echo $blocker_count; ?></h3>
                        <p class="text-muted mb-0">Blockers</p>
                        <small class="text-muted">Issues reported</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="mood-indicator">
                            <i class="fas fa-tasks" style="color: #28a745;"></i>
                        </div>
                        <h3><?php echo count($action_items); ?></h3>
                        <p class="text-muted mb-0">Action Items</p>
                        <small class="text-muted">From this meeting</small>
                    </div>
                </div>
            </div>

            <!-- Tabs for different views -->
            <ul class="nav nav-tabs" id="standupTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="responses-tab" data-bs-toggle="tab" 
                            data-bs-target="#responses" type="button" role="tab">
                        <i class="fas fa-comments me-2"></i>Responses (<?php echo $total_responses; ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="actions-tab" data-bs-toggle="tab" 
                            data-bs-target="#actions" type="button" role="tab">
                        <i class="fas fa-tasks me-2"></i>Action Items (<?php echo count($action_items); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="attachments-tab" data-bs-toggle="tab" 
                            data-bs-target="#attachments" type="button" role="tab">
                        <i class="fas fa-paperclip me-2"></i>Attachments (<?php echo count($attachments); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="summary-tab" data-bs-toggle="tab" 
                            data-bs-target="#summary" type="button" role="tab">
                        <i class="fas fa-chart-bar me-2"></i>Summary
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="standupTabsContent">
                <!-- Responses Tab -->
                <div class="tab-pane fade show active" id="responses" role="tabpanel">
                    <?php if (empty($responses)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h4>No responses yet</h4>
                            <p class="text-muted">Be the first to submit your standup!</p>
                            <a href="<?php echo route('daily_standups.response_form', ['standup_id' => $standup_id]); ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>Submit Response
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($responses as $response): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="response-card">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($response['full_name']); ?>
                                                <?php if ($response['attendance_status'] !== 'present'): ?>
                                                    <span class="badge bg-warning ms-1">
                                                        <?php echo ucfirst($response['attendance_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($response['designation']); ?>
                                                <?php if ($response['department']): ?>
                                                    • <?php echo htmlspecialchars($response['department']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="mb-1">
                                                <?php 
                                                $mood_icons = [
                                                    'excellent' => 'fa-smile-beam text-success',
                                                    'good' => 'fa-smile text-info',
                                                    'okay' => 'fa-meh text-warning',
                                                    'frustrated' => 'fa-frown text-orange',
                                                    'blocked' => 'fa-exclamation-triangle text-danger'
                                                ];
                                                $mood = $response['mood'] ?? 'okay';
                                                ?>
                                                <i class="fas <?php echo $mood_icons[$mood]; ?> fa-lg"></i>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo date('g:i A', strtotime($response['submitted_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- Yesterday -->
                                    <div class="mb-3">
                                        <h6 class="small text-muted mb-2">
                                            <i class="far fa-check-circle me-1"></i>Yesterday
                                        </h6>
                                        <p class="mb-0 small">
                                            <?php 
                                            $yesterday = htmlspecialchars($response['yesterday_work'] ?? '');
                                            echo strlen($yesterday) > 150 ? substr($yesterday, 0, 150) . '...' : $yesterday;
                                            ?>
                                        </p>
                                    </div>
                                    
                                    <!-- Today -->
                                    <div class="mb-3">
                                        <h6 class="small text-muted mb-2">
                                            <i class="fas fa-bullseye me-1"></i>Today
                                        </h6>
                                        <p class="mb-0 small">
                                            <?php 
                                            $today = htmlspecialchars($response['today_plan'] ?? '');
                                            echo strlen($today) > 150 ? substr($today, 0, 150) . '...' : $today;
                                            ?>
                                        </p>
                                        <?php if ($response['estimated_hours']): ?>
                                            <small class="text-muted">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo $response['estimated_hours']; ?> hours estimated
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Blockers -->
                                    <?php if (!empty(trim($response['blockers'] ?? ''))): ?>
                                    <div class="blocker-alert">
                                        <h6 class="small text-muted mb-2">
                                            <i class="fas fa-exclamation-triangle me-1"></i>Blockers
                                        </h6>
                                        <p class="mb-0 small">
                                            <?php echo htmlspecialchars(substr($response['blockers'], 0, 100)); ?>
                                            <?php if (strlen($response['blockers']) > 100): ?>...<?php endif; ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Action Items Tab -->
                <div class="tab-pane fade" id="actions" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5>Action Items from this Meeting</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createActionModal">
                            <i class="fas fa-plus me-2"></i>Add Action Item
                        </button>
                    </div>
                    
                    <?php if (empty($action_items)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h4>No action items yet</h4>
                            <p class="text-muted">Add action items to track follow-ups from this meeting.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($action_items as $item): ?>
                            <div class="col-md-6 mb-3">
                                <div class="action-item action-<?php echo htmlspecialchars($item['priority']); ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-2"><?php echo htmlspecialchars($item['description']); ?></h6>
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                <span class="badge bg-<?php 
                                                    echo $item['priority'] === 'critical' ? 'danger' : 
                                                         ($item['priority'] === 'high' ? 'warning' : 
                                                         ($item['priority'] === 'medium' ? 'primary' : 'info'));
                                                ?>">
                                                    <?php echo ucfirst($item['priority']); ?>
                                                </span>
                                                <span class="badge bg-light text-dark">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo htmlspecialchars($item['assignee_name'] ?? 'Unassigned'); ?>
                                                </span>
                                                <?php if ($item['due_date']): ?>
                                                    <span class="badge bg-light text-dark">
                                                        <i class="far fa-calendar me-1"></i>
                                                        <?php echo date('M j', strtotime($item['due_date'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($item['notes']): ?>
                                                <p class="small mb-0"><?php echo htmlspecialchars(substr($item['notes'], 0, 100)); ?>...</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light" type="button" 
                                                    data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item" href="#">
                                                        <i class="fas fa-check me-2"></i>Mark Complete
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="#">
                                                        <i class="fas fa-edit me-2"></i>Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#">
                                                        <i class="fas fa-trash me-2"></i>Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Attachments Tab -->
                <div class="tab-pane fade" id="attachments" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5>Meeting Attachments</h5>
                        <button class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i>Upload File
                        </button>
                    </div>
                    
                    <?php if (empty($attachments)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-paperclip fa-3x text-muted mb-3"></i>
                            <h4>No attachments</h4>
                            <p class="text-muted">Upload meeting notes, screenshots, or related documents.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($attachments as $attachment): ?>
                            <div class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <?php 
                                        $icon = 'fa-file';
                                        $ext = pathinfo($attachment['filename'], PATHINFO_EXTENSION);
                                        if (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf text-danger';
                                        elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word text-primary';
                                        elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel text-success';
                                        elseif (in_array($ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint text-warning';
                                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-file-image text-info';
                                        ?>
                                        <i class="fas <?php echo $icon; ?> fa-2x me-3"></i>
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($attachment['filename']); ?></h6>
                                            <small class="text-muted">
                                                Uploaded by <?php echo htmlspecialchars($attachment['uploader_name']); ?> • 
                                                <?php echo date('M j, Y g:i A', strtotime($attachment['uploaded_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div>
                                        <small class="text-muted me-3">
                                            <?php 
                                            $size = $attachment['file_size'];
                                            if ($size < 1024) {
                                                echo $size . ' B';
                                            } elseif ($size < 1048576) {
                                                echo round($size / 1024, 1) . ' KB';
                                            } else {
                                                echo round($size / 1048576, 1) . ' MB';
                                            }
                                            ?>
                                        </small>
                                        <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Summary Tab -->
                <div class="tab-pane fade" id="summary" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Meeting Summary</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($standup['notes'])): ?>
                                        <p><?php echo nl2br(htmlspecialchars($standup['notes'])); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">No meeting summary notes added yet.</p>
                                    <?php endif; ?>
                                    
                                    <!-- Add notes form for facilitators -->
                                    <?php if ($standup['facilitator_id'] == $staff_id || $current_user['user_type'] == 'admin'): ?>
                                    <form class="mt-3">
                                        <div class="mb-3">
                                            <label class="form-label">Add Meeting Notes</label>
                                            <textarea class="form-control" rows="4" 
                                                      placeholder="Add key takeaways, decisions made, or important discussions..."></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Save Notes</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Quick Stats -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="h4 mb-1"><?php echo $present_count; ?></div>
                                            <small class="text-muted">Present</small>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="h4 mb-1"><?php echo $late_count; ?></div>
                                            <small class="text-muted">Late</small>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="h4 mb-1"><?php echo $blocker_count; ?></div>
                                            <small class="text-muted">Blockers</small>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="h4 mb-1"><?php echo count($action_items); ?></div>
                                            <small class="text-muted">Actions</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <!-- Mood Distribution -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0">Team Mood Distribution</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="moodChart" height="200"></canvas>
                                </div>
                            </div>
                            
                            <!-- Attendance Chart -->
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Attendance Status</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="attendanceChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Action Item Modal -->
    <div class="modal fade" id="createActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="create_action" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Action Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="3" required
                                      placeholder="What needs to be done?"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign To</label>
                                <select class="form-select" name="assigned_to">
                                    <option value="">Select team member</option>
                                    <?php 
                                    $stmt = $pdo->query("
                                        SELECT id, full_name, designation 
                                        FROM staff_profiles 
                                        WHERE employment_status = 'Active'
                                        ORDER BY full_name
                                    ");
                                    $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($staff_members as $member):
                                    ?>
                                    <option value="<?php echo htmlspecialchars($member['id']); ?>">
                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                        (<?php echo htmlspecialchars($member['designation']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date" 
                                   value="<?php echo date('Y-m-d', strtotime('+2 days')); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Action Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Meeting Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="list-group">
                        <a href="#" class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-pdf text-danger fa-2x me-3"></i>
                                <div>
                                    <h6 class="mb-1">Export as PDF</h6>
                                    <small class="text-muted">Complete meeting report with formatting</small>
                                </div>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-excel text-success fa-2x me-3"></i>
                                <div>
                                    <h6 class="mb-1">Export as Excel</h6>
                                    <small class="text-muted">Spreadsheet with all responses and data</small>
                                </div>
                            </div>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-csv text-primary fa-2x me-3"></i>
                                <div>
                                    <h6 class="mb-1">Export as CSV</h6>
                                    <small class="text-muted">Raw data for analysis</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Mood Distribution Chart
            const moodCtx = document.getElementById('moodChart').getContext('2d');
            const moodCounts = {
                excellent: <?php echo count(array_filter($responses, fn($r) => $r['mood'] == 'excellent')); ?>,
                good: <?php echo count(array_filter($responses, fn($r) => $r['mood'] == 'good')); ?>,
                okay: <?php echo count(array_filter($responses, fn($r) => $r['mood'] == 'okay')); ?>,
                frustrated: <?php echo count(array_filter($responses, fn($r) => $r['mood'] == 'frustrated')); ?>,
                blocked: <?php echo count(array_filter($responses, fn($r) => $r['mood'] == 'blocked')); ?>
            };
            
            new Chart(moodCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Excellent', 'Good', 'Okay', 'Frustrated', 'Blocked'],
                    datasets: [{
                        data: Object.values(moodCounts),
                        backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#fd7e14', '#dc3545'],
                        borderColor: 'white',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
            
            // Attendance Chart
            const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
            const attendanceCounts = {
                present: <?php echo $present_count; ?>,
                late: <?php echo $late_count; ?>,
                absent: <?php echo count(array_filter($responses, fn($r) => $r['attendance_status'] == 'absent')); ?>,
                excused: <?php echo count(array_filter($responses, fn($r) => $r['attendance_status'] == 'excused')); ?>
            };
            
            new Chart(attendanceCtx, {
                type: 'bar',
                data: {
                    labels: ['Present', 'Late', 'Absent', 'Excused'],
                    datasets: [{
                        data: Object.values(attendanceCounts),
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Tab switching with URL hash
            const tabTriggers = document.querySelectorAll('#standupTabs button[data-bs-toggle="tab"]');
            tabTriggers.forEach(trigger => {
                trigger.addEventListener('shown.bs.tab', event => {
                    const activeTab = event.target.getAttribute('data-bs-target');
                    window.location.hash = activeTab;
                });
            });
            
            // Check URL hash on page load
            if (window.location.hash) {
                const hash = window.location.hash;
                const trigger = document.querySelector(`#standupTabs button[data-bs-target="${hash}"]`);
                if (trigger) {
                    new bootstrap.Tab(trigger).show();
                }
            }
        });
    </script>
</body>
</html>