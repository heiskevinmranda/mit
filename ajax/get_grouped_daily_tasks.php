<?php
// ajax/get_grouped_daily_tasks.php - Fetch daily tasks grouped by assigned user

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
    
    // Get tasks grouped by user
    $grouped_tasks = getGroupedDailyTasks($pdo, $current_user_id, $current_user_type);
    
    // Format the response
    $response = [
        'success' => true,
        'grouped' => $grouped_tasks['grouped'],
        'data' => $grouped_tasks
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Get Grouped Daily Tasks Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>