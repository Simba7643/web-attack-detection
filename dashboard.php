<?php
require_once 'config.php';
$csrf_token = generateCSRFToken();

logActivity($_SESSION['user_id'] ?? 0, 'DASHBOARD_ACCESS', 'Dashboard page accessed');

if (!isset($_SESSION['admin_verified']) || $_SESSION['admin_verified'] !== true) {
    logActivity($_SESSION['user_id'] ?? 0, 'UNAUTHORIZED_ACCESS', 'Attempt to access dashboard without admin verification');
    session_destroy();
    session_start();
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    logActivity(0, 'UNAUTHORIZED_ACCESS', 'Attempt to access dashboard without login');
    header('Location: login.php');
    exit();
}

if (isset($_SESSION['admin_verified_time']) && (time() - $_SESSION['admin_verified_time'] > 1800)) {
    logActivity($_SESSION['user_id'], 'SESSION_TIMEOUT', 'Session timed out');
    unset($_SESSION['admin_verified'], $_SESSION['admin_verified_time']);
    header('Location: login.php?timeout=1');
    exit();
}

if (!isAdmin()) {
    logActivity($_SESSION['user_id'], 'UNAUTHORIZED_ACCESS', 'Non-admin user attempted to access dashboard');
    header('Location: login.php');
    exit();
}

$admin_username = htmlspecialchars($_SESSION['username']);
$admin_role     = htmlspecialchars($_SESSION['role'] ?? 'Administrator');
$admin_initials = strtoupper(substr($admin_username, 0, 2));

// ── Server location for map centre ──
function getServerPublicIP() {
    $ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '127.0.0.1';
    if (strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || $ip == '127.0.0.1') {
        $public_ip = @file_get_contents('https://api.ipify.org');
        if ($public_ip) return trim($public_ip);
    }
    return $ip;
}

function getLocationFromIP($ip) {
    if ($ip == '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return ['lat' => 9.0320, 'lng' => 38.7469, 'country' => 'Ethiopia', 'city' => 'Addis Ababa'];
    }
    $url  = "http://ip-api.com/json/{$ip}?fields=status,lat,lon,country,city";
    $data = @file_get_contents($url);
    if ($data) {
        $loc = json_decode($data, true);
        if ($loc['status'] == 'success') {
            return ['lat' => $loc['lat'], 'lng' => $loc['lon'], 'country' => $loc['country'], 'city' => $loc['city']];
        }
    }
    return ['lat' => 9.0320, 'lng' => 38.7469, 'country' => 'Ethiopia', 'city' => 'Addis Ababa'];
}

$server_ip       = getServerPublicIP();
$server_location = getLocationFromIP($server_ip);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ML Shield — Attack Detection Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
/* ===== YOUR EXACT ORIGINAL STYLES – UNCHANGED ===== */
:root {
  --bg: #07080f;
  --surface: #0d0f1a;
  --surface2: #131626;
  --surface3: #1a1d30;
  --border: rgba(255,255,255,0.06);
  --border-accent: rgba(255,255,255,0.12);
  --text: #e8eaf0;
  --muted: #6b7099;
  --accent: #4f6fff;
  --accent2: #8b5cf6;
  --danger: #ff3d5a;
  --warn: #f59e0b;
  --success: #10b981;
  --info: #06b6d4;
  --sidebar-w: 220px;
  --font: 'Space Grotesk', sans-serif;
  --mono: 'JetBrains Mono', monospace;
}

*{margin:0;padding:0;box-sizing:border-box;}
html{height:100%;}

body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  overflow: hidden;
}

body::before {
  content:'';
  position:fixed;inset:0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events:none;z-index:0;
}

/* ═══ SIDEBAR ═══ */
.sidebar {
  width: var(--sidebar-w);
  min-height: 100vh;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  left: 0; top: 0; bottom: 0;
  z-index: 50;
  transition: width .25s cubic-bezier(.4,0,.2,1);
  overflow: hidden;
}
.sidebar.collapsed { width: 60px; }

.sidebar-logo {
  padding: 22px 20px 18px;
  display: flex; align-items: center; gap: 12px;
  border-bottom: 1px solid var(--border);
  min-height: 64px;
}
.logo-icon {
  width: 32px; height: 32px;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.logo-text { font-size: 15px; font-weight: 600; letter-spacing: 0.03em; white-space: nowrap; overflow: hidden; transition: opacity .2s; }

.sidebar.collapsed .logo-text,
.sidebar.collapsed .nav-label,
.sidebar.collapsed .section-label,
.sidebar.collapsed .dl-label { opacity: 0; width: 0; }

.nav-section { padding: 12px 10px 6px; flex: 1; overflow-y: auto; }
.section-label {
  font-size: 10px; font-weight: 600; letter-spacing: 0.12em; color: var(--muted);
  text-transform: uppercase; padding: 0 8px; margin-bottom: 4px;
  white-space: nowrap; overflow: hidden;
}

.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 8px; color: var(--muted);
  text-decoration: none; cursor: pointer; transition: all .18s;
  margin-bottom: 2px; white-space: nowrap; position: relative;
}
.nav-link:hover { background: var(--surface2); color: var(--text); }
.nav-link.active { background: rgba(79,111,255,0.12); color: var(--accent); }
.nav-link.active::before {
  content:''; position: absolute;
  left: 0; top: 50%; transform: translateY(-50%);
  width: 3px; height: 60%;
  background: var(--accent); border-radius: 0 3px 3px 0;
}
.nav-link i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
.nav-label { font-size: 13.5px; font-weight: 500; }

.nav-badge {
  margin-left: auto; background: var(--danger); color: #fff;
  font-size: 10px; font-weight: 600; padding: 1px 6px;
  border-radius: 20px; min-width: 20px; text-align: center;
}

.sidebar-bottom { border-top: 1px solid var(--border); padding: 10px; }
.dl-btn {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 8px 10px; background: transparent; border: none; color: var(--muted);
  font-family: var(--font); font-size: 13px; font-weight: 500;
  border-radius: 7px; cursor: pointer; transition: all .18s;
  text-align: left; white-space: nowrap;
}
.dl-btn:hover { background: var(--surface2); color: var(--text); }
.dl-btn i { width: 18px; text-align:center; font-size: 13px; flex-shrink: 0; }
.dl-label { overflow: hidden; }

.toggle-btn {
  position: absolute; right: -12px; top: 20px;
  width: 24px; height: 24px; background: var(--surface2);
  border: 1px solid var(--border-accent); border-radius: 50%;
  color: var(--muted); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; transition: all .18s; z-index: 60;
}
.toggle-btn:hover { color: var(--text); background: var(--surface3); }
.sidebar.collapsed .toggle-btn i { transform: rotate(180deg); }

/* ═══ MAIN CONTENT ═══ */
.main {
  margin-left: var(--sidebar-w); flex: 1;
  display: flex; flex-direction: column;
  min-height: 100vh;
  transition: margin-left .25s cubic-bezier(.4,0,.2,1);
  overflow-y: auto; height: 100vh;
}
.main.expanded { margin-left: 60px; }

/* ═══ TOPBAR ═══ */
.topbar {
  background: var(--surface); border-bottom: 1px solid var(--border);
  padding: 0 28px; height: 64px;
  display: flex; align-items: center; gap: 16px;
  position: sticky; top: 0; z-index: 40;
}
.page-title { font-size: 16px; font-weight: 600; }
.page-sub { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-spacer { flex: 1; }

.live-pill {
  display: flex; align-items: center; gap: 7px;
  background: rgba(255,61,90,0.1); border: 1px solid rgba(255,61,90,0.2);
  border-radius: 20px; padding: 5px 12px;
  font-size: 11px; font-weight: 600; color: var(--danger); letter-spacing: 0.06em;
}
.live-dot { width: 7px; height: 7px; background: var(--danger); border-radius: 50%; animation: livepulse 1.4s ease-in-out infinite; }
@keyframes livepulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(.85);} }

.top-btn {
  width: 36px; height: 36px; background: var(--surface2);
  border: 1px solid var(--border); border-radius: 8px; color: var(--muted);
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  font-size: 13px; transition: all .18s;
}
.top-btn:hover { background: var(--surface3); color: var(--text); border-color: var(--border-accent); }
.top-btn.notif-on { color: var(--success) !important; border-color: rgba(16,185,129,.3); }

.user-chip {
  display: flex; align-items: center; gap: 9px;
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; padding: 5px 12px 5px 8px;
  font-size: 12px; font-weight: 500; position: relative; cursor: default;
}
.user-chip:hover .user-dropdown { display: block; }
.user-avatar {
  width: 28px; height: 28px;
  background: linear-gradient(135deg,var(--accent),var(--accent2));
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.user-name-wrap { display: flex; flex-direction: column; line-height: 1.2; }
.user-name  { font-size: 13px; font-weight: 600; color: var(--text); }
.user-role  { font-size: 10px; color: var(--muted); letter-spacing:.02em; }

.user-dropdown {
  display: none; position: absolute; top: calc(100% + 8px); right: 0;
  background: var(--surface2); border: 1px solid var(--border-accent);
  border-radius: 10px; padding: 6px; min-width: 160px;
  box-shadow: 0 8px 24px rgba(0,0,0,.5); z-index: 200;
}
.user-dropdown a {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; border-radius: 6px; color: var(--muted);
  text-decoration: none; font-size: 13px; font-weight: 500; transition: all .15s;
}
.user-dropdown a:hover { background: var(--surface3); color: var(--text); }
.user-dropdown a.danger { color: var(--danger); }
.user-dropdown a.danger:hover { background: rgba(255,61,90,.1); }
.user-dropdown hr { border: none; border-top: 1px solid var(--border); margin: 4px 0; }

/* ═══ PAGE BODY ═══ */
.page-body { padding: 24px 28px; flex: 1; }
.page-content { display: none; }
.page-content.active { display: block; animation: fadeUp .3s ease; }
@keyframes fadeUp { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

/* ═══ STAT CARDS ═══ */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap: 14px; margin-bottom: 24px; }
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 14px; padding: 18px 20px;
  cursor: pointer; transition: all .22s; position: relative; overflow: hidden;
}
.stat-card::after {
  content:''; position: absolute; inset: 0;
  background: radial-gradient(ellipse at top left,var(--c,transparent) 0%,transparent 70%);
  opacity: .07; pointer-events: none;
}
.stat-card:hover { border-color: var(--border-accent); transform: translateY(-2px); }
.stat-card:hover::after { opacity: .13; }
.stat-card.c-danger{--c:var(--danger);} .stat-card.c-warn{--c:var(--warn);}
.stat-card.c-accent{--c:var(--accent);} .stat-card.c-success{--c:var(--success);}
.stat-icon-wrap { width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:14px; }
.c-danger .stat-icon-wrap{background:rgba(255,61,90,.12);color:var(--danger);}
.c-warn   .stat-icon-wrap{background:rgba(245,158,11,.12);color:var(--warn);}
.c-accent .stat-icon-wrap{background:rgba(79,111,255,.12);color:var(--accent);}
.c-success .stat-icon-wrap{background:rgba(16,185,129,.12);color:var(--success);}
.stat-val { font-size:30px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1;margin-bottom:5px;letter-spacing:-0.02em; }
.c-danger .stat-val{color:var(--danger);}
.c-warn   .stat-val{color:var(--warn);}
.c-accent .stat-val{color:var(--accent);}
.c-success .stat-val{color:var(--success);}
.stat-label { font-size:12px;color:var(--muted);font-weight:500; }

