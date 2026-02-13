<?php
/**
 * Quick test to verify config loading
 */

require_once __DIR__ . '/config/email.php';

echo "NOTIFICATION_STALE_DAYS: " . (defined('NOTIFICATION_STALE_DAYS') ? NOTIFICATION_STALE_DAYS : 'NOT DEFINED') . "\n";
echo "NOTIFICATION_CLOSED_DAYS: " . (defined('NOTIFICATION_CLOSED_DAYS') ? NOTIFICATION_CLOSED_DAYS : 'NOT DEFINED') . "\n";
echo "BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "\n";
