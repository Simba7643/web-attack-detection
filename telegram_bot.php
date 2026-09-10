<?php
// telegram_bot.php - COMPLETE BOT WITH MENU BUTTON & AUTO PUSH
// Fully dynamic - reads authorized users from database

require_once 'config.php';

// ============================================
// BOT CONFIGURATION
// ============================================
$bot_token = '8773269762:AAGePzp2b0ZMRxW2VqykohFJmjP9wYhJ0z4';

// File to track last sent attack ID
$track_file = __DIR__ . '/last_attack_id.txt';
$log_file = __DIR__ . '/telegram_bot.log';
$menu_setup_file = __DIR__ . '/menu_setup.done';

// ============================================
// SETUP MENU BUTTON (Run once automatically)
// ============================================
if (!file_exists($menu_setup_file)) {
    $commands = [
        ['command' => 'start', 'description' => '🚀 Start the bot and see welcome message'],
        ['command' => 'help', 'description' => '❓ Show all available commands'],
        ['command' => 'critical', 'description' => '🔴 Last 10 critical attacks'],
        ['command' => 'today', 'description' => '📊 Attacks detected today'],
        ['command' => 'week', 'description' => '📈 Attacks in last 7 days'],
        ['command' => 'attackers', 'description' => '🎯 Top 5 attacking IPs'],
        ['command' => 'all', 'description' => '📋 Last 20 attacks'],
        ['command' => 'status', 'description' => '🔒 Current security status'],
        ['command' => 'types', 'description' => '⚔️ Most common attack types'],
        ['command' => 'blocked', 'description' => '🚫 Number of blocked IPs'],
        ['command' => 'myinfo', 'description' => '👤 Your account information'],
        ['command' => 'search', 'description' => '🔍 Search attacks by IP (use: /search 1.2.3.4)']
    ];
    
    $url = "https://api.telegram.org/bot{$bot_token}/setMyCommands";
    $post_data = json_encode(['commands' => $commands]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    file_put_contents($menu_setup_file, date('Y-m-d H:i:s'));
    log_message("✅ Menu button commands set successfully");
}

// ============================================
// FUNCTIONS
// ============================================
function log_message($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
}

function sendMessage($chat_id, $message) {
    global $bot_token;
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code == 200;
}

// Get all authorized users from database
function getAuthorizedUsers($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, username, telegram_chat_id, telegram_username, telegram_notifications 
        FROM users 
        WHERE telegram_chat_id IS NOT NULL 
        AND telegram_chat_id != ''
        AND telegram_notifications = 1
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Check if a specific chat_id is authorized
function isAuthorized($chat_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT id, username, telegram_username 
        FROM users 
        WHERE telegram_chat_id = ? AND telegram_notifications = 1
    ");
    $stmt->execute([$chat_id]);
    return $stmt->fetch();
}

// ========== AUTO PUSH NOTIFICATION FUNCTION ==========
function checkAndSendNewAttacks($pdo, $last_sent_id) {
    global $track_file;
    
    $users = getAuthorizedUsers($pdo);
    if (empty($users)) {
        return $last_sent_id;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, attacker_ip, attack_type, severity, confidence, target_url, timestamp 
        FROM attacks 
        WHERE id > ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$last_sent_id]);
    $new_attacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $new_last_id = $last_sent_id;
    
    foreach ($new_attacks as $attack) {
        $new_last_id = max($new_last_id, $attack['id']);
        
        if ($attack['severity'] == 'critical') {
            $emoji = "🚨🔴🚨";
            $title = "CRITICAL ATTACK DETECTED!";
            $priority = "⚠️ IMMEDIATE ACTION REQUIRED ⚠️";
        } elseif ($attack['severity'] == 'high') {
            $emoji = "⚠️🔶⚠️";
            $title = "HIGH SEVERITY ATTACK";
            $priority = "⚠️ Action recommended";
        } else {
            $emoji = "📢🟡📢";
            $title = "MEDIUM SEVERITY ATTACK";
            $priority = "Monitor situation";
        }
        
        $message = "
{$emoji} <b>{$title}</b> {$emoji}

<b>Attack Type:</b> {$attack['attack_type']}
<b>Source IP:</b> <code>{$attack['attacker_ip']}</code>
<b>Severity:</b> <b>" . strtoupper($attack['severity']) . "</b>
<b>Confidence:</b> " . ($attack['confidence'] ? ($attack['confidence'] * 100) . '%' : 'High') . "
<b>Target:</b> " . (substr($attack['target_url'] ?? '', 0, 60)) . "
<b>Time:</b> " . date('Y-m-d H:i:s', strtotime($attack['timestamp'])) . "

{$priority}

💡 <i>Block this IP using the dashboard or /block command</i>
        ";
        
        foreach ($users as $user) {
            if (!empty($user['telegram_chat_id'])) {
                sendMessage($user['telegram_chat_id'], $message);
                log_message("📨 AUTO PUSH: Sent {$attack['severity']} attack to @{$user['telegram_username']}");
                
                if ($attack['severity'] == 'critical') {
                    $short_msg = "🚨 <b>CRITICAL!</b> {$attack['attack_type']} from <code>{$attack['attacker_ip']}</code>";
                    sendMessage($user['telegram_chat_id'], $short_msg);
                }
            }
        }
    }
    
    return $new_last_id;
}

// ============================================
// COMMAND PROCESSOR
// ============================================
function processCommand($text, $pdo, $user) {
    $text_lower = strtolower(trim($text));
    $username = $user['username'];
    $telegram_username = $user['telegram_username'] ?? $username;
    
    // /start command
    if ($text_lower == '/start') {
        return "🤖 *Welcome " . htmlspecialchars($username) . "!*\n\n" .
               "You are authorized to use the *ML Shield Security Bot*\n\n" .
               "📊 *Available Commands:*\n" .
               "─────────────────\n" .
               "🔴 /critical - Last 10 critical attacks\n" .
               "📊 /today - Attacks today\n" .
               "📈 /week - Attacks last 7 days\n" .
               "🎯 /attackers - Top 5 attackers\n" .
               "📋 /all - Last 20 attacks\n" .
               "🔒 /status - Security status\n" .
               "🔍 /search [IP] - Find attacks by IP\n" .
               "⚔️ /types - Attack distribution\n" .
               "🚫 /blocked - Blocked IPs count\n" .
               "👤 /myinfo - Your account info\n" .
               "❓ /help - Show all commands\n\n" .
               "⚡ I'll automatically alert you when attacks are detected!\n\n" .
               "💡 *Tip:* Type / in the chat to see all commands as buttons!";
    }
    
    // /myinfo command
    if ($text_lower == '/myinfo' || $text_lower == '/whoami') {
        $stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $chat_id = $stmt->fetchColumn();
        
        return "👤 *Your Account Information*\n\n" .
               "System Username: *{$username}*\n" .
               "Telegram Username: @{$telegram_username}\n" .
               "Chat ID: `{$chat_id}`\n" .
               "Role: Administrator\n\n" .
               "📅 Time: " . date('Y-m-d H:i:s');
    }
    
    // /help command
    if ($text_lower == '/help') {
        return "📋 *Complete Command List*\n\n" .
               "🔴 `/critical` - Last 10 critical severity attacks\n" .
               "📊 `/today` - Total attacks detected today\n" .
               "📈 `/week` - Total attacks in last 7 days\n" .
               "🎯 `/attackers` - Top 5 most active attackers\n" .
               "📋 `/all` - Last 20 attacks (all types)\n" .
               "🔒 `/status` - Current security posture\n" .
               "🔍 `/search 192.168.1.1` - Find attacks from specific IP\n" .
               "⚔️ `/types` - Most common attack types\n" .
               "🚫 `/blocked` - Number of blocked IP addresses\n" .
               "👤 `/myinfo` - Your account information\n" .
               "❓ `/help` - Show this menu\n\n" .
               "💡 *Tip:* I send automatic alerts for new attacks!\n\n" .
               "📱 *Pro tip:* Type `/` in the chat to see all commands as buttons!";
    }
    
    // /critical command
    if ($text_lower == '/critical') {
        $stmt = $pdo->query("SELECT attacker_ip, attack_type, target_url, timestamp FROM attacks WHERE severity='critical' ORDER BY timestamp DESC LIMIT 10");
        $rows = $stmt->fetchAll();
        if (!$rows) return "✅ *No critical attacks found!* Your system is secure.";
        
        $res = "🚨 *CRITICAL ATTACKS* 🚨\n\n";
        foreach ($rows as $r) {
            $time = date('Y-m-d H:i', strtotime($r['timestamp']));
            $res .= "🔴 *{$r['attack_type']}*\n";
            $res .= "   📍 IP: `{$r['attacker_ip']}`\n";
            $res .= "   🎯 Target: " . substr($r['target_url'] ?? 'Unknown', 0, 40) . "\n";
            $res .= "   ⏰ Time: {$time}\n\n";
        }
        return $res;
    }
    
    // /today command
    if ($text_lower == '/today') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM attacks WHERE DATE(timestamp)=CURDATE()");
        $count = $stmt->fetchColumn();
        
        $stmt2 = $pdo->query("SELECT COUNT(*) FROM attacks WHERE DATE(timestamp)=CURDATE() AND severity='critical'");
        $critical = $stmt2->fetchColumn();
        
        $stmt3 = $pdo->query("SELECT COUNT(*) FROM attacks WHERE DATE(timestamp)=CURDATE() AND severity='high'");
        $high = $stmt3->fetchColumn();
        
        $response = "📊 *Today's Attack Report*\n\n" .
                    "🔴 Critical: {$critical}\n" .
                    "🟠 High: {$high}\n" .
                    "────────────────\n" .
                    "📌 *Total: {$count}* attacks\n\n";
        
        if ($count > 0) {
            $response .= "⚠️ System under active attack!";
        } else {
            $response .= "✅ System secure!";
        }
        
        return $response;
    }
    
    // /week command
    if ($text_lower == '/week') {
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as critical FROM attacks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats = $stmt->fetch();
        
        $stmt2 = $pdo->query("
            SELECT DATE(timestamp) as date, COUNT(*) as count 
            FROM attacks 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
            GROUP BY DATE(timestamp) 
            ORDER BY date DESC
        ");
        $daily = $stmt2->fetchAll();
        
        $res = "📈 *Last 7 Days Summary*\n\n";
        $res .= "Total: *{$stats['total']}* attacks\n";
        $res .= "Critical: *{$stats['critical']}*\n\n";
        $res .= "📅 *Daily Breakdown:*\n";
        
        foreach ($daily as $d) {
            $res .= "   {$d['date']}: {$d['count']} attacks\n";
        }
        
        return $res;
    }
    
    // /attackers command
    if ($text_lower == '/attackers') {
        $stmt = $pdo->query("
            SELECT attacker_ip, COUNT(*) as cnt, MAX(severity) as max_severity 
            FROM attacks 
            GROUP BY attacker_ip 
            ORDER BY cnt DESC 
            LIMIT 5
        ");
        $rows = $stmt->fetchAll();
        if (!$rows) return "No attackers found in database.";
        
        $res = "🎯 *Top Attackers*\n\n";
        $i = 1;
        foreach ($rows as $r) {
            $icon = $r['max_severity'] == 'critical' ? '🔴' : ($r['max_severity'] == 'high' ? '🟠' : '🟡');
            $res .= "{$i}. {$icon} `{$r['attacker_ip']}`\n";
            $res .= "   └─ {$r['cnt']} attacks\n\n";
            $i++;
        }
        return $res;
    }
    
    // /all command
    if ($text_lower == '/all') {
        $stmt = $pdo->query("
            SELECT attacker_ip, attack_type, severity, timestamp 
            FROM attacks 
            ORDER BY timestamp DESC 
            LIMIT 20
        ");
        $rows = $stmt->fetchAll();
        if (!$rows) return "No attacks found in database.";
        
        $res = "📋 *LAST 20 ATTACKS*\n\n";
        foreach ($rows as $r) {
            $icon = $r['severity'] == 'critical' ? '🔴' : ($r['severity'] == 'high' ? '🟠' : '🟡');
            $time = date('H:i:s', strtotime($r['timestamp']));
            $res .= "{$icon} *{$r['attack_type']}*\n";
            $res .= "   From: `{$r['attacker_ip']}` at {$time}\n\n";
        }
        return $res;
    }
    
    // /status command
    if ($text_lower == '/status') {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM attacks");
        $all_time = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as critical FROM attacks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $week = $stmt->fetch();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM attacks WHERE DATE(timestamp)=CURDATE()");
        $today = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM blocked_ips");
        $blocked = $stmt->fetchColumn();
        
        if ($week['critical'] > 10) $risk = "🔴 CRITICAL RISK";
        elseif ($week['critical'] > 3) $risk = "🟠 HIGH RISK";
        elseif ($week['critical'] > 0) $risk = "🟡 MEDIUM RISK";
        else $risk = "🟢 LOW RISK";
        
        $last_attack = $pdo->query("SELECT timestamp FROM attacks ORDER BY timestamp DESC LIMIT 1")->fetchColumn();
        
        return "🔒 *SECURITY STATUS REPORT*\n\n" .
               "👤 User: {$username}\n" .
               "🎯 Risk Level: {$risk}\n\n" .
               "📊 *Statistics:*\n" .
               "   • All-time: {$all_time} attacks\n" .
               "   • Last 7 days: {$week['total']} attacks\n" .
               "   • Critical (7d): {$week['critical']}\n" .
               "   • Today: {$today} attacks\n" .
               "   • Blocked IPs: {$blocked}\n\n" .
               "⏰ Last attack: " . ($last_attack ? date('Y-m-d H:i', strtotime($last_attack)) : 'Never') . "\n\n" .
               ($week['critical'] > 0 ? "⚠️ *Action Required:* Investigate critical attacks immediately!" : "✅ *Status:* System operating normally.");
    }
    
    // /search command
    if (preg_match('/^\/search (.+)$/i', $text, $matches)) {
        $ip = trim($matches[1]);
        $stmt = $pdo->prepare("
            SELECT attack_type, severity, target_url, timestamp 
            FROM attacks 
            WHERE attacker_ip = ? 
            ORDER BY timestamp DESC 
            LIMIT 10
        ");
        $stmt->execute([$ip]);
        $rows = $stmt->fetchAll();
        
        if (!$rows) return "🔍 No attacks found from IP: `{$ip}`";
        
        $res = "🔍 *Attacks from `{$ip}`*\n\n";
        foreach ($rows as $r) {
            $icon = $r['severity'] == 'critical' ? '🔴' : ($r['severity'] == 'high' ? '🟠' : '🟡');
            $time = date('Y-m-d H:i', strtotime($r['timestamp']));
            $res .= "{$icon} *{$r['attack_type']}* ({$r['severity']})\n";
            $res .= "   🎯 {$r['target_url']}\n";
            $res .= "   ⏰ {$time}\n\n";
        }
        return $res;
    }
    
    // /types command
    if ($text_lower == '/types') {
        $stmt = $pdo->query("
            SELECT attack_type, COUNT(*) as cnt 
            FROM attacks 
            GROUP BY attack_type 
            ORDER BY cnt DESC 
            LIMIT 10
        ");
        $rows = $stmt->fetchAll();
        if (!$rows) return "No attack data available.";
        
        $total = array_sum(array_column($rows, 'cnt'));
        $res = "⚔️ *ATTACK TYPE DISTRIBUTION*\n\n";
        
        foreach ($rows as $r) {
            $percent = round(($r['cnt'] / $total) * 100);
            $bar = str_repeat("█", ceil($percent / 5));
            $res .= "• *{$r['attack_type']}*: {$r['cnt']} ({$percent}%)\n";
            $res .= "  {$bar}\n\n";
        }
        return $res;
    }
    
    // /blocked command
    if ($text_lower == '/blocked') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM blocked_ips");
        $count = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT ip, reason, blocked_at FROM blocked_ips ORDER BY blocked_at DESC LIMIT 5");
        $recent = $stmt->fetchAll();
        
        $res = "🚫 *Blocked IPs*\n\n";
        $res .= "Total blocked: *{$count}*\n\n";
        
        if ($recent) {
            $res .= "📌 *Recently blocked:*\n";
            foreach ($recent as $ip) {
                $res .= "   • `{$ip['ip']}` - " . date('Y-m-d', strtotime($ip['blocked_at'])) . "\n";
            }
        }
        
        return $res;
    }
    
    // Default response for unknown commands
    if (!empty($text) && substr($text, 0, 1) == '/') {
        return "🤔 Unknown command: `{$text}`\n\nType /help to see available commands, {$username}!\n\n💡 *Tip:* Type `/` in the chat to see all commands as buttons!";
    }
    
    // Natural language responses
    if (preg_match('/(hi|hello|hey|sup)/i', $text_lower)) {
        return "👋 Hello {$username}! (@{$telegram_username})\n\nYour security bot is ready!\n\n💡 *Tip:* Type `/` in the chat to see all commands as buttons!\n\nType /help to see what I can do.";
    }
    
    if (preg_match('/(bye|goodbye|see you)/i', $text_lower)) {
        return "👋 Goodbye {$username}! I'll keep monitoring. Type /start anytime! 🔒";
    }
    
    if (preg_match('/(thank|thanks)/i', $text_lower)) {
        return "🙏 You're welcome, {$username}! I'm here 24/7 to protect your system. 🛡️";
    }
    
    return "👋 Hey {$username}! I didn't understand that.\n\nTry:\n• `/help` - Show all commands\n• `/status` - Security status\n• `/today` - Today's attacks\n\n💡 *Tip:* Type `/` in the chat to see all commands as buttons!";
}

// ============================================
// MAIN BOT LOOP
// ============================================
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║              🤖 ML SHIELD TELEGRAM BOT (COMPLETE)             ║\n";
echo "╠════════════════════════════════════════════════════════════════╣\n";
echo "║  Status:     ✅ RUNNING                                        ║\n";
echo "║  Mode:       Database-driven (no hardcoded users)             ║\n";
echo "║  Bot:        @nahAbe_bot                                       ║\n";
echo "║  Menu:       ✅ Type '/' to see commands                       ║\n";
echo "║  Auto-Push:  ✅ Enabled (sends alerts for new attacks)        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Load last sent attack ID
$last_sent_attack_id = file_exists($track_file) ? (int)file_get_contents($track_file) : 0;

log_message("🚀 Bot started in DYNAMIC mode");
echo "📝 Log file: $log_file\n";
echo "👥 Authorized users: " . count(getAuthorizedUsers($pdo)) . " found in database\n";
echo "💬 Send commands to @nahAbe_bot on Telegram\n";
echo "💡 Tip: Type '/' in the chat to see all commands as buttons!\n";
echo "🛑 Press Ctrl+C to stop the bot\n\n";

$last_update_id = 0;

while (true) {
    try {
        // Check for new attacks (auto push)
        $new_last_id = checkAndSendNewAttacks($pdo, $last_sent_attack_id);
        if ($new_last_id != $last_sent_attack_id) {
            file_put_contents($track_file, $new_last_id);
            $last_sent_attack_id = $new_last_id;
        }
        
        // Check for incoming commands
        $url = "https://api.telegram.org/bot{$bot_token}/getUpdates?timeout=30&offset=" . ($last_update_id + 1);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 35);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $updates = json_decode($response, true);
        
        if ($updates && isset($updates['ok']) && $updates['ok'] && !empty($updates['result'])) {
            foreach ($updates['result'] as $update) {
                $last_update_id = $update['update_id'];
                
                if (isset($update['message'])) {
                    $msg = $update['message'];
                    $chat_id = $msg['chat']['id'];
                    $username = $msg['from']['username'] ?? 'unknown';
                    $text = trim($msg['text'] ?? '');
                    
                    echo "📩 [" . date('H:i:s') . "] From @{$username} (Chat ID: {$chat_id}): {$text}\n";
                    log_message("📩 From @{$username} (Chat ID: {$chat_id}): {$text}");
                    
                    // Check authorization
                    $authorized_user = isAuthorized($chat_id, $pdo);
                    
                    if (!$authorized_user) {
                        echo "   ⛔ UNAUTHORIZED - Not in database\n";
                        log_message("🚫 Unauthorized access from Chat ID: {$chat_id}");
                        sendMessage($chat_id, "⛔ *Access Denied*\n\nYour Telegram account is not authorized to use this bot.\n\nContact your system administrator to get access.");
                        continue;
                    }
                    
                    // Process command
                    echo "   ✅ Authorized user: {$authorized_user['username']}\n";
                    $reply = processCommand($text, $pdo, $authorized_user);
                    sendMessage($chat_id, $reply);
                    echo "   ✅ Reply sent\n";
                    log_message("✅ Replied to @{$username} ({$authorized_user['username']})");
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        log_message("❌ Error: " . $e->getMessage());
        sleep(5);
    }
    
    sleep(1);
}
?>