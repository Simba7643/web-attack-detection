<?php
require_once 'config.php';
requireLogin();
header('Content-Type: application/json');
try {
    $stmt = $pdo->query("SELECT id, attacker_ip, attack_type, severity, confidence, target_url, timestamp FROM attacks WHERE DATE(timestamp) >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY timestamp DESC LIMIT 200");
    echo json_encode(['success' => true, 'attacks' => $stmt->fetchAll()]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>