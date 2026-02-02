<?php
$content = file_get_contents('pages/staff/edit_profile.php');
preg_match('/INSERT INTO staff_profiles\s+\((.*?)\)\s+VALUES/s', $content, $matches);
if (isset($matches[1])) {
    $columns = explode(',', $matches[1]);
    echo "Columns: " . count($columns) . "\n";
    foreach ($columns as $i => $col) {
        echo ($i + 1) . ". " . trim($col) . "\n";
    }
}