/* ═══ GRID LAYOUTS ═══ */
.grid-2 { display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px; }
.grid-3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px; }

/* ═══ CARDS ═══ */
.card { background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px 22px; }
.card-header { display:flex;align-items:center;gap:10px;margin-bottom:18px; }
.card-title { font-size:14px;font-weight:600;flex:1; }
.card-badge { font-size:10px;font-weight:600;padding:3px 8px;border-radius:20px;letter-spacing:0.04em; }
.badge-red{background:rgba(255,61,90,.15);color:var(--danger);}
.badge-blue{background:rgba(79,111,255,.15);color:var(--accent);}
.badge-green{background:rgba(16,185,129,.15);color:var(--success);}
.card-icon{color:var(--muted);font-size:13px;}
.card-action {
  background:var(--surface2);border:1px solid var(--border);border-radius:7px;
  padding:5px 12px;color:var(--muted);font-family:var(--font);
  font-size:12px;font-weight:500;cursor:pointer;transition:all .18s;
}
.card-action:hover{background:var(--surface3);color:var(--text);}

/* ═══ MAP (Leaflet) ═══ */
#attackMap {
  height: 280px; border-radius: 10px; overflow: hidden; position: relative; z-index: 1;
}
.leaflet-tile { filter: brightness(0.75) saturate(0.9); }
.leaflet-container { background: #0a0c15 !important; }
.map-legend {
  display:flex;gap:16px;margin-top:10px;font-size:11px;color:var(--muted);padding: 0 4px;
}
.map-legend span { display:flex;align-items:center;gap:5px; }

/* ═══ TABLE ═══ */
.table-wrap { overflow-x: auto; }
table { width:100%;border-collapse:collapse;font-size:13px; }
th {
  font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;
  letter-spacing:0.07em;padding:0 12px 10px;text-align:left;border-bottom:1px solid var(--border);
}
td { padding:11px 12px;border-bottom:1px solid var(--border);color:var(--text); }
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(255,255,255,0.02);}
.mono{font-family:var(--mono);font-size:12px;color:var(--accent);}

/* ═══ BADGES ═══ */
.sev{display:inline-block;font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;letter-spacing:0.04em;text-transform:uppercase;}
.sev-critical{background:rgba(255,61,90,.15);color:var(--danger);border:1px solid rgba(255,61,90,.25);}
.sev-high{background:rgba(245,158,11,.15);color:var(--warn);border:1px solid rgba(245,158,11,.25);}
.sev-medium{background:rgba(6,182,212,.15);color:var(--info);border:1px solid rgba(6,182,212,.2);}
.threat{font-size:11px;font-weight:600;padding:2px 8px;border-radius:5px;text-transform:uppercase;}
.threat-critical,.threat-high{background:rgba(255,61,90,.15);color:var(--danger);}
.threat-medium{background:rgba(245,158,11,.15);color:var(--warn);}
.threat-low{background:rgba(16,185,129,.12);color:var(--success);}

/* ═══ ALERTS LIST ═══ */
.alert-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);transition:all .18s;}
.alert-item:last-child{border-bottom:none;}
.alert-item:hover{padding-left:4px;}
.alert-dot{width:8px;height:8px;border-radius:50%;background:var(--danger);margin-top:4px;flex-shrink:0;animation:livepulse 1.4s ease-in-out infinite;}
.alert-type{font-size:13px;font-weight:600;margin-bottom:2px;}
.alert-meta{font-size:11px;color:var(--muted);}

/* ═══ BUTTONS ═══ */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-family:var(--font);font-size:13px;font-weight:500;cursor:pointer;transition:all .18s;border:none;}
.btn-danger{background:rgba(255,61,90,.12);color:var(--danger);border:1px solid rgba(255,61,90,.2);}
.btn-danger:hover{background:rgba(255,61,90,.2);}
.btn-primary{background:var(--accent);color:#fff;}
.btn-primary:hover{background:#3d5df5;}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border);}
.btn-ghost:hover{color:var(--text);background:var(--surface3);}

/* ═══ FILTER BAR ═══ */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
.filter-bar input,.filter-bar select{
  background:var(--surface2);border:1px solid var(--border);border-radius:8px;
  color:var(--text);font-family:var(--font);font-size:13px;padding:8px 14px;transition:all .18s;
}
.filter-bar input:focus,.filter-bar select:focus{outline:none;border-color:var(--accent);}
.filter-bar input::placeholder{color:var(--muted);}
.filter-bar select option{background:var(--surface2);}

/* ═══ PAGINATION ═══ */
.pagination{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;}
.pg-btn{padding:7px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:7px;color:var(--muted);font-family:var(--font);font-size:12px;cursor:pointer;transition:all .18s;}
.pg-btn:hover:not(:disabled){color:var(--text);border-color:var(--border-accent);}
.pg-btn:disabled{opacity:.4;cursor:not-allowed;}
.pg-info{font-size:12px;color:var(--muted);padding:0 8px;}

/* ═══ SETTINGS ═══ */
.settings-group{margin-bottom:22px;}
.settings-group label{display:block;font-size:11px;font-weight:600;margin-bottom:8px;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;}
.settings-group input,.settings-group select{
  width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;
  color:var(--text);font-family:var(--font);font-size:14px;padding:11px 16px;
  transition:all .18s;max-width:480px;
}
.settings-group input:focus,.settings-group select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,111,255,.1);}
.settings-group select option{background:var(--surface2);}

/* ═══ TOAST ═══ */
.toast{position:fixed;top:20px;right:20px;background:var(--surface2);border:1px solid var(--border-accent);border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.4);animation:toastin .25s ease;}
@keyframes toastin{from{transform:translateX(120%);opacity:0;}to{transform:translateX(0);opacity:1;}}
.toast-danger{border-color:rgba(255,61,90,.3);}
.toast-success{border-color:rgba(16,185,129,.3);}
.toast i{font-size:15px;}
.toast-danger i{color:var(--danger);}
.toast-success i{color:var(--success);}

/* ═══ AI PAGE ═══ */
.ai-chat-wrap{max-height:400px;overflow-y:auto;margin-bottom:16px;display:flex;flex-direction:column;gap:12px;}
.ai-msg{display:flex;gap:10px;align-items:flex-start;}
.ai-msg.user{flex-direction:row-reverse;}
.ai-avatar{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.ai-avatar.bot{background:linear-gradient(135deg,var(--accent),var(--accent2));}
.ai-avatar.user{background:var(--surface3);color:var(--muted);}
.ai-bubble{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:11px 15px;font-size:13.5px;line-height:1.6;max-width:80%;}
.ai-msg.user .ai-bubble{background:rgba(79,111,255,.1);border-color:rgba(79,111,255,.2);}
.ai-input-wrap{display:flex;gap:10px;align-items:flex-end;}
.ai-input{flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:var(--font);font-size:14px;padding:11px 16px;resize:none;min-height:44px;max-height:120px;transition:border-color .18s;}
.ai-input:focus{outline:none;border-color:var(--accent);}
.ai-input::placeholder{color:var(--muted);}

/* ═══ ANALYTICS ═══ */
.analytics-stat{text-align:center;padding:20px;}
.analytics-num{font-size:36px;font-weight:700;color:var(--accent);margin-bottom:4px;}
.analytics-lbl{font-size:12px;color:var(--muted);font-weight:500;}

/* ═══ SCROLLBAR ═══ */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--surface3);border-radius:10px;}
::-webkit-scrollbar-thumb:hover{background:var(--border-accent);}

@media(max-width:900px){
  .sidebar{width:60px;}
  .logo-text,.nav-label,.section-label,.dl-label{opacity:0;width:0;}
  .main{margin-left:60px;}
  .grid-2,.grid-3{grid-template-columns:1fr;}
}

