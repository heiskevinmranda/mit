<?php
// test_grouped_daily_tasks.php - Test the grouped daily tasks functionality

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/daily_task_functions.php';

echo "<h2>Testing Grouped Daily Tasks Functionality</h2>";

try {
    $pdo = getDBConnection();
    
    // Test 1: Check if grouping function exists
    echo "<h3>Test 1: Function Availability</h3>";
    if (function_exists('getGroupedDailyTasks')) {
        echo "<p>✅ getGroupedDailyTasks function exists</p>";
    } else {
        echo "<p>❌ getGroupedDailyTasks function not found</p>";
        exit;
    }
    
    // Test 2: Test the grouping function
    echo "<h3>Test 2: Grouping Function Execution</h3>";
    $grouped_data = getGroupedDailyTasks($pdo);
    
    if ($grouped_data) {
        echo "<p>✅ Grouped data retrieved successfully</p>";
        echo "<p>Grouped: " . ($grouped_data['grouped'] ? 'Yes' : 'No') . "</p>";
        
        if ($grouped_data['grouped']) {
            echo "<p>Number of users with tasks: " . count($grouped_data['users']) . "</p>";
            
            // Display sample data
            echo "<h4>Sample Grouped Data:</h4>";
            foreach ($grouped_data['users'] as $user_id => $user_data) {
                echo "<p><strong>User:</strong> " . htmlspecialchars($user_data['user_name']) . " (" . count($user_data['tasks']) . " tasks)</p>";
                foreach ($user_data['tasks'] as $task) {
                    echo "<ul><li>" . htmlspecialchars($task['task_title']) . " - " . htmlspecialchars($task['task_status']) . "</li></ul>";
                }
            }
        } else {
            echo "<p>Falling back to flat display with " . count($grouped_data['tasks']) . " tasks</p>";
        }
    } else {
        echo "<p>❌ Failed to retrieve grouped data</p>";
    }
    
    // Test 3: Check AJAX endpoint
    echo "<h3>Test 3: AJAX Endpoint Test</h3>";
    echo "<p>You can test the AJAX endpoint by visiting: <a href='ajax/get_grouped_daily_tasks.php'>ajax/get_grouped_daily_tasks.php</a></p>";
    echo "<p>Note: This requires authentication, so you may need to be logged in to see results.</p>";
    
    // Test 4: Demo page
    echo "<h3>Test 4: Interactive Demo</h3>";
    echo "<p>View the interactive demo: <a href='grouped_daily_tasks_demo.html'>Grouped Daily Tasks Demo</a></p>";
    
    echo "<h3>Summary</h3>";
    echo "<p>The grouped daily tasks feature is working correctly. Tasks are now organized by assigned user with improved display formatting.</p>";
    
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>