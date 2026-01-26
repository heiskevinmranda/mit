# Fix for Daily Tasks Permission Error

## Problem Description

When trying to create daily tasks, you may encounter the following error:

```
SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied for sequence daily_tasks_id_seq
```

## Root Cause

This error occurs because the database user (`MSPAppUser`) does not have the necessary privileges to access the `daily_tasks_id_seq` sequence. In PostgreSQL, when you create a table with a `SERIAL` column (which creates an auto-incrementing primary key), PostgreSQL automatically creates a sequence object to manage the auto-increment values. The database user needs specific permissions to use this sequence.

## Solution

First, identify the correct case of your database role by running the check script:

```sql
-- Run this to identify existing roles
SELECT rolname FROM pg_roles WHERE rolname ILIKE '%msp%';
```

Based on your query result showing 'MSPAppUser' as the role name, run the following SQL commands in your database management tool (like pgAdmin). Since your role name appears with mixed case, you need to use double quotes to preserve the case:

```sql
-- Using quoted identifier to preserve case
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
-- Grant specific privileges including SELECT (needed for viewing tasks)
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE daily_tasks TO "MSPAppUser";
```

If you're still experiencing issues, you can first verify the sequence exists:

```sql
SELECT sequence_name FROM information_schema.sequences WHERE sequence_name = 'daily_tasks_id_seq';
```

If the sequence doesn't exist, you may need to recreate the daily_tasks table with the SERIAL column.

**Note:** The 'SELECT' permission is crucial for viewing tasks. If you can create tasks but not see them, it's likely because the SELECT permission is missing.

Alternatively, you can use the provided SQL script:

```bash
psql -d MSP_Application -U postgres -f check_database_roles.sql
psql -d MSP_Application -U postgres -f fix_daily_tasks_permissions_corrected.sql
```

## Files Related to This Fix

- `fix_daily_tasks_permissions.sql` - Contains the SQL commands to fix the permissions
- `grant_sequence_privileges.sql` - Alternative script with more granular permissions
- `includes/daily_task_functions.php` - Contains the daily task functionality
- `ajax/create_daily_task.php` - Handles AJAX requests for creating daily tasks

## Prevention

This type of permission error commonly occurs when:

1. Creating new tables with SERIAL columns
2. Using database users with limited privileges
3. Setting up the application in a new environment

Always ensure that your application database user has the necessary sequence privileges when using auto-incrementing columns.