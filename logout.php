<?php
require_once 'config.php';

// Log logout activity
if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'LOGOUT', 'User logged out');
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>
