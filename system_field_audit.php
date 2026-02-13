<?php
// Comprehensive System Field Audit Script
// Checks all database tables and compares with form fields in the application

require_once 'config/database.php';

$pdo = getDBConnection();

echo "=== SYSTEM FIELD AUDIT REPORT ===\n";
echo "Generated on: " . date('Y-m-d H:i:s') . "\n\n";

// Define expected tables and their fields based on the application
$tables_config = [
    'tickets' => [
        'required_fields' => [
            'id' => 'uuid',
            'ticket_number' => 'character varying(100)',
            'title' => 'character varying(255)',
            'description' => 'text',
            'client_id' => 'uuid',
            'location_id' => 'uuid',
            'category' => 'character varying(100)',
            'priority' => 'character varying(20)',
            'status' => 'character varying(50)',
            'created_by' => 'uuid',
            'created_at' => 'timestamp without time zone',
            'updated_at' => 'timestamp without time zone',
            // Recently added fields
            'requested_by' => 'character varying(255)',
            'requested_by_email' => 'character varying(255)',
            'csr_sn' => 'character varying(255)',
            'pi_number' => 'character varying(255)',
            'location_manual' => 'character varying(255)',
            'estimated_hours' => 'numeric',
            'work_start_time' => 'timestamp without time zone',
            'work_end_time' => 'timestamp without time zone',
            'total_work_hours' => 'numeric',
            'assigned_to' => 'uuid',
            'closed_at' => 'timestamp without time zone',
            'sla_start_time' => 'timestamp without time zone',
            'sla_breach_time' => 'timestamp without time zone',
            'actual_response_time' => 'integer',
            'actual_resolution_time' => 'integer',
            'client_feedback' => 'text',
            'rating' => 'integer'
        ]
    ],
    'clients' => [
        'required_fields' => [
            'id' => 'uuid',
            'company_name' => 'character varying(255)',
            'contact_person' => 'character varying(255)',
            'email' => 'character varying(255)',
            'phone' => 'character varying(50)',
            'address' => 'text',
            'city' => 'character varying(100)',
            'state' => 'character varying(100)',
            'country' => 'character varying(100)',
            'postal_code' => 'character varying(20)',
            'website' => 'character varying(255)',
            'notes' => 'text',
            'created_at' => 'timestamp without time zone',
            'updated_at' => 'timestamp without time zone'
        ]
    ],
    'staff_profiles' => [
        'required_fields' => [
            'id' => 'uuid',
            'user_id' => 'uuid',
            'staff_id' => 'character varying',
            'full_name' => 'character varying',
            'designation' => 'character varying',
            'department' => 'character varying',
            'employment_type' => 'character varying',
            'date_of_joining' => 'date',
            'reporting_manager_id' => 'uuid',
            'official_email' => 'character varying',
            'personal_email' => 'character varying',
            'phone_number' => 'character varying',
            'alternate_phone' => 'character varying',
            'emergency_contact_name' => 'character varying',
            'emergency_contact_number' => 'character varying',
            'current_address' => 'text',
            'permanent_address' => 'text',
            'national_id' => 'character varying',
            'passport_number' => 'character varying',
            'date_of_birth' => 'date',
            'gender' => 'character varying',
            'nationality' => 'character varying',
            'tax_id' => 'character varying',
            'work_permit_details' => 'text',
            'role_category' => 'character varying',
            'skills' => 'text',
            'certifications' => 'text',
            'experience_years' => 'integer',
            'assigned_clients' => 'text',
            'service_area' => 'character varying',
            'shift_timing' => 'character varying',
            'on_call_support' => 'boolean',
            'username' => 'character varying',
            'role_level' => 'character varying',
            'system_access' => 'text',
            'company_laptop_issued' => 'boolean',
            'asset_serial_number' => 'character varying',
            'vpn_access' => 'boolean',
            'bank_name' => 'character varying',
            'account_number' => 'character varying',
            'salary_type' => 'character varying',
            'payment_method' => 'character varying',
            'employment_status' => 'character varying',
            'last_working_day' => 'date',
            'remarks' => 'text',
            'staff_signature_data' => 'text',
            'hr_approval_date' => 'date',
            'hr_manager_id' => 'uuid',
            'created_at' => 'timestamp without time zone',
            'updated_at' => 'timestamp without time zone',
            'profile_picture' => 'character varying'
        ]
    ],
    'contracts' => [
        'required_fields' => [
            'id' => 'uuid',
            'client_id' => 'uuid',
            'contract_number' => 'character varying',
            'contract_type' => 'character varying',
            'start_date' => 'date',
            'end_date' => 'date',
            'service_scope' => 'text',
            'monthly_amount' => 'numeric',
            'status' => 'character varying',
            'response_time_hours' => 'integer',
            'resolution_time_hours' => 'integer',
            'penalty_terms' => 'text',
            'created_at' => 'timestamp without time zone',
            'updated_at' => 'timestamp without time zone'
        ]
    ],
    'users' => [
        'required_fields' => [
            'id' => 'uuid',
            'username' => 'character varying',
            'email' => 'character varying',
            'password_hash' => 'character varying',
            'role' => 'character varying',
            'first_name' => 'character varying',
            'last_name' => 'character varying',
            'phone' => 'character varying',
            'is_active' => 'boolean',
            'last_login' => 'timestamp without time zone',
            'created_at' => 'timestamp without time zone',
            'updated_at' => 'timestamp without time zone'
        ]
    ]
];

