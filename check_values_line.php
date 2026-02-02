<?php
$content = file_get_contents('pages/staff/edit_profile.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'VALUES') !== false) {
        echo 'Line ' . ($i + 1) . ': ' . trim($line) . "\n";
        echo 'Length: ' . strlen($line) . "\n";
        echo 'Placeholders: ' . substr_count($line, '?') . "\n";
    }
}