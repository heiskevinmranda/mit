-- Test creating a task for today and verifying it can be seen

-- Create a test task for today (manually inserting to verify functionality)
INSERT INTO daily_tasks (task_title, task_description, task_status, priority, task_date) 
VALUES ('Test Task Today', 'This is a test task created for today', 'pending', 'medium', CURRENT_DATE);

-- Verify it was created and can be retrieved
SELECT 
    id, 
    task_title, 
    task_description, 
    task_status, 
    priority, 
    task_date 
FROM daily_tasks 
WHERE task_date = CURRENT_DATE 
ORDER BY created_at DESC;

-- Also check all recent tasks
SELECT 
    id, 
    task_title, 
    task_status, 
    priority, 
    task_date,
    created_at
FROM daily_tasks 
WHERE task_date >= CURRENT_DATE - INTERVAL '1 day'
ORDER BY task_date DESC, created_at DESC;