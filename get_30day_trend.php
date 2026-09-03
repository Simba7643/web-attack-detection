<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

try {
    // Check if attacks table exists and has data
    $table_check = $pdo->query("SHOW TABLES LIKE 'attacks'");
    if ($table_check->rowCount() == 0) {
        // No attacks table yet
        echo json_encode([
            'success' => true,
            'stats' => [
                'avgDaily' => 0,
                'total' => 0,
                'peakDay' => 'No data yet'
            ],
            'daily_data' => []
        ]);
        exit();
    }
    
    // Get attacks from last 30 days
    $stmt = $pdo->prepare("
        SELECT 
            DATE(timestamp) as attack_date,
            COUNT(*) as total
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(timestamp)
        ORDER BY attack_date
    ");
    $stmt->execute();
    $daily_attacks = $stmt->fetchAll();
    
    // If no data in last 30 days, check if there's ANY data
    if (empty($daily_attacks)) {
        $check_stmt = $pdo->query("SELECT COUNT(*) as total FROM attacks");
        $total_count = $check_stmt->fetch();
        
        if ($total_count['total'] > 0) {
            // Has data but not in last 30 days
            echo json_encode([
                'success' => true,
                'stats' => [
                    'avgDaily' => 0,
                    'total' => 0,
                    'peakDay' => 'No attacks in last 30 days',
                    'total_all_time' => $total_count['total']
                ],
                'daily_data' => []
            ]);
        } else {
            // No attacks at all
            echo json_encode([
                'success' => true,
                'stats' => [
                    'avgDaily' => 0,
                    'total' => 0,
                    'peakDay' => 'No attack data yet'
                ],
                'daily_data' => []
            ]);
        }
        exit();
    }
    
    // Calculate statistics
    $total_attacks = 0;
    $peak_day = '';
    $max_attacks = 0;
    $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    
    foreach ($daily_attacks as $day) {
        $total_attacks += $day['total'];
        
        if ($day['total'] > $max_attacks) {
            $max_attacks = $day['total'];
            $day_of_week = date('w', strtotime($day['attack_date']));
            $peak_day = $day_names[$day_of_week];
        }
    }
    
    $avg_daily = round($total_attacks / count($daily_attacks));
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'avgDaily' => $avg_daily,
            'total' => $total_attacks,
            'peakDay' => $peak_day ?: 'No data',
            'daysWithData' => count($daily_attacks)
        ],
        'daily_data' => $daily_attacks
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>