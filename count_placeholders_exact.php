<?php
$content = file_get_contents('pages/staff/edit_profile.php');
preg_match('/VALUES \(.*?\)/', $content, $matches);
if (isset($matches[0])) {
    echo "VALUES clause: " . $matches[0] . "\n";
    echo "Placeholders: " . substr_count($matches[0], '?') . "\n";
    
    // Count the individual placeholders
    $placeholders = 0;
    $values = $matches[0];
    for ($i = 0; $i < strlen($values); $i++) {
        if ($values[$i] === '?') {
            $placeholders++;
        }
    }
    echo "Manual count: " . $placeholders . "\n";
}