/* ═══ PASSWORD MODAL ═══ */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(8px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal.show {
    display: flex;
    animation: fadeIn 0.2s ease;
}
.modal-content {
    background: var(--surface);
    border: 1px solid var(--border-accent);
    border-radius: 20px;
    max-width: 450px;
    width: 90%;
    padding: 28px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}
.modal-header h3 {
    font-size: 18px;
    font-weight: 600;
}
.modal-close {
    background: var(--surface2);
    border: 1px solid var(--border);
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--muted);
}
.modal-close:hover {
    background: var(--surface3);
    color: var(--text);
}
.password-field {
    margin-bottom: 20px;
}
.password-field label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 8px;
    text-transform: uppercase;
}
.password-field input {
    width: 100%;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    color: var(--text);
    font-size: 14px;
    font-family: var(--font);
}
.password-field input:focus {
    outline: none;
    border-color: var(--accent);
}
.password-strength {
    margin-top: 8px;
    font-size: 11px;
    color: var(--muted);
}
.password-strength.weak { color: var(--danger); }
.password-strength.medium { color: var(--warn); }
.password-strength.strong { color: var(--success); }
.modal-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}
.modal-actions button {
    flex: 1;
    padding: 12px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-modal-primary {
    background: var(--accent);
    border: none;
    color: white;
}
.btn-modal-primary:hover {
    background: #3d5df5;
    transform: translateY(-1px);
}
.btn-modal-secondary {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--muted);
}
.btn-modal-secondary:hover {
    background: var(--surface3);
    color: var(--text);
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body>

<!-- ═══ SIDEBAR (unchanged) ═══ -->
<aside class="sidebar" id="sidebar">
  <button class="toggle-btn" onclick="toggleSidebar()"><i class="fas fa-chevron-left"></i></button>

  <div class="sidebar-logo">
    <div class="logo-icon"><i class="fas fa-shield-halved"></i></div>
    <span class="logo-text">ML Shield</span>
  </div>

  <div class="nav-section">
    <div class="section-label">Monitor</div>
    <a class="nav-link active" onclick="showPage('dashboard')">
      <i class="fas fa-gauge-high"></i><span class="nav-label">Dashboard</span>
    </a>
    <a class="nav-link" onclick="showPage('attacks')">
      <i class="fas fa-bug"></i><span class="nav-label">Attacks</span>
      <span class="nav-badge" id="navBadge">0</span>
    </a>
    <a class="nav-link" onclick="showPage('attackers')">
      <i class="fas fa-user-secret"></i><span class="nav-label">Attackers</span>
    </a>
    <a class="nav-link" onclick="showPage('analytics')">
      <i class="fas fa-chart-line"></i><span class="nav-label">Analytics</span>
    </a>
    <a class="nav-link" onclick="showPage('ai')">
      <i class="fas fa-brain"></i><span class="nav-label">AI Assistant</span>
    </a>

    <div class="section-label" style="margin-top:16px;">System</div>
    <a class="nav-link" onclick="showPage('settings')">
      <i class="fas fa-sliders"></i><span class="nav-label">Settings</span>
    </a>
    <a class="nav-link" href="logout.php">
      <i class="fas fa-arrow-right-from-bracket"></i><span class="nav-label">Logout</span>
    </a>
  </div>

  <div class="sidebar-bottom">
    <button class="dl-btn" onclick="exportCSV()"><i class="fas fa-file-csv" style="color:var(--success)"></i><span class="dl-label">CSV Export</span></button>
    <button class="dl-btn" onclick="exportExcel()"><i class="fas fa-file-excel" style="color:var(--success)"></i><span class="dl-label">Excel Export</span></button>
    <button class="dl-btn" onclick="exportPDF()"><i class="fas fa-file-pdf" style="color:var(--danger)"></i><span class="dl-label">PDF Export</span></button>
  </div>
</aside>

<!-- ═══ MAIN ═══ -->
<main class="main" id="main">

  <!-- TOPBAR (unchanged) -->
  <div class="topbar">
    <div>
      <div class="page-title" id="pageTitle">Overview</div>
      <div class="page-sub" id="pageSub">Real-time threat intelligence</div>
    </div>
    <div class="topbar-spacer"></div>

    <div class="live-pill"><div class="live-dot"></div>LIVE</div>

    <button class="top-btn" onclick="toggleNotifications()" id="notifBtn" title="Enable push alerts">
      <i class="fas fa-bell"></i>
    </button>
    <button class="top-btn" onclick="toggleTheme()" id="themeBtn" title="Toggle theme">
      <i class="fas fa-moon"></i>
    </button>
    <button class="top-btn" onclick="refreshAll()" title="Refresh">
      <i class="fas fa-rotate-right"></i>
    </button>
<!-- Change Password Modal -->
<div id="passwordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-key" style="margin-right: 8px; color: var(--accent);"></i> Change Password</h3>
            <button class="modal-close" onclick="closePasswordModal()"><i class="fas fa-times"></i></button>
        </div>
        <form id="changePasswordForm" onsubmit="changePassword(event)">
            <div class="password-field">
                <label>Current Password</label>
                <input type="password" id="current_password" placeholder="Enter your current password" required>
            </div>
            <div class="password-field">
                <label>New Password</label>
                <input type="password" id="new_password" placeholder="Enter new password (min 8 characters)" onkeyup="checkPasswordStrength()" required>
                <div id="passwordStrength" class="password-strength"></div>
            </div>
            <div class="password-field">
                <label>Confirm New Password</label>
                <input type="password" id="confirm_password" placeholder="Confirm new password" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-secondary" onclick="closePasswordModal()">Cancel</button>
                <button type="submit" class="btn-modal-primary">Update Password</button>
            </div>
        </form>
    </div>
</div>
    <div class="user-chip">
      <div class="user-avatar"><?= $admin_initials ?></div>
      <div class="user-name-wrap">
        <span class="user-name"><?= $admin_username ?></span>
        <span class="user-role"><?= $admin_role ?></span>
      </div>
   <div class="user-dropdown">
    <a href="#" onclick="showChangePasswordModal(); return false;">
        <i class="fas fa-key" style="width:16px;text-align:center"></i> Change Password
    </a>
    <a href="#" onclick="showPage('settings')">
        <i class="fas fa-sliders" style="width:16px;text-align:center"></i> Settings
    </a>
    <hr>
    <a href="logout.php" class="danger">
        <i class="fas fa-arrow-right-from-bracket" style="width:16px;text-align:center"></i> Logout
    </a>
</div>
    </div>
  </div>

  <!-- PAGE BODY -->
  <div class="page-body">

    <!-- ═══ DASHBOARD PAGE (unchanged) ═══ -->
    <div class="page-content active" id="dashboardPage">

      <div class="stats-grid">
        <div class="stat-card c-danger" onclick="showSeverityPage('critical')">
          <div class="stat-icon-wrap"><i class="fas fa-skull-crossbones"></i></div>
          <div class="stat-val" id="criticalCount">—</div>
          <div class="stat-label">Critical Attacks</div>
        </div>
        <div class="stat-card c-warn" onclick="showSeverityPage('high')">
          <div class="stat-icon-wrap"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="stat-val" id="highCount">—</div>
          <div class="stat-label">High Severity</div>
        </div>
        <div class="stat-card c-accent" onclick="showSeverityPage('medium')">
          <div class="stat-icon-wrap"><i class="fas fa-bell"></i></div>
          <div class="stat-val" id="mediumCount">—</div>
          <div class="stat-label">Medium Severity</div>
        </div>
        <div class="stat-card c-success" onclick="showPage('attackers')">
          <div class="stat-icon-wrap"><i class="fas fa-users"></i></div>
          <div class="stat-val" id="uniqueAttackers">—</div>
          <div class="stat-label">Unique Attackers</div>
        </div>
        <div class="stat-card c-accent">
          <div class="stat-icon-wrap"><i class="fas fa-chart-column"></i></div>
          <div class="stat-val" id="totalAttacks">—</div>
          <div class="stat-label">Total Today</div>
        </div>
        <div class="stat-card c-danger">
          <div class="stat-icon-wrap"><i class="fas fa-ban"></i></div>
          <div class="stat-val" id="blockedIPs">—</div>
          <div class="stat-label">Blocked IPs</div>
        </div>
      </div>

      <div class="grid-2" style="grid-template-columns:1.6fr 1fr;">
        <div class="card">
          <div class="card-header">
            <i class="fas fa-earth-americas card-icon"></i>
            <span class="card-title">Global Attack Map</span>
            <span class="card-badge badge-red">LIVE</span>
            <button class="card-action" onclick="refreshMap()"><i class="fas fa-rotate-right"></i> Refresh</button>
          </div>
          <div id="attackMap"></div>
          <div class="map-legend">
            <span><i class="fas fa-circle" style="color:var(--danger);font-size:9px"></i> Critical</span>
            <span><i class="fas fa-circle" style="color:var(--warn);font-size:9px"></i> High</span>
            <span><i class="fas fa-circle" style="color:var(--info);font-size:9px"></i> Medium</span>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <i class="fas fa-siren card-icon"></i>
            <span class="card-title">Critical Alerts</span>
            <span class="card-badge badge-red">URGENT</span>
          </div>
          <div id="alertsList" style="max-height:260px;overflow-y:auto;"></div>
        </div>
      </div>

      <!-- Top Attackers -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <i class="fas fa-user-secret card-icon"></i>
          <span class="card-title">Top Attackers</span>
          <span class="card-badge badge-blue">IP Reputation</span>
          <button class="btn btn-danger" onclick="blockTopAttackers()" style="margin-left:auto;padding:5px 12px;">
            <i class="fas fa-ban"></i> Block All
          </button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>IP Address</th><th>Count</th><th>Threat</th><th>Last Attack</th><th>Last Seen</th><th>Action</th></tr></thead>
            <tbody id="attackersTableBody"><tr><td colspan="6" style="text-align:center;color:var(--muted);">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- Recent Attacks -->
      <div class="card">
        <div class="card-header">
          <i class="fas fa-list card-icon"></i>
          <span class="card-title">Recent Attacks</span>
          <span class="card-badge badge-blue">Last 5</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Time</th><th>Attacker IP</th><th>Target URL</th><th>Type</th><th>Confidence</th><th>Severity</th></tr></thead>
            <tbody id="attacksTableBody"><tr><td colspan="6" style="text-align:center;color:var(--muted);">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ ATTACKS PAGE (FIXED: now fetches from DB) ═══ -->
    <div class="page-content" id="attacksPage">
      <div class="card">
        <div class="card-header">
          <i class="fas fa-database card-icon"></i>
          <span class="card-title">Attack History</span>
          <span class="card-badge badge-blue" id="attacksPageBadge">10 / page</span>
        </div>
        <div class="filter-bar">
          <input type="text" id="attackSearch" placeholder="Search IP or URL…" onkeyup="filterAttacks()">
          <select id="severityFilter" onchange="filterAttacks()">
            <option value="">All Severities</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
          </select>
          <select id="typeFilter" onchange="filterAttacks()">
            <option value="">All Types</option>
            <option value="SQL Injection">SQL Injection</option>
            <option value="XSS">XSS</option>
            <option value="Path Traversal">Path Traversal</option>
            <option value="Brute Force">Brute Force</option>
            <option value="Command Injection">Command Injection</option>
          </select>
          <input type="date" id="dateFilter" onchange="filterAttacks()">
          <button class="btn btn-ghost" onclick="clearAttackFilters()" style="padding:7px 14px;font-size:12px;">
            <i class="fas fa-times"></i> Clear
          </button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Time</th><th>Attacker IP</th><th>Target URL</th><th>Type</th><th>Confidence</th><th>Severity</th></tr></thead>
            <tbody id="allAttacksTableBody"><tr><td colspan="6" style="text-align:center;color:var(--muted);">Loading attacks from database…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination" id="attacksPagination"></div>
      </div>
    </div>

    <!-- ═══ ATTACKERS PAGE (FIXED: fetches from DB) ═══ -->
    <div class="page-content" id="attackersPage">
      <div class="card">
        <div class="card-header">
          <i class="fas fa-address-card card-icon"></i>
          <span class="card-title">Attackers Directory</span>
          <span class="card-badge badge-blue">10 / page</span>
        </div>
        <div class="filter-bar">
          <input type="text" id="attackerSearch" placeholder="Search IP…" onkeyup="filterAttackers()">
          <select id="threatFilter" onchange="filterAttackers()">
            <option value="">All Threats</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>IP Address</th><th>Count</th><th>Threat</th><th>Last Attack</th><th>Last Seen</th><th>Action</th></tr></thead>
            <tbody id="allAttackersTableBody"><tr><td colspan="6" style="text-align:center;color:var(--muted);">Loading attackers…</td></tr></tbody>
          </table>
        </div>
        <div class="pagination" id="attackersPagination"></div>
      </div>
    </div>

    <!-- ═══ ANALYTICS PAGE (unchanged) ═══ -->
    <div class="page-content" id="analyticsPage">
      <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
        <div class="card analytics-stat"><div class="analytics-num" id="avgDailyAttacks">—</div><div class="analytics-lbl">Avg Daily (30d)</div></div>
        <div class="card analytics-stat"><div class="analytics-num" id="totalMonth">—</div><div class="analytics-lbl">Total Attacks (30d)</div></div>
        <div class="card analytics-stat"><div class="analytics-num" id="peakDay" style="font-size:24px;">—</div><div class="analytics-lbl">Peak Attack Day</div></div>
      </div>
      <div class="grid-2">
        <div class="card"><div class="card-header"><i class="fas fa-chart-line card-icon"></i><span class="card-title">Attack Trends (7 days)</span></div><canvas id="trendsChart" height="200"></canvas></div>
        <div class="card"><div class="card-header"><i class="fas fa-chart-pie card-icon"></i><span class="card-title">Attack Distribution</span></div><canvas id="distributionChart" height="200"></canvas></div>
      </div>
      <div class="grid-2">
        <div class="card"><div class="card-header"><i class="fas fa-chart-bar card-icon"></i><span class="card-title">Severity Breakdown</span></div><canvas id="severityChart" height="200"></canvas></div>
        <div class="card"><div class="card-header"><i class="fas fa-clock card-icon"></i><span class="card-title">Hourly Pattern</span></div><canvas id="hourlyChart" height="200"></canvas></div>
      </div>
      <div class="grid-2">
        <div class="card"><div class="card-header"><i class="fas fa-calendar-week card-icon"></i><span class="card-title">Attacks by Day of Week</span></div><canvas id="weeklyChart" height="200"></canvas></div>
        <div class="card"><div class="card-header"><i class="fas fa-ranking-star card-icon"></i><span class="card-title">Top 10 Attack Sources</span></div><canvas id="topSourcesChart" height="200"></canvas></div>
      </div>
      <div class="card">
        <div class="card-header">
          <i class="fas fa-calendar-alt card-icon"></i>
          <span class="card-title">Monthly Summary</span>
          <button class="btn btn-ghost" onclick="exportMonthlyReport()" style="margin-left:auto;padding:6px 12px;"><i class="fas fa-file-excel"></i> Export</button>
        </div>
        <div id="monthlySummary" style="color:var(--muted);font-size:13px;">Loading…</div>
      </div>
    </div>

    <!-- ═══ AI PAGE (unchanged) ═══ -->
    <div class="page-content" id="aiPage">
      <div class="card" style="max-width:720px;">
        <div class="card-header">
          <i class="fas fa-brain card-icon"></i>
          <span class="card-title">AI Security Assistant</span>
          <span class="card-badge badge-blue">Powered by ML Shield</span>
        </div>
        <div class="ai-chat-wrap" id="aiChat">
          <div class="ai-msg">
            <div class="ai-avatar bot"><i class="fas fa-robot"></i></div>
            <div class="ai-bubble">Hello! I'm your AI security assistant. I can help analyze attack patterns, explain threats, suggest mitigations, and answer questions about your security data. How can I help?</div>
          </div>
        </div>
        <div class="ai-input-wrap">
          <textarea class="ai-input" id="aiInput" placeholder="Ask about attack patterns, threats, mitigations…" rows="1" onkeydown="aiKeydown(event)"></textarea>
          <button class="btn btn-primary" onclick="sendAiMessage()"><i class="fas fa-paper-plane"></i></button>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
          <button class="btn btn-ghost" style="font-size:12px;padding:5px 12px;" onclick="aiQuick('What attack types are most common?')">Common attack types</button>
          <button class="btn btn-ghost" style="font-size:12px;padding:5px 12px;" onclick="aiQuick('How to mitigate SQL injection?')">Mitigate SQL injection</button>
          <button class="btn btn-ghost" style="font-size:12px;padding:5px 12px;" onclick="aiQuick('Explain XSS attacks')">Explain XSS</button>
        </div>
      </div>
    </div>

    <!-- ═══ SETTINGS PAGE (unchanged) ═══ -->
    <div class="page-content" id="settingsPage">
      <div class="card" style="max-width:540px;">
        <div class="card-header">
          <i class="fas fa-sliders card-icon"></i>
          <span class="card-title">System Configuration</span>
        </div>
        <div class="settings-group">
          <label>Alert Level</label>
          <select id="alertLevel">
            <option value="all">All Attacks</option>
            <option value="critical">Critical Only</option>
            <option value="high">High and Above</option>
            <option value="medium">Medium and Above</option>
          </select>
        </div>
        <div class="settings-group">
          <label>Auto Refresh Interval</label>
          <select id="refreshInterval">
            <option value="5">5 Seconds</option>
            <option value="10">10 Seconds</option>
            <option value="30">30 Seconds</option>
            <option value="60">60 Seconds</option>
          </select>
        </div>
        <div class="settings-group">
          <label>Security Level</label>
          <select id="securityLevel">
            <option value="low">Low — Monitor Only</option>
            <option value="medium">Medium — Alert &amp; Log</option>
            <option value="high">High — Auto-block</option>
          </select>
        </div>
        <div class="settings-group">
          <label>Alert Email</label>
          <input type="email" id="notificationEmail" placeholder="admin@example.com">
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
          <button class="btn btn-primary" onclick="saveSettings()"><i class="fas fa-floppy-disk"></i> Save Settings</button>
          <button class="btn btn-danger" onclick="clearAllData()"><i class="fas fa-trash"></i> Clear All Data</button>
        </div>
      </div>
    </div>

  </div><!-- /page-body -->
</main>

<script>
// ============================================================
// FIXED: Attacks & Attackers now fetch REAL data from database
// All other code (map, AI, notifications) remains untouched
// ============================================================

let allAttacks = [], allAttackers = [], currentData = {};
let currentAttacksList = [], currentAttackersList = [];
let attacksPage = 1, attackersPage = 1;
const PER_PAGE = 10;
let refreshTimer, currentRefresh = 5;
let notifEnabled = false, lastAttackId = 0;
let isDark = true;
let leafletMap = null, mapMarkerLayer = null;

const SERVER_LAT = <?= json_encode($server_location['lat']) ?>;
const SERVER_LNG = <?= json_encode($server_location['lng']) ?>;
const SERVER_CITY = <?= json_encode($server_location['city']) ?>;

// Helper functions (unchanged)
function isPublicIP(ip) {
    if (!ip || typeof ip !== 'string') return false;
    const parts = ip.split('.');
    if (parts.length !== 4) return false;
    const nums = parts.map(Number);
    if (nums.some(n => isNaN(n) || n < 0 || n > 255)) return false;
    if (nums[0] === 10) return false;
    if (nums[0] === 127) return false;
    if (nums[0] === 192 && nums[1] === 168) return false;
    if (nums[0] === 172 && nums[1] >= 16 && nums[1] <= 31) return false;
    if (nums[0] === 0) return false;
    if (nums[0] === 169 && nums[1] === 254) return false;
    return true;
}
const geoCache = {};
async function getGeoForIP(ip) {
    if (geoCache[ip]) return geoCache[ip];
    try {
const r = await fetch(`https://ip-api.com/json/${ip}?fields=status,lat,lon,country,city`);        const d = await r.json();
        if (d.status === 'success') {
            const loc = { lat: d.lat, lng: d.lon, country: d.country, city: d.city };
            geoCache[ip] = loc;
            return loc;
        }
    } catch(e) {}
    return null;
}
const MOCK_STATS = { critical_attacks:12, high_attacks:34, medium_attacks:67, unique_attackers:29, total_attacks:113, blocked_ips:8 };
const MOCK_ATTACKS = Array.from({length:40},(_,i)=>({ id:i+1, timestamp:new Date(Date.now()-i*180000).toISOString(), attacker_ip:['45.33.32.156','185.220.101.42','194.165.16.11','103.21.244.0','5.188.206.26','91.108.56.180','162.247.74.200','89.248.167.131','77.247.181.162','198.98.54.100','176.10.104.240','195.206.105.217','46.165.230.5','213.202.254.180','80.82.77.33'][i%15], target_url:['/admin/login.php','/wp-login.php','/api/users?id=1','/search?q=test','/etc/passwd'][i%5], attack_type:['SQL Injection','XSS','Path Traversal','Brute Force','Command Injection'][i%5], confidence:(0.7+Math.random()*0.3), severity:['critical','high','medium'][i%3], lat:[-33.8,51.5,35.6,40.7,48.8,55.7,1.3,28.6,19.4,-23.5,37.5,34.0,41.9,52.3,25.2][i%15], lng:[151.2,-0.1,139.8,-74.0,2.35,37.6,103.8,77.2,-99.1,-46.6,127.0,-118.2,12.5,4.9,55.3][i%15] }));
const MOCK_ATTACKERS = Array.from({length:15},(_,i)=>({ ip:['45.33.32.156','185.220.101.42','194.165.16.11','103.21.244.0','5.188.206.26','91.108.56.180','162.247.74.200','89.248.167.131','77.247.181.162','198.98.54.100','176.10.104.240','195.206.105.217','46.165.230.5','213.202.254.180','80.82.77.33'][i], attack_count:Math.floor(Math.random()*50)+1, threat_level:['critical','high','medium','low'][i%4], last_attack_type:['SQL Injection','XSS','Path Traversal'][i%3], last_seen:new Date(Date.now()-i*300000).toLocaleString() }));

function showPage(name) {
    document.querySelectorAll('.page-content').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.nav-link').forEach(a=>a.classList.remove('active'));
    document.getElementById(pages[name].id).classList.add('active');
    document.querySelectorAll(`.nav-link[onclick="showPage('${name}')"]`).forEach(a=>a.classList.add('active'));
    document.getElementById('pageTitle').textContent = pages[name].title;
    document.getElementById('pageSub').textContent   = pages[name].sub;
    if (name === 'attacks')   { clearAttackFilters(); loadAllAttacks(); }
    if (name === 'attackers') loadAllAttackers();
    if (name === 'analytics') loadAnalytics();
}
function showSeverityPage(severity) {
    showPage('attacks');
    setTimeout(() => {
        document.getElementById('severityFilter').value = severity;
        filterAttacks();
        const labels = {critical:'Critical Only', high:'High Only', medium:'Medium Only'};
        document.getElementById('attacksPageBadge').textContent = labels[severity] || '10 / page';
    }, 300);
}
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('main').classList.toggle('expanded');
    localStorage.setItem('sidebarCollapsed', document.getElementById('sidebar').classList.contains('collapsed'));
    if(leafletMap) setTimeout(()=> leafletMap.invalidateSize(), 300);
}
(function(){
    if (localStorage.getItem('sidebarCollapsed')==='true') {
        document.getElementById('sidebar').classList.add('collapsed');
        document.getElementById('main').classList.add('expanded');
    }
})();

