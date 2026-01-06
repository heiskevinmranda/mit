<?php
// pages/tickets/work_log.php

require_once '../../includes/routes.php';
require_once '../../includes/auth.php';
requireLogin();

$pdo = getDBConnection();
$current_user = getCurrentUser();

$ticket_id = $_GET['id'] ?? null;
$error = '';
$success = '';

if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// Get ticket info
$stmt = $pdo->prepare("SELECT t.*, sp.full_name as assigned_to_name FROM tickets t LEFT JOIN staff_profiles sp ON t.assigned_to = sp.id WHERE t.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    $error = "Ticket not found";
}

// Get existing work logs
$work_logs = [];
$total_logged_hours = 0;

try {
    $stmt = $pdo->prepare("
        SELECT wl.*, sp.full_name, sp.staff_id 
        FROM work_logs wl
        LEFT JOIN staff_profiles sp ON wl.staff_id = sp.id
        WHERE wl.ticket_id = ?
        ORDER BY wl.work_date DESC, wl.start_time DESC
    ");
    $stmt->execute([$ticket_id]);
    $work_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total logged hours
    foreach ($work_logs as $log) {
        $total_logged_hours += $log['total_hours'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error loading work logs: " . $e->getMessage());
}

// Handle adding work log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_work_log'])) {
    try {
        $work_date = $_POST['work_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $description = trim($_POST['description']);
        $work_type = $_POST['work_type'] ?? 'Regular';
        
        if (empty($work_date) || empty($start_time) || empty($end_time)) {
            throw new Exception("Please fill in all required time fields");
        }
        
        if (empty($description)) {
            throw new Exception("Please enter a work description");
        }
        
        // Calculate hours
        $start = new DateTime("$work_date $start_time");
        $end = new DateTime("$work_date $end_time");
        
        // If end time is before start time, assume next day
        if ($end < $start) {
            $end->modify('+1 day');
        }
        
        $interval = $start->diff($end);
        $total_hours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
        
        // Insert work log
        $stmt = $pdo->prepare("
            INSERT INTO work_logs (ticket_id, staff_id, work_date, start_time, end_time, total_hours, description, work_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        $stmt->execute([$ticket_id, $staff_id, $work_date, $start_time, $end_time, $total_hours, $description, $work_type]);
        
        // Update ticket's total work hours
        $new_total = $total_logged_hours + $total_hours;
        $stmt = $pdo->prepare("UPDATE tickets SET total_work_hours = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_total, $ticket_id]);
        
        // If this is the first work log, set work_start_time
        if (empty($ticket['work_start_time'])) {
            $stmt = $pdo->prepare("UPDATE tickets SET work_start_time = ? WHERE id = ?");
            $stmt->execute(["$work_date $start_time", $ticket_id]);
        }
        
        // Update work_end_time
        $stmt = $pdo->prepare("UPDATE tickets SET work_end_time = ? WHERE id = ?");
        $stmt->execute(["$work_date $end_time", $ticket_id]);
        
        // Add ticket log
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
            VALUES (?, ?, ?, ?)
        ");
        
        $log_description = "Work logged: " . number_format($total_hours, 2) . " hours on $work_date ($start_time - $end_time)";
        $stmt->execute([$ticket_id, $staff_id, 'Work Logged', $log_description]);
        
        $success = "Work log added successfully!";
        
        // Refresh page
        header("Location: work_log.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle starting work
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_work'])) {
    try {
        $start_time = date('Y-m-d H:i:s');
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        
        // Update ticket
        $stmt = $pdo->prepare("
            UPDATE tickets 
            SET work_start_time = ?, assigned_to = ?, status = 'In Progress', updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$start_time, $staff_id, $ticket_id]);
        
        // Add log
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$ticket_id, $staff_id, 'Work Started', "Started working on ticket at " . date('g:i A')]);
        
        $success = "Work started! Timer is running.";
        
        // Refresh page
        header("Location: work_log.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle ending work
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_work'])) {
    try {
        $end_time = date('Y-m-d H:i:s');
        $staff_id = $current_user['staff_profile']['id'] ?? null;
        
        // Calculate hours worked
        if ($ticket['work_start_time']) {
            $start = new DateTime($ticket['work_start_time']);
            $end = new DateTime($end_time);
            $interval = $start->diff($end);
            $hours_worked = $interval->h + ($interval->i / 60) + ($interval->s / 3600) + ($interval->days * 24);
            
            // Create work log entry
            $stmt = $pdo->prepare("
                INSERT INTO work_logs (ticket_id, staff_id, work_date, start_time, end_time, total_hours, description, work_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $work_date = date('Y-m-d');
            $start_time_formatted = date('H:i:s', strtotime($ticket['work_start_time']));
            $end_time_formatted = date('H:i:s');
            $description = "Work session completed";
            
            $stmt->execute([
                $ticket_id, 
                $staff_id, 
                $work_date, 
                $start_time_formatted, 
                $end_time_formatted, 
                $hours_worked, 
                $description, 
                'Regular'
            ]);
            
            // Update total hours
            $new_total = $total_logged_hours + $hours_worked;
            $stmt = $pdo->prepare("UPDATE tickets SET total_work_hours = ?, work_end_time = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_total, $end_time, $ticket_id]);
        }
        
        // Add log
        $stmt = $pdo->prepare("
            INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$ticket_id, $staff_id, 'Work Ended', "Finished working on ticket at " . date('g:i A')]);
        
        $success = "Work ended successfully!";
        
        // Refresh page
        header("Location: work_log.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle deleting work log
if (isset($_GET['delete_log'])) {
    try {
        $log_id = $_GET['delete_log'];
        
        // Get log details before deleting
        $stmt = $pdo->prepare("SELECT total_hours FROM work_logs WHERE id = ?");
        $stmt->execute([$log_id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($log) {
            // Delete the log
            $stmt = $pdo->prepare("DELETE FROM work_logs WHERE id = ?");
            $stmt->execute([$log_id]);
            
            // Update ticket total
            $new_total = max(0, $total_logged_hours - $log['total_hours']);
            $stmt = $pdo->prepare("UPDATE tickets SET total_work_hours = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$new_total, $ticket_id]);
            
            // Add ticket log
            $staff_id = $current_user['staff_profile']['id'] ?? null;
            $stmt = $pdo->prepare("
                INSERT INTO ticket_logs (ticket_id, staff_id, action, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$ticket_id, $staff_id, 'Work Log Deleted', "Deleted work log entry"]);
            
            $success = "Work log deleted successfully";
        }
        
        // Refresh page
        header("Location: work_log.php?id=" . $ticket_id);
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Log - Ticket #<?php echo htmlspecialchars($ticket['ticket_number'] ?? ''); ?> | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .work-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        
        .timer-card {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .timer-display {
            font-size: 48px;
            font-weight: bold;
            text-align: center;
            color: #856404;
            margin: 15px 0;
        }
        
        .work-log-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .log-item {
            padding: 15px;
            border-left: 4px solid #007bff;
            background: #f8f9fa;
            margin-bottom: 15px;
            border-radius: 0 5px 5px 0;
        }
        
        .log-item.working {
            border-left-color: #28a745;
            background: #d4edda;
        }
        
        .hours-badge {
            font-size: 14px;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .progress-bar-container {
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .timer-display {
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <?php require_once '../../includes/routes.php'; include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1><i class="fas fa-clock"></i> Work Log - Ticket #<?php echo htmlspecialchars($ticket['ticket_number'] ?? ''); ?></h1>
                <a href="<?php echo route('tickets.view'); ?>?id=<?php echo $ticket_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Ticket
                </a>
            </div>
            
            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($ticket): ?>
            
            <!-- Work Summary -->
            <div class="work-summary-card">
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <small class="text-light">Estimated Hours</small>
                            <h3><?php echo $ticket['estimated_hours'] ? number_format($ticket['estimated_hours'], 2) : 'Not set'; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <small class="text-light">Logged Hours</small>
                            <h3><?php echo number_format($ticket['total_work_hours'] ?? 0, 2); ?></h3>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <small class="text-light">Remaining</small>
                            <h3>
                                <?php 
                                $remaining = ($ticket['estimated_hours'] ?? 0) - ($ticket['total_work_hours'] ?? 0);
                                echo number_format(max(0, $remaining), 2);
                                ?>
                            </h3>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <?php if ($ticket['estimated_hours'] > 0): ?>
                <div class="progress-bar-container">
                    <?php 
                    $progress = min(100, (($ticket['total_work_hours'] ?? 0) / $ticket['estimated_hours']) * 100);
                    ?>
                    <div class="progress-bar-fill" style="width: <?php echo $progress; ?>%"></div>
                </div>
                <div class="text-center text-light">
                    <?php echo number_format($progress, 1); ?>% Complete
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Timer Control -->
            <div class="timer-card">
                <h4><i class="fas fa-stopwatch"></i> Work Timer</h4>
                
                <?php if ($ticket['work_start_time'] && !$ticket['work_end_time']): ?>
                <!-- Active Timer -->
                <div class="timer-display" id="timerDisplay">00:00:00</div>
                <div class="text-center mb-3">
                    <small class="text-muted">Started: <?php echo date('M j, Y g:i A', strtotime($ticket['work_start_time'])); ?></small>
                </div>
                <form method="POST" class="text-center">
                    <button type="submit" name="end_work" class="btn btn-danger btn-lg">
                        <i class="fas fa-stop"></i> Stop Work
                    </button>
                </form>
                
                <script>
                    // Calculate elapsed time
                    const startTime = new Date("<?php echo $ticket['work_start_time']; ?>");
                    
                    function updateTimer() {
                        const now = new Date();
                        const diff = now - startTime;
                        
                        const hours = Math.floor(diff / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        
                        document.getElementById('timerDisplay').textContent = 
                            String(hours).padStart(2, '0') + ':' + 
                            String(minutes).padStart(2, '0') + ':' + 
                            String(seconds).padStart(2, '0');
                    }
                    
                    // Update timer every second
                    updateTimer();
                    setInterval(updateTimer, 1000);
                </script>
                
                <?php else: ?>
                <!-- Start Work Button -->
                <div class="text-center">
                    <form method="POST">
                        <button type="submit" name="start_work" class="btn btn-success btn-lg">
                            <i class="fas fa-play"></i> Start Working
                        </button>
                    </form>
                    <small class="text-muted mt-2 d-block">Click to start tracking your work time</small>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="row">
                <!-- Add Work Log Form -->
                <div class="col-lg-6">
                    <div class="work-log-card">
                        <h4><i class="fas fa-plus-circle"></i> Add Work Log</h4>
                        
                        <form method="POST" class="mt-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="work_date" class="form-label required-label">Date</label>
                                        <input type="date" class="form-control" id="work_date" name="work_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="work_type" class="form-label">Work Type</label>
                                        <select class="form-select" id="work_type" name="work_type">
                                            <option value="Regular">Regular Work</option>
                                            <option value="Overtime">Overtime</option>
                                            <option value="Emergency">Emergency</option>
                                            <option value="Maintenance">Maintenance</option>
                                            <option value="Travel">Travel Time</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="start_time" class="form-label required-label">Start Time</label>
                                        <input type="time" class="form-control" id="start_time" name="start_time" 
                                               value="09:00" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="end_time" class="form-label required-label">End Time</label>
                                        <input type="time" class="form-control" id="end_time" name="end_time" 
                                               value="17:00" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label required-label">Work Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="What work was done during this time..." required></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div id="hoursCalculation" class="text-muted">
                                    <i class="fas fa-calculator"></i> Hours: <span id="calculatedHours">0.00</span>
                                </div>
                                <button type="submit" name="add_work_log" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Log Work
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Work Log History -->
                <div class="col-lg-6">
                    <div class="work-log-card">
                        <h4><i class="fas fa-history"></i> Work Log History</h4>
                        
                        <?php if (!empty($work_logs)): ?>
                            <div class="mt-3">
                                <?php foreach ($work_logs as $log): ?>
                                <div class="log-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <strong><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></strong>
                                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($log['work_type']); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <?php echo date('M j, Y', strtotime($log['work_date'])); ?>
                                            </small>
                                            <div>
                                                <span class="badge bg-primary hours-badge">
                                                    <?php echo number_format($log['total_hours'], 2); ?> hrs
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            <i class="far fa-clock"></i> 
                                            <?php echo date('g:i A', strtotime($log['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($log['end_time'])); ?>
                                        </small>
                                    </div>
                                    
                                    <div class="mb-2">
                                        <?php echo nl2br(htmlspecialchars($log['description'])); ?>
                                    </div>
                                    
                                    <?php if ($current_user['staff_profile']['id'] == $log['staff_id'] || isAdmin() || isManager()): ?>
                                    <div class="text-end">
                                        <a href="work_log.php?id=<?php echo $ticket_id; ?>&delete_log=<?php echo $log['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this work log?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-clock fa-2x mb-2"></i><br>
                                No work logs yet. Start tracking your time!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            
            <!-- Ticket Not Found -->
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error ?: "Ticket not found."); ?>
            </div>
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Tickets
            </a>
            
            <?php endif; ?>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Calculate hours when time inputs change
        document.getElementById('start_time')?.addEventListener('change', calculateHours);
        document.getElementById('end_time')?.addEventListener('change', calculateHours);
        
        function calculateHours() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startTime && endTime) {
                const start = new Date(`2000-01-01T${startTime}`);
                const end = new Date(`2000-01-01T${endTime}`);
                
                // Handle overnight shifts
                if (end < start) {
                    end.setDate(end.getDate() + 1);
                }
                
                const diffMs = end - start;
                const diffHours = diffMs / (1000 * 60 * 60);
                
                document.getElementById('calculatedHours').textContent = diffHours.toFixed(2);
            }
        }
        
        // Initialize calculation
        calculateHours();
    </script>
</body>
</html>