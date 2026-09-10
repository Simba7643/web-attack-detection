<?php
// telegram_poll.php - Fixed to fetch ALL attacks from database
require_once 'config.php';

// ============================================
// YOUR BOT TOKEN
// ============================================
$bot_token = '8773269762:AAGePzp2b0ZMRxW2VqykohFJmjP9wYhJ0z4';
// ============================================

echo "🤖 Telegram Bot Starting in POLLING mode...\n";
echo "Waiting for messages. Press Ctrl+C to stop.\n\n";

set_time_limit(0);
$last_update_id = 0;

while (true) {
    $url = "https://api.telegram.org/bot{$bot_token}/getUpdates?offset=" . ($last_update_id + 1) . "&timeout=30";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 35);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $updates = json_decode($response, true);
        
        if (isset($updates['ok']) && $updates['ok'] && !empty($updates['result'])) {
            foreach ($updates['result'] as $update) {
                $last_update_id = $update['update_id'];
                
                if (isset($update['message']['text'])) {
                    $chat_id = $update['message']['chat']['id'];
                    $text = trim($update['message']['text']);
                    $username = $update['message']['from']['username'] ?? 'User';
                    
                    echo "📩 Message from @{$username}: {$text}\n";
                    
                    $reply = processCommand($text, $pdo);
                    
                    $send_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
                    $send_data = [
                        'chat_id' => $chat_id,
                        'text' => $reply,
                        'parse_mode' => 'HTML'
                    ];
                    
                    $send_ch = curl_init();
                    curl_setopt($send_ch, CURLOPT_URL, $send_url);
                    curl_setopt($send_ch, CURLOPT_POST, true);
                    curl_setopt($send_ch, CURLOPT_POSTFIELDS, http_build_query($send_data));
                    curl_setopt($send_ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($send_ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_exec($send_ch);
                    curl_close($send_ch);
                    
                    echo "✅ Reply sent\n";
                }
            }
        }
    }
    
    sleep(1);
}

