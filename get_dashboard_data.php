<?php
// get_dashboard_data.php - Fetch all dashboard data
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    // Get today's statistics from attack_statistics table
    $stmt = $pdo->query("
        SELECT 
            COALESCE(SUM(total_attacks), 0) as total_attacks,
            COALESCE(SUM(critical_attacks), 0) as critical_attacks,
            COALESCE(SUM(high_attacks), 0) as high_attacks,
            COALESCE(SUM(medium_attacks), 0) as medium_attacks
        FROM attack_statistics 
        WHERE date = CURDATE()
    ");
    $stats = $stmt->fetch();
    
    // If no statistics for today, set defaults
    if (!$stats) {
        $stats = [
            'total_attacks' => 0,
            'critical_attacks' => 0,
            'high_attacks' => 0,
            'medium_attacks' => 0
        ];
    }
    
    // Get unique attackers count for today
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT attacker_ip) as unique_attackers
        FROM attacks 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $unique = $stmt->fetch();
    $stats['unique_attackers'] = $unique['unique_attackers'] ?? 0;
    
    // ========== FIX: Count blocked IPs from the correct table ==========
    $stmt = $pdo->query("SELECT COUNT(*) as blocked_ips FROM blocked_ips");
    $blocked = $stmt->fetch();
    $stats['blocked_ips'] = $blocked['blocked_ips'] ?? 0;
    // ===================================================================
    
    // Get trends for last 7 days
    $stmt = $pdo->query("
        SELECT 
            DATE(date) as attack_date,
            SUM(total_attacks) as total
        FROM attack_statistics 
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(date)
        ORDER BY attack_date
    ");
    $trends = $stmt->fetchAll();
    
    // If no trends data, create empty array
    if (empty($trends)) {
        $trends = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $trends[] = ['attack_date' => $date, 'total' => '0'];
        }
    }
    
    // Get attack distribution by type for last 24 hours
    $stmt = $pdo->query("
        SELECT 
            attack_type,
            COUNT(*) as count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY attack_type
        ORDER BY count DESC
    ");
    $distribution = $stmt->fetchAll();
    
    // Get severity counts for today
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium
        FROM attacks 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $severity = $stmt->fetch();
    
    // Get hourly pattern for last 24 hours
    $stmt = $pdo->query("
        SELECT 
            HOUR(timestamp) as hour,
            COUNT(*) as count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY HOUR(timestamp)
    ");
    $hourly = $stmt->fetchAll();
    
    // Get top attackers
    $stmt = $pdo->query("
        SELECT 
            ip,
            attack_count,
            threat_level,
            COALESCE(last_attack_type, 'Unknown') as last_attack_type,
            DATE_FORMAT(last_seen, '%Y-%m-%d %H:%i:%s') as last_seen
        FROM ip_reputation 
        ORDER BY attack_count DESC 
        LIMIT 10
    ");
    $top_attackers = $stmt->fetchAll();
    
    // Get recent attacks (last 20)
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
        LIMIT 20
    ");
    $recent_attacks = $stmt->fetchAll();
    
    // Format the response
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'trends' => $trends,
        'distribution' => $distribution,
        'severity' => $severity,
        'hourly' => $hourly,
        'top_attackers' => $top_attackers,
        'recent_attacks' => $recent_attacks
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>