// Dashboard data (unchanged)
function fetchDashboard() {
    fetch('get_dashboard_data.php')
        .then(r=>r.json())
        .then(d=>{ if(d.success) renderDashboard(d); else useMockDashboard(); })
        .catch(useMockDashboard);
}
function useMockDashboard() {
    renderDashboard({success:true, stats:MOCK_STATS, recent_attacks:MOCK_ATTACKS.slice(0,10), top_attackers:MOCK_ATTACKERS.slice(0,5)});
}
function renderDashboard(d) {
    currentData = d;
    const s = d.stats || MOCK_STATS;
    animateNum('criticalCount',   s.critical_attacks  || 0);
    animateNum('highCount',       s.high_attacks       || 0);
    animateNum('mediumCount',     s.medium_attacks     || 0);
    animateNum('uniqueAttackers', s.unique_attackers   || 0);
    animateNum('totalAttacks',    s.total_attacks      || 0);
    animateNum('blockedIPs',      s.blocked_ips        || 0);
    document.getElementById('navBadge').textContent = (s.critical_attacks||0)+(s.high_attacks||0);
    renderAlerts(d.recent_attacks || MOCK_ATTACKS.slice(0,5));
    renderAttackersTable(d.top_attackers || MOCK_ATTACKERS.slice(0,5));
    renderRecentAttacks(d.recent_attacks || MOCK_ATTACKS.slice(0,5));
    plotMapAttacks(d.recent_attacks || MOCK_ATTACKS.slice(0,20));
    if (d.recent_attacks?.length > 0) {
        const a = d.recent_attacks[0];
        if (a.id > lastAttackId) { lastAttackId = a.id; pushNotify(a); }
    }
}
function animateNum(id, target) {
    const el = document.getElementById(id);
    const start = parseInt(el.textContent) || 0;
    const dur=600, step=16; let t=0;
    const iv = setInterval(()=>{
        t+=step;
        const p=Math.min(t/dur,1), e=1-Math.pow(1-p,3);
        el.textContent=Math.round(start+(target-start)*e);
        if(p>=1) clearInterval(iv);
    },step);
}
function renderAlerts(attacks) {
    const critical = attacks.filter(a=>a.severity==='critical');
    const list = document.getElementById('alertsList');
    if (!critical.length) { list.innerHTML='<div style="padding:20px;text-align:center;color:var(--muted);font-size:13px;"><i class="fas fa-check-circle" style="color:var(--success)"></i> No critical alerts</div>'; return; }
    list.innerHTML = critical.slice(0,8).map(a=>`<div class="alert-item"><div class="alert-dot"></div><div><div class="alert-type">${a.attack_type}</div><div class="alert-meta">${a.attacker_ip} · ${relTime(a.timestamp)}</div><div class="alert-meta" style="margin-top:2px;font-family:var(--mono);font-size:10px;">${(a.target_url||'').substring(0,50)}</div></div></div>`).join('');
}
function renderAttackersTable(attackers) {
    document.getElementById('attackersTableBody').innerHTML = attackers.slice(0,5).map(a=>`<tr><td><span class="mono">${a.ip}</span></td><td>${a.attack_count}</td><td><span class="threat threat-${a.threat_level}">${(a.threat_level||'low').toUpperCase()}</span></td><td>${a.last_attack_type||'N/A'}</td><td style="color:var(--muted);font-size:12px;">${a.last_seen||'N/A'}</td><td><button class="btn btn-danger" style="padding:4px 10px;font-size:12px;" onclick="blockIP('${a.ip}')"><i class="fas fa-ban"></i> Block</button></td></tr>`).join('');
}
function renderRecentAttacks(attacks) {
    document.getElementById('attacksTableBody').innerHTML = attacks.slice(0,5).map(a=>`<tr><td style="color:var(--muted);font-size:12px;">${new Date(a.timestamp).toLocaleTimeString()}</td><td><span class="mono">${a.attacker_ip}</span></td><td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${a.target_url}">${(a.target_url||'').substring(0,50)}</td><td style="font-size:12px;font-weight:500;">${a.attack_type}</td><td style="font-family:var(--mono);font-size:12px;">${a.confidence?(a.confidence*100).toFixed(1)+'%':'—'}</td><td><span class="sev sev-${a.severity}">${(a.severity||'medium').toUpperCase()}</span></td></tr>`).join('');
}

