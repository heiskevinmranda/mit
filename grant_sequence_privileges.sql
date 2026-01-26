-- Grant necessary privileges to MSPAppUser for daily_tasks sequence
-- This fixes the "permission denied for sequence daily_tasks_id_seq" error

-- First check the actual role name in your database
-- Run: SELECT rolname FROM pg_roles WHERE rolname ILIKE '%msp%';

-- Grant usage and select privileges on the sequence (most likely lowercase)
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO mspappuser;

-- Also ensure the user has INSERT privilege on the table itself
GRANT INSERT ON TABLE daily_tasks TO mspappuser;

-- Optional: Grant other necessary privileges for full functionality
GRANT SELECT, UPDATE, DELETE ON TABLE daily_tasks TO mspappuser;

-- If your role was created with quotes as "MSPAppUser", use this instead:
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
-- GRANT INSERT ON TABLE daily_tasks TO "MSPAppUser";
-- GRANT SELECT, UPDATE, DELETE ON TABLE daily_tasks TO "MSPAppUser";