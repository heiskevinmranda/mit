<?php
// ajax/create_multiple_daily_tasks.php - Create multiple daily tasks at once

header('Content-Type: application/json');

// Include required files
require_once '../includes/auth.php';
require_once '../includes/daily_task_functions.php';

try {
    // Handle both form data and JSON data
    $tasks_data = [];
    
    // Check if it's JSON data
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $data = json_decode($input, true);
        if (isset($data['tasks']) && is_array($data['tasks'])) {
            $tasks_data = $data['tasks'];
        }
    }
    
    // Fallback to POST data if JSON not available
    if (empty($tasks_data) && isset($_POST['tasks']) && is_array($_POST['tasks'])) {
        $tasks_data = $_POST['tasks'];
    }
    
    // Validate required fields
    if (empty($tasks_data)) {
        throw new Exception('No tasks provided');
    }
    
    if (!is_array($tasks_data)) {
        throw new Exception('Tasks data must be an array');
    }
    
    // Get database connection
    $pdo = getDBConnection();
    
    $created_tasks = [];
    $failed_tasks = [];
    
    // Process each task
    foreach ($tasks_data as $index => $task_info) {
        try {
            // Validate required fields for each task
            if (empty($task_info['task_title'])) {
                $failed_tasks[] = [
                    'index' => $index,
                    'title' => $task_info['task_title'] ?? 'Untitled',
                    'error' => 'Task title is required'
                ];
                continue;
            }
            
            // Prepare task data
            $task_data = [
                'task_title' => trim($task_info['task_title']),
                'task_description' => trim($task_info['task_description'] ?? ''),
                'priority' => $task_info['priority'] ?? 'medium',
                'task_status' => 'pending'
            ];
            
            // Handle assigned user
            if (!empty($task_info['assigned_to'])) {
                // Check if assigned_to is a user ID or email/name
                if (filter_var($task_info['assigned_to'], FILTER_VALIDATE_EMAIL)) {
                    // It's an email, find the user ID
                    $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                    $userStmt->execute([':email' => $task_info['assigned_to']]);
                    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        $task_data['assigned_to'] = $user['id'];
                    } else {
                        $failed_tasks[] = [
                            'index' => $index,
                            'title' => $task_info['task_title'],
                            'error' => 'User with email ' . $task_info['assigned_to'] . ' not found'
                        ];
                        continue;
                    }
                } else {
                    // Assume it's a user ID
                    $task_data['assigned_to'] = $task_info['assigned_to'];
                }
                
                // Verify the user exists
                $userCheckStmt = $pdo->prepare("SELECT id FROM users WHERE id = :user_id");
                $userCheckStmt->execute([':user_id' => $task_data['assigned_to']]);
                if ($userCheckStmt->rowCount() == 0) {
                    $failed_tasks[] = [
                        'index' => $index,
                        'title' => $task_info['task_title'],
                        'error' => 'Assigned user does not exist'
                    ];
                    continue;
                }
            }
            
            // Set the user who is creating the tasks
            $creator_id = $_SESSION['user_id'];
            
            // Create the task
            $task_id = createDailyTask($pdo, $task_data, $creator_id);
            
            if ($task_id) {
                $created_tasks[] = [
                    'index' => $index,
                    'task_id' => $task_id,
                    'title' => $task_info['task_title']
                ];
            } else {
                $failed_tasks[] = [
                    'index' => $index,
                    'title' => $task_info['task_title'],
                    'error' => 'Failed to create task'
                ];
            }
            
        } catch (Exception $e) {
            $failed_tasks[] = [
                'index' => $index,
                'title' => $task_info['task_title'] ?? 'Untitled',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'created_count' => count($created_tasks),
        'failed_count' => count($failed_tasks),
        'created_tasks' => $created_tasks,
        'failed_tasks' => $failed_tasks,
        'message' => sprintf(
            'Successfully created %d task(s). %d task(s) failed.',
            count($created_tasks),
            count($failed_tasks)
        )
    ]);
    
} catch (Exception $e) {
    error_log("Bulk Daily Task Creation Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>