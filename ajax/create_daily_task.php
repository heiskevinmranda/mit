<?php
// ajax/create_daily_task.php - Handle daily task creation via AJAX

header('Content-Type: application/json');

// Include required files
require_once '../includes/auth.php';
require_once '../includes/daily_task_functions.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    // Get POST data
    $task_title = trim($_POST['task_title'] ?? '');
    $task_description = trim($_POST['task_description'] ?? '');
    $assigned_to_name = trim($_POST['assigned_to_name'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    
    // Validation
    if (empty($task_title)) {
        echo json_encode([
            'success' => false,
            'message' => 'Task title is required'
        ]);
        exit;
    }
    
    if (strlen($task_title) > 255) {
        echo json_encode([
            'success' => false,
            'message' => 'Task title must be 255 characters or less'
        ]);
        exit;
    }
    
    // Check if assigned_to_name column exists in the database
    $columnExists = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to_name'";
        $checkStmt = $pdo->query($checkColumnSql);
        $columnExists = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        // If there's an error checking, assume column doesn't exist
        $columnExists = false;
    }
    
    // Prepare task data
    $task_data = [
        'task_title' => $task_title,
        'task_description' => $task_description,
        'priority' => $priority
    ];
    
    // Only include assigned_to_name if the column exists
    if ($columnExists) {
        $task_data['assigned_to_name'] = $assigned_to_name;
    }
    
    // Create the task
    $task_id = createDailyTask($pdo, $task_data);
    
    if ($task_id) {
        echo json_encode([
            'success' => true,
            'message' => 'Task created successfully',
            'task_id' => $task_id
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create task'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Daily Task Creation Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>