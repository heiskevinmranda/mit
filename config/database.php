<?php
// config/database.php

// Database configuration
$DB_HOST = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'localhost';
$DB_PORT = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '5432';
$DB_NAME = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? 'MSP_Application';
$DB_USER = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? 'MSPAppUser';
$DB_PASS = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? '2q+w7wQMH8xd';

define('DB_HOST', $DB_HOST);
define('DB_PORT', $DB_PORT);
define('DB_NAME', $DB_NAME);
define('DB_USER', $DB_USER);
define('DB_PASS', $DB_PASS);

// Create connection
function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}
?>