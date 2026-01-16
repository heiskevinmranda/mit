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
$standup_id = $_GET['standup_id'] ?? $_POST['standup_id'] ?? null;
if (!$standup_id) {
    header("Location: " . route('daily_standups.index'));
    exit;
}

// Get standup details
$stmt = $pdo->prepare("
    SELECT ds.*, sp.full_name as facilitator_name
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

// Get existing response
$stmt = $pdo->prepare("
    SELECT * FROM standup_responses 
    WHERE standup_id = ? AND staff_id = ?
");
$stmt->execute([$standup_id, $staff_id]);
$existing_response = $stmt->fetch(PDO::FETCH_ASSOC);
$is_edit = !empty($existing_response);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $yesterday_work = $_POST['yesterday_work'] ?? '';
    $today_plan = $_POST['today_plan'] ?? '';
    $blockers = $_POST['blockers'] ?? '';
    $mood = $_POST['mood'] ?? 'okay';
    $estimated_hours = $_POST['estimated_hours'] ?? 8;
    $attendance_status = $_POST['attendance_status'] ?? 'present';
    
    if ($is_edit) {
        // Update existing response
        $stmt = $pdo->prepare("
            UPDATE standup_responses 
            SET yesterday_work = ?, today_plan = ?, blockers = ?, 
                mood = ?, estimated_hours = ?, attendance_status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$yesterday_work, $today_plan, $blockers, $mood, $estimated_hours, $attendance_status, $existing_response['id']]);
    } else {
        // Insert new response
        $stmt = $pdo->prepare("
            INSERT INTO standup_responses 
            (standup_id, staff_id, yesterday_work, today_plan, blockers, 
             mood, estimated_hours, attendance_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$standup_id, $staff_id, $yesterday_work, $today_plan, $blockers, $mood, $estimated_hours, $attendance_status]);
    }
    
    // Redirect to view page
    header("Location: " . route('daily_standups.view', ['id' => $standup_id]));
    exit;
}