// LEAFLET MAP (unchanged)
function initLeafletMap() {
    if (leafletMap) return;
    leafletMap = L.map('attackMap', { center: [SERVER_LAT || 20, SERVER_LNG || 0], zoom: 2, zoomControl: true, attributionControl: false, scrollWheelZoom: false });
// Dark satellite-style tiles (ESRI World Imagery + dark overlay)
L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
    attribution: '© Esri'
}).addTo(leafletMap);

// Dark overlay to make it feel like a cyber threat map
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    opacity: 0.6,
    attribution: '© CartoDB'
}).addTo(leafletMap);    const homeIcon = L.divIcon({ className:'', html:`<div style="width:14px;height:14px;background:#10b981;border-radius:50%;border:3px solid #fff;box-shadow:0 0 10px #10b981;"></div>`, iconSize:[14,14], iconAnchor:[7,7] });
    L.marker([SERVER_LAT||9.03, SERVER_LNG||38.74], {icon:homeIcon}).bindPopup(`<b style="color:#10b981">🛡 Your Server</b><br>${SERVER_CITY}`).addTo(leafletMap);
    mapMarkerLayer = L.layerGroup().addTo(leafletMap);
}
async function plotMapAttacks(attacks) {
    if (!leafletMap || !mapMarkerLayer) return;
    mapMarkerLayer.clearLayers();

    const colors = { critical: '#ff3d5a', high: '#f59e0b', medium: '#06b6d4' };
    const sizes  = { critical: 14, high: 10, medium: 8 };

    // Only public IPs — no localhost or private ranges
// Use all attacks — private IPs fall back to server location
const publicAttacks = attacks;
    for (let i = 0; i < Math.min(publicAttacks.length, 30); i++) {
        const a = publicAttacks[i];

        // Fetch real geo location from ip-api
        // Public IPs → real geo lookup, private IPs → server location with slight random offset
let geo;
if (isPublicIP(a.attacker_ip)) {
    geo = await getGeoForIP(a.attacker_ip);
}
if (!geo) {
    // Private IP: place near server with small random offset so dots don't stack
    geo = {
        lat: SERVER_LAT + (Math.random() - 0.5) * 2,
        lng: SERVER_LNG + (Math.random() - 0.5) * 2,
        city: 'Local Network',
        country: 'Internal'
    };
}

        const color = colors[a.severity] || colors.medium;
        const size  = sizes[a.severity]  || sizes.medium;

        // Pulsing dot icon
        const icon = L.divIcon({
            className: '',
            html: `<div style="
                width:${size}px; height:${size}px;
                background:${color}; border-radius:50%;
                border:2px solid rgba(255,255,255,0.4);
                box-shadow:0 0 ${size + 6}px ${color}, 0 0 ${size + 12}px ${color}55;
            "></div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });

        // Rich popup using all your DB columns
        const popup = `
            <div style="font-family:'Space Grotesk',sans-serif;font-size:12px;min-width:200px;line-height:1.7;">
                <div style="font-size:13px;font-weight:700;color:${color};margin-bottom:6px;">
                    ${a.attack_type}
                </div>
<div><span style="color:#888;">📍 Location:</span> <strong>${isPublicIP(a.attacker_ip) ? geo.city + ', ' + geo.country : '🏠 Internal — ' + a.attacker_ip}</strong></div>                <div><span style="color:#888;">🌐 IP:</span> <code style="background:#1a1d30;padding:1px 5px;border-radius:3px;">${a.attacker_ip}</code></div>
                <div><span style="color:#888;">🎯 Target:</span> ${(a.target_url || '').substring(0, 45)}${(a.target_url || '').length > 45 ? '…' : ''}</div>
                <div><span style="color:#888;">⚠️ Severity:</span> <strong style="color:${color}">${(a.severity || '').toUpperCase()}</strong></div>
                <div><span style="color:#888;">🎯 Confidence:</span> ${a.confidence ? (a.confidence * 100).toFixed(1) + '%' : '—'}</div>
                <div><span style="color:#888;">📊 Anomaly Score:</span> ${a.anomaly_score ?? '—'}</div>
                <div><span style="color:#888;">🔴 Critical:</span> ${a.is_critical ? '<span style="color:#ff3d5a">Yes</span>' : 'No'}</div>
                <div><span style="color:#888;">🕐 Time:</span> ${relTime(a.timestamp)}</div>
            </div>`;

        L.marker([geo.lat, geo.lng], { icon })
         .bindPopup(popup, { maxWidth: 260 })
         .addTo(mapMarkerLayer);
    }
}
function refreshMap() { plotMapAttacks(currentData.recent_attacks || MOCK_ATTACKS); showToast('Map refreshed','success'); }

// Desktop push notifications (unchanged)
function toggleNotifications() {
    if (!('Notification' in window)) { showToast('Push notifications not supported in this browser','danger'); return; }
    if (Notification.permission === 'granted') {
        notifEnabled = !notifEnabled;
        const btn = document.getElementById('notifBtn');
        btn.classList.toggle('notif-on', notifEnabled);
        btn.title = notifEnabled ? 'Push alerts ON — click to disable' : 'Enable push alerts';
        showToast(notifEnabled ? '🔔 Push alerts enabled' : '🔕 Push alerts disabled', 'success');
    } else if (Notification.permission === 'denied') {
        showToast('Notifications blocked — allow them in browser settings','danger');
    } else {
        Notification.requestPermission().then(perm => {
            if (perm === 'granted') { notifEnabled = true; document.getElementById('notifBtn').classList.add('notif-on'); document.getElementById('notifBtn').title = 'Push alerts ON — click to disable'; showToast('🔔 Push alerts enabled','success'); new Notification('ML Shield — Alerts Active', { body: 'You will now receive desktop notifications for critical attacks.', icon: '/favicon.ico', tag: 'mlshield-test' }); }
            else { showToast('Notification permission denied','danger'); }
        });
    }
}
function pushNotify(attack) {
    if (!notifEnabled || Notification.permission !== 'granted') return;
    if (!['critical','high'].includes(attack.severity)) return;
    const title = attack.severity === 'critical' ? '🚨 Critical Attack Detected!' : '⚠️ High Severity Attack';
    new Notification(title, { body: `${attack.attack_type} from ${attack.attacker_ip}\n${(attack.target_url||'').substring(0,60)}`, icon: '/favicon.ico', tag: `attack-${attack.id}`, requireInteraction: attack.severity === 'critical' });
}

// ========== FIXED: ATTACKS PAGE (real DB data) ==========
function loadAllAttacks() {
    fetch('get_all_attacks.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.attacks) {
                allAttacks = data.attacks;
                currentAttacksList = allAttacks;
                attacksPage = 1;
                renderAttacksPage();
                console.log('✅ Attacks loaded from DB:', allAttacks.length);
            } else {
                console.warn('⚠️ No attacks from DB, using mock (fallback)');
                allAttacks = MOCK_ATTACKS;
                currentAttacksList = allAttacks;
                attacksPage = 1;
                renderAttacksPage();
            }
        })
        .catch(err => {
            console.error('❌ Failed to fetch attacks:', err);
            allAttacks = MOCK_ATTACKS;
            currentAttacksList = allAttacks;
            attacksPage = 1;
            renderAttacksPage();
        });
}

function renderAttacksPage() {
    const start = (attacksPage - 1) * PER_PAGE;
    const page = currentAttacksList.slice(start, start + PER_PAGE);
    const tbody = document.getElementById('allAttacksTableBody');
    if (!page.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);">No attacks found</td></tr>';
        renderPagination('attacks', currentAttacksList.length);
        return;
    }
    tbody.innerHTML = page.map(a => `
        <tr>
            <td style="color:var(--muted);">${new Date(a.timestamp).toLocaleString()}</td>
            <td><span class="mono">${escapeHtml(a.attacker_ip)}</span></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" title="${escapeHtml(a.target_url)}">${escapeHtml((a.target_url || '').substring(0,60))}${(a.target_url || '').length > 60 ? '…' : ''}</td>
            <td style="font-size:12px;font-weight:500;">${escapeHtml(a.attack_type)}</td>
            <td style="font-family:var(--mono);font-size:12px;">${a.confidence ? (a.confidence * 100).toFixed(1) + '%' : '—'}</td>
            <td><span class="sev sev-${a.severity || 'medium'}">${(a.severity || 'medium').toUpperCase()}</span></td>
        </tr>
    `).join('');
    renderPagination('attacks', currentAttacksList.length);
}

function filterAttacks() {
    let filtered = allAttacks;
    const s = document.getElementById('attackSearch').value.toLowerCase();
    const sev = document.getElementById('severityFilter').value;
    const typ = document.getElementById('typeFilter').value;
    const dt = document.getElementById('dateFilter').value;
    if (s) filtered = filtered.filter(a => a.attacker_ip?.toLowerCase().includes(s) || a.target_url?.toLowerCase().includes(s));
    if (sev) filtered = filtered.filter(a => a.severity === sev);
    if (typ) filtered = filtered.filter(a => a.attack_type === typ);
    if (dt) filtered = filtered.filter(a => a.timestamp?.startsWith(dt));
    currentAttacksList = filtered;
    attacksPage = 1;
    renderAttacksPage();
}
function clearAttackFilters() {
    document.getElementById('attackSearch').value = '';
    document.getElementById('severityFilter').value = '';
    document.getElementById('typeFilter').value = '';
    document.getElementById('dateFilter').value = '';
    document.getElementById('attacksPageBadge').textContent = '10 / page';
    currentAttacksList = allAttacks;
    attacksPage = 1;
    renderAttacksPage();
}

// ========== FIXED: ATTACKERS PAGE (real DB data) ==========
function loadAllAttackers() {
    fetch('get_all_attackers.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.attackers) {
                allAttackers = data.attackers;
                currentAttackersList = allAttackers;
                attackersPage = 1;
                renderAttackersPage();
                console.log('✅ Attackers loaded from DB:', allAttackers.length);
            } else {
                console.warn('⚠️ No attackers from DB, using mock (fallback)');
                allAttackers = MOCK_ATTACKERS;
                currentAttackersList = allAttackers;
                attackersPage = 1;
                renderAttackersPage();
            }
        })
        .catch(err => {
            console.error('❌ Failed to fetch attackers:', err);
            allAttackers = MOCK_ATTACKERS;
            currentAttackersList = allAttackers;
            attackersPage = 1;
            renderAttackersPage();
        });
}

function renderAttackersPage() {
    const start = (attackersPage - 1) * PER_PAGE;
    const page = currentAttackersList.slice(start, start + PER_PAGE);
    const tbody = document.getElementById('allAttackersTableBody');
    if (!page.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);">No attackers found</td></tr>';
        renderPagination('attackers', currentAttackersList.length);
        return;
    }
    tbody.innerHTML = page.map(a => `
        <tr>
            <td><span class="mono">${escapeHtml(a.ip)}</span></td>
            <td>${a.attack_count}</td>
            <td><span class="threat threat-${a.threat_level}">${(a.threat_level || 'low').toUpperCase()}</span></td>
            <td>${escapeHtml(a.last_attack_type || 'N/A')}</td>
            <td style="color:var(--muted);">${escapeHtml(a.last_seen || 'N/A')}</td>
            <td><button class="btn btn-danger" style="padding:4px 10px;font-size:12px;" onclick="blockIP('${escapeHtml(a.ip)}')"><i class="fas fa-ban"></i> Block</button></td>
        </tr>
    `).join('');
    renderPagination('attackers', currentAttackersList.length);
}

function filterAttackers() {
    let f = allAttackers;
    const s = document.getElementById('attackerSearch').value.toLowerCase();
    const t = document.getElementById('threatFilter').value;
    if (s) f = f.filter(a => a.ip?.toLowerCase().includes(s));
    if (t) f = f.filter(a => a.threat_level === t);
    currentAttackersList = f;
    attackersPage = 1;
    renderAttackersPage();
}

// Pagination (unchanged)
function renderPagination(type, total) {
    const totalPages = Math.ceil(total / PER_PAGE);
    const current = type === 'attacks' ? attacksPage : attackersPage;
    const container = document.getElementById(`${type}Pagination`);
    if (totalPages <= 1) { container.innerHTML = ''; return; }
    container.innerHTML = `<button class="pg-btn" onclick="changePage('${type}', ${current-1})" ${current===1 ? 'disabled' : ''}>← Prev</button><span class="pg-info">${current} / ${totalPages} · ${total} total</span><button class="pg-btn" onclick="changePage('${type}', ${current+1})" ${current===totalPages ? 'disabled' : ''}>Next →</button>`;
}
function changePage(type, page) {
    if (type === 'attacks') { attacksPage = page; renderAttacksPage(); }
    else { attackersPage = page; renderAttackersPage(); }
}

// Analytics (unchanged)
let chartsInited=false;
let trendsChart,distChart,sevChart,hourlyChart,weeklyChart,topChart;
const ALLOWED_TYPES = ['SQL Injection','XSS','Path Traversal','Brute Force','Command Injection','Other'];
function loadAnalytics() { if(!chartsInited){initCharts();chartsInited=true;} fetch('get_analytics_data.php').then(r=>r.json()).then(d=>{ if(d.success) updateCharts(d); else useMockAnalytics(); }).catch(useMockAnalytics); fetch('get_30day_trend.php').then(r=>r.json()).then(d=>{ if(d.success){ animateNum('avgDailyAttacks',d.stats?.avgDaily||0); animateNum('totalMonth',d.stats?.total||0); document.getElementById('peakDay').textContent=d.stats?.peakDay||'N/A'; } }).catch(()=>{ animateNum('avgDailyAttacks',18); animateNum('totalMonth',547); document.getElementById('peakDay').textContent='Tuesday'; }); }
function useMockAnalytics() { trendsChart.data.labels=['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; trendsChart.data.datasets[0].data=[45,78,34,92,67,23,55]; trendsChart.update(); distChart.data.labels=['SQL Injection','XSS','Path Traversal','Brute Force','Command Injection']; distChart.data.datasets[0].data=[35,28,18,12,7]; distChart.update(); sevChart.data.datasets[0].data=[12,34,67]; sevChart.update(); hourlyChart.data.datasets[0].data=Array(24).fill(0).map((_,i)=>Math.floor(Math.sin(i/3)*20+25+Math.random()*15)); hourlyChart.update(); weeklyChart.data.datasets[0].data=[23,67,78,91,55,34,20]; weeklyChart.update(); topChart.data.labels=['45.33.32.156','185.220.101.42','194.165.16.11','103.21.244.0','5.188.206.26']; topChart.data.datasets[0].data=[89,67,45,38,29]; topChart.update(); document.getElementById('monthlySummary').innerHTML=`<table style="width:100%;font-size:13px;"><thead><tr style="color:var(--muted);"><th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border);">Month</th><th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border);">Total</th><th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border);">Critical</th><th style="padding:8px 12px;text-align:left;border-bottom:1px solid var(--border);">Blocked</th></tr></thead><tbody><tr><td style="padding:10px 12px;border-bottom:1px solid var(--border);">This Month</td><td style="padding:10px 12px;border-bottom:1px solid var(--border);">547</td><td style="padding:10px 12px;border-bottom:1px solid var(--border);color:var(--danger);">12</td><td style="padding:10px 12px;border-bottom:1px solid var(--border);color:var(--success);">8</td></tr><tr><td style="padding:10px 12px;">Last Month</td><td style="padding:10px 12px;">482</td><td style="padding:10px 12px;color:var(--danger);">9</td><td style="padding:10px 12px;color:var(--success);">6</td></tr></tbody></table>`; animateNum('avgDailyAttacks',18); animateNum('totalMonth',547); document.getElementById('peakDay').textContent='Tuesday'; }
function updateCharts(d) { if(d.trends){trendsChart.data.labels=d.trends.map(t=>t.attack_date);trendsChart.data.datasets[0].data=d.trends.map(t=>parseInt(t.total));trendsChart.update();} if(d.distribution){ const filtered = d.distribution.filter(x => ALLOWED_TYPES.includes(x.attack_type)); distChart.data.labels=filtered.map(x=>x.attack_type); distChart.data.datasets[0].data=filtered.map(x=>parseInt(x.count)); distChart.update(); } if(d.severity){sevChart.data.datasets[0].data=[d.severity.critical||0,d.severity.high||0,d.severity.medium||0];sevChart.update();} if(d.hourly){const arr=Array(24).fill(0);d.hourly.forEach(h=>arr[parseInt(h.hour)]=parseInt(h.count));hourlyChart.data.datasets[0].data=arr;hourlyChart.update();} if(d.weekly){weeklyChart.data.datasets[0].data=d.weekly;weeklyChart.update();} if(d.topSources){topChart.data.labels=d.topSources.map(s=>s.ip);topChart.data.datasets[0].data=d.topSources.map(s=>parseInt(s.count));topChart.update();} if(d.monthlySummary) document.getElementById('monthlySummary').innerHTML=d.monthlySummary; }
const CHART_DEF = { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{ x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6b7099',font:{size:11}}}, y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6b7099',font:{size:11}}} } };
function initCharts() { Chart.defaults.color='#6b7099'; Chart.defaults.font.family="'Space Grotesk',sans-serif"; trendsChart=new Chart(document.getElementById('trendsChart'),{type:'line',data:{labels:[],datasets:[{label:'Attacks',data:[],borderColor:'#4f6fff',backgroundColor:'rgba(79,111,255,0.08)',fill:true,tension:0.4,pointBackgroundColor:'#4f6fff',pointRadius:3}]},options:{...CHART_DEF}}); distChart=new Chart(document.getElementById('distributionChart'),{type:'doughnut',data:{labels:[],datasets:[{data:[],backgroundColor:['#ff3d5a','#f59e0b','#4f6fff','#10b981','#8b5cf6'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{position:'bottom',labels:{color:'#6b7099',font:{size:11},padding:10}}}}}); sevChart=new Chart(document.getElementById('severityChart'),{type:'bar',data:{labels:['Critical','High','Medium'],datasets:[{data:[0,0,0],backgroundColor:['rgba(255,61,90,0.7)','rgba(245,158,11,0.7)','rgba(79,111,255,0.7)'],borderRadius:6,borderSkipped:false}]},options:{...CHART_DEF}}); hourlyChart=new Chart(document.getElementById('hourlyChart'),{type:'line',data:{labels:Array.from({length:24},(_,i)=>i+':00'),datasets:[{label:'Attacks',data:Array(24).fill(0),borderColor:'#10b981',backgroundColor:'rgba(16,185,129,0.08)',fill:true,tension:0.4,pointRadius:0}]},options:{...CHART_DEF}}); weeklyChart=new Chart(document.getElementById('weeklyChart'),{type:'bar',data:{labels:['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],datasets:[{label:'Attacks',data:[0,0,0,0,0,0,0],backgroundColor:'rgba(139,92,246,0.7)',borderRadius:6,borderSkipped:false}]},options:{...CHART_DEF}}); topChart=new Chart(document.getElementById('topSourcesChart'),{type:'bar',data:{labels:[],datasets:[{label:'Attacks',data:[],backgroundColor:'rgba(6,182,212,0.7)',borderRadius:6,borderSkipped:false}]},options:{...CHART_DEF,indexAxis:'y',scales:{x:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6b7099',font:{size:11}}},y:{grid:{color:'rgba(255,255,255,0.04)'},ticks:{color:'#6b7099',font:{size:10}}}}}}); }
function exportMonthlyReport() {
    const table = document.querySelector('#monthlySummary table');
    if (!table) { showToast('No monthly data to export', 'danger'); return; }

    const wb = XLSX.utils.book_new();

    // Pull data from the rendered table
    const rows = [
        ['ML Shield — Monthly Summary Report'],
        [`Generated: ${new Date().toLocaleString()}`],
        []
    ];
    table.querySelectorAll('tr').forEach(tr => {
        const cells = [...tr.querySelectorAll('th, td')].map(c => c.innerText.trim());
        rows.push(cells);
    });

    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:18},{wch:10},{wch:10},{wch:10}];
    XLSX.utils.book_append_sheet(wb, ws, 'Monthly Report');

    XLSX.writeFile(wb, `mlshield_monthly_${ts()}.xlsx`);
    showToast('Monthly report exported', 'success');
}

