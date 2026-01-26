-- Verify that tasks exist in the database and can be accessed

-- Check if there are any records in the daily_tasks table
SELECT COUNT(*) as total_tasks FROM daily_tasks;

-- Check today's tasks specifically
SELECT 
    id, 
    task_title, 
    task_description, 
    task_status, 
    priority, 
    task_date, 
    created_at 
FROM daily_tasks 
WHERE task_date = CURRENT_DATE 
ORDER BY created_at DESC;

-- Check if there are tasks from the last few days
SELECT 
    id, 
    task_title, 
    task_status, 
    priority, 
    task_date 
FROM daily_tasks 
WHERE task_date >= CURRENT_DATE - INTERVAL '3 days'
ORDER BY task_date DESC, created_at DESC;