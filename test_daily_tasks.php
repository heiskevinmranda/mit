<?php
// Test script for daily tasks functionality

require_once 'includes/auth.php';
require_once 'includes/daily_task_functions.php';

echo "<h2>Daily Tasks Functionality Test</h2>";

try {
    // Get database connection
    $pdo = getDBConnection();
    echo "<p>✅ Database connection successful</p>";
    
    // Test creating a daily task
    $test_task_data = [
        'task_title' => 'Test Daily Task - ' . date('Y-m-d H:i:s'),
        'task_description' => 'This is a test task created to verify the daily tasks functionality is working properly.',
        'priority' => 'medium'
    ];
    
    echo "<p>Creating test task...</p>";
    $task_id = createDailyTask($pdo, $test_task_data);
    
    if ($task_id) {
        echo "<p>✅ Task created successfully with ID: {$task_id}</p>";
        
        // Test retrieving today's tasks
        echo "<p>Retrieving today's tasks...</p>";
        $today_tasks = getTodayTasks($pdo);
        
        echo "<p>✅ Found " . count($today_tasks) . " tasks for today</p>";
        
        // Display the tasks
        if (!empty($today_tasks)) {
            echo "<h3>Today's Tasks:</h3>";
            echo "<ul>";
            foreach ($today_tasks as $task) {
                echo "<li>";
                echo "<strong>" . htmlspecialchars($task['task_title']) . "</strong><br>";
                echo "Status: " . htmlspecialchars($task['task_status']) . "<br>";
                echo "Priority: " . htmlspecialchars($task['priority']) . "<br>";
                echo "Created: " . htmlspecialchars($task['created_at']);
                echo "</li>";
            }
            echo "</ul>";
        }
        
        // Test updating task status
        echo "<p>Testing status update...</p>";
        $update_result = updateDailyTaskStatus($pdo, $task_id, 'in_progress');
        if ($update_result) {
            echo "<p>✅ Task status updated successfully</p>";
            
            // Verify the update
            $updated_tasks = getDailyTasksByDate($pdo, date('Y-m-d'));
            foreach ($updated_tasks as $task) {
                if ($task['id'] == $task_id) {
                    echo "<p>Verified status: " . htmlspecialchars($task['task_status']) . "</p>";
                    break;
                }
            }
        }
        
        // Test deleting the task
        echo "<p>Testing task deletion...</p>";
        $delete_result = deleteDailyTask($pdo, $task_id);
        if ($delete_result) {
            echo "<p>✅ Task deleted successfully</p>";
        }
        
    } else {
        echo "<p>❌ Failed to create task</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
?>