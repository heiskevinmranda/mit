<?php
$cols = ['user_id', 'staff_id', 'full_name', 'designation', 'department', 'employment_type', 'date_of_joining', 'reporting_manager_id', 'official_email', 'personal_email', 'phone_number', 'alternate_phone', 'emergency_contact_name', 'emergency_contact_number', 'current_address', 'permanent_address', 'national_id', 'passport_number', 'date_of_birth', 'gender', 'nationality', 'tax_id', 'work_permit_details', 'role_category', 'skills', 'certifications', 'experience_years', 'assigned_clients', 'service_area', 'shift_timing', 'on_call_support', 'username', 'role_level', 'system_access', 'company_laptop_issued', 'asset_serial_number', 'vpn_access', 'bank_name', 'account_number', 'salary_type', 'payment_method', 'employment_status', 'last_working_day', 'remarks', 'staff_signature_data', 'hr_approval_date', 'hr_manager_id', 'photo_path', 'signature_path', 'documents'];

$duplicates = array();
foreach (array_count_values($cols) as $col => $count) {
    if ($count > 1) {
        $duplicates[] = $col;
    }
}

if (!empty($duplicates)) {
    echo "Duplicates found: " . implode(', ', $duplicates) . "\n";
} else {
    echo "No duplicates found\n";
}

echo "Total columns: " . count($cols) . "\n";