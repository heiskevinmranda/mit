<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Test the INSERT statement with a prepared statement
    $sql = "INSERT INTO staff_profiles 
                                    (user_id, staff_id, full_name, designation, department, employment_type,
                                     date_of_joining, reporting_manager_id, official_email, personal_email,
                                     phone_number, alternate_phone, emergency_contact_name, emergency_contact_number,
                                     current_address, permanent_address, national_id, passport_number, date_of_birth,
                                     gender, nationality, tax_id, work_permit_details, role_category, skills,
                                     certifications, experience_years, assigned_clients, service_area, shift_timing,
                                     on_call_support, username, role_level, system_access, company_laptop_issued,
                                     asset_serial_number, vpn_access, bank_name, account_number, salary_type,
                                     payment_method, employment_status, last_working_day, remarks, staff_signature_data,
                                     hr_approval_date, hr_manager_id, photo_path, signature_path, documents) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    echo "Prepare successful\n";
    echo "Placeholders: " . substr_count($sql, '?') . "\n";
    
    // Try to execute with dummy data
    $result = $stmt->execute([
        1, 'STAFF202602020001', 'Test User', 'Test Designation', 'Test Department', 'Full-time',
        '2023-01-01', 1, 'test@example.com', 'test2@example.com',
        '1234567890', '0987654321', 'Emergency Contact', '1112223333',
        'Current Address', 'Permanent Address', '123456789', 'P12345678', '1990-01-01',
        'Male', 'Test Nationality', 'T123456789', 'Work Permit Details', 'Role Category', 'Skills',
        'Certifications', 5, 'Assigned Clients', 'Service Area', 'Shift Timing',
        1, 'testuser', 'Role Level', 'System Access', 1,
        'AS123456', 1, 'Bank Name', '1234567890', 'Salary Type',
        'Payment Method', 'Active', '2024-12-31', 'Remarks', null,
        '2024-01-01', 1, null, null, null
    ]);
    
    echo "Execute result: " . ($result ? "Success" : "Failure") . "\n";
    
    if (!$result) {
        echo "Error info: ";
        print_r($stmt->errorInfo());
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}