// Get yesterday's response for reference
$yesterday_date = date('Y-m-d', strtotime('-1 day'));
$stmt = $pdo->prepare("
    SELECT sr.today_plan as yesterday_planned
    FROM standup_responses sr
    JOIN daily_standups ds ON sr.standup_id = ds.id
    WHERE sr.staff_id = ? AND ds.meeting_date = ?
    ORDER BY sr.submitted_at DESC
    LIMIT 1
");
$stmt->execute([$staff_id, $yesterday_date]);
$yesterday_response = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit' : 'Submit'; ?> Standup Response | MSP Application</title>
    <link rel="stylesheet" href="/mit/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .mood-selector .btn {
            border-radius: 20px;
            padding: 8px 20px;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .mood-excellent { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .mood-good { background: #d1ecf1; color: #0c5460; border-color: #bee5eb; }
        .mood-okay { background: #fff3cd; color: #856404; border-color: #ffeaa7; }
        .mood-frustrated { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .mood-blocked { background: #dc3545; color: white; border-color: #dc3545; }
        .mood-active { transform: scale(1.05); box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2); }
        .word-counter {
            font-size: 12px;
            color: #6c757d;
            text-align: right;
            margin-top: 5px;
        }
        .yesterday-reference {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1>
                        <i class="fas fa-edit me-2"></i>
                        <?php echo $is_edit ? 'Edit Standup Response' : 'Submit Daily Standup'; ?>
                    </h1>
                    <p class="text-muted mb-0">
                        For: <strong><?php echo htmlspecialchars($standup['title']); ?></strong>
                        • <?php echo date('F j, Y', strtotime($standup['meeting_date'])); ?>
                    </p>
                </div>
                <a href="<?php echo route('daily_standups.view', ['id' => $standup_id]); ?>" 
                   class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Meeting
                </a>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="standup_id" value="<?php echo htmlspecialchars($standup_id); ?>">
                
                <!-- Yesterday's Reference -->
                <?php if ($yesterday_response && !empty($yesterday_response['yesterday_planned'])): ?>
                <div class="yesterday-reference">
                    <h6><i class="fas fa-history me-2"></i>Yesterday's Plan</h6>
                    <p class="mb-0"><?php echo htmlspecialchars(substr($yesterday_response['yesterday_planned'], 0, 200)); ?>...</p>
                    <small class="text-muted">Reference from your previous standup</small>
                </div>
                <?php endif; ?>

                <!-- Yesterday's Work -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <span class="badge bg-primary me-2">1</span>
                        What did you work on yesterday?
                    </h4>
                    <div class="mb-3">
                        <label class="form-label">Accomplishments & Completed Tasks</label>
                        <textarea class="form-control" name="yesterday_work" rows="4" 
                                  placeholder="List the tasks you completed yesterday, including ticket numbers, client work, or projects..."
                                  oninput="updateWordCount(this, 'yesterday-count')"
                                  required><?php echo htmlspecialchars($existing_response['yesterday_work'] ?? ''); ?></textarea>
                        <div class="word-counter">
                            <span id="yesterday-count">0</span> words
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> Mention specific ticket numbers, client names, and quantifiable results.
                    </div>
                </div>

                <!-- Today's Plan -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <span class="badge bg-success me-2">2</span>
                        What will you work on today?
                    </h4>
                    <div class="mb-3">
                        <label class="form-label">Today's Goals & Planned Tasks</label>
                        <textarea class="form-control" name="today_plan" rows="4" 
                                  placeholder="Outline your priorities for today. Be specific about what you plan to accomplish..."
                                  oninput="updateWordCount(this, 'today-count')"
                                  required><?php echo htmlspecialchars($existing_response['today_plan'] ?? ''); ?></textarea>
                        <div class="word-counter">
                            <span id="today-count">0</span> words
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Estimated Hours Today</label>
                            <input type="number" class="form-control" name="estimated_hours" 
                                   min="0" max="12" step="0.5" value="<?php echo htmlspecialchars($existing_response['estimated_hours'] ?? 8); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Attendance Status</label>
                            <select class="form-select" name="attendance_status">
                                <option value="present" <?php echo ($existing_response['attendance_status'] ?? 'present') == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="late" <?php echo ($existing_response['attendance_status'] ?? '') == 'late' ? 'selected' : ''; ?>>Running Late</option>
                                <option value="excused" <?php echo ($existing_response['attendance_status'] ?? '') == 'excused' ? 'selected' : ''; ?>>Excused Absence</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Blockers -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <span class="badge bg-warning me-2">3</span>
                        Any blockers or impediments?
                    </h4>
                    <div class="mb-3">
                        <label class="form-label">Issues, Dependencies, or Help Needed</label>
                        <textarea class="form-control" name="blockers" rows="3" 
                                  placeholder="List anything blocking your progress, questions for the team, or help needed from others..."
                                  oninput="updateWordCount(this, 'blocker-count')"><?php echo htmlspecialchars($existing_response['blockers'] ?? ''); ?></textarea>
                        <div class="word-counter">
                            <span id="blocker-count">0</span> words
                        </div>
                    </div>
                </div>

                <!-- Mood -->
                <div class="form-section">
                    <h4 class="mb-3">
                        <span class="badge bg-info me-2">4</span>
                        How are you feeling today?
                    </h4>
                    <div class="mood-selector mb-3">
                        <input type="hidden" name="mood" id="selected-mood" value="<?php echo htmlspecialchars($existing_response['mood'] ?? 'okay'); ?>">
                        
                        <button type="button" class="btn mood-excellent" data-mood="excellent">
                            <i class="fas fa-smile-beam me-2"></i>Excellent
                        </button>
                        <button type="button" class="btn mood-good" data-mood="good">
                            <i class="fas fa-smile me-2"></i>Good
                        </button>
                        <button type="button" class="btn mood-okay" data-mood="okay">
                            <i class="fas fa-meh me-2"></i>Okay
                        </button>
                        <button type="button" class="btn mood-frustrated" data-mood="frustrated">
                            <i class="fas fa-frown me-2"></i>Frustrated
                        </button>
                        <button type="button" class="btn mood-blocked" data-mood="blocked">
                            <i class="fas fa-exclamation-triangle me-2"></i>Blocked
                        </button>
                    </div>
                    <small class="text-muted">This helps the team understand your current state and provide support if needed.</small>
                </div>

                <!-- Submit Button -->
                <div class="text-center mt-4">
                    <button type="submit" name="submit_response" class="btn btn-primary btn-lg px-5">
                        <i class="fas fa-paper-plane me-2"></i>
                        <?php echo $is_edit ? 'Update Response' : 'Submit Standup'; ?>
                    </button>
                    <a href="<?php echo route('daily_standups.view', ['id' => $standup_id]); ?>" 
                       class="btn btn-outline-secondary btn-lg ms-3">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Initialize word counts
        document.querySelectorAll('textarea').forEach(textarea => {
            const targetId = textarea.getAttribute('oninput').match(/'([^']+)'/)[1];
            updateWordCount(textarea, targetId);
        });

        // Word counter function
        function updateWordCount(textarea, countId) {
            const text = textarea.value.trim();
            const wordCount = text === '' ? 0 : text.split(/\s+/).length;
            document.getElementById(countId).textContent = wordCount;
        }

        // Mood selector
        document.querySelectorAll('.mood-selector .btn').forEach(button => {
            button.addEventListener('click', function() {
                const mood = this.getAttribute('data-mood');
                document.getElementById('selected-mood').value = mood;
                
                // Remove active class from all buttons
                document.querySelectorAll('.mood-selector .btn').forEach(btn => {
                    btn.classList.remove('mood-active');
                });
                
                // Add active class to clicked button
                this.classList.add('mood-active');
            });
            
            // Set initial active mood
            const currentMood = document.getElementById('selected-mood').value;
            if (button.getAttribute('data-mood') === currentMood) {
                button.classList.add('mood-active');
            }
        });

        // Auto-save draft every 30 seconds
        let autoSaveTimer;
        function autoSaveDraft() {
            const form = document.querySelector('form');
            const formData = new FormData(form);
            
            fetch('<?php echo route('daily_standups.auto_save'); ?>', {
                method: 'POST',
                body: formData
            }).then(response => {
                console.log('Auto-saved draft');
            });
        }
        
        // Start auto-save
        document.querySelector('form').addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(autoSaveDraft, 30000);
        });
    </script>
</body>
</html>