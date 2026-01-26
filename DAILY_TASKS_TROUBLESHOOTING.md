# Daily Tasks Troubleshooting Guide

## Issue: Can Create Tasks But Cannot View Them

### Symptoms
- Successfully create daily tasks
- When clicking "View Today's Tasks" or similar, no tasks appear
- No error messages are shown

### Root Causes and Solutions

#### 1. Missing SELECT Permissions
The most common cause is that the database user has INSERT permissions but lacks SELECT permissions.

**Solution:**
Ensure the user has SELECT permissions on the daily_tasks table:
```sql
GRANT SELECT ON TABLE daily_tasks TO "MSPAppUser";
```

#### 2. Date Mismatch
Tasks might have been created with a different date than what the view is querying.

**Diagnosis:**
Check if tasks exist in the database:
```sql
SELECT * FROM daily_tasks WHERE task_date = CURRENT_DATE;
SELECT * FROM daily_tasks; -- See all tasks regardless of date
```

#### 3. Column Structure Issues
The table might have been created differently than expected.

**Diagnosis:**
Check the table structure:
```sql
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name = 'daily_tasks'
ORDER BY ordinal_position;
```

#### 4. Authentication/Authorization Issues
The view might be filtered based on user permissions.

**Diagnosis:**
Check if the user can access the raw data directly:
```sql
SELECT * FROM daily_tasks WHERE task_date = CURRENT_DATE;
```

### Debugging Steps

1. **Run the diagnostic script:**
```bash
psql -d MSP_Application -U postgres -f check_daily_tasks_data.sql
```

2. **Verify all required permissions are granted:**
```sql
-- Required permissions for full functionality
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE daily_tasks TO "MSPAppUser";
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";
```

3. **Test the AJAX endpoint directly:**
Open your browser's developer tools and navigate to:
`/ajax/get_daily_tasks.php`
Check the response in the Network tab to see if data is being returned.

4. **Check browser console for JavaScript errors:**
Press F12 and look for any JavaScript errors that might prevent the tasks from displaying.

### Common Fixes

#### Complete Permission Grant:
```sql
-- For the sequence (needed for creating tasks)
GRANT USAGE, SELECT ON SEQUENCE daily_tasks_id_seq TO "MSPAppUser";

-- For the table (needed for all operations including viewing)
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE daily_tasks TO "MSPAppUser";
```

#### Check if records exist:
```sql
-- Check if there are any records at all
SELECT COUNT(*) FROM daily_tasks;

-- Check if there are records for today specifically
SELECT COUNT(*) FROM daily_tasks WHERE task_date = CURRENT_DATE;
```

### Verification

After applying fixes:
1. Refresh the dashboard
2. Click "View Today's Tasks" again
3. Check browser console for errors
4. Verify the AJAX call in Network tab returns data