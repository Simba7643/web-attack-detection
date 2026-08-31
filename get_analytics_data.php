<?php
// get_analytics_data.php - Analytics data for dashboard
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    // 1. Attack Trends (Last 7 Days)
    $stmt = $pdo->query("
        SELECT DATE(timestamp) as attack_date, COUNT(*) as total 
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(timestamp)
        ORDER BY attack_date
    ");
    $trends = $stmt->fetchAll();
    
    // Fill missing dates
    $trendsData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = false;
        foreach ($trends as $row) {
            if ($row['attack_date'] == $date) {
                $trendsData[] = ['attack_date' => date('M d', strtotime($date)), 'total' => $row['total']];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $trendsData[] = ['attack_date' => date('M d', strtotime($date)), 'total' => 0];
        }
    }
    
    // 2. Attack Distribution by Type
    $stmt = $pdo->query("
        SELECT 
            attack_type,
            COUNT(*) as count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY attack_type
        ORDER BY count DESC
    ");
    $distribution = $stmt->fetchAll();
    
    // 3. Severity Distribution
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $severity = $stmt->fetch();
    
    // 4. Hourly Attack Pattern (Last 24 hours)
    $stmt = $pdo->query("
        SELECT 
            HOUR(timestamp) as hour,
            COUNT(*) as count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY HOUR(timestamp)
        ORDER BY hour
    ");
    $hourlyResult = $stmt->fetchAll();
    
    $hourly = [];
    for ($i = 0; $i < 24; $i++) {
        $found = false;
        foreach ($hourlyResult as $row) {
            if ($row['hour'] == $i) {
                $hourly[] = ['hour' => $i, 'count' => $row['count']];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $hourly[] = ['hour' => $i, 'count' => 0];
        }
    }
    
    // 5. Weekly Distribution (Last 30 days by day of week)
    $stmt = $pdo->query("
        SELECT 
            DAYOFWEEK(timestamp) as day_of_week,
            COUNT(*) as count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DAYOFWEEK(timestamp)
        ORDER BY day_of_week
    ");
    
    $weekly = [0, 0, 0, 0, 0, 0, 0]; // Sun=1, Mon=2, ..., Sat=7
    while ($row = $stmt->fetch()) {
        $index = $row['day_of_week'] - 1;
        $weekly[$index] = (int)$row['count'];
    }
    
    // 6. Top Attack Sources (Top 10)
    $stmt = $pdo->query("
        SELECT 
            attacker_ip as ip,
            COUNT(*) as count
        FROM attacks 
        GROUP BY attacker_ip
        ORDER BY count DESC 
        LIMIT 10
    ");
    $topSources = $stmt->fetchAll();
    
    // 7. Attack Types for Pie Chart
    $stmt = $pdo->query("
        SELECT 
            attack_type,
            COUNT(*) as count
        FROM attacks 
        GROUP BY attack_type
        ORDER BY count DESC
    ");
    $attackTypes = $stmt->fetchAll();
    
    // 8. Monthly Summary HTML
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(timestamp, '%Y-%m') as month,
            COUNT(*) as total,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(timestamp, '%Y-%m')
        ORDER BY month DESC
    ");
    $monthlyData = $stmt->fetchAll();
    
    $monthlySummary = '<table style="width:100%; border-collapse:collapse;">';
    $monthlySummary .= '<thead><tr style="background:rgba(255,255,255,0.1);"><th style="padding:12px;">Month</th><th style="padding:12px;">Total</th><th style="padding:12px;">Critical</th><th style="padding:12px;">High</th><th style="padding:12px;">Medium</th></tr></thead>';
    $monthlySummary .= '<tbody>';
    if (empty($monthlyData)) {
        $monthlySummary .= '<tr><td colspan="5" style="text-align:center; padding:20px;">No attack data available</td></tr>';
    } else {
        foreach ($monthlyData as $row) {
            $monthlySummary .= '<tr>';
            $monthlySummary .= '<td style="padding:8px;">' . date('F Y', strtotime($row['month'] . '-01')) . '</td>';
            $monthlySummary .= '<td style="padding:8px;">' . number_format($row['total']) . '</td>';
            $monthlySummary .= '<td style="padding:8px; color:#ff4757;">' . number_format($row['critical']) . '</td>';
            $monthlySummary .= '<td style="padding:8px; color:#ffa502;">' . number_format($row['high']) . '</td>';
            $monthlySummary .= '<td style="padding:8px; color:#ffd32a;">' . number_format($row['medium']) . '</td>';
            $monthlySummary .= '</tr>';
        }
    }
    $monthlySummary .= '</tbody></table>';
    
    echo json_encode([
        'success' => true,
        'trends' => $trendsData,
        'distribution' => $distribution,
        'severity' => $severity,
        'hourly' => $hourly,
        'weekly' => $weekly,
        'topSources' => $topSources,
        'attackTypes' => $attackTypes,
        'monthlySummary' => $monthlySummary
    ]);
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
