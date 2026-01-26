-- Fix permissions for daily_tasks table and sequence
-- Run this in pgAdmin 4

-- NOTE: PostgreSQL converts unquoted identifiers to lowercase by default
-- Check your actual role name first with: SELECT rolname FROM pg_roles WHERE rolname ILIKE '%msp%';

-- Grant usage on the sequence to the specific database user (likely lowercase)
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO mspappuser;

-- Grant all privileges on the table
GRANT ALL PRIVILEGES ON TABLE daily_tasks TO mspappuser;

-- If your role was created with quotes as "MSPAppUser", use these instead:
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
-- GRANT ALL PRIVILEGES ON TABLE daily_tasks TO "MSPAppUser";

-- Alternative: Grant to PUBLIC if needed
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO PUBLIC;
-- GRANT ALL PRIVILEGES ON TABLE daily_tasks TO PUBLIC;