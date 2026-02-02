<?php
$content = file_get_contents('pages/staff/edit_profile.php');
preg_match('/VALUES \(.*?\)/', $content, $matches);
if (isset($matches[0])) {
    echo "VALUES clause: " . $matches[0] . "\n";
    echo "Placeholders: " . substr_count($matches[0], '?') . "\n";
}