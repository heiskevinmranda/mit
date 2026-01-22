# Daily Meeting Minutes & User Tasks Module

## Overview
The Daily Meeting Minutes & User Tasks module allows managers to assign daily tasks to users, with role-based access controls and automatic task resetting.

## Features
- **Role-based Access Control:**
  - Super Admin: Can see/edit all tasks for all users
  - Admin: Can see/edit all tasks for all users
  - Manager: Can create tasks for users, see all tasks of assigned users
  - Support Tech: Can only see tasks assigned to them

- **Task Management:**
  - Create, edit, view, and delete daily tasks
  - Assign tasks with priorities (Low, Medium, High, Urgent)
  - Set due times for tasks
  - Track task status (Pending, In Progress, Completed, Cancelled)

- **Dashboard Integration:**
  - Shows today's tasks on user dashboards
  - Visual indicators for task completion rates
  - Quick access to task management

- **Automatic Reset:**
  - Incomplete tasks from previous days are automatically carried forward
  - Completed tasks older than 30 days are cleaned up

## Cron Job Setup
To enable automatic task resetting, set up a daily cron job to run:
```
php /path/to/your/project/scripts/reset_daily_tasks.php
```

## Database Schema
The module creates a `daily_tasks` table with the following fields:
- id: Unique identifier
- task_date: Date of the task (defaults to current date)
- assigned_to: User ID of the person assigned to the task
- assigned_by: User ID of the person who assigned the task
- task_title: Title of the task
- task_description: Detailed description of the task
- task_status: Current status of the task
- priority: Priority level of the task
- due_time: Due time for the task
- timestamps: Creation, update, and completion times

## Files Included
- `includes/daily_task_functions.php` - Core functions for task management
- `pages/daily_tasks/` - CRUD operations for daily tasks
- `scripts/reset_daily_tasks.php` - Cron job script for task reset
- Updated `dashboard.php` - Integration with user dashboards