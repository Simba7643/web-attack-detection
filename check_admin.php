<?php
require_once 'config.php';

// Only allow access from localhost for security
$allowed_ips = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    die('Access denied');
}

echo "<h2>Admin Password Information</h2>";

// Get all admin users
$stmt = $pdo->query("SELECT id, username, password_hash, role FROM users WHERE role = 'admin'");
$admins = $stmt->fetchAll();

if (count($admins) > 0) {
    echo "<h3>Admin Users Found:</h3>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Username</th><th>Password Hash</th><th>Note</th></tr>";
    
    foreach ($admins as $admin) {
        echo "<tr>";
        echo "<td>" . $admin['id'] . "</td>";
        echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
        echo "<td>" . substr($admin['password_hash'], 0, 50) . "...</td>";
        echo "<td>";
        
        // Test common passwords
        $common_passwords = ['admin123', 'password', 'admin', '123456'];
        foreach ($common_passwords as $test_pw) {
            if (password_verify($test_pw, $admin['password_hash'])) {
                echo "<span style='color:green'>Password is: <strong>$test_pw</strong></span>";
                break;
            }
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No admin users found. Creating default admin...</p>";
    
    // Create default admin
    $default_password = 'admin123';
    $hash = password_hash($default_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
    $stmt->execute(['admin', $hash]);
    
    echo "<p style='color:green'>✓ Admin user created!</p>";
    echo "<p>Username: <strong>admin</strong></p>";
    echo "<p>Password: <strong>$default_password</strong></p>";
}

echo "<br><a href='login.php'>Go to Login →</a>";
?>