<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Get POST data
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';

// Validate inputs
if (empty($current_password) || empty($new_password)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit();
}

// Validate new password strength
if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit();
}

// Check password strength
$strength = 0;
if (strlen($new_password) >= 8) $strength++;
if (preg_match('/[a-z]/', $new_password)) $strength++;
if (preg_match('/[A-Z]/', $new_password)) $strength++;
if (preg_match('/[0-9]/', $new_password)) $strength++;
if (preg_match('/[^a-zA-Z0-9]/', $new_password)) $strength++;

if ($strength < 3) {
    echo json_encode(['success' => false, 'error' => 'Password too weak. Use uppercase, lowercase, numbers, and symbols']);
    exit();
}

try {
    // Get user's current password hash
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }
    
    // Verify current password (the temporary password)
    if (!password_verify($current_password, $user['password_hash'])) {
        logActivity($_SESSION['user_id'], 'FAILED_PASSWORD_CHANGE', 'Incorrect current password');
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit();
    }
    
    // Check if new password is same as old
    if (password_verify($new_password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'New password cannot be the same as current password']);
        exit();
    }
    
    // Hash the new password
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    
    // REPLACE the password_hash in database (using existing column - no new columns!)
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$new_hash, $_SESSION['user_id']]);
    
    // Log successful password change
    logActivity($_SESSION['user_id'], 'PASSWORD_CHANGED', 'User changed their password');
    
    echo json_encode(['success' => true, 'message' => 'Password changed successfully! Please login again.']);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>