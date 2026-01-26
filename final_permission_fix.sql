-- Final permission fix for your specific case
-- Based on your query result showing the role name as 'MSPAppUser'

-- Grant the necessary permissions using the exact role name with quotes
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
-- Grant all privileges on the table (including SELECT which is needed for viewing)
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE daily_tasks TO "MSPAppUser";

-- Verify the grants worked by checking the permissions
SELECT grantee, privilege_type 
FROM information_schema.role_table_grants 
WHERE table_name = 'daily_tasks' AND grantee = 'MSPAppUser';

-- Also verify sequence permissions
SELECT schemaname, sequencename, grantee, privilege_type
FROM pg_sequences s
JOIN information_schema.usage_privileges p ON s.schemaname = p.object_schema AND s.sequencename = p.object_name
WHERE s.sequencename = 'daily_tasks_id_seq' AND p.grantee = 'MSPAppUser';