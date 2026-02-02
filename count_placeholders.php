<?php
// Extract the VALUES clause from the file
$values_clause = "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

$placeholder_count = substr_count($values_clause, '?');
echo "Placeholders in VALUES clause: " . $placeholder_count . "\n";

// The columns that should have placeholders are 1-50 (excluding created_at and updated_at)
$columns_with_placeholders = 50 - 2; // subtracting created_at and updated_at
echo "Columns that should have placeholders: " . $columns_with_placeholders . "\n";

echo "Match: " . ($placeholder_count == $columns_with_placeholders ? "YES" : "NO") . "\n";