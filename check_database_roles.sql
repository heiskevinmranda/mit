-- Check existing database roles to identify the correct name for granting permissions

SELECT rolname 
FROM pg_roles 
WHERE rolname ILIKE '%msp%';

-- Alternative: Check all roles
SELECT rolname FROM pg_roles;

-- Check if the specific sequences exist
SELECT sequence_name 
FROM information_schema.sequences 
WHERE sequence_name ILIKE '%daily_tasks%';

-- Check if the table exists
SELECT tablename 
FROM pg_tables 
WHERE tablename = 'daily_tasks';