function processCommand($text, $pdo) {
    $text_lower = strtolower(trim($text));
    
    // /start command
    if ($text_lower == '/start') {
        return "🤖 *Welcome to Attack Detection Bot!*\n\n" .
               "I can help you monitor your security system.\n\n" .
               "*Commands:*\n" .
               "🔴 /critical - Show ALL critical attacks\n" .
               "📊 /today - Attacks today\n" .
               "📈 /week - Attacks in last 7 days\n" .
               "🎯 /attackers - Top attackers\n" .
               "📊 /all - Show ALL attacks (last 20)\n" .
               "🔒 /status - Security status\n" .
               "❓ /help - All commands";
    }
    
    // /help command
    if ($text_lower == '/help') {
        return "📋 *Available Commands:*\n\n" .
               "🔴 /critical - ALL critical attacks\n" .
               "📊 /today - Attacks today\n" .
               "📈 /week - Attacks in last 7 days\n" .
               "🎯 /attackers - Top 5 attacking IPs\n" .
               "📊 /all - Last 20 attacks (all types)\n" .
               "🔒 /status - Security status report\n" .
               "🔍 /search [IP] - Find attacks by IP\n" .
               "⚔️ /types - Most common attack types\n" .
               "🚫 /blocked - Number of blocked IPs\n" .
               "📊 /risk - Current risk score\n" .
               "😄 /joke - Tell a joke";
    }
    
    // ========== CRITICAL ATTACKS (ALL, not just today) ==========
    if ($text_lower == '/critical') {
        $stmt = $pdo->query("SELECT attacker_ip, attack_type, timestamp FROM attacks WHERE severity = 'critical' ORDER BY timestamp DESC LIMIT 10");
        $attacks = $stmt->fetchAll();
        
        if (count($attacks) == 0) {
            return "✅ *No critical attacks found in database!*";
        }
        
        $response = "🚨 *CRITICAL ATTACKS (All Time)* 🚨\n\n";
        foreach ($attacks as $a) {
            $date = date('Y-m-d H:i', strtotime($a['timestamp']));
            $response .= "• *{$a['attack_type']}* from `{$a['attacker_ip']}`\n  at {$date}\n\n";
        }
        return $response;
    }
    
    // ========== ALL ATTACKS (Last 20) ==========
    if ($text_lower == '/all') {
        $stmt = $pdo->query("SELECT attacker_ip, attack_type, severity, timestamp FROM attacks ORDER BY timestamp DESC LIMIT 20");
        $attacks = $stmt->fetchAll();
        
        if (count($attacks) == 0) {
            return "📭 *No attacks found in database!*\n\nRun your ML detector to start capturing attacks.";
        }
        
        $response = "📊 *LAST 20 ATTACKS*\n\n";
        foreach ($attacks as $a) {
            $date = date('Y-m-d H:i', strtotime($a['timestamp']));
            $severity_icon = $a['severity'] == 'critical' ? '🔴' : ($a['severity'] == 'high' ? '🟠' : '🟡');
            $response .= "{$severity_icon} *{$a['attack_type']}* from `{$a['attacker_ip']}`\n   at {$date}\n\n";
        }
        return $response;
    }
    
    // ========== ATTACKS TODAY ==========
    if ($text_lower == '/today') {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attacks WHERE DATE(timestamp) = CURDATE()");
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            return "✅ *Zero attacks today!* 🎉\n\nLast attack was on " . getLastAttackDate($pdo);
        }
        
        // Also show today's attacks
        $stmt = $pdo->query("SELECT attacker_ip, attack_type, severity, timestamp FROM attacks WHERE DATE(timestamp) = CURDATE() ORDER BY timestamp DESC LIMIT 10");
        $attacks = $stmt->fetchAll();
        
        $response = "📊 *ATTACKS TODAY ({$count})*\n\n";
        foreach ($attacks as $a) {
            $time = date('H:i', strtotime($a['timestamp']));
            $severity_icon = $a['severity'] == 'critical' ? '🔴' : ($a['severity'] == 'high' ? '🟠' : '🟡');
            $response .= "{$severity_icon} *{$a['attack_type']}* from `{$a['attacker_ip']}` at {$time}\n";
        }
        return $response;
    }
    
    // ========== ATTACKS THIS WEEK ==========
    if ($text_lower == '/week') {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attacks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            return "📈 *No attacks in last 7 days!*";
        }
        
        $stmt = $pdo->query("SELECT DATE(timestamp) as date, COUNT(*) as count FROM attacks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(timestamp) ORDER BY date DESC");
        $daily = $stmt->fetchAll();
        
        $response = "📈 *ATTACKS LAST 7 DAYS (Total: {$count})*\n\n";
        foreach ($daily as $d) {
            $response .= "• {$d['date']}: {$d['count']} attacks\n";
        }
        return $response;
    }
    
    // ========== TOP ATTACKERS ==========
    if ($text_lower == '/attackers') {
        $stmt = $pdo->query("SELECT attacker_ip, COUNT(*) as count FROM attacks GROUP BY attacker_ip ORDER BY count DESC LIMIT 5");
        $attackers = $stmt->fetchAll();
        
        if (count($attackers) == 0) {
            return "No attackers found in database.";
        }
        
        $response = "🎯 *TOP ATTACKING IPS*\n\n";
        $rank = 1;
        foreach ($attackers as $a) {
            $response .= "{$rank}. `{$a['attacker_ip']}` - {$a['count']} attacks\n";
            $rank++;
        }
        return $response;
    }
    
    // ========== SECURITY STATUS ==========
    if ($text_lower == '/status') {
        // Total all time
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attacks");
        $all_time = $stmt->fetchColumn();
        
        // Last 7 days
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as critical FROM attacks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats = $stmt->fetch();
        
        // Today
        $stmt = $pdo->query("SELECT COUNT(*) as today FROM attacks WHERE DATE(timestamp) = CURDATE()");
        $today = $stmt->fetchColumn();
        
        $risk = ($stats['critical'] > 5) ? "🔴 HIGH RISK" : (($stats['critical'] > 0) ? "🟡 MEDIUM RISK" : "🟢 LOW RISK");
        $last_attack = getLastAttackDate($pdo);
        
        return "🔒 *SECURITY STATUS REPORT*\n\n" .
               "• All-time attacks: {$all_time}\n" .
               "• Last 7 days: {$stats['total']} attacks\n" .
               "• Critical (7d): {$stats['critical']}\n" .
               "• Today: {$today} attacks\n" .
               "• Risk level: {$risk}\n" .
               "• Last attack: {$last_attack}";
    }
    
    // ========== SEARCH BY IP ==========
    if (preg_match('/^\/search (.+)$/i', $text_lower, $matches)) {
        $ip = $matches[1];
        $stmt = $pdo->prepare("SELECT attack_type, severity, timestamp FROM attacks WHERE attacker_ip = ? ORDER BY timestamp DESC LIMIT 10");
        $stmt->execute([$ip]);
        $attacks = $stmt->fetchAll();
        
        if (count($attacks) == 0) {
            return "No attacks found from IP: `{$ip}`";
        }
        
        $response = "🔍 *ATTACKS FROM {$ip}*\n\n";
        foreach ($attacks as $a) {
            $date = date('Y-m-d H:i', strtotime($a['timestamp']));
            $response .= "• {$a['attack_type']} ({$a['severity']}) at {$date}\n";
        }
        return $response;
    }
    
    // ========== ATTACK TYPES ==========
    if ($text_lower == '/types') {
        $stmt = $pdo->query("SELECT attack_type, COUNT(*) as count FROM attacks GROUP BY attack_type ORDER BY count DESC");
        $types = $stmt->fetchAll();
        
        if (count($types) == 0) {
            return "No attack data available.";
        }
        
        $response = "⚔️ *ATTACK TYPE DISTRIBUTION*\n\n";
        foreach ($types as $t) {
            $response .= "• {$t['attack_type']}: {$t['count']} attacks\n";
        }
        return $response;
    }
    
    // ========== BLOCKED IPS ==========
    if ($text_lower == '/blocked') {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ip_reputation WHERE threat_level IN ('critical', 'high')");
        $count = $stmt->fetchColumn();
        return "🚫 *Blocked IPs:* {$count}";
    }
    
    // ========== RISK SCORE ==========
    if ($text_lower == '/risk') {
        $stmt = $pdo->query("SELECT SUM(CASE WHEN severity='critical' THEN 100 WHEN severity='high' THEN 70 ELSE 0 END) as score FROM attacks WHERE DATE(timestamp) = CURDATE()");
        $score_data = $stmt->fetch();
        $score = min(100, ($score_data['score'] / 100));
        $level = $score >= 80 ? 'Critical' : ($score >= 60 ? 'High' : ($score >= 40 ? 'Medium' : 'Low'));
        return "📊 *Risk Score:* " . round($score) . "% - {$level} Risk";
    }
    
    // ========== JOKE ==========
    if ($text_lower == '/joke') {
        $jokes = [
            "Why do hackers wear gloves? To avoid leaving fingerprints! 😄",
            "Why did the SQL query break up? It found someone with more 'connections'! 💔",
            "Why do programmers prefer dark mode? Because light attracts bugs! 🐛",
            "What's a firewall's favorite song? 'Stop! In the Name of Love' 🎵",
            "Why do security analysts love coffee? Because it helps them stay alert! ☕"
        ];
        return $jokes[array_rand($jokes)];
    }
    
    // ========== GREETINGS ==========
    if (preg_match('/^(hi|hello|hey)$/i', $text_lower)) {
        return "👋 Hello! Type /help to see what I can do!\n\nI can show you ALL attacks from your database, not just today's.";
    }
    
    // ========== HOW ARE YOU ==========
    if (preg_match('/how are you/i', $text_lower)) {
        return "🤖 I'm doing great! Monitoring your system 24/7!\n\nI have access to ALL attack data in your database.";
    }
    
    // ========== GOODBYE ==========
    if (preg_match('/bye|goodbye/i', $text_lower)) {
        return "👋 Goodbye! Stay secure! Type /start to see me again.";
    }
    
    // ========== DEFAULT ==========
    return "🤔 I didn't understand that.\n\nTry:\n• /critical - Show ALL critical attacks\n• /all - Show last 20 attacks\n• /today - Today's attacks\n• /week - Weekly summary\n• /help - All commands";
}

function getLastAttackDate($pdo) {
    $stmt = $pdo->query("SELECT timestamp FROM attacks ORDER BY timestamp DESC LIMIT 1");
    $last = $stmt->fetch();
    if ($last) {
        return date('Y-m-d H:i', strtotime($last['timestamp']));
    }
    return "No attacks ever recorded";
}
?>