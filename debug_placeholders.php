<?php
// Create the exact VALUES clause
$values = 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)';

// Count total placeholders
$total_placeholders = substr_count($values, '?');
echo "Total placeholders: " . $total_placeholders . "\n";

// Let's also check if there are any issues with the string
echo "String length: " . strlen($values) . "\n";

// Check for any non-standard characters
for ($i = 0; $i < strlen($values); $i++) {
    if ($values[$i] === '?') {
        echo "Placeholder at position " . $i . "\n";
    }
}

// Let's also try to extract all placeholders with their positions
preg_match_all('/\?/', $values, $matches, PREG_OFFSET_CAPTURE);
echo "Number of matches: " . count($matches[0]) . "\n";

foreach ($matches[0] as $i => $match) {
    echo "Match " . ($i + 1) . " at position " . $match[1] . "\n";
}