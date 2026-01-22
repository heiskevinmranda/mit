-- Fix permissions for daily_tasks table and sequence
-- Run this in pgAdmin 4

-- Grant usage on the sequence
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO PUBLIC;

-- Grant all privileges on the table
GRANT ALL PRIVILEGES ON TABLE daily_tasks TO PUBLIC;

-- Alternative: Grant to specific user (replace 'your_username' with actual username)
-- GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO your_username;
-- GRANT ALL PRIVILEGES ON TABLE daily_tasks TO your_username;