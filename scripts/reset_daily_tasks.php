<?php
// scripts/reset_daily_tasks.php
// Script to reset daily tasks - should be run daily via cron job

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/daily_task_functions.php';

try {
    $pdo = getDBConnection();
    
    // Get yesterday's date to move incomplete tasks to today
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $today = date('Y-m-d');
    
    // Get incomplete tasks from yesterday that need to be moved to today
    $sql = "SELECT * FROM daily_tasks WHERE task_date = :yesterday AND task_status != 'completed'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':yesterday' => $yesterday]);
    $incomplete_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $moved_count = 0;
    
    foreach ($incomplete_tasks as $task) {
        // Check if a similar task already exists for today for the same user
        $check_sql = "SELECT id FROM daily_tasks WHERE task_date = :today AND assigned_to = :assigned_to AND task_title = :task_title";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([
            ':today' => $today,
            ':assigned_to' => $task['assigned_to'],
            ':task_title' => $task['task_title']
        ]);
        
        if (!$check_stmt->fetch()) {
            // Move the task to today by inserting a new record
            $insert_sql = "INSERT INTO daily_tasks 
                          (task_date, assigned_to, assigned_by, task_title, task_description, task_status, priority, due_time, created_at, updated_at) 
                          VALUES 
                          (:task_date, :assigned_to, :assigned_by, :task_title, :task_description, :task_status, :priority, :due_time, :created_at, :updated_at)";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $result = $insert_stmt->execute([
                ':task_date' => $today,
                ':assigned_to' => $task['assigned_to'],
                ':assigned_by' => $task['assigned_by'],
                ':task_title' => $task['task_title'],
                ':task_description' => $task['task_description'],
                ':task_status' => $task['task_status'], // Keep the same status
                ':priority' => $task['priority'],
                ':due_time' => $task['due_time'],
                ':created_at' => $task['created_at'],
                ':updated_at' => $task['updated_at']
            ]);
            
            if ($result) {
                $moved_count++;
                
                // Optionally, we could delete the old task, but keeping it for history
                // Or we could mark it as 'carried_forward' to show the history
                $update_old_sql = "UPDATE daily_tasks SET task_status = 'carried_forward', updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $update_old_stmt = $pdo->prepare($update_old_sql);
                $update_old_stmt->execute([':id' => $task['id']]);
            }
        }
    }
    
    // Log the reset operation
    error_log("Daily tasks reset completed: {$moved_count} tasks moved from {$yesterday} to {$today}");
    
    echo "Daily tasks reset completed:\n";
    echo "- Date: {$today}\n";
    echo "- Incomplete tasks from {$yesterday}: " . count($incomplete_tasks) . "\n";
    echo "- Tasks moved to today: {$moved_count}\n";
    
} catch (Exception $e) {
    error_log("Error in daily tasks reset: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
?>