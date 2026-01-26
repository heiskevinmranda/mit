<?php
// debug_bulk_tasks.php - Debug bulk task creation

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/daily_task_functions.php';

echo "<h2>Debug Bulk Task Creation</h2>";

// Simulate the data structure you're sending
$test_data = [
    'tasks' => [
        [
            'task_title' => 'Test Task 1',
            'task_description' => 'First test task',
            'priority' => 'medium',
            'assigned_to' => '1' // Replace with actual user ID
        ],
        [
            'task_title' => 'Test Task 2', 
            'task_description' => 'Second test task',
            'priority' => 'high',
            'assigned_to' => '1' // Replace with actual user ID
        ]
    ]
];

echo "<h3>Test Data Structure</h3>";
echo "<pre>" . htmlspecialchars(json_encode($test_data, JSON_PRETTY_PRINT)) . "</pre>";

echo "<h3>Testing Direct Function Call</h3>";

try {
    $pdo = getDBConnection();
    
    // Get a valid user ID for testing
    $userStmt = $pdo->query("SELECT id FROM users WHERE is_active = true LIMIT 1");
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "<p>No active users found for testing</p>";
        exit;
    }
    
    echo "<p>Using user ID: " . $user['id'] . "</p>";
    
    // Update test data with valid user ID
    foreach ($test_data['tasks'] as &$task) {
        $task['assigned_to'] = $user['id'];
    }
    
    echo "<h3>Executing Bulk Creation</h3>";
    
    $created_tasks = [];
    $failed_tasks = [];
    
    foreach ($test_data['tasks'] as $index => $task_info) {
        try {
            if (empty($task_info['task_title'])) {
                throw new Exception('Task title is required');
            }
            
            $task_data = [
                'task_title' => trim($task_info['task_title']),
                'task_description' => trim($task_info['task_description'] ?? ''),
                'priority' => $task_info['priority'] ?? 'medium',
                'task_status' => 'pending',
                'assigned_to' => $task_info['assigned_to']
            ];
            
            // Verify user exists
            $userCheckStmt = $pdo->prepare("SELECT id FROM users WHERE id = :user_id");
            $userCheckStmt->execute([':user_id' => $task_data['assigned_to']]);
            if ($userCheckStmt->rowCount() == 0) {
                throw new Exception('Assigned user does not exist');
            }
            
            $creator_id = $_SESSION['user_id'] ?? $user['id'];
            $task_id = createDailyTask($pdo, $task_data, $creator_id);
            
            if ($task_id) {
                $created_tasks[] = [
                    'index' => $index,
                    'task_id' => $task_id,
                    'title' => $task_info['task_title']
                ];
                echo "<p>✅ Created task: " . htmlspecialchars($task_info['task_title']) . " (ID: {$task_id})</p>";
            } else {
                throw new Exception('Failed to create task');
            }
            
        } catch (Exception $e) {
            $failed_tasks[] = [
                'index' => $index,
                'title' => $task_info['task_title'] ?? 'Untitled',
                'error' => $e->getMessage()
            ];
            echo "<p>❌ Failed to create task: " . htmlspecialchars($task_info['task_title'] ?? 'Untitled') . " - " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    echo "<h3>Results</h3>";
    echo "<p>Created: " . count($created_tasks) . " tasks</p>";
    echo "<p>Failed: " . count($failed_tasks) . " tasks</p>";
    
    if (!empty($created_tasks)) {
        echo "<h4>Created Tasks:</h4>";
        echo "<ul>";
        foreach ($created_tasks as $task) {
            echo "<li>" . htmlspecialchars($task['title']) . " (ID: {$task['task_id']})</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($failed_tasks)) {
        echo "<h4>Failed Tasks:</h4>";
        echo "<ul>";
        foreach ($failed_tasks as $task) {
            echo "<li>" . htmlspecialchars($task['title']) . " - " . htmlspecialchars($task['error']) . "</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>