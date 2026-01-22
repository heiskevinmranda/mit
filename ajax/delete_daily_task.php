<?php
// ajax/delete_daily_task.php - Delete a daily task

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
    
    // Get task ID from POST data
    $task_id = $_POST['task_id'] ?? null;
    
    if (!$task_id) {
        throw new Exception('Task ID is required');
    }
    
    // Delete the task
    $result = deleteDailyTask($pdo, $task_id);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Task deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete task');
    }
    
} catch (Exception $e) {
    error_log("Delete Daily Task Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>