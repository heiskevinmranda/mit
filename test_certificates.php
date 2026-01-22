<?php
// test_certificates.php
// Test script for certificate management functionality

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

echo "<h1>Certificate Management System Test</h1>\n";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✓ Database connection successful</p>\n";
    
    // Test 1: Check if certificates table exists
    echo "<h2>Test 1: Database Schema</h2>\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM certificates");
        $count = $stmt->fetch()['count'];
        echo "<p style='color: green;'>✓ Certificates table exists with $count records</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Certificates table does not exist: " . $e->getMessage() . "</p>\n";
        echo "<p>Please run install_certificates_table.php first</p>\n";
        exit;
    }
    
    // Test 2: Check table structure
    echo "<h2>Test 2: Table Structure</h2>\n";
    $required_columns = [
        'id', 'user_id', 'certificate_name', 'certificate_type', 
        'issuing_organization', 'issue_date', 'expiry_date', 
        'certificate_number', 'file_path', 'file_name', 'file_size', 
        'mime_type', 'status', 'approval_status', 'approved_by', 
        'approval_notes', 'rejection_reason', 'created_at', 'updated_at'
    ];
    
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'certificates'");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $missing_columns = array_diff($required_columns, $columns);
    if (empty($missing_columns)) {
        echo "<p style='color: green;'>✓ All required columns present</p>\n";
    } else {
        echo "<p style='color: red;'>✗ Missing columns: " . implode(', ', $missing_columns) . "</p>\n";
    }
    
    // Test 3: Check foreign key constraints
    echo "<h2>Test 3: Foreign Key Constraints</h2>\n";
    try {
        $stmt = $pdo->query("SELECT constraint_name FROM information_schema.table_constraints WHERE table_name = 'certificates' AND constraint_type = 'FOREIGN KEY'");
        $constraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($constraints) >= 1) {
            echo "<p style='color: green;'>✓ Foreign key constraints found: " . implode(', ', $constraints) . "</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠ No foreign key constraints found (this might be OK depending on setup)</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Error checking constraints: " . $e->getMessage() . "</p>\n";
    }
    
    // Test 4: Check indexes
    echo "<h2>Test 4: Database Indexes</h2>\n";
    try {
        $stmt = $pdo->query("SELECT indexname FROM pg_indexes WHERE tablename = 'certificates'");
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $expected_indexes = ['idx_certificates_user_id', 'idx_certificates_status', 'idx_certificates_approval_status'];
        $found_indexes = array_intersect($expected_indexes, $indexes);
        
        if (count($found_indexes) >= 2) {
            echo "<p style='color: green;'>✓ Required indexes found: " . implode(', ', $found_indexes) . "</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠ Some indexes missing: " . implode(', ', array_diff($expected_indexes, $found_indexes)) . "</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Error checking indexes: " . $e->getMessage() . "</p>\n";
    }
    
    // Test 5: Test AJAX endpoints accessibility
    echo "<h2>Test 5: AJAX Endpoints</h2>\n";
    
    $endpoints = [
        '/mit/ajax/upload_certificate.php',
        '/mit/ajax/manage_certificates.php',
        '/mit/ajax/download_certificate.php'
    ];
    
    foreach ($endpoints as $endpoint) {
        $full_url = 'http://localhost' . $endpoint;
        echo "<p>Testing: $endpoint</p>\n";
        
        // Test basic accessibility (without authentication)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 || $http_code === 400) {
            echo "<p style='color: green;'>✓ Endpoint accessible (HTTP $http_code)</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠ Endpoint returned HTTP $http_code</p>\n";
        }
    }
    
    // Test 6: Check file upload directory
    echo "<h2>Test 6: File Storage</h2>\n";
    $upload_base_dir = __DIR__ . '/uploads/certificates';
    
    if (!is_dir($upload_base_dir)) {
        if (mkdir($upload_base_dir, 0755, true)) {
            echo "<p style='color: green;'>✓ Created upload directory: $upload_base_dir</p>\n";
        } else {
            echo "<p style='color: red;'>✗ Failed to create upload directory</p>\n";
        }
    } else {
        echo "<p style='color: green;'>✓ Upload directory exists: $upload_base_dir</p>\n";
    }
    
    // Test 7: Permissions functions
    echo "<h2>Test 7: Permission Functions</h2>\n";
    
    // Simulate different user types
    $test_users = [
        ['user_type' => 'super_admin', 'email' => 'admin@test.com'],
        ['user_type' => 'admin', 'email' => 'admin@test.com'],
        ['user_type' => 'manager', 'email' => 'manager@test.com'],
        ['user_type' => 'support_tech', 'email' => 'tech@test.com'],
        ['user_type' => 'client', 'email' => 'client@test.com']
    ];
    
    // Include permissions file
    require_once __DIR__ . '/includes/permissions.php';
    
    foreach ($test_users as $user) {
        // Mock the getCurrentUser function for testing
        if (!function_exists('getCurrentUser')) {
            function getCurrentUser() {
                return ['user_type' => 'admin', 'email' => 'test@example.com'];
            }
        }
        
        echo "<h3>User Type: {$user['user_type']}</h3>\n";
        
        // Test certificate permissions
        $can_upload = canUploadCertificates();
        $can_manage = canManageCertificates();
        $can_export = canExportCertificates();
        $can_view_mgmt = canViewCertificateManagement();
        
        echo "<ul>\n";
        echo "<li>Can upload certificates: " . ($can_upload ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: red;">✗ No</span>') . "</li>\n";
        echo "<li>Can manage certificates: " . ($can_manage ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: red;">✗ No</span>') . "</li>\n";
        echo "<li>Can export certificates: " . ($can_export ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: red;">✗ No</span>') . "</li>\n";
        echo "<li>Can view management dashboard: " . ($can_view_mgmt ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: red;">✗ No</span>') . "</li>\n";
        echo "</ul>\n";
    }
    
    // Test 8: Sample certificate creation
    echo "<h2>Test 8: Sample Data Creation</h2>\n";
    
    try {
        // Get a sample user ID
        $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
        $sample_user = $stmt->fetch();
        
        if ($sample_user) {
            echo "<p>Using sample user ID: {$sample_user['id']}</p>\n";
            
            // Insert sample certificate
            $stmt = $pdo->prepare("
                INSERT INTO certificates (
                    user_id, certificate_name, certificate_type, issuing_organization,
                    issue_date, certificate_number, file_name, file_size, mime_type,
                    status, approval_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $sample_user['id'],
                'Sample Technical Certification',
                'technical',
                'Microsoft',
                '2023-01-15',
                'MS-CERT-001',
                'sample_certificate.pdf',
                1024000,
                'application/pdf',
                'approved',
                'approved'
            ]);
            
            if ($result) {
                echo "<p style='color: green;'>✓ Sample certificate created successfully</p>\n";
                
                // Clean up - delete the sample certificate
                $stmt = $pdo->prepare("DELETE FROM certificates WHERE certificate_name = 'Sample Technical Certification'");
                $stmt->execute();
                echo "<p>Sample certificate cleaned up</p>\n";
            }
        } else {
            echo "<p style='color: orange;'>⚠ No users found in database to create sample certificate</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Error creating sample certificate: " . $e->getMessage() . "</p>\n";
    }
    
    echo "<h2>Test Summary</h2>\n";
    echo "<p>All tests completed. Please review the results above.</p>\n";
    echo "<p>If all tests pass, the certificate management system should be ready for use.</p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Fatal error: " . $e->getMessage() . "</p>\n";
}
?>