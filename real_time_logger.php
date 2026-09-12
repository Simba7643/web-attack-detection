<?php
// real_time_logger.php - NO session_start() here!

function logServerAccess() {
    global $pdo;
    
    if (!isset($pdo)) {
        try {
            $host = 'localhost';
            $dbname = 'attack_db';
            $username = 'root';
            $password = '';
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            return;
        }
    }
    
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Only access session if active (don't start it)
        $user_id = 0;
        $username = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $user_id = $_SESSION['user_id'] ?? 0;
            $username = $_SESSION['username'] ?? '';
        }
        
        $current_file = basename($_SERVER['SCRIPT_NAME'], '.php');
        $action = strtoupper($current_file) . '_ACCESS';
        $details = "Page accessed: " . $current_file;
        
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs 
            (user_id, username, ip_address, user_agent, action, details, request_url, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$user_id, $username, $ip, $user_agent, $action, $details, $uri]);
        
    } catch(PDOException $e) {
        // Silent fail
    }
}

// Only log if session is active - DON'T start a new session
if (session_status() === PHP_SESSION_ACTIVE) {
    logServerAccess();
}
?>