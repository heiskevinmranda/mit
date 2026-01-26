<?php
require_once 'includes/auth.php';
require_once 'includes/profile_picture_helper.php';
requireLogin();

$current_user = getCurrentUser();

// Test profile picture functions
echo "<h1>Profile Picture Test</h1>";

echo "<h2>Current User Info</h2>";
echo "<p>User ID: " . $current_user['id'] . "</p>";
echo "<p>Email: " . $current_user['email'] . "</p>";

echo "<h2>Profile Picture Display</h2>";
echo "<p>Small: " . getProfilePictureHTML($current_user['id'], $current_user['email'], 'sm') . "</p>";
echo "<p>Medium: " . getProfilePictureHTML($current_user['id'], $current_user['email'], 'md') . "</p>";
echo "<p>Large: " . getProfilePictureHTML($current_user['id'], $current_user['email'], 'lg') . "</p>";
echo "<p>X-Large: " . getProfilePictureHTML($current_user['id'], $current_user['email'], 'xl') . "</p>";

echo "<h2>Profile Picture URL</h2>";
echo "<p>URL: " . getProfilePicture($current_user['id'], $current_user['email']) . "</p>";

echo "<h2>Upload Test Form</h2>";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $result = uploadProfilePicture($_FILES['profile_picture'], $current_user['id']);
    if ($result['success']) {
        echo "<p style='color: green;'>Success: " . $result['message'] . "</p>";
        echo "<p>New file path: " . $result['file_path'] . "</p>";
    } else {
        echo "<p style='color: red;'>Error: " . $result['message'] . "</p>";
    }
}
?>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp">
    <button type="submit">Upload Profile Picture</button>
</form>

<a href="pages/staff/profile.php">View Profile Page</a> | 
<a href="pages/staff/edit_profile.php">Edit Profile</a>