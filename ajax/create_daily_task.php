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
    $assigned_to_id = trim($_POST['assigned_to_id'] ?? ''); // New: UUID of assigned user
    $priority = $_POST['priority'] ?? 'medium';
    
    // Validation
    if (empty($task_title)) {
        throw new Exception('Task title is required');
    }
    
    if (strlen($task_title) > 255) {
        throw new Exception('Task title must be 255 characters or less');
    }
    
    // Check if assigned_to and assigned_by columns exist
    $assignedColumnsExist = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name IN ('assigned_to', 'assigned_by')";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedColumnsExist = $checkStmt->rowCount() >= 2; // Both columns should exist
    } catch (Exception $e) {
        // If there's an error checking, assume columns don't exist
        $assignedColumnsExist = false;
    }
    
    // Check if assigned_to_name column exists (fallback)
    $assignedToNameExists = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to_name'";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedToNameExists = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $assignedToNameExists = false;
    }
    
    // Prepare task data
    $task_data = [
        'task_title' => $task_title,
        'task_description' => $task_description,
        'priority' => $priority
    ];
    
    // Add assignment information if columns exist
    if ($assignedColumnsExist) {
        // Validate assigned_to_id if provided
        if (!empty($assigned_to_id)) {
            // Check if the assigned user exists
            $userCheckSql = "SELECT id FROM users WHERE id = :user_id";
            $userCheckStmt = $pdo->prepare($userCheckSql);
            $userCheckStmt->execute([':user_id' => $assigned_to_id]);
            if ($userCheckStmt->rowCount() == 0) {
                throw new Exception('Assigned user does not exist');
            }
            
            $task_data['assigned_to'] = $assigned_to_id;
        }
        
        // Set the user who is creating the task
        $task_data['assigned_by'] = $_SESSION['user_id'];
    }
    
    // Include assigned_to_name if the column exists (for backward compatibility)
    if ($assignedToNameExists) {
        $task_data['assigned_to_name'] = $assigned_to_name;
    }
    
    // Create the task with creator ID
    $task_id = createDailyTask($pdo, $task_data, $_SESSION['user_id']);
    
    if ($task_id) {
        echo json_encode([
            'success' => true,
            'message' => 'Task created successfully',
            'task_id' => $task_id
        ]);
    } else {
        throw new Exception('Failed to create task');
    }
    
} catch (Exception $e) {
    error_log("Daily Task Creation Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>