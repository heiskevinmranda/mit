<?php
// ajax/get_daily_tasks.php - Fetch daily tasks for display (follow-up tasks)

header('Content-Type: application/json');

// Include required files
require_once '../includes/auth.php';
require_once '../includes/daily_task_functions.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    // Get current user info for permission checks
    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_user_type = $_SESSION['user_type'] ?? null;
    
    // Get tasks for follow-up (pending/in-progress) based on user permissions
    $follow_up_tasks = getTasksForFollowUp($pdo, $current_user_id, $current_user_type);
    
    // Format the response
    $response = [
        'success' => true,
        'tasks' => $follow_up_tasks,
        'count' => count($follow_up_tasks)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get Daily Tasks Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>