<?php
// ajax/get_daily_tasks.php - Fetch daily tasks for display

header('Content-Type: application/json');

// Include required files
require_once '../includes/auth.php';
require_once '../includes/daily_task_functions.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    // Get today's tasks
    $today_tasks = getTodayTasks($pdo);
    
    // Format the response
    $response = [
        'success' => true,
        'tasks' => $today_tasks,
        'count' => count($today_tasks)
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get Daily Tasks Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>