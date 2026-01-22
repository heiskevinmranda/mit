<?php
// test_certificate_routes.php
// Test script to verify certificate routes are working

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/routes.php';

echo "<h1>Certificate Routes Test</h1>";

// Test route generation
echo "<h2>Route Generation Tests</h2>";

$routes_to_test = [
    'certificates.manage' => 'Manage Certificates',
    'certificates.admin' => 'Admin Certificate Management', 
    'certificates.export' => 'Certificate Export'
];

foreach ($routes_to_test as $route => $description) {
    $url = route($route);
    echo "<p>$description: <a href='$url'>$url</a></p>";
}

// Check if current user has certificate permissions
echo "<h2>Permission Tests</h2>";

if (function_exists('canViewCertificateManagement')) {
    $can_manage = canViewCertificateManagement();
    echo "<p>Can view certificate management: " . ($can_manage ? 'YES' : 'NO') . "</p>";
} else {
    echo "<p>Function canViewCertificateManagement not found</p>";
}

if (function_exists('canUploadCertificates')) {
    $can_upload = canUploadCertificates();
    echo "<p>Can upload certificates: " . ($can_upload ? 'YES' : 'NO') . "</p>";
} else {
    echo "<p>Function canUploadCertificates not found</p>";
}

if (function_exists('canManageCertificates')) {
    $can_manage_all = canManageCertificates();
    echo "<p>Can manage certificates (approve/reject): " . ($can_manage_all ? 'YES' : 'NO') . "</p>";
} else {
    echo "<p>Function canManageCertificates not found</p>";
}

echo "<h2>JavaScript Functions Test</h2>";
echo "<p>Check browser console for JavaScript errors.</p>";

echo "<button onclick=\"alert(typeof initCertificateUpload !== 'undefined' ? 'initCertificateUpload function is available' : 'initCertificateUpload function is NOT available')\">Test JS Function</button>";

echo "<h2>Database Test</h2>";
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM certificates LIMIT 1");
    $result = $stmt->fetch();
    echo "<p>Certificates table: EXISTS with " . $result['count'] . " records</p>";
} catch (Exception $e) {
    echo "<p>Certificates table: DOES NOT EXIST - " . $e->getMessage() . "</p>";
}
?>