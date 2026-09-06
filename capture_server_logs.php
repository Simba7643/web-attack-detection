<?php
// capture_server_logs.php - Parse Apache access logs and store in database
// Can now be run from browser OR command line

require_once 'config.php';

// ========== OPTIONAL: Restrict access (uncomment if needed) ==========
// $allowed_ips = ['127.0.0.1', '::1'];
// if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
//     die('Access denied - This script can only be run from localhost or command line');
// }
// =====================================================================

// Path to Apache access log
$log_file = 'C:/xampp/apache/logs/access.log';

// For browser output
echo "<pre>";
echo "[" . date('Y-m-d H:i:s') . "] Starting Apache log import...\n";

if (!file_exists($log_file)) {
    die("❌ Log file not found: $log_file\n");
}

$track_file = __DIR__ . '/last_log_position.txt';
$last_position = file_exists($track_file) ? (int)file_get_contents($track_file) : 0;

$handle = fopen($log_file, 'r');
if (!$handle) {
    die("❌ Cannot open log file\n");
}

fseek($handle, $last_position);

$imported = 0;
$skipped = 0;

// Apache Combined Log Format regex
$pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) HTTP\/\d+\.\d+" (\d+) \S+ "([^"]*)" "([^"]*)"/';

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if (empty($line)) continue;
    
    if (preg_match($pattern, $line, $matches)) {
        $ip = $matches[1];
        $timestamp_raw = $matches[2];
        $method = $matches[3];
        $url = $matches[4];
        $response_code = $matches[5];
        $referer = $matches[6] ?? '';
        $user_agent = $matches[7] ?? '';
        
        // Only process your project URLs
        if (strpos($url, '/project/') !== false) {
            
            // Parse timestamp
            $timestamp_parts = explode(':', $timestamp_raw);
            $date_part = $timestamp_parts[0];
            $time_part = $timestamp_parts[1] . ':' . $timestamp_parts[2] . ':' . explode(' ', $timestamp_parts[3])[0];
            $mysql_timestamp = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $date_part) . ' ' . $time_part));
            
            // Determine action
            $action = 'SERVER_ACCESS';
            $details = "Access to: $url";
            
            if (strpos($url, 'dashboard.php') !== false) {
                $action = 'DASHBOARD_ACCESS';
                $details = "Dashboard page accessed";
            } elseif (strpos($url, 'login.php') !== false) {
                $action = 'LOGIN_PAGE_ACCESS';
                $details = "Login page accessed";
            } elseif (strpos($url, 'verify_otp.php') !== false) {
                $action = 'OTP_PAGE_ACCESS';
                $details = "OTP page accessed";
            } elseif (strpos($url, '.css') !== false || strpos($url, '.js') !== false) {
                $skipped++;
                continue;
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO activity_logs 
                    (user_id, ip_address, action, details, request_method, request_url, response_code, referer, user_agent, created_at) 
                    VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ip, $action, $details, $method, $url, $response_code, $referer, $user_agent, $mysql_timestamp]);
                $imported++;
            } catch (PDOException $e) {
                echo "❌ Error: " . $e->getMessage() . "\n";
            }
        }
    } else {
        $skipped++;
    }
}

$current_position = ftell($handle);
file_put_contents($track_file, $current_position);
fclose($handle);

echo "✅ Import completed!\n";
echo "📊 Imported: $imported logs\n";
echo "⏭️ Skipped: $skipped lines\n";
echo "📍 Next position: $current_position\n";
echo "</pre>";
?>