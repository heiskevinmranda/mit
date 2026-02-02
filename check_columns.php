<?php
// Extract the column list from the INSERT statement
$columns = [
    'user_id', 'staff_id', 'full_name', 'designation', 'department', 'employment_type',
    'date_of_joining', 'reporting_manager_id', 'official_email', 'personal_email',
    'phone_number', 'alternate_phone', 'emergency_contact_name', 'emergency_contact_number',
    'current_address', 'permanent_address', 'national_id', 'passport_number', 'date_of_birth',
    'gender', 'nationality', 'tax_id', 'work_permit_details', 'role_category', 'skills',
    'certifications', 'experience_years', 'assigned_clients', 'service_area', 'shift_timing',
    'on_call_support', 'username', 'role_level', 'system_access', 'company_laptop_issued',
    'asset_serial_number', 'vpn_access', 'bank_name', 'account_number', 'salary_type',
    'payment_method', 'employment_status', 'last_working_day', 'remarks', 'staff_signature_data',
    'hr_approval_date', 'hr_manager_id', 'photo_path', 'signature_path', 'documents',
    'created_at', 'updated_at'
];

echo "Total columns listed: " . count($columns) . "\n";
echo "Unique columns: " . count(array_unique($columns)) . "\n";

// Check for duplicates
$duplicates = array();
foreach (array_count_values($columns) as $val => $c) {
    if ($c > 1) $duplicates[] = $val;
}
if (!empty($duplicates)) {
    echo "Duplicates found: " . implode(', ', $duplicates) . "\n";
} else {
    echo "No duplicates found\n";
}

// Compare with actual table structure
$actual_columns = [
    'id', 'user_id', 'staff_id', 'full_name', 'designation', 'department', 'employment_type',
    'date_of_joining', 'reporting_manager_id', 'official_email', 'personal_email',
    'phone_number', 'alternate_phone', 'emergency_contact_name', 'emergency_contact_number',
    'current_address', 'permanent_address', 'national_id', 'passport_number', 'date_of_birth',
    'gender', 'nationality', 'tax_id', 'work_permit_details', 'role_category', 'skills',
    'certifications', 'experience_years', 'assigned_clients', 'service_area', 'shift_timing',
    'on_call_support', 'username', 'role_level', 'system_access', 'company_laptop_issued',
    'asset_serial_number', 'vpn_access', 'bank_name', 'account_number', 'salary_type',
    'payment_method', 'employment_status', 'last_working_day', 'remarks', 'staff_signature_data',
    'hr_approval_date', 'hr_manager_id', 'created_at', 'updated_at', 'photo_path', 'signature_path', 'documents'
];

echo "\nActual table columns: " . count($actual_columns) . "\n";

// Find missing columns
$missing = array_diff($actual_columns, $columns);
echo "Columns in table but not in INSERT: " . implode(', ', $missing) . "\n";

// Find extra columns  
$extra = array_diff($columns, $actual_columns);
echo "Columns in INSERT but not in table: " . implode(', ', $extra) . "\n";