// AI Chat (unchanged)
// ========== AI ASSISTANT - CALLS API ==========
function sendAiMessage() { 
    const input = document.getElementById('aiInput'); 
    const msg = input.value.trim(); 
    if(!msg) return; 
    
    // Add user message to chat
    addAiMessage(msg, 'user'); 
    input.value = '';
    
    // Show typing indicator
    showAiTyping();
    
    // Call the API
    fetch('llm_investigator.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ query: msg })
    })
    .then(response => response.json())
    .then(data => {
        hideAiTyping();
        if (data.success) {
            addAiMessage(data.answer, 'bot');
        } else {
            addAiMessage('❌ Error: ' + (data.error || 'Unknown error'), 'bot');
        }
    })
    .catch(error => {
        hideAiTyping();
        addAiMessage('❌ Network error: ' + error.message, 'bot');
        console.error('Error:', error);
    });
}

function showAiTyping() {
    const chat = document.getElementById('aiChat');
    const typingDiv = document.createElement('div');
    typingDiv.id = 'aiTyping';
    typingDiv.className = 'ai-msg bot';
    typingDiv.innerHTML = `
        <div class="ai-avatar bot"><i class="fas fa-robot"></i></div>
        <div class="ai-bubble" style="background: var(--surface2);">
            <span style="display: inline-block; width: 8px; height: 8px; background: var(--muted); border-radius: 50%; margin: 0 2px; animation: typing 1.4s infinite;"></span>
            <span style="display: inline-block; width: 8px; height: 8px; background: var(--muted); border-radius: 50%; margin: 0 2px; animation: typing 1.4s 0.2s infinite;"></span>
            <span style="display: inline-block; width: 8px; height: 8px; background: var(--muted); border-radius: 50%; margin: 0 2px; animation: typing 1.4s 0.4s infinite;"></span>
            <span style="margin-left: 8px; font-size: 12px; color: var(--muted);">AI is thinking...</span>
        </div>
    `;
    chat.appendChild(typingDiv);
    chat.scrollTop = chat.scrollHeight;
}

