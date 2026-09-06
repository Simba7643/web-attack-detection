<?php
// block_ip.php
session_start(); // Ensure session is started FIRST
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Check if admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Check request method safely
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST requests are allowed']);
    exit();
}

// CSRF check
$headers = getallheaders();
if (!isset($headers['X-CSRF-Token']) || !verifyCSRFToken($headers['X-CSRF-Token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit();
}

$ip = sanitizeInput($_POST['ip'] ?? '');
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['success' => false, 'error' => 'Invalid IP address']);
    exit();
}

try {
    // Check if already blocked
    $stmt = $pdo->prepare("SELECT 1 FROM blocked_ips WHERE ip = ?");
    $stmt->execute([$ip]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => "IP $ip is already blocked"]);
        exit();
    }

    // Insert into blocked_ips
    $stmt = $pdo->prepare("INSERT INTO blocked_ips (ip, reason, blocked_at) VALUES (?, 'Blocked by admin', NOW())");
    $stmt->execute([$ip]);

    // Safe log activity – check if session and function exist
    if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
        @logActivity($_SESSION['user_id'], 'BLOCK_IP', "Blocked IP: $ip");
    }

    echo json_encode(['success' => true, 'message' => "IP $ip blocked successfully"]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>