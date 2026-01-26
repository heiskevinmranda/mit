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
    $assigned_to_id = trim($_POST['assigned_to_id'] ?? ''); // New: UUID of assigned user
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
    
    // Check if the current user can update this task
    // Only allow status updates to be made by the assigned user or admins/managers
    $currentUserCanUpdate = canUpdateDailyTask($pdo, $task_id, $_SESSION['user_id']);
    
    if (!$currentUserCanUpdate) {
        throw new Exception('You do not have permission to update this task');
    }
    
    // Prepare task data
    $task_data = [
        'task_title' => $task_title,
        'task_description' => $task_description,
        'task_status' => $task_status,
        'priority' => $priority
    ];
    
    // Add assignment information if columns exist
    if ($assignedColumnsExist) {
        // Only allow changing assigned_to if user has admin privileges
        if (in_array($_SESSION['user_type'], ['super_admin', 'admin', 'manager'])) {
            if (!empty($assigned_to_id)) {
                // Validate assigned_to_id if provided
                $userCheckSql = "SELECT id FROM users WHERE id = :user_id";
                $userCheckStmt = $pdo->prepare($userCheckSql);
                $userCheckStmt->execute([':user_id' => $assigned_to_id]);
                if ($userCheckStmt->rowCount() == 0) {
                    throw new Exception('Assigned user does not exist');
                }
                
                $task_data['assigned_to'] = $assigned_to_id;
            }
        } else {
            // Regular users can't reassign tasks, only update status of their own tasks
            if ($task_status !== $_POST['task_status'] ?? 'pending') {
                // Status is being changed, check if user is the assigned user
                $sql = "SELECT assigned_to FROM daily_tasks WHERE id = :task_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':task_id' => $task_id]);
                $task = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($task && $task['assigned_to'] != $_SESSION['user_id']) {
                    throw new Exception('Only the assigned user can update the task status');
                }
            }
        }
        
        // Only admins can update assigned_by
        if (in_array($_SESSION['user_type'], ['super_admin', 'admin', 'manager'])) {
            $task_data['assigned_by'] = $_SESSION['user_id'];
        }
    }
    
    // Include assigned_to_name if the column exists (for backward compatibility)
    if ($assignedToNameExists) {
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