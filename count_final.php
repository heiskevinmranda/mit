<?php
$s = 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)';
echo 'Length: ' . strlen($s) . "\n";
echo 'Placeholders: ' . substr_count($s, '?') . "\n";

// Manual count
$count = 0;
for ($i = 0; $i < strlen($s); $i++) {
    if ($s[$i] === '?') {
        $count++;
    }
}
echo 'Manual count: ' . $count . "\n";

// Regex count
preg_match_all('/\?/', $s, $matches);
echo 'Regex count: ' . count($matches[0]) . "\n";