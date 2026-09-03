<?php
// config.php - Complete Database Configuration with Security Functions

// Start session ONLY if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
$host = 'localhost';
$dbname = 'attack_db';
$username = 'root';
$password = '';

// Security configuration
define('SESSION_TIMEOUT', 1800); // 30 minutes

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    $db_connected = true;
    
    // Create activity_logs table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `username` VARCHAR(100),
            `action` VARCHAR(255) NOT NULL,
            `details` TEXT,
            `ip_address` VARCHAR(45),
            `user_agent` TEXT,
            `request_url` VARCHAR(500),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Create blocked_ips table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `blocked_ips` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ip` VARCHAR(45) NOT NULL UNIQUE,
            `reason` TEXT,
            `blocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
} catch(PDOException $e) {
    die("<div style='background: #f8d7da; color: #721c24; padding: 10px; margin: 10px; border-radius: 5px;'>"
        . "❌ Connection failed: " . $e->getMessage() . "</div>");
}

// ============ LOGGING FUNCTION ============
function logActivity($user_id, $action, $details = '') {
    global $pdo;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $request_url = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        // Get username if user_id is provided
        $username = '';
        if ($user_id > 0) {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $username = $stmt->fetchColumn();
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, username, action, details, ip_address, user_agent, request_url, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $username, $action, $details, $ip, $user_agent, $request_url]);
        return true;
    } catch(PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

// ============ IP BLOCKING FUNCTIONS ============

// Check if an IP is blocked
function isIpBlocked($ip) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM blocked_ips WHERE ip = ?");
        $stmt->execute([$ip]);
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        error_log("Failed to check blocked IP: " . $e->getMessage());
        return false;
    }
}

// Block an IP address
function blockIp($ip, $reason = 'Manual block by admin') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO blocked_ips (ip, reason) VALUES (?, ?)");
        $stmt->execute([$ip, $reason]);
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        error_log("Failed to block IP: " . $e->getMessage());
        return false;
    }
}

// Unblock an IP address
function unblockIp($ip) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM blocked_ips WHERE ip = ?");
        $stmt->execute([$ip]);
        return $stmt->rowCount() > 0;
    } catch(PDOException $e) {
        error_log("Failed to unblock IP: " . $e->getMessage());
        return false;
    }
}

// Check and block access for current IP
function checkBlockedAccess() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $current_page = basename($script_name);
    
    // Pages that should NOT be blocked (prevent admin lockout)
    $exclude_pages = ['login.php', 'verify_otp.php', 'block_ip.php', 'unblock_ip.php'];
    
    // Don't block on excluded pages
    if (in_array($current_page, $exclude_pages)) {
        return true;
    }
    
    // Check if IP is blocked
    if (isIpBlocked($ip)) {
        http_response_code(403);
        die("Access Denied - Your IP address ($ip) has been blocked by the security system.");
    }
    
    return true;
}

// ============ SECURITY FUNCTIONS ============

// Require login for protected pages
function requireLogin() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        if (function_exists('logActivity')) {
            logActivity(0, 'UNAUTHORIZED_ACCESS', 'Attempt to access page without login');
        }
        header('Location: login.php');
        exit();
    }
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

// Rate limiting function
function checkRateLimit($ip, $limit = 100, $timeWindow = 60) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attacks WHERE attacker_ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$ip, $timeWindow]);
        $count = $stmt->fetchColumn();
        
        if ($count > $limit) {
            header('HTTP/1.1 429 Too Many Requests');
            die('Rate limit exceeded. Please try again later.');
        }
    } catch(PDOException $e) {
        return true;
    }
}

// Sanitize input
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Session security
function secureSession() {
    // Only regenerate if session is active
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['last_regeneration'])) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        } else if (time() - $_SESSION['last_regeneration'] > 300) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
        
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'] ?? 0, 'SESSION_TIMEOUT', 'Session expired');
            }
            session_unset();
            session_destroy();
            header('Location: login.php?timeout=1');
            exit();
        }
        $_SESSION['last_activity'] = time();
    }
}

// ============ RUN IP BLOCK CHECK ON EVERY REQUEST ============
// This will block access if the visitor's IP is in blocked_ips table
checkBlockedAccess();

// ============ REMOVED THE DEMO SESSION CODE! ============
// DO NOT set default sessions - let users log in properly!
?>