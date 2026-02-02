<?php
// Debug the exact parameter count issue
echo "=== DEBUGGING PARAMETER COUNT ===\n\n";

// Extract the exact SQL from the PHP file
$sql_insert = "INSERT INTO staff_profiles 
                                    (user_id, staff_id, full_name, designation, department, employment_type,
                                     date_of_joining, reporting_manager_id, official_email, personal_email,
                                     phone_number, alternate_phone, emergency_contact_name, emergency_contact_number,
                                     current_address, permanent_address, national_id, passport_number, date_of_birth,
                                     gender, nationality, tax_id, work_permit_details, role_category, skills,
                                     certifications, experience_years, assigned_clients, service_area, shift_timing,
                                     on_call_support, username, role_level, system_access, company_laptop_issued,
                                     asset_serial_number, vpn_access, bank_name, account_number, salary_type,
                                     payment_method, employment_status, last_working_day, remarks, staff_signature_data,
                                     hr_approval_date, hr_manager_id, created_at, updated_at, photo_path, signature_path, documents) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)";

$sql_update = "UPDATE staff_profiles SET 
                                    staff_id = ?,
                                    full_name = ?, 
                                    designation = ?,
                                    department = ?,
                                    employment_type = ?,
                                    date_of_joining = ?,
                                    reporting_manager_id = ?,
                                    official_email = ?,
                                    personal_email = ?,
                                    phone_number = ?, 
                                    alternate_phone = ?,
                                    emergency_contact_name = ?,
                                    emergency_contact_number = ?,
                                    current_address = ?,
                                    permanent_address = ?,
                                    national_id = ?,
                                    passport_number = ?,
                                    date_of_birth = ?,
                                    gender = ?,
                                    nationality = ?,
                                    tax_id = ?,
                                    work_permit_details = ?,
                                    role_category = ?,
                                    skills = ?,
                                    certifications = ?,
                                    experience_years = ?,
                                    assigned_clients = ?,
                                    service_area = ?,
                                    shift_timing = ?,
                                    on_call_support = ?,
                                    username = ?,
                                    role_level = ?,
                                    system_access = ?,
                                    company_laptop_issued = ?,
                                    asset_serial_number = ?,
                                    vpn_access = ?,
                                    bank_name = ?,
                                    account_number = ?,
                                    salary_type = ?,
                                    payment_method = ?,
                                    employment_status = ?,
                                    last_working_day = ?,
                                    remarks = ?,
                                    hr_approval_date = ?,
                                    hr_manager_id = ?,
                                    updated_at = NOW()
                                    WHERE user_id = ?";

echo "INSERT Statement:\n";
echo "Placeholders (?): " . substr_count($sql_insert, '?') . "\n";

echo "\nUPDATE Statement:\n";
echo "Placeholders (?): " . substr_count($sql_update, '?') . "\n";

// Let's also check what parameters we're actually passing
echo "\n=== PARAMETERS BEING PASSED ===\n";

// Simulate the parameter array for INSERT (48 parameters)
$insert_params = [
    'user_id_value', 'staff_id_value', 'full_name_value', 'designation_value', 'department_value',
    'employment_type_value', 'date_of_joining_value', 'reporting_manager_id_value', 'official_email_value',
    'personal_email_value', 'phone_value', 'alternate_phone_value', 'emergency_contact_name_value',
    'emergency_contact_number_value', 'current_address_value', 'permanent_address_value', 'national_id_value',
    'passport_number_value', 'date_of_birth_value', 'gender_value', 'nationality_value', 'tax_id_value',
    'work_permit_details_value', 'role_category_value', 'skills_value', 'certifications_value',
    'experience_years_value', 'assigned_clients_value', 'service_area_value', 'shift_timing_value',
    'on_call_support_value', 'username_value', 'role_level_value', 'system_access_value',
    'company_laptop_issued_value', 'asset_serial_number_value', 'vpn_access_value', 'bank_name_value',
    'account_number_value', 'salary_type_value', 'payment_method_value', 'employment_status_value',
    'last_working_day_value', 'remarks_value', null, // staff_signature_data
    'hr_approval_date_value', 'hr_manager_id_value', null, null, null
];

echo "INSERT Parameters: " . count($insert_params) . "\n";

// Simulate the parameter array for UPDATE (48 parameters)
$update_params = [
    'staff_id_value', 'full_name_value', 'designation_value', 'department_value',
    'employment_type_value', 'date_of_joining_value', 'reporting_manager_id_value', 'official_email_value',
    'personal_email_value', 'phone_value', 'alternate_phone_value', 'emergency_contact_name_value',
    'emergency_contact_number_value', 'current_address_value', 'permanent_address_value', 'national_id_value',
    'passport_number_value', 'date_of_birth_value', 'gender_value', 'nationality_value', 'tax_id_value',
    'work_permit_details_value', 'role_category_value', 'skills_value', 'certifications_value',
    'experience_years_value', 'assigned_clients_value', 'service_area_value', 'shift_timing_value',
    'on_call_support_value', 'username_value', 'role_level_value', 'system_access_value',
    'company_laptop_issued_value', 'asset_serial_number_value', 'vpn_access_value', 'bank_name_value',
    'account_number_value', 'salary_type_value', 'payment_method_value', 'employment_status_value',
    'last_working_day_value', 'remarks_value', 'hr_approval_date_value', 'hr_manager_id_value',
    'user_id_value' // WHERE clause
];

echo "UPDATE Parameters: " . count($update_params) . "\n";

echo "\n=== VERIFICATION ===\n";
echo "INSERT - Placeholders: " . substr_count($sql_insert, '?') . " vs Parameters: " . count($insert_params) . " = " . (substr_count($sql_insert, '?') == count($insert_params) ? "MATCH" : "MISMATCH") . "\n";
echo "UPDATE - Placeholders: " . substr_count($sql_update, '?') . " vs Parameters: " . count($update_params) . " = " . (substr_count($sql_update, '?') == count($update_params) ? "MATCH" : "MISMATCH") . "\n";