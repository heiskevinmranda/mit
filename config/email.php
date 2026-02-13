<?php
/**
 * Email Configuration
 * 
 * Configure SMTP settings for sending ticket notification emails
 * Update these values with your actual SMTP server details
 */

// SMTP Server Settings
define('SMTP_HOST', 'smtppro.zoho.com');      // Zoho SMTP server
define('SMTP_PORT', 465);                     // SSL Port
define('SMTP_USERNAME', 'jobi@flashnet.co.tz'); // Full email address
define('SMTP_PASSWORD', 'EE67tJFXP45n');  // Replace with actual password or app password
define('SMTP_FROM_EMAIL', 'jobi@flashnet.co.tz'); // From email address
define('SMTP_FROM_NAME', 'MSP Portal');   // Sender name shown in emails

// SMTP Security
define('SMTP_SECURE', 'ssl');                  // 'ssl' for port 465, 'tls' for port 587
define('SMTP_AUTH', true);                      // Enable SMTP authentication

// Email Settings
define('EMAIL_ENABLED', true);                  // Set to false to disable all emails
define('EMAIL_DEBUG', false);                   // Set to true for debug output

// Notification Settings
define('NOTIFICATION_STALE_DAYS', 2);           // Days before sending stale update reminder
define('NOTIFICATION_CLOSED_DAYS', 5);          // Days before sending stale close reminder
define('NOTIFICATION_FROM_HOUR', 9);            // Start sending notifications from this hour (24h format)
define('NOTIFICATION_TO_HOUR', 18);            // Stop sending notifications after this hour (24h format)

// Base URL for ticket links in emails
define('BASE_URL', 'https://ahead.msp.co.tz/mit');    // Update to your actual domain

/**
 * Get the email configuration as an array
 * @return array Email configuration
 */
function getEmailConfig() {
    return [
        'host' => SMTP_HOST,
        'port' => SMTP_PORT,
        'username' => SMTP_USERNAME,
        'password' => SMTP_PASSWORD,
        'from_email' => SMTP_FROM_EMAIL,
        'from_name' => SMTP_FROM_NAME,
        'secure' => SMTP_SECURE ?? 'tls',
        'auth' => SMTP_AUTH ?? true,
        'enabled' => EMAIL_ENABLED ?? true,
        'debug' => EMAIL_DEBUG ?? false
    ];
}
