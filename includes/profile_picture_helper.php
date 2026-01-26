<?php
/**
 * Profile Picture Helper Functions
 */

/**
 * Upload profile picture
 * @param array $file - $_FILES['profile_picture']
 * @param string $user_id - User ID
 * @return array - ['success' => bool, 'message' => string, 'file_path' => string]
 */
function uploadProfilePicture($file, $user_id) {
    // Use absolute path from web root
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/mit/uploads/profile_pictures/';
    
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => 'No file uploaded or upload error occurred.'
        ];
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        return [
            'success' => false,
            'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.'
        ];
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'message' => 'File size too large. Maximum size is 5MB.'
        ];
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $file_path = $upload_dir . $filename;
    $db_path = 'uploads/profile_pictures/' . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Delete old profile picture if exists
        deleteOldProfilePicture($user_id);
        
        return [
            'success' => true,
            'message' => 'Profile picture uploaded successfully.',
            'file_path' => $db_path  // Store relative path in database
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Failed to move uploaded file.'
        ];
    }
}

/**
 * Delete old profile picture for a user
 * @param string $user_id - User ID
 */
function deleteOldProfilePicture($user_id) {
    $pdo = getDBConnection();
    
    // Get current profile picture path
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    if ($result && !empty($result['profile_picture'])) {
        $profile_path = $result['profile_picture'];
        
        // Check multiple possible locations
        $paths_to_check = [
            $profile_path,
            'pages/staff/' . $profile_path,
            $_SERVER['DOCUMENT_ROOT'] . '/mit/' . $profile_path
        ];
        
        foreach ($paths_to_check as $path) {
            if (file_exists($path)) {
                unlink($path);
                break;
            }
        }
    }
}

/**
 * Get profile picture URL for a user
 * @param string $user_id - User ID
 * @param string $email - User email (for fallback)
 * @return string - Profile picture URL or Gravatar fallback
 */
function getProfilePicture($user_id, $email = '') {
    $pdo = getDBConnection();
    
    // Get profile picture from database
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    if ($result && !empty($result['profile_picture'])) {
        $profile_path = $result['profile_picture'];
        
        // Check if file exists at the stored path
        if (file_exists($profile_path)) {
            // Return absolute URL
            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            return $base_url . '/mit/' . $profile_path . '?v=' . filemtime($profile_path);
        }
        
        // Check alternative paths (handle legacy uploads)
        $alternative_paths = [
            'pages/staff/' . $profile_path,
            $_SERVER['DOCUMENT_ROOT'] . '/mit/' . $profile_path
        ];
        
        foreach ($alternative_paths as $alt_path) {
            if (file_exists($alt_path)) {
                // Update database with correct path
                $correct_path = str_replace('pages/staff/', '', $profile_path);
                updateUserProfilePicture($user_id, $correct_path);
                // Return absolute URL
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                return $base_url . '/mit/' . $correct_path . '?v=' . filemtime($alt_path);
            }
        }
    }
    
    // Fallback to Gravatar or default avatar
    if (!empty($email)) {
        $gravatar_url = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . '?s=200&d=mp';
        return $gravatar_url;
    }
    
    // Default avatar
    return '/mit/assets/default-avatar.png';
}

/**
 * Get profile picture HTML for display
 * @param string $user_id - User ID
 * @param string $email - User email
 * @param string $size - Size class (sm, md, lg, xl)
 * @param string $additional_classes - Additional CSS classes
 * @return string - HTML for profile picture
 */
function getProfilePictureHTML($user_id, $email = '', $size = 'md', $additional_classes = '') {
    $picture_url = getProfilePicture($user_id, $email);
    $size_classes = [
        'sm' => 'width: 32px; height: 32px;',
        'md' => 'width: 40px; height: 40px;',
        'lg' => 'width: 60px; height: 60px;',
        'xl' => 'width: 100px; height: 100px;'
    ];
    
    $style = isset($size_classes[$size]) ? $size_classes[$size] : $size_classes['md'];
    
    // Ensure we have an absolute URL
    if (strpos($picture_url, 'http') !== 0 && strpos($picture_url, '//') !== 0) {
        // Convert relative path to absolute URL
        if (strpos($picture_url, '/') !== 0) {
            $picture_url = '/' . $picture_url;
        }
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $picture_url = $base_url . '/mit' . $picture_url;
    }
    
    if (strpos($picture_url, 'gravatar.com') !== false || strpos($picture_url, 'default-avatar.png') !== false) {
        // Show initials for default/gravatar
        $initials = !empty($email) ? strtoupper(substr($email, 0, 1)) : 'U';
        return "<div class='profile-picture-initials $additional_classes' style='$style'>$initials</div>";
    } else {
        // Show actual image
        return "<img src='$picture_url' alt='Profile Picture' class='profile-picture-img $additional_classes' style='$style'>";
    }
}

/**
 * Update user's profile picture in database
 * @param string $user_id - User ID
 * @param string $file_path - File path
 * @return bool - Success status
 */
function updateUserProfilePicture($user_id, $file_path) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
        return $stmt->execute([$file_path, $user_id]);
    } catch (Exception $e) {
        error_log("Error updating profile picture: " . $e->getMessage());
        return false;
    }
}
?>