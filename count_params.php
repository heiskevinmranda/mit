<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // Get actual table structure
    $stmt = $pdo->query("SELECT column_name, ordinal_position FROM information_schema.columns WHERE table_name = 'staff_profiles' ORDER BY ordinal_position");
    
    echo "Actual staff_profiles table columns:\n";
    $columns = [];
    while ($row = $stmt->fetch()) {
        echo $row['ordinal_position'] . ". " . $row['column_name'] . "\n";
        $columns[] = $row['column_name'];
    }
    
    echo "\nTotal columns: " . count($columns) . "\n";
    
    // Check our INSERT statement
    $insert_columns = [
        'user_id', 'staff_id', 'full_name', 'designation', 'department', 'employment_type',
        'date_of_joining', 'reporting_manager_id', 'official_email', 'personal_email',
        'phone_number', 'alternate_phone', 'emergency_contact_name', 'emergency_contact_number',
        'current_address', 'permanent_address', 'national_id', 'passport_number', 'date_of_birth',
        'gender', 'nationality', 'tax_id', 'work_permit_details', 'role_category', 'skills',
        'certifications', 'experience_years', 'assigned_clients', 'service_area', 'shift_timing',
        'on_call_support', 'username', 'role_level', 'system_access', 'company_laptop_issued',
        'asset_serial_number', 'vpn_access', 'bank_name', 'account_number', 'salary_type',
        'payment_method', 'employment_status', 'last_working_day', 'remarks', 'staff_signature_data',
        'hr_approval_date', 'hr_manager_id', 'created_at', 'updated_at'
    ];
    
    echo "\nOur INSERT columns: " . count($insert_columns) . "\n";
    
    // Check for mismatches
    $missing = array_diff($insert_columns, $columns);
    $extra = array_diff($columns, $insert_columns);
    
    if (!empty($missing)) {
        echo "\nMissing columns in our INSERT: " . implode(', ', $missing) . "\n";
    }
    
    if (!empty($extra)) {
        echo "\nExtra columns in table: " . implode(', ', $extra) . "\n";
    }
    
    if (empty($missing) && empty($extra)) {
        echo "\nColumn lists match!\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}