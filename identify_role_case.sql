-- This script helps identify the exact role name and case in PostgreSQL

-- Show all roles that might be related to MSP
SELECT rolname 
FROM pg_roles 
WHERE rolname ILIKE '%msp%';

-- If you see MSPAppUser in the results, then use the quoted version:
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
-- GRANT ALL PRIVILEGES ON TABLE daily_tasks TO "MSPAppUser";

-- Since your query showed that the role exists as MSPAppUser, 
-- you need to use double quotes to preserve the case in the GRANT statements:

-- Corrected commands for your specific case:
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
GRANT ALL PRIVILEGES ON TABLE daily_tasks TO "MSPAppUser";

-- If you're still getting errors, you can also try creating the sequence if it doesn't exist:
-- CREATE SEQUENCE IF NOT EXISTS daily_tasks_id_seq OWNED BY daily_tasks.id;