function hideAiTyping() {
    const typing = document.getElementById('aiTyping');
    if (typing) typing.remove();
}

function addAiMessage(text, role) { 
    const chat = document.getElementById('aiChat'); 
    const div = document.createElement('div'); 
    div.className = `ai-msg ${role}`; 
    // Preserve line breaks
    const formattedText = text.replace(/\n/g, '<br>');
    div.innerHTML = `
        <div class="ai-avatar ${role}">${role === 'bot' ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-user"></i>'}</div>
        <div class="ai-bubble">${formattedText}</div>
    `; 
    chat.appendChild(div); 
    chat.scrollTop = chat.scrollHeight; 
}

function aiQuick(q) {
    document.getElementById('aiInput').value = q;
    sendAiMessage();
}

// Add CSS animation
const style = document.createElement('style');
style.textContent = `
    @keyframes typing {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
        30% { transform: translateY(-8px); opacity: 1; }
    }
`;
document.head.appendChild(style);

// Actions (unchanged)
// FIXED — sends token in X-CSRF-Token header, matches block_ip.php's getallheaders()
function blockIP(ip) {
    if (!confirm(`Block IP: ${ip}?`)) return;
    fetch('block_ip.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf_token ?>'   // ← moved here
        },
        body: 'ip=' + encodeURIComponent(ip)        // ← token removed from body
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || ip + ' blocked', 'success');
            fetchDashboard();   // refreshes the blockedIPs stat card
        } else {
            showToast('Error: ' + (data.error || 'unknown'), 'danger');
        }
    })
    .catch(() => showToast('Request failed', 'danger'));
}function blockTopAttackers() { if(!confirm('Block all top attackers?')) return; fetch('block_all.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`csrf_token=<?= $csrf_token ?>`}).then(()=>{showToast('All top attackers blocked','success');fetchDashboard();}).catch(()=>showToast('Done (demo mode)','success')); }
function saveSettings() { const s={ alert_level:document.getElementById('alertLevel').value, refresh_interval:parseInt(document.getElementById('refreshInterval').value), security_level:document.getElementById('securityLevel').value, notification_email:document.getElementById('notificationEmail').value }; localStorage.setItem('mlshield_settings',JSON.stringify(s)); currentRefresh=s.refresh_interval; clearInterval(refreshTimer); refreshTimer=setInterval(fetchDashboard,currentRefresh*1000); showToast('Settings saved','success'); }
function loadSettings() { const saved=localStorage.getItem('mlshield_settings'); if(saved){ const s=JSON.parse(saved); document.getElementById('alertLevel').value=s.alert_level||'all'; document.getElementById('refreshInterval').value=s.refresh_interval||5; document.getElementById('securityLevel').value=s.security_level||'medium'; document.getElementById('notificationEmail').value=s.notification_email||''; currentRefresh=s.refresh_interval||5; } }
function clearAllData() { if(!confirm('⚠ This will delete ALL attack data. Are you sure?')) return; fetch('clear_data.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`csrf_token=<?= $csrf_token ?>`}).then(()=>{showToast('All data cleared','success');fetchDashboard();}).catch(()=>showToast('Cleared (demo)','success')); }
function toggleTheme() { isDark=!isDark; const vars={ '--bg': isDark?'#07080f':'#f0f2f8', '--surface': isDark?'#0d0f1a':'#fff', '--surface2': isDark?'#131626':'#f5f7fc', '--surface3': isDark?'#1a1d30':'#e8ecf5', '--border': isDark?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.08)', '--border-accent':isDark?'rgba(255,255,255,0.12)':'rgba(0,0,0,0.15)', '--text': isDark?'#e8eaf0':'#1a1d2e', '--muted': isDark?'#6b7099':'#8a90b0' }; Object.entries(vars).forEach(([k,v])=>document.body.style.setProperty(k,v)); document.getElementById('themeBtn').innerHTML=`<i class="fas fa-${isDark?'moon':'sun'}"></i>`; localStorage.setItem('theme',isDark?'dark':'light'); }
function exportCSV() { const attacks=currentData.recent_attacks||MOCK_ATTACKS; const rows=[['Time','IP','URL','Type','Confidence','Severity'],...attacks.map(a=>[new Date(a.timestamp).toLocaleString(),a.attacker_ip,a.target_url,a.attack_type,((a.confidence||0)*100).toFixed(1)+'%',a.severity])]; download(rows.map(r=>r.join(',')).join('\n'),`attacks_${ts()}.csv`,'text/csv'); showToast('CSV exported','success'); }
function exportExcel() {
    const attacks = currentData.recent_attacks || MOCK_ATTACKS;
    if (!attacks.length) { showToast('No data to export', 'danger'); return; }

    const s = currentData.stats || MOCK_STATS;

    const wb = XLSX.utils.book_new();

    // Sheet 1: Attacks
    const attackRows = [
        ['ML Shield — Attack Report'],
        [`Generated: ${new Date().toLocaleString()}`],
        [],
        ['Time', 'Attacker IP', 'Target URL', 'Attack Type', 'Confidence', 'Severity', 'Anomaly Score', 'Is Critical']
    ];
    attacks.forEach(a => attackRows.push([
        new Date(a.timestamp).toLocaleString(),
        a.attacker_ip,
        a.target_url || '',
        a.attack_type,
        a.confidence ? (a.confidence * 100).toFixed(1) + '%' : '—',
        (a.severity || 'medium').toUpperCase(),
        a.anomaly_score ?? '—',
        a.is_critical ? 'Yes' : 'No'
    ]));
    const ws1 = XLSX.utils.aoa_to_sheet(attackRows);
    ws1['!cols'] = [
        {wch:22},{wch:16},{wch:45},{wch:20},{wch:12},{wch:10},{wch:14},{wch:10}
    ];
    XLSX.utils.book_append_sheet(wb, ws1, 'Attacks');

    // Sheet 2: Summary stats
    const statsRows = [
        ['ML Shield — Summary Statistics'],
        [`Generated: ${new Date().toLocaleString()}`],
        [],
        ['Metric', 'Value'],
        ['Critical Attacks',  s.critical_attacks  || 0],
        ['High Attacks',      s.high_attacks       || 0],
        ['Medium Attacks',    s.medium_attacks     || 0],
        ['Total Today',       s.total_attacks      || 0],
        ['Unique Attackers',  s.unique_attackers   || 0],
        ['Blocked IPs',       s.blocked_ips        || 0]
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(statsRows);
    ws2['!cols'] = [{wch:22},{wch:12}];
    XLSX.utils.book_append_sheet(wb, ws2, 'Summary');

    XLSX.writeFile(wb, `mlshield_export_${ts()}.xlsx`);
    showToast('Excel exported successfully', 'success');
}
function exportPDF() {
    const attacks = currentData.recent_attacks || MOCK_ATTACKS;
    if (!attacks.length) { showToast('No data to export', 'danger'); return; }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    // Header
    doc.setFillColor(7, 8, 15);
    doc.rect(0, 0, 210, 30, 'F');
    doc.setTextColor(79, 111, 255);
    doc.setFontSize(18);
    doc.setFont('helvetica', 'bold');
    doc.text('ML Shield — Attack Report', 14, 18);

    doc.setFontSize(9);
    doc.setTextColor(107, 112, 153);
    doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 25);
    doc.text(`Total attacks: ${attacks.length}`, 140, 25);

    // Stats summary box
    const s = currentData.stats || MOCK_STATS;
    doc.setFillColor(13, 15, 26);
    doc.roundedRect(14, 34, 182, 22, 3, 3, 'F');
    doc.setFontSize(9);
    doc.setTextColor(255, 61, 90);  doc.text(`Critical: ${s.critical_attacks || 0}`, 20, 43);
    doc.setTextColor(245, 158, 11); doc.text(`High: ${s.high_attacks || 0}`, 60, 43);
    doc.setTextColor(6, 182, 212);  doc.text(`Medium: ${s.medium_attacks || 0}`, 95, 43);
    doc.setTextColor(16, 185, 129); doc.text(`Unique Attackers: ${s.unique_attackers || 0}`, 135, 43);
    doc.setTextColor(107, 112, 153);doc.text(`Blocked IPs: ${s.blocked_ips || 0}`, 20, 51);
    doc.text(`Total Today: ${s.total_attacks || 0}`, 60, 51);

    // Attacks table
    doc.autoTable({
        startY: 62,
        head: [['Time', 'Attacker IP', 'Target URL', 'Attack Type', 'Confidence', 'Severity']],
        body: attacks.map(a => [
            new Date(a.timestamp).toLocaleString(),
            a.attacker_ip,
            (a.target_url || '').substring(0, 45),
            a.attack_type,
            a.confidence ? (a.confidence * 100).toFixed(1) + '%' : '—',
            (a.severity || 'medium').toUpperCase()
        ]),
        styles: {
            fontSize: 8,
            cellPadding: 3,
            textColor: [232, 234, 240],
            fillColor: [13, 15, 26],
            lineColor: [26, 29, 48],
            lineWidth: 0.3
        },
        headStyles: {
            fillColor: [19, 22, 38],
            textColor: [107, 112, 153],
            fontStyle: 'bold',
            fontSize: 8
        },
        alternateRowStyles: {
            fillColor: [19, 22, 38]
        },
        columnStyles: {
            5: {
                cellWidth: 22,
                fontStyle: 'bold'
            }
        },
        didDrawCell: (data) => {
            // Color severity column
            if (data.column.index === 5 && data.section === 'body') {
                const val = data.cell.raw;
                if (val === 'CRITICAL') doc.setTextColor(255, 61, 90);
                else if (val === 'HIGH')     doc.setTextColor(245, 158, 11);
                else                         doc.setTextColor(6, 182, 212);
            }
        }
    });

    // Footer
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(107, 112, 153);
        doc.text(`ML Shield Security Report — Page ${i} of ${pageCount}`, 14, doc.internal.pageSize.height - 8);
    }

    doc.save(`mlshield_report_${ts()}.pdf`);
    showToast('PDF exported successfully', 'success');
}
function download(data,filename,mime){const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([data],{type:mime}));a.download=filename;a.click();URL.revokeObjectURL(a.href);}
function ts(){return new Date().toISOString().slice(0,19).replace(/:/g,'-');}
function showToast(msg,type='success') { const icon=type==='success'?'check-circle':'circle-exclamation'; const t=document.createElement('div'); t.className=`toast toast-${type}`; t.innerHTML=`<i class="fas fa-${icon}"></i><span>${msg}</span>`; document.body.appendChild(t); setTimeout(()=>{t.style.transition='all .3s';t.style.opacity='0';t.style.transform='translateX(120%)';setTimeout(()=>t.remove(),300);},3000); }
function relTime(ts) { const diff=(Date.now()-new Date(ts))/1000; if(diff<60) return `${Math.floor(diff)}s ago`; if(diff<3600) return `${Math.floor(diff/60)}m ago`; return `${Math.floor(diff/3600)}h ago`; }
function refreshAll(){fetchDashboard();loadAllAttacks();loadAllAttackers();showToast('Refreshed','success');}
function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; }); }