// Track missing tables and fields
$missing_tables = [];
$missing_fields = [];
$field_mismatches = [];

echo "=== DATABASE TABLE ANALYSIS ===\n\n";

// Get all existing tables
$stmt = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_type = 'BASE TABLE'
    ORDER BY table_name
");
$existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($existing_tables) . " tables in database:\n";
foreach ($existing_tables as $table) {
    echo "  - $table\n";
}
echo "\n";

// Analyze each configured table
foreach ($tables_config as $table_name => $config) {
    echo "--- Analyzing table: $table_name ---\n";
    
    if (!in_array($table_name, $existing_tables)) {
        echo "❌ Table '$table_name' is MISSING from database\n";
        $missing_tables[] = $table_name;
        continue;
    }
    
    // Get current table structure
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = ? 
        ORDER BY ordinal_position
    ");
    $stmt->execute([$table_name]);
    $current_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $current_field_names = array_column($current_columns, 'column_name');
    
    echo "Current fields (" . count($current_columns) . "):\n";
    foreach ($current_columns as $col) {
        $length = $col['character_maximum_length'] ? "({$col['character_maximum_length']})" : "";
        echo "  {$col['column_name']} - {$col['data_type']}{$length}" . 
             ($col['is_nullable'] === 'YES' ? ' NULL' : ' NOT NULL') . "\n";
    }
    
    // Check for missing required fields
    $missing_in_table = [];
    foreach ($config['required_fields'] as $field_name => $expected_type) {
        if (!in_array($field_name, $current_field_names)) {
            echo "❌ Missing required field: $field_name ($expected_type)\n";
            $missing_fields[$table_name][] = ['field' => $field_name, 'type' => $expected_type];
            $missing_in_table[] = $field_name;
        }
    }
    
    // Check for field type mismatches
    foreach ($current_columns as $col) {
        $field_name = $col['column_name'];
        if (isset($config['required_fields'][$field_name])) {
            $expected_type = $config['required_fields'][$field_name];
            $actual_type = $col['data_type'];
            if ($col['character_maximum_length']) {
                $actual_type .= "({$col['character_maximum_length']})";
            }
            
            // Simple type comparison (could be enhanced)
            if (strpos($expected_type, $actual_type) === false && 
                strpos($actual_type, $expected_type) === false) {
                echo "⚠️  Type mismatch for '$field_name': expected '$expected_type', got '$actual_type'\n";
                $field_mismatches[$table_name][] = [
                    'field' => $field_name,
                    'expected' => $expected_type,
                    'actual' => $actual_type
                ];
            }
        }
    }
    
    if (empty($missing_in_table)) {
        echo "✅ All required fields present\n";
    }
    echo "\n";
}

// Check for extra tables that might not be documented
$undocumented_tables = array_diff($existing_tables, array_keys($tables_config));
if (!empty($undocumented_tables)) {
    echo "=== UNDOCUMENTED TABLES FOUND ===\n";
    foreach ($undocumented_tables as $table) {
        echo "  - $table\n";
    }
    echo "\n";
}

// Generate SQL for missing fields
if (!empty($missing_fields)) {
    echo "=== SQL SCRIPT TO ADD MISSING FIELDS ===\n\n";
    
    foreach ($missing_fields as $table_name => $fields) {
        echo "-- Add missing fields to $table_name table\n";
        foreach ($fields as $field_info) {
            $field = $field_info['field'];
            $type = $field_info['type'];
            
            // Determine if field should be nullable
            $nullable = in_array($field, [
                'requested_by_email', 'csr_sn', 'pi_number', 'location_manual', 
                'estimated_hours', 'work_start_time', 'work_end_time', 'total_work_hours',
                'assigned_to', 'closed_at', 'sla_breach_time', 'actual_response_time',
                'actual_resolution_time', 'client_feedback', 'rating'
            ]) ? 'NULL' : 'NOT NULL';
            
            echo "ALTER TABLE $table_name ADD COLUMN $field $type $nullable;\n";
        }
        echo "\n";
    }
}

// Summary
echo "=== AUDIT SUMMARY ===\n";
echo "Tables missing: " . count($missing_tables) . "\n";
echo "Fields missing: " . array_sum(array_map('count', $missing_fields)) . "\n";
echo "Type mismatches: " . array_sum(array_map('count', $field_mismatches)) . "\n";

if (empty($missing_tables) && empty($missing_fields) && empty($field_mismatches)) {
    echo "\n🎉 All tables and fields are properly configured!\n";
} else {
    echo "\n⚠️  Issues found - see details above\n";
}

?>