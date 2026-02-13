@echo off
REM Ticket Notification Cron Job Batch File
REM Run this daily using Windows Task Scheduler
REM 
REM To set up Task Scheduler, run:
REM schtasks /create /tn "MIT Ticket Notifications" /tr "c:\wamp64\www\mit\cron\run_notifications.bat" /sc daily /st 09:00

echo Starting Ticket Notification Cron Job...
echo.

c:\wamp64\bin\php\php8.4.15\php.exe c:\wamp64\www\mit\cron\check_ticket_notifications.php

echo.
echo Ticket Notification Cron Job Complete.
echo.

REM Exit with the same code as the PHP script
exit /b %ERRORLEVEL%
