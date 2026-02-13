<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Check driver first just in case
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Driver: $driver\n";
    
    if ($driver == 'pgsql') {
        $sql = "
        CREATE TABLE IF NOT EXISTS certificates (
            id SERIAL PRIMARY KEY,
            user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            certificate_name VARCHAR(255) NOT NULL,
            certificate_type VARCHAR(100) NOT NULL,
            issuing_organization VARCHAR(255),
            issue_date DATE,
            expiry_date DATE,
            certificate_number VARCHAR(100),
            file_path VARCHAR(500),
            file_name VARCHAR(255),
            file_size INTEGER,
            mime_type VARCHAR(100),
            status VARCHAR(50) DEFAULT 'pending',
            approval_status VARCHAR(50) DEFAULT 'pending',
            approval_notes TEXT,
            rejection_reason TEXT,
            approved_by UUID REFERENCES users(id) ON DELETE SET NULL,
            approved_at TIMESTAMP,
            rejected_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        ";
    } else {
        // Fallback for MySQL if needed (but unlikely given config)
        $sql = "
        CREATE TABLE IF NOT EXISTS certificates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            certificate_name VARCHAR(255) NOT NULL,
            certificate_type VARCHAR(100) NOT NULL,
            issuing_organization VARCHAR(255),
            issue_date DATE,
            expiry_date DATE,
            certificate_number VARCHAR(100),
            file_path VARCHAR(500),
            file_name VARCHAR(255),
            file_size INT,
            mime_type VARCHAR(100),
            status VARCHAR(50) DEFAULT 'pending',
            approval_status VARCHAR(50) DEFAULT 'pending',
            approval_notes TEXT,
            rejection_reason TEXT,
            approved_by INT,
            approved_at TIMESTAMP NULL,
            rejected_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        );
        ";
    }
    
    $pdo->exec($sql);
    echo "Table 'certificates' created successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
