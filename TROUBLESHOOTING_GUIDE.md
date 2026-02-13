# MSP Dashboard Blank Page Troubleshooting Guide

## 🔍 Problem Description
After successful login, you see a blank purple page with only the sidebar showing "MSP Portal" but no content in the main area.

## 🚀 Immediate Steps to Take

### 1. Run the Debug Script
First, access the debug script to get detailed information:
```
http://your-domain/mit/debug_dashboard.php
```

This will show you:
- PHP configuration and extensions
- File permissions and existence
- Session status and configuration
- Database connection status
- Detailed error information

### 2. Test with Minimal Dashboard
Access the minimal test dashboard:
```
http://your-domain/mit/test_minimal_dashboard.php
```

This will help determine if the issue is:
- **Framework-level**: If this works, the basic PHP/session setup is correct
- **Dashboard-specific**: If this fails, there's a fundamental issue with PHP/Apache

## 📋 Systematic Troubleshooting Steps

### Phase 1: PHP and Server Configuration

#### A. Check PHP Extensions
Your current PHP installation is missing `pdo_pgsql` extension:
```bash
# Check current extensions
php -m | findstr -i "pdo\|pgsql"

# You should see:
# pdo
# pdo_mysql
# pdo_pgsql  <- This is MISSING
```

**Fix:** Enable `pdo_pgsql` extension in WAMP:
1. Open WAMP menu
2. PHP → PHP Extensions
3. Find and enable `php_pdo_pgsql`
4. Restart Apache

#### B. Check Session Configuration
```bash
# Check session save path
php -r "echo session_save_path();"

# Should output: c:/wamp64/tmp
```

Verify permissions:
```bash
# Check if Apache can write to session directory
icacls "C:\wamp64\tmp"
```

#### C. Check Error Logs
```bash
# Apache error log
type "C:\wamp64\logs\apache_error.log"

# PHP error log (if configured)
type "C:\wamp64\logs\php_error.log"
```

### Phase 2: Database Connection Issues

#### A. Verify PostgreSQL Extension
The main issue appears to be missing PostgreSQL support:
```php
// Your config/database.php uses PostgreSQL:
$dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

// But your PHP only has MySQL support
```

**Solutions:**
1. **Enable PostgreSQL extension** (Recommended):
   - In WAMP: PHP → PHP Extensions → php_pdo_pgsql
   - Restart Apache

2. **Install PostgreSQL** if not installed:
   - Download from https://www.postgresql.org/download/windows/
   - Install with default settings
   - Note the port (usually 5432)

3. **Alternative: Switch to MySQL** (if acceptable):
   - Change DSN to: `$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME;`
   - Update database configuration accordingly

#### B. Test Database Connection Manually
```bash
# Test PostgreSQL connection
psql -h localhost -U MSPAppUser -d MSP_Application

# If PostgreSQL isn't installed, you'll get an error
```

### Phase 3: File and Permission Issues

#### A. Check File Permissions
```bash
# Check if Apache can read PHP files
icacls "C:\wamp64\www\mit\*.php"

# Should show BUILTIN\Users have (RX) read permissions
```

#### B. Verify Required Files Exist
Check these files exist and are readable:
- `includes/auth.php`
- `includes/header.php`
- `includes/sidebar.php`
- `includes/footer.php`
- `config/database.php`
- `dashboard.php`

### Phase 4: Browser-Side Debugging

#### A. Check Browser Console
1. Press F12 to open Developer Tools
2. Go to Console tab
3. Look for JavaScript errors
4. Check Network tab for failed requests (404, 500 errors)

#### B. Clear Browser Cache
1. Ctrl+Shift+Delete
2. Clear all cache and cookies
3. Try logging in again

#### C. Test in Incognito Mode
This eliminates browser extension interference.

## 🔧 Quick Fixes to Try

### Fix 1: Enable PostgreSQL Extension
1. Click WAMP icon in system tray
2. PHP → PHP Extensions
3. Scroll down and check `php_pdo_pgsql`
4. Restart Apache (click WAMP icon → Restart All Services)

### Fix 2: Test Database Connection
Create a simple test file:
```php
<?php
// test_db.php
try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=MSP_Application', 'MSPAppUser', '2q+w7wQMH8xd');
    echo "Database connection successful!";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
```

### Fix 3: Check Session Handling
Create a session test:
```php
<?php
// test_session.php
session_start();
$_SESSION['test'] = 'working';
echo "Session ID: " . session_id() . "<br>";
echo "Session data: ";
print_r($_SESSION);
?>
```

## 📊 Expected Behavior vs Actual Behavior

### Working System Should Show:
1. **Login Page** → Successful authentication
2. **Dashboard** → Purple background with sidebar and main content area
3. **Sidebar** → Navigation menu with user info
4. **Main Content** → Dashboard statistics and widgets

### Current Issue:
- ✅ Login works (authentication successful)
- ✅ Sidebar appears (basic HTML/CSS working)
- ❌ Main content area is blank (PHP execution or database issue)

## 🎯 Most Likely Causes

Based on your setup, the most probable causes are:

1. **Missing pdo_pgsql extension** (High probability)
2. **PostgreSQL service not running** (Medium probability)
3. **Database connection configuration** (Medium probability)
4. **Session handling issues** (Low probability)
5. **File permission problems** (Low probability)

## 🛠️ Step-by-Step Resolution Process

### Step 1: Enable PostgreSQL Extension
1. Open WAMP menu
2. PHP → PHP Extensions
3. Find and enable `php_pdo_pgsql`
4. Restart Apache services
5. Test: `http://your-domain/mit/test_minimal_dashboard.php`

### Step 2: Verify PostgreSQL Service
1. Open Services (services.msc)
2. Look for "postgresql-x64-XX"
3. Ensure it's running
4. If not installed, download and install PostgreSQL

### Step 3: Test Database Connection
1. Run the debug script: `http://your-domain/mit/debug_dashboard.php`
2. Check the database section for connection status
3. If failing, verify credentials in `config/database.php`

### Step 4: Check Apache Error Logs
1. Open `C:\wamp64\logs\apache_error.log`
2. Look for recent errors after login attempts
3. Common errors:
   - "Class 'PDO' not found" (missing PDO extension)
   - "could not find driver" (missing pdo_pgsql)
   - "Connection refused" (PostgreSQL not running)

### Step 5: Browser Console Debugging
1. Press F12 after login
2. Check Console tab for errors
3. Check Network tab for failed requests
4. Look for 500 Internal Server Error responses

## 🆘 Emergency Workaround

If you need immediate access while troubleshooting:

1. **Create a simple dashboard alternative:**
   ```php
   <?php
   // simple_dashboard.php
   session_start();
   if (!isset($_SESSION['user_id'])) {
       header('Location: /mit/login');
       exit;
   }
   ?>
   <h1>Simple Dashboard</h1>
   <p>Welcome, <?php echo $_SESSION['email']; ?>!</p>
   <a href="/mit/logout">Logout</a>
   ```

2. **Use the test dashboard** as your temporary interface

## 📞 When to Seek Further Help

Contact support if:
- Enabling pdo_pgsql doesn't resolve the issue
- PostgreSQL installation fails
- You see specific error messages in logs
- The debug script shows unexpected configuration issues

Provide:
1. Output from `debug_dashboard.php`
2. Apache error log contents
3. Browser console errors
4. Results of the troubleshooting steps above

## 📝 Quick Reference Commands

```bash
# Check PHP version and extensions
php -v
php -m | findstr -i "pdo\|pgsql"

# Check session configuration
php -r "echo session_save_path();"

# Check file permissions
icacls "C:\wamp64\www\mit"

# Check Apache status
netstat -an | findstr :80

# Check PostgreSQL service
sc query postgresql-x64-14

# View Apache error logs
type "C:\wamp64\logs\apache_error.log"
```

This systematic approach should help you identify and resolve the blank page issue.