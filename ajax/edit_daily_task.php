<?php
// ajax/edit_daily_task.php - Update a daily task

header('Content-Type: application/json');

// Include required files
require_once '../includes/auth.php';
require_once '../includes/daily_task_functions.php';

try {
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get database connection
    $pdo = getDBConnection();
    
    // Get POST data
    $task_id = $_POST['task_id'] ?? null;
    $task_title = trim($_POST['task_title'] ?? '');
    $task_description = trim($_POST['task_description'] ?? '');
    $assigned_to_name = trim($_POST['assigned_to_name'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $task_status = $_POST['task_status'] ?? 'pending';
    
    if (!$task_id) {
        throw new Exception('Task ID is required');
    }
    
    if (empty($task_title)) {
        throw new Exception('Task title is required');
    }
    
    if (strlen($task_title) > 255) {
        throw new Exception('Task title must be 255 characters or less');
    }
    
    // Prepare task data
    $task_data = [
        'task_title' => $task_title,
        'task_description' => $task_description,
        'task_status' => $task_status,
        'priority' => $priority
    ];
    
    // Check if assigned_to_name column exists
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
    
    // Only include assigned_to_name if the column exists
    if ($columnExists) {
        $task_data['assigned_to_name'] = $assigned_to_name;
    }
    
    // Update the task
    $result = updateDailyTask($pdo, $task_id, $task_data);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Task updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update task');
    }
    
} catch (Exception $e) {
    error_log("Edit Daily Task Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>