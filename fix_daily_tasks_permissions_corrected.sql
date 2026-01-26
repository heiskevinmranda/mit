-- Fix permissions for daily_tasks table and sequence
-- Run this in pgAdmin 4
-- Note: PostgreSQL converts unquoted identifiers to lowercase by default

-- Check if the role exists first by running: \du or use the query from check_database_roles.sql

-- Option 1: If the role exists as 'mspappuser' (lowercase - most common scenario)
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO mspappuser;

-- Option 2: If the role was created with quotes as 'MSPAppUser', use quotes:
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";

-- Grant all privileges on the table
GRANT ALL PRIVILEGES ON TABLE daily_tasks TO mspappuser;

-- Option 2 alternative for quoted user:
-- GRANT ALL PRIVILEGES ON TABLE daily_tasks TO "MSPAppUser";

-- If you're unsure of the role name, first run the check_database_roles.sql script to identify it