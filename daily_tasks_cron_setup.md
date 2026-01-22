# Daily Tasks Module - Cron Job Setup

## Automatic Task Reset

The Daily Tasks module includes functionality to automatically carry forward incomplete tasks to the next day. This is accomplished through a cron job that runs daily.

## Cron Job Setup Instructions

### Linux/Unix Systems
Add the following line to your crontab (run `crontab -e`):

```bash
# Run daily task reset at 12:01 AM every day
1 0 * * * /usr/bin/php /path/to/your/project/scripts/reset_daily_tasks.php >> /var/log/daily_tasks_reset.log 2>&1
```

### Windows Systems
Use the Windows Task Scheduler to create a scheduled task:

1. Open Task Scheduler
2. Click "Create Basic Task"
3. Set trigger to "Daily" at 12:01 AM
4. Set action to "Start a program":
   - Program: `C:\path\to\php\php.exe`
   - Arguments: `C:\path\to\your\project\scripts\reset_daily_tasks.php`
   - Start in: `C:\path\to\your\project\scripts\`

## What the Cron Job Does

1. **Carries Forward Incomplete Tasks**: Moves incomplete tasks from the previous day to the current day
2. **Maintains History**: Marks original tasks as "carried_forward" for tracking purposes
3. **Cleans Up Old Data**: Removes completed tasks older than 30 days
4. **Prevents Duplicates**: Checks for existing tasks with the same title for the same user

## Functions Used

The cron job utilizes these functions from `includes/daily_task_functions.php`:

- `carryForwardIncompleteTasks()` - Moves incomplete tasks to the next day
- `cleanupOldTasks()` - Removes old completed tasks
- `resetDailyTasksForNewDay()` - Main function that orchestrates the reset process

## Logging

The script logs its operations to help with monitoring and debugging. The log includes:
- Number of tasks carried forward
- Number of old tasks cleaned up
- Timestamp of the operation
- Any errors that occurred

## Troubleshooting

If the cron job isn't working:

1. Verify the PHP path is correct
2. Check file permissions on the script
3. Ensure the web server user has database access
4. Review the log file for error messages