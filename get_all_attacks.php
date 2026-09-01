<?php
// get_all_attacks.php - Get all attacks for the Attacks page
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            id,
            attacker_ip,
            target_url,
            attack_type,
            confidence,
            severity,
            timestamp
        FROM attacks 
        ORDER BY timestamp DESC 
        LIMIT 500
   
?>
 ");
    $attacks = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'attacks' => $attacks
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
