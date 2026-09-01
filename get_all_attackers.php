<?php
// get_all_attackers.php - Get all attackers for the Attackers page
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            ip,
            attack_count,
            threat_level,
            COALESCE(last_attack_type, 'Unknown') as last_attack_type,
            DATE_FORMAT(last_seen, '%Y-%m-%d %H:%i:%s') as last_seen
        FROM ip_reputation 
        ORDER BY attack_count DESC
    ");
    $attackers = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'attackers' => $attackers
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>