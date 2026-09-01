<?php
// get_ai_analytics.php - AI-Powered Executive Dashboard Data
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

try {
    // 1. Calculate Risk Score
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN severity = 'critical' THEN 100 ELSE 0 END) as critical_score,
            SUM(CASE WHEN severity = 'high' THEN 70 ELSE 0 END) as high_score,
            SUM(CASE WHEN severity = 'medium' THEN 40 ELSE 0 END) as medium_score,
            COUNT(*) as total
        FROM attacks 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $scores = $stmt->fetch();
    
    $total_risk_score = min(100, ($scores['critical_score'] + $scores['high_score'] + $scores['medium_score']) / 100);
    $risk_level = $total_risk_score >= 80 ? 'Critical' : ($total_risk_score >= 60 ? 'High' : ($total_risk_score >= 40 ? 'Medium' : 'Low'));
    
    // 2. Business Impact Assessment
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT attacker_ip) as unique_attackers,
            COUNT(*) as total_attacks,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count
        FROM attacks 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $weekly_stats = $stmt->fetch();
    
    $business_impact = [
        'data_breach_risk' => $weekly_stats['critical_count'] > 10 ? 'High' : ($weekly_stats['critical_count'] > 3 ? 'Medium' : 'Low'),
        'estimated_affected_assets' => $weekly_stats['unique_attackers'] * 5,
        'recommended_action' => $weekly_stats['critical_count'] > 5 ? 'Immediate incident response required' : 'Continue monitoring'
    ];
    
    // 3. Compliance Status
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as critical_24h
        FROM attacks 
        WHERE severity = 'critical' AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $compliance = $stmt->fetch();
    
    $compliance_status = [
        'pci_dss' => $compliance['critical_24h'] > 0 ? 'Violation Detected' : 'Compliant',
        'gdpr' => $compliance['critical_24h'] > 3 ? 'Breach Risk' : 'Compliant',
        'hipaa' => 'Monitoring Active'
    ];
    
    // 4. AI-Generated Executive Summary
    $executive_summary = generateExecutiveSummary($weekly_stats, $total_risk_score);
    
    echo json_encode([
        'success' => true,
        'risk_score' => $total_risk_score,
        'risk_level' => $risk_level,
        'risk_color' => $risk_level == 'Critical' ? '#ff4757' : ($risk_level == 'High' ? '#ffa502' : '#ffd32a'),
        'business_impact' => $business_impact,
        'compliance_status' => $compliance_status,
        'executive_summary' => $executive_summary,
        'recommendations' => generateRecommendations($weekly_stats)
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function generateExecutiveSummary($stats, $risk_score) {
    if ($stats['total_attacks'] == 0) {
        return "✅ System is secure. No attacks detected in the last 7 days.";
    }
    
    $summary = "📊 In the last 7 days, ";
    $summary .= "{$stats['total_attacks']} attacks were detected ";
    $summary .= "from {$stats['unique_attackers']} unique sources. ";
    
    if ($stats['critical_count'] > 0) {
        $summary .= "⚠️ {$stats['critical_count']} critical attacks require immediate attention. ";
    }
    
    $summary .= "Overall risk score: " . round($risk_score) . "%. ";
    
    return $summary;
}

function generateRecommendations($stats) {
    $recs = [];
    
    if ($stats['critical_count'] > 5) {
        $recs[] = "🚨 URGENT: Implement immediate IP blocking for all critical threat sources";
    }
    if ($stats['unique_attackers'] > 10) {
        $recs[] = "🛡️ Consider enabling auto-blocking for repeated offenders";
    }
    if ($stats['total_attacks'] > 100) {
        $recs[] = "📈 High attack volume detected - Review firewall rules";
    }
    
    if (empty($recs)) {
        $recs[] = "✅ Current security posture is good. Continue monitoring.";
    }
    
    return $recs;
}
?>
