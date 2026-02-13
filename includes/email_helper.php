<?php
/**
 * Email Helper Utility
 * 
 * Provides functions to send HTML emails using PHPMailer
 * Requires PHPMailer to be installed via Composer
 */

require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using PHPMailer
 * 
 * @param string $to Email address of the recipient
 * @param string $subject Email subject
 * @param string $body HTML body content
 * @param string $toName Optional name of the recipient
 * @return bool True if email sent successfully, false otherwise
 */
function sendEmail($to, $subject, $body, $toName = '') {
    // Check if emails are enabled
    if (!defined('EMAIL_ENABLED') || !EMAIL_ENABLED) {
        logEmailError('Email sending is disabled');
        return false;
    }

    // Validate email address
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        logEmailError("Invalid email address: $to");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        if (defined('EMAIL_DEBUG') && EMAIL_DEBUG) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        
        // Security settings
        $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        
        // Authentication
        $auth = defined('SMTP_AUTH') ? SMTP_AUTH : true;
        if ($auth) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
        }

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

        $mail->send();
        logEmailLog("Email sent successfully to $to: $subject");
        return true;

    } catch (Exception $e) {
        logEmailError("Failed to send email to $to: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send email to multiple recipients
 * 
 * @param array $recipients Array of email addresses or ['email' => 'name'] pairs
 * @param string $subject Email subject
 * @param string $body HTML body content
 * @return int Number of emails sent successfully
 */
function sendEmailToMultiple($recipients, $subject, $body) {
    $sentCount = 0;
    
    foreach ($recipients as $email => $name) {
        // Handle both simple array ['email1', 'email2'] and associative ['email' => 'name']
        if (is_numeric($email)) {
            $email = $name;
            $name = '';
        }
        
        if (sendEmail($email, $subject, $body, $name)) {
            $sentCount++;
        }
    }
    
    return $sentCount;
}

/**
 * Get the HTML email template with header and footer
 * 
 * @param string $title Email title
 * @param string $content Main content HTML
 * @return string Complete HTML email template
 */
function getEmailTemplate($title, $content) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/mit';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-wrapper {
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .email-body {
            padding: 30px 20px;
        }
        .ticket-info {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .ticket-info h3 {
            margin-top: 0;
            color: #667eea;
        }
        .ticket-info p {
            margin: 8px 0;
        }
        .ticket-info strong {
            color: #555;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            margin: 20px 0;
            font-weight: 500;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .email-footer {
            background-color: #f4f4f4;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #888888;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-wrapper">
            <div class="email-header">
                <h1>{$title}</h1>
            </div>
            <div class="email-body">
                {$content}
            </div>
            <div class="email-footer">
                <p>This is an automated notification from MIT System.</p>
                <p>Please do not reply to this email.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Log email success
 */
function logEmailLog($message) {
    $logFile = __DIR__ . '/../logs/email.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] INFO: $message" . PHP_EOL, FILE_APPEND);
}

/**
 * Log email errors
 */
function logEmailError($message) {
    $logFile = __DIR__ . '/../logs/email_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] ERROR: $message" . PHP_EOL, FILE_APPEND);
}