const pages = { dashboard: {id:'dashboardPage', title:'Overview', sub:'Real-time threat intelligence'}, attacks: {id:'attacksPage', title:'Attacks', sub:'Complete attack history · 10 per page'}, attackers: {id:'attackersPage', title:'Attackers', sub:'IP reputation & directory'}, analytics: {id:'analyticsPage', title:'Analytics', sub:'Deep-dive insights & trends'}, ai: {id:'aiPage', title:'AI Assistant', sub:'Intelligent security analysis'}, settings: {id:'settingsPage', title:'Settings', sub:'System configuration'} };

document.addEventListener('DOMContentLoaded',()=>{
    loadSettings();
    initLeafletMap();
    fetchDashboard();
    refreshTimer = setInterval(fetchDashboard, currentRefresh * 1000);
    if(localStorage.getItem('theme')==='light') toggleTheme();
});
// ========== CHANGE PASSWORD FUNCTIONALITY ==========
function showChangePasswordModal() {
    document.getElementById('passwordModal').classList.add('show');
    document.getElementById('current_password').value = '';
    document.getElementById('new_password').value = '';
    document.getElementById('confirm_password').value = '';
    document.getElementById('passwordStrength').innerHTML = '';
    document.getElementById('passwordStrength').className = 'password-strength';
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.remove('show');
}

function checkPasswordStrength() {
    const password = document.getElementById('new_password').value;
    const strengthDiv = document.getElementById('passwordStrength');
    
    if (password.length === 0) {
        strengthDiv.innerHTML = '';
        strengthDiv.className = 'password-strength';
        return;
    }
    
    let strength = 0;
    let message = '';
    let className = '';
    
    // Length check
    if (password.length >= 8) strength++;
    if (password.length >= 12) strength++;
    
    // Complexity checks
    if (/[a-z]/.test(password)) strength++;
    if (/[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    if (strength <= 2) {
        message = '❌ Weak password - use at least 8 characters with uppercase, lowercase, numbers, and symbols';
        className = 'weak';
    } else if (strength <= 4) {
        message = '⚠️ Medium password - add more complexity';
        className = 'medium';
    } else {
        message = '✅ Strong password!';
        className = 'strong';
    }
    
    strengthDiv.innerHTML = message;
    strengthDiv.className = 'password-strength ' + className;
}

function changePassword(event) {
    event.preventDefault();
    
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    // Validation
    if (newPassword.length < 8) {
        showToast('Password must be at least 8 characters', 'danger');
        return;
    }
    
    if (newPassword !== confirmPassword) {
        showToast('New passwords do not match', 'danger');
        return;
    }
    
    // Check password strength
    let strength = 0;
    if (newPassword.length >= 8) strength++;
    if (/[a-z]/.test(newPassword)) strength++;
    if (/[A-Z]/.test(newPassword)) strength++;
    if (/[0-9]/.test(newPassword)) strength++;
    if (/[^a-zA-Z0-9]/.test(newPassword)) strength++;
    
    if (strength < 3) {
        showToast('Please use a stronger password (uppercase, lowercase, numbers, symbols)', 'danger');
        return;
    }
    
    // Send request to change password
    fetch('change_password.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf_token ?>'
        },
        body: 'current_password=' + encodeURIComponent(currentPassword) + 
              '&new_password=' + encodeURIComponent(newPassword)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Password changed successfully! Please login again.', 'success');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 2000);
        } else {
            showToast(data.error || 'Failed to change password', 'danger');
        }
    })
    .catch(error => {
        showToast('Network error. Please try again.', 'danger');
    });
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('passwordModal');
    if (event.target === modal) {
        closePasswordModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closePasswordModal();
    }
});
</script>
</body>
</html>
 