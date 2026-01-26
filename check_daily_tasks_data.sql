-- Diagnostic script to check the daily_tasks table and data

-- Check if the daily_tasks table exists
SELECT EXISTS (
    SELECT FROM information_schema.tables 
    WHERE table_name = 'daily_tasks'
) AS table_exists;

-- Check if the daily_tasks_id_seq sequence exists
SELECT EXISTS (
    SELECT FROM information_schema.sequences 
    WHERE sequence_name = 'daily_tasks_id_seq'
) AS sequence_exists;

-- Check the structure of the daily_tasks table
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name = 'daily_tasks'
ORDER BY ordinal_position;

-- Check if there are any records in the table
SELECT COUNT(*) as total_records FROM daily_tasks;

-- Check today's records specifically
SELECT * FROM daily_tasks WHERE task_date = CURRENT_DATE;

-- Check recent records (last 7 days)
SELECT * FROM daily_tasks 
WHERE task_date >= CURRENT_DATE - INTERVAL '7 days'
ORDER BY task_date DESC, created_at DESC;

-- Check current user permissions on the table
SELECT grantee, privilege_type 
FROM information_schema.role_table_grants 
WHERE table_name = 'daily_tasks' AND grantee = 'MSPAppUser';

-- Check current user permissions on the sequence
SELECT schemaname, sequencename, grantee, privilege_type
FROM pg_sequences s
JOIN information_schema.usage_privileges p ON s.schemaname = p.object_schema AND s.sequencename = p.object_name
WHERE s.sequencename = 'daily_tasks_id_seq' AND p.grantee = 'MSPAppUser';