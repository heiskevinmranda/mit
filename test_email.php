<?php
/**
 * Test Email Script
 * Use this to verify email configuration is working
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/email.php';
require_once __DIR__ . '/includes/email_helper.php';

echo "=== Email Configuration Test ===\n\n";

// Display configuration (without password)
echo "SMTP Host: " . SMTP_HOST . "\n";
echo "SMTP Port: " . SMTP_PORT . "\n";
echo "SMTP Username: " . SMTP_USERNAME . "\n";
echo "SMTP From Email: " . SMTP_FROM_EMAIL . "\n";
echo "SMTP From Name: " . SMTP_FROM_NAME . "\n";
echo "SMTP Secure: " . (defined('SMTP_SECURE') ? SMTP_SECURE : 'tls') . "\n";
echo "Email Enabled: " . (defined('EMAIL_ENABLED') && EMAIL_ENABLED ? 'Yes' : 'No') . "\n";
echo "Base URL: " . (defined('BASE_URL') ? BASE_URL : 'Not set') . "\n\n";

echo "Sending test email...\n\n";

$testEmail = 'kevin@flashnet.co.tz';
$testName = 'Kevin';

$subject = 'Test Email - MIT Ticket Notification System';

$content = "
    <p>Hello <strong>{$testName}</strong>,</p>
    
    <p>This is a test email to verify that the MIT Ticket Notification System email configuration is working correctly.</p>
    
    <div class='ticket-info'>
        <h3>Email Test Successful!</h3>
        <p><strong>Test Date:</strong> " . date('F j, Y, g:i a') . "</p>
        <p><strong>SMTP Host:</strong> " . SMTP_HOST . "</p>
        <p><strong>SMTP Port:</strong> " . SMTP_PORT . "</p>
    </div>
    
    <p>If you received this email, the email system is configured correctly!</p>
    
    <p>You will receive notifications about stale tickets in the future.</p>
";

$body = getEmailTemplate('MIT System - Test Email', $content);

$result = sendEmail($testEmail, $subject, $body, $testName);

if ($result) {
    echo "SUCCESS: Test email sent successfully to {$testEmail}\n";
    echo "Please check your inbox (and spam folder) for the test email.\n";
} else {
    echo "FAILED: Could not send test email.\n";
    echo "Please check the email error logs at: logs/email_errors.log\n";
}

echo "\n=== Test Complete ===\n";
