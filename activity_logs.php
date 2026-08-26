<?php
require_once 'config.php';
requireLogin();

// Only admins can view logs
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$logs = [];

try {
    // Check if table exists
    $check = $pdo->query("SHOW TABLES LIKE 'activity_logs'");
    if ($check->rowCount() == 0) {
        $error = "Activity logs table does not exist.";
    } else {
        // Get all logs
        $stmt = $pdo->query("
            SELECT * FROM activity_logs 
            ORDER BY created_at DESC 
            LIMIT 200
        ");
        $logs = $stmt->fetchAll();
    }
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT ip_address) as unique_ips,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today
    FROM activity_logs
")->fetch();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Activity Logs - Dashboard Access</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
        }
        
        h2 {
            color: white;
            margin-bottom: 20px;
        }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-box {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #70a1ff;
        }
        
        .stat-label {
            color: rgba(255,255,255,0.8);
            font-size: 12px;
            margin-top: 5px;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filters input, .filters select {
            padding: 10px;
            border-radius: 8px;
            border: none;
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 14px;
        }
        
        .filters input::placeholder {
            color: rgba(255,255,255,0.6);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            color: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        th {
            background: rgba(255,255,255,0.2);
            font-weight: bold;
        }
        
        tr:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .error {
            background: rgba(255, 71, 87, 0.2);
            border-left: 4px solid #ff4757;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #ff4757;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: rgba(255,255,255,0.6);
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-access {
            background: #17a2b8;
        }
        
        .badge-view {
            background: #28a745;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-5px);
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            margin-left: 10px;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        @media (max-width: 768px) {
            table {
                font-size: 12px;
            }
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2><i class="fas fa-history"></i> Dashboard Access Logs</h2>
            <button class="refresh-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$error && count($logs) > 0): ?>
            <!-- Statistics -->
            <div class="stats-bar">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Dashboard Accesses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['today']; ?></div>
                    <div class="stat-label">Today's Accesses</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['unique_users']; ?></div>
                    <div class="stat-label">Unique Users</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['unique_ips']; ?></div>
                    <div class="stat-label">Unique IP Addresses</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters">
                <input type="text" id="searchInput" placeholder="🔍 Search by user, action, or IP..." onkeyup="filterTable()">
                <select id="actionFilter" onchange="filterTable()">
                    <option value="">All Actions</option>
                    <option value="DASHBOARD_ACCESS">Dashboard Access</option>
                    <option value="DASHBOARD_VIEW">Dashboard View</option>
                </select>
            </div>
            
            <!-- Logs Table -->
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                            <th>Browser</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                            <td>
                                <i class="fas fa-user-circle"></i>
                                <?php echo htmlspecialchars($log['username'] ?? 'Guest'); ?>
                                <?php if ($log['user_id'] > 0): ?>
                                    <span style="color: #70a1ff; font-size: 10px;">(ID: <?php echo $log['user_id']; ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badge_class = ($log['action'] == 'DASHBOARD_ACCESS') ? 'badge-access' : 'badge-view';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                             </td>
                            <td><?php echo htmlspecialchars($log['details'] ?? 'N/A'); ?></td>
                            <td>
                                <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                            </td>
                            <td>
                                <small title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                    <?php 
                                    $agent = $log['user_agent'];
                                    if (strlen($agent) > 50) {
                                        echo substr($agent, 0, 50) . '...';
                                    } else {
                                        echo $agent;
                                    }
                                    ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
        <?php elseif (!$error && count($logs) == 0): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list" style="font-size: 48px; margin-bottom: 20px;"></i>
                <p>No dashboard access logs found yet.</p>
                <p>Logs will appear here when users access the dashboard.</p>
            </div>
        <?php endif; ?>
        
        <br>
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    
    <script>
        function filterTable() {
            const searchValue = document.getElementById('searchInput').value.toLowerCase();
            const actionValue = document.getElementById('actionFilter').value;
            const rows = document.querySelectorAll('#logsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const actionCell = row.cells[2]?.textContent || '';
                const matchesSearch = text.includes(searchValue);
                const matchesAction = !actionValue || actionCell.includes(actionValue);
                
                if (matchesSearch && matchesAction) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
