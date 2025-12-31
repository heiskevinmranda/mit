<?php
// add_sample_locations.php
require_once 'includes/auth.php';

$pdo = getDBConnection();

echo "<h2>Adding Sample Locations</h2>";

// First, let's see what clients we have
$clients = $pdo->query("SELECT id, company_name FROM clients LIMIT 5")->fetchAll();

if (empty($clients)) {
    echo "<p>No clients found. Please add clients first.</p>";
    echo "<p><a href='pages/clients/create.php'>Add Clients</a></p>";
    exit;
}

echo "<p>Found " . count($clients) . " clients</p>";

// Sample locations for each client
$sample_locations = [
    'Demo Client Ltd' => [
        ['Main Office', '123 Business Street, Dar es Salaam', '+255987654321', 'contact@democlient.com'],
        ['Branch Office', '456 Industrial Area, Dar es Salaam', '+255987654322', 'branch@democlient.com'],
        ['Warehouse', '789 Logistics Park, Dar es Salaam', '+255987654323', 'warehouse@democlient.com']
    ],
    'ABC Corporation' => [
        ['Headquarters', '101 Corporate Tower, Nairobi', '+254712345678', 'hq@abccorp.com'],
        ['Sales Office', '202 Sales Plaza, Mombasa', '+254712345679', 'sales@abccorp.com']
    ],
    'XYZ Enterprises' => [
        ['Main Facility', '303 Enterprise Park, Kampala', '+256712345678', 'admin@xyz.com'],
        ['Data Center', '404 Tech Valley, Kampala', '+256712345679', 'dc@xyz.com']
    ]
];

$added_count = 0;

foreach ($clients as $client) {
    $client_name = $client['company_name'];
    
    echo "<h3>Adding locations for: $client_name</h3>";
    
    // Check if client already has locations
    $stmt = $pdo->prepare("SELECT COUNT(*) as location_count FROM client_locations WHERE client_id = ?");
    $stmt->execute([$client['id']]);
    $location_count = $stmt->fetch()['location_count'];
    
    if ($location_count > 0) {
        echo "<p>Client already has $location_count locations - skipping</p>";
        continue;
    }
    
    // Add sample locations if available, otherwise add generic ones
    if (isset($sample_locations[$client_name])) {
        $locations_to_add = $sample_locations[$client_name];
    } else {
        $locations_to_add = [
            ['Main Office', '123 Example Street, City', '+255000000000', 'office@example.com'],
            ['Branch Office', '456 Example Avenue, City', '+255000000001', 'branch@example.com']
        ];
    }
    
    // Add locations
    foreach ($locations_to_add as $index => $location_data) {
        $is_primary = ($index === 0); // First location is primary
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO client_locations 
                (client_id, location_name, address, phone, email, is_primary) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $client['id'],
                $location_data[0], // location_name
                $location_data[1], // address
                $location_data[2], // phone
                $location_data[3], // email
                $is_primary
            ]);
            
            $added_count++;
            echo "<p style='color: green;'>✓ Added: {$location_data[0]}</p>";
            
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ Error adding {$location_data[0]}: " . $e->getMessage() . "</p>";
        }
    }
}

echo "<h3>Summary</h3>";
echo "<p>Total locations added: $added_count</p>";
echo "<p><a href='pages/tickets/create.php'>Go to Create Ticket Page</a> to test</p>";

// Show all locations in database
echo "<h3>All Locations in Database</h3>";
$all_locations = $pdo->query("
    SELECT cl.*, c.company_name 
    FROM client_locations cl 
    JOIN clients c ON cl.client_id = c.id 
    ORDER BY c.company_name, cl.location_name
")->fetchAll();

if (empty($all_locations)) {
    echo "<p>No locations found in database.</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Client</th><th>Location</th><th>Address</th><th>Phone</th><th>Email</th><th>Primary</th></tr>";
    
    foreach ($all_locations as $location) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($location['company_name']) . "</td>";
        echo "<td>" . htmlspecialchars($location['location_name']) . "</td>";
        echo "<td>" . htmlspecialchars($location['address']) . "</td>";
        echo "<td>" . htmlspecialchars($location['phone']) . "</td>";
        echo "<td>" . htmlspecialchars($location['email']) . "</td>";
        echo "<td>" . ($location['is_primary'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>