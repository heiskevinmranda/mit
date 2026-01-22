<?php
// includes/daily_task_functions.php - Simplified version

require_once __DIR__ . '/../config/database.php';

/**
 * Create a new daily task
 */
function createDailyTask($pdo, $task_data) {
    // Check if assigned_to_name column exists
    $columnExists = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to_name'";
        $checkStmt = $pdo->query($checkColumnSql);
        $columnExists = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        // If there's an error checking, assume column doesn't exist
        $columnExists = false;
    }
    
    if ($columnExists) {
        $sql = "INSERT INTO daily_tasks (task_title, task_description, assigned_to_name, priority) 
                VALUES (:task_title, :task_description, :assigned_to_name, :priority) 
                RETURNING id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':task_title' => $task_data['task_title'],
            ':task_description' => $task_data['task_description'] ?? '',
            ':assigned_to_name' => $task_data['assigned_to_name'] ?? '',
            ':priority' => $task_data['priority'] ?? 'medium'
        ]);
    } else {
        $sql = "INSERT INTO daily_tasks (task_title, task_description, priority) 
                VALUES (:task_title, :task_description, :priority) 
                RETURNING id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':task_title' => $task_data['task_title'],
            ':task_description' => $task_data['task_description'] ?? '',
            ':priority' => $task_data['priority'] ?? 'medium'
        ]);
    }
    
    return $stmt->fetch(PDO::FETCH_ASSOC)['id'];
}

/**
 * Get all daily tasks for a specific date
 */
function getDailyTasksByDate($pdo, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }
    
    $sql = "SELECT * FROM daily_tasks 
            WHERE task_date = :date
            ORDER BY priority DESC, created_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all daily tasks (for all dates)
 */
function getAllDailyTasks($pdo) {
    $sql = "SELECT * FROM daily_tasks 
            ORDER BY task_date DESC, priority DESC, created_at ASC";
    
    $stmt = $pdo->query($sql);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Update a daily task
 */
function updateDailyTask($pdo, $task_id, $task_data) {
    $sql = "UPDATE daily_tasks 
            SET task_title = :task_title, 
                task_description = :task_description, 
                task_status = :task_status, 
                priority = :priority, 
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :task_id";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':task_id' => $task_id,
        ':task_title' => $task_data['task_title'],
        ':task_description' => $task_data['task_description'] ?? '',
        ':task_status' => $task_data['task_status'] ?? 'pending',
        ':priority' => $task_data['priority'] ?? 'medium'
    ]);
}

/**
 * Update task status
 */
function updateDailyTaskStatus($pdo, $task_id, $status) {
    $sql = "UPDATE daily_tasks 
            SET task_status = :task_status, 
                updated_at = CURRENT_TIMESTAMP";
    
    if ($status === 'completed') {
        $sql .= ", completed_at = CURRENT_TIMESTAMP";
    }
    
    $sql .= " WHERE id = :task_id";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':task_id' => $task_id,
        ':task_status' => $status
    ]);
}

/**
 * Delete a daily task
 */
function deleteDailyTask($pdo, $task_id) {
    $sql = "DELETE FROM daily_tasks WHERE id = :task_id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([':task_id' => $task_id]);
}

/**
 * Get today's tasks for all users
 */
function getTodayTasks($pdo) {
    return getDailyTasksByDate($pdo, date('Y-m-d'));
}

/**
 * Get task priorities
 */
function getTaskPriorities() {
    return [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent'
    ];
}

/**
 * Get task statuses
 */
function getTaskStatuses() {
    return [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled'
    ];
}
?>