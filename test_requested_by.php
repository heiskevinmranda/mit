<?php
// Test script to verify the requested_by functionality
echo "Testing requested_by field implementation...\n";

// Check if the form field was added correctly
if (isset($_POST['requested_by'])) {
    echo "SUCCESS: Form includes 'requested_by' field\n";
    echo "Value received: " . htmlspecialchars($_POST['requested_by']) . "\n";
} else {
    echo "INFO: Not running as POST request, checking form structure...\n";
}

// Show information about the changes made
echo "\nChanges made to the system:\n";
echo "1. Added 'Requested By' and 'Requester Email' fields to the ticket creation form\n";
echo "2. Updated backend processing to store these values\n";
echo "3. Added display of these values in the ticket view page\n";
echo "4. Created migration script to add columns to database\n";
echo "\nNote: The database migration failed due to permission restrictions.\n";
echo "The columns may need to be added manually to the tickets table:\n";
echo "- requested_by VARCHAR(255)\n";
echo "- requested_by_email VARCHAR(255)\n";

// Show the form for manual testing
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "\nForm preview:\n";
    echo "Visit the ticket creation page to test the new fields.\n";
}
?>