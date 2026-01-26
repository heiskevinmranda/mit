<?php
// includes/daily_task_functions.php - Enhanced version with proper user assignment

require_once __DIR__ . '/../config/database.php';

/**
 * Create a new daily task
 */
function createDailyTask($pdo, $task_data, $created_by_user_id = null) {
    // Check if assigned_to and assigned_by columns exist
    $assignedColumnsExist = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name IN ('assigned_to', 'assigned_by')";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedColumnsExist = $checkStmt->rowCount() >= 2; // Both columns should exist
    } catch (Exception $e) {
        // If there's an error checking, assume columns don't exist
        $assignedColumnsExist = false;
    }
    
    // Check if assigned_to_name column exists (fallback)
    $assignedToNameExists = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to_name'";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedToNameExists = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $assignedToNameExists = false;
    }
    
    if ($assignedColumnsExist) {
        $sql = "INSERT INTO daily_tasks (task_date, task_title, task_description, assigned_to, assigned_by, priority) 
                VALUES (CURRENT_DATE, :task_title, :task_description, :assigned_to, :assigned_by, :priority) 
                RETURNING id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':task_title' => $task_data['task_title'],
            ':task_description' => $task_data['task_description'] ?? '',
            ':assigned_to' => $task_data['assigned_to'] ?? null,
            ':assigned_by' => $created_by_user_id,
            ':priority' => $task_data['priority'] ?? 'medium'
        ]);
    } elseif ($assignedToNameExists) {
        // Fallback to assigned_to_name column if new columns don't exist
        $sql = "INSERT INTO daily_tasks (task_date, task_title, task_description, assigned_to_name, priority) 
                VALUES (CURRENT_DATE, :task_title, :task_description, :assigned_to_name, :priority) 
                RETURNING id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':task_title' => $task_data['task_title'],
            ':task_description' => $task_data['task_description'] ?? '',
            ':assigned_to_name' => $task_data['assigned_to_name'] ?? '',
            ':priority' => $task_data['priority'] ?? 'medium'
        ]);
    } else {
        // Fallback to basic version without assignment
        $sql = "INSERT INTO daily_tasks (task_date, task_title, task_description, priority) 
                VALUES (CURRENT_DATE, :task_title, :task_description, :priority) 
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
 * Get daily tasks for a user with follow-up capability
 * If user_id is provided, only return tasks assigned to that user or tasks they created (for admins/managers)
 * Shows all pending/in-progress tasks, not just today's tasks, to allow for follow-up
 */
function getDailyTasksByDate($pdo, $date = null, $user_id = null, $user_type = null) {
    // Check if assigned_to column exists
    $assignedColumnsExist = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to'";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedColumnsExist = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $assignedColumnsExist = false;
    }
    
    if (!$date) {
        // Show all pending/in-progress tasks for follow-up, not just today's tasks
        if ($assignedColumnsExist && $user_id && $user_type) {
            // Apply permission-based filtering
            if (in_array($user_type, ['super_admin', 'admin', 'manager'])) {
                // Admins and managers can see all pending/in-progress tasks
                $sql = "SELECT dt.*, 
                               COALESCE(sp.full_name, u.email) as assigned_to_name, 
                               COALESCE(sp2.full_name, u2.email) as assigned_by_name 
                        FROM daily_tasks dt
                        LEFT JOIN users u ON dt.assigned_to = u.id
                        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                        LEFT JOIN users u2 ON dt.assigned_by = u2.id
                        LEFT JOIN staff_profiles sp2 ON u2.id = sp2.user_id
                        WHERE dt.task_status IN ('pending', 'in_progress')
                        ORDER BY task_date ASC, priority DESC, created_at ASC";
                $stmt = $pdo->query($sql);
            } else {
                // Regular users can only see pending/in-progress tasks assigned to them
                $sql = "SELECT dt.*, 
                               COALESCE(sp.full_name, u.email) as assigned_to_name, 
                               COALESCE(sp2.full_name, u2.email) as assigned_by_name 
                        FROM daily_tasks dt
                        LEFT JOIN users u ON dt.assigned_to = u.id
                        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                        LEFT JOIN users u2 ON dt.assigned_by = u2.id
                        LEFT JOIN staff_profiles sp2 ON u2.id = sp2.user_id
                        WHERE dt.task_status IN ('pending', 'in_progress') AND dt.assigned_to = :user_id
                        ORDER BY task_date ASC, priority DESC, created_at ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $user_id]);
            }
        } else {
            // Fallback to original query if no assignment columns exist or no user context
            $sql = "SELECT * FROM daily_tasks 
                    WHERE task_status IN ('pending', 'in_progress')
                    ORDER BY task_date ASC, priority DESC, created_at ASC";
            $stmt = $pdo->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // If a specific date is provided, use it
    if ($assignedColumnsExist && $user_id && $user_type) {
        // Apply permission-based filtering
        if (in_array($user_type, ['super_admin', 'admin', 'manager'])) {
            // Admins and managers can see all tasks for the day
            $sql = "SELECT dt.*, 
                           COALESCE(sp.full_name, u.email) as assigned_to_name, 
                           COALESCE(sp2.full_name, u2.email) as assigned_by_name 
                    FROM daily_tasks dt
                    LEFT JOIN users u ON dt.assigned_to = u.id
                    LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                    LEFT JOIN users u2 ON dt.assigned_by = u2.id
                    LEFT JOIN staff_profiles sp2 ON u2.id = sp2.user_id
                    WHERE task_date = :date
                    ORDER BY priority DESC, created_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':date' => $date]);
        } else {
            // Regular users can only see tasks assigned to them
            $sql = "SELECT dt.*, 
                           COALESCE(sp.full_name, u.email) as assigned_to_name, 
                           COALESCE(sp2.full_name, u2.email) as assigned_by_name 
                    FROM daily_tasks dt
                    LEFT JOIN users u ON dt.assigned_to = u.id
                    LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                    LEFT JOIN users u2 ON dt.assigned_by = u2.id
                    LEFT JOIN staff_profiles sp2 ON u2.id = sp2.user_id
                    WHERE task_date = :date AND dt.assigned_to = :user_id
                    ORDER BY priority DESC, created_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':date' => $date, ':user_id' => $user_id]);
        }
    } else {
        // Fallback to original query if no assignment columns exist or no user context
        $sql = "SELECT * FROM daily_tasks 
                WHERE task_date = :date
                ORDER BY priority DESC, created_at ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':date' => $date]);
    }
    
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
                updated_at = CURRENT_TIMESTAMP";
    
    // Check if assigned_to and assigned_by columns exist
    $assignedColumnsExist = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name IN ('assigned_to', 'assigned_by')";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedColumnsExist = $checkStmt->rowCount() >= 2; // Both columns should exist
    } catch (Exception $e) {
        $assignedColumnsExist = false;
    }
    
    // Check if assigned_to_name column exists (fallback)
    $assignedToNameExists = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to_name'";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedToNameExists = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $assignedToNameExists = false;
    }
    
    // Add assignment fields to the query if they exist
    if ($assignedColumnsExist && isset($task_data['assigned_to'])) {
        $sql .= ", assigned_to = :assigned_to";
    }
    
    if ($assignedColumnsExist && isset($task_data['assigned_by'])) {
        $sql .= ", assigned_by = :assigned_by";
    }
    
    if ($assignedToNameExists && isset($task_data['assigned_to_name'])) {
        $sql .= ", assigned_to_name = :assigned_to_name";
    }
    
    $sql .= " WHERE id = :task_id";
    
    $stmt = $pdo->prepare($sql);
    
    $params = [
        ':task_id' => $task_id,
        ':task_title' => $task_data['task_title'],
        ':task_description' => $task_data['task_description'] ?? '',
        ':task_status' => $task_data['task_status'] ?? 'pending',
        ':priority' => $task_data['priority'] ?? 'medium'
    ];
    
    // Add assignment parameters if they exist
    if ($assignedColumnsExist && isset($task_data['assigned_to'])) {
        $params[':assigned_to'] = $task_data['assigned_to'];
    }
    
    if ($assignedColumnsExist && isset($task_data['assigned_by'])) {
        $params[':assigned_by'] = $task_data['assigned_by'];
    }
    
    if ($assignedToNameExists && isset($task_data['assigned_to_name'])) {
        $params[':assigned_to_name'] = $task_data['assigned_to_name'];
    }
    
    return $stmt->execute($params);
}

/**
 * Check if the current user can update a daily task
 * Returns true if user can update the task (either assigned user or admin/super admin)
 */
function canUpdateDailyTask($pdo, $task_id, $user_id) {
    // Get the task details
    $sql = "SELECT assigned_to FROM daily_tasks WHERE id = :task_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':task_id' => $task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return false; // Task doesn't exist
    }
    
    // Check if assigned_to column exists
    $assignedColumnsExist = false;
    try {
        $checkColumnSql = "SELECT column_name FROM information_schema.columns 
                          WHERE table_name = 'daily_tasks' AND column_name = 'assigned_to'";
        $checkStmt = $pdo->query($checkColumnSql);
        $assignedColumnsExist = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        $assignedColumnsExist = false;
    }
    
    if ($assignedColumnsExist) {
        // If the user is the assigned user, they can update it
        if ($task['assigned_to'] && $task['assigned_to'] == $user_id) {
            return true;
        }
        
        // Check if user has admin privileges
        // Get user role from users table
        $userSql = "SELECT user_type FROM users WHERE id = :user_id";
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute([':user_id' => $user_id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && in_array($user['user_type'], ['super_admin', 'admin', 'manager'])) {
            return true; // Admins and managers can update any task
        }
        
        return false; // User is not assigned to this task and doesn't have admin privileges
    } else {
        // Fallback: if no assigned_to column exists, allow update (backward compatibility)
        return true;
    }
}

/**
 * Update task status
 */
function updateDailyTaskStatus($pdo, $task_id, $status, $user_id = null) {
    // First, check if the user can update this task
    if ($user_id && !canUpdateDailyTask($pdo, $task_id, $user_id)) {
        return false; // User doesn't have permission to update this task
    }
    
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
function getTodayTasks($pdo, $user_id = null, $user_type = null) {
    // For compatibility, use specific date of today
    return getDailyTasksByDate($pdo, date('Y-m-d'), $user_id, $user_type);
}

/**
 * Get tasks for follow-up (all pending/in-progress tasks)
 */
function getTasksForFollowUp($pdo, $user_id = null, $user_type = null) {
    // This calls the modified getDailyTasksByDate function without a date
    // which now returns all pending/in-progress tasks for follow-up
    return getDailyTasksByDate($pdo, null, $user_id, $user_type);
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