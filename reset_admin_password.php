<?php
// admin_users.php - Complete Admin Management with Add/Delete & Telegram Fields
require_once 'config.php';

 

$success = '';
$error = '';
$showAddForm = false;
$current_user_id = $_SESSION['user_id'] ?? 0;

// Handle user actions - FIXED with safe server check
$request_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($request_method == 'POST' && isset($_POST['action'])) {
    if (isset($_POST['csrf_token']) && verifyCSRFToken($_POST['csrf_token'])) {
        
        // ADD NEW ADMIN
        if ($_POST['action'] == 'add') {
            $new_username = trim($_POST['username']);
            $new_email = trim($_POST['email']);
            $new_password = $_POST['password'];
            $telegram_chat_id = !empty($_POST['telegram_chat_id']) ? trim($_POST['telegram_chat_id']) : NULL;
            $telegram_username = !empty($_POST['telegram_username']) ? trim($_POST['telegram_username']) : NULL;
            
            if (empty($new_username) || empty($new_password)) {
                $error = "Username and password are required";
            } else {
                try {
                    // Check if username exists
                    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $check->execute([$new_username]);
                    if ($check->rowCount() > 0) {
                        $error = "Username already exists";
                    } else {
                        $hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("
                            INSERT INTO users (username, email, password_hash, role, telegram_chat_id, telegram_username, telegram_notifications) 
                            VALUES (?, ?, ?, 'admin', ?, ?, 1)
                        ");
                        $stmt->execute([$new_username, $new_email, $hash, $telegram_chat_id, $telegram_username]);
                        
                        if (function_exists('logActivity')) {
                            logActivity($current_user_id, 'ADD_ADMIN', "Added new admin: $new_username");
                        }
                        $success = "Admin user '$new_username' created successfully";
                        $showAddForm = false;
                    }
                } catch(PDOException $e) {
                    $error = "Failed to add admin: " . $e->getMessage();
                }
            }
        }
        
        // DELETE ADMIN
        if ($_POST['action'] == 'delete') {
            $user_id = intval($_POST['user_id']);
            
            // Prevent deleting own account
            if ($user_id == $current_user_id) {
                $error = "You cannot delete your own account";
            } else {
                try {
                    // Get username for logging
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$user_id]);
                    $username = $stmt->fetchColumn();
                    
                    if ($username) {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
                        $stmt->execute([$user_id]);
                        
                        if (function_exists('logActivity')) {
                            logActivity($current_user_id, 'DELETE_ADMIN', "Deleted admin: $username (ID: $user_id)");
                        }
                        $success = "Admin user '$username' deleted successfully";
                    } else {
                        $error = "User not found or not an admin";
                    }
                } catch(PDOException $e) {
                    $error = "Failed to delete admin: " . $e->getMessage();
                }
            }
        }
        
        // RESET PASSWORD
        if ($_POST['action'] == 'reset_password') {
            $user_id = intval($_POST['user_id']);
            try {
                // Generate a strong 12-character password
                $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%';
                $new_password = '';
                for ($i = 0; $i < 12; $i++) {
                    $new_password .= $characters[random_int(0, strlen($characters) - 1)];
                }
                
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'");
                $stmt->execute([$hash, $user_id]);
                
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $username = $stmt->fetchColumn();
                    
                    if (function_exists('logActivity')) {
                        logActivity($current_user_id, 'RESET_ADMIN_PASSWORD', "Reset password for admin: $username");
                    }
                    $success = "Password reset for admin '$username'. New password: <strong style='background:#1a1d30; padding:4px 8px; border-radius:6px; font-family:monospace;'>$new_password</strong>";
                } else {
                    $error = "Admin user not found";
                }
            } catch(PDOException $e) {
                $error = "Failed to reset password: " . $e->getMessage();
            } catch(Exception $e) {
                $error = "Failed to generate password: " . $e->getMessage();
            }
        }
    } else {
        $error = "CSRF validation failed";
    }
}

// Toggle add form
if (isset($_GET['action']) && $_GET['action'] == 'add') {
    $showAddForm = true;
}

// Get ALL admin users with Telegram info
try {
    $table_check = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($table_check->rowCount() == 0) {
        $error = "Users table does not exist. Please create it first.";
        $admins = [];
    } else {
        // Check if telegram columns exist, if not add them
        $columns = $pdo->query("SHOW COLUMNS FROM users");
        $existing_columns = [];
        while ($col = $columns->fetch()) {
            $existing_columns[] = $col['Field'];
        }
        
        if (!in_array('telegram_chat_id', $existing_columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN telegram_chat_id BIGINT NULL UNIQUE");
        }
        if (!in_array('telegram_username', $existing_columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN telegram_username VARCHAR(100) NULL");
        }
        if (!in_array('telegram_notifications', $existing_columns)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN telegram_notifications TINYINT DEFAULT 1");
        }
        
        // Get only users with role = 'admin'
        $query = "SELECT id, username, email, role, created_at, telegram_chat_id, telegram_username, telegram_notifications 
                  FROM users WHERE role = 'admin' ORDER BY id";
        $admins = $pdo->query($query)->fetchAll();
        
        // If no admin exists, create a default one
        if (count($admins) == 0) {
            $default_password = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')");
            $stmt->execute([$default_password]);
            $admins = $pdo->query($query)->fetchAll();
            $success = "Default admin user created (username: admin, password: admin123)";
        }
    }
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $admins = [];
}

$totalAdmins = count($admins);
$csrf_token = generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - ML Shield</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            --font: 'Space Grotesk', sans-serif;
            --mono: 'JetBrains Mono', monospace;
        }

        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
        }

        body::before {
            content:'';
            position:fixed; inset:0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
            pointer-events:none; z-index:0;
        }

        .wrap {
            width: 100%;
            max-width: 1200px;
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }

        .brand-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .brand-name {
            font-size: 17px;
            font-weight: 700;
        }

        .brand-sep {
            width: 1px; height: 24px;
            background: var(--border-accent);
            margin: 0 4px;
        }

        .brand-page {
            font-size: 14px;
            color: var(--muted);
            font-weight: 500;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            margin-bottom: 20px;
            border: 1px solid;
            animation: fadeDown .3s ease;
        }

        @keyframes fadeDown {
            from { opacity:0; transform:translateY(-8px); }
            to { opacity:1; transform:translateY(0); }
        }

        .alert-success {
            background: rgba(16,185,129,0.08);
            border-color: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .alert-error {
            background: rgba(255,61,90,0.08);
            border-color: rgba(255,61,90,0.2);
            color: #ff6b84;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .card-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            background: rgba(79,111,255,0.12);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 600;
            flex: 1;
        }

        .badge {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            background: rgba(79,111,255,0.12);
            color: var(--accent);
        }

        .btn-add {
            background: var(--success);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-add:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            padding: 0 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }

        .user-avatar {
            width: 28px; height: 28px;
            border-radius: 7px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            margin-right: 8px;
        }

        .user-name {
            font-weight: 600;
        }

        .telegram-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            padding: 2px 8px;
            background: rgba(0,136,204,0.15);
            border-radius: 20px;
            color: #0088cc;
        }

        .role-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
            background: rgba(79,111,255,0.12);
            color: var(--accent);
            border: 1px solid rgba(79,111,255,0.2);
        }

        .btn-icon {
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 14px;
            padding: 6px 10px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-reset {
            color: var(--success);
        }

        .btn-reset:hover {
            background: rgba(16,185,129,0.2);
        }

        .btn-delete {
            color: var(--danger);
        }

        .btn-delete:hover {
            background: rgba(255,61,90,0.2);
        }

        .add-user-form {
            background: var(--surface2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: none;
        }

        .add-user-form.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .form-input {
            width: 100%;
            background: var(--surface3);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            padding: 11px 14px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: var(--surface3);
            color: var(--muted);
            border: 1px solid var(--border);
            padding: 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 10px;
        }

        .info-box {
            background: rgba(79,111,255,0.06);
            border: 1px solid rgba(79,111,255,0.15);
            border-radius: 11px;
            padding: 14px 18px;
            font-size: 13px;
            color: var(--muted);
        }

        .info-row {
            display: flex;
            gap: 9px;
            margin-bottom: 5px;
        }
        .info-dot {
            width: 5px; height: 5px;
            background: var(--accent);
            border-radius: 50%;
            margin-top: 6px;
        }

        .foot-links {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .foot-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
        }

        .foot-link:hover {
            background: var(--surface2);
            color: var(--text);
        }

        .telegram-field-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .telegram-field-group {
                grid-template-columns: 1fr;
            }
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        .btn-warning {
    color: var(--warn);
}
.btn-warning:hover {
    background: rgba(245,158,11,0.2);
}
    </style>
</head>
<body>

<div class="wrap">

    <div class="brand">
        <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
        <span class="brand-name">ML Shield</span>
        <div class="brand-sep"></div>
        <span class="brand-page">Admin Management</span>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $success; ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="card-icon"><i class="fas fa-users-gear"></i></div>
            <span class="card-title">Current Admin Accounts</span>
            <span class="badge"><?php echo $totalAdmins; ?> admins</span>
            <button class="btn-add" onclick="toggleAddForm()">
                <i class="fas fa-user-plus"></i> Add New Admin
            </button>
        </div>

        <div id="addUserForm" class="add-user-form <?php echo $showAddForm ? 'show' : ''; ?>">
            <h4 style="margin-bottom: 16px;"><i class="fas fa-user-shield"></i> Create New Administrator</h4>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input class="form-input" type="text" name="username" placeholder="Enter username" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email (Optional)</label>
                    <input class="form-input" type="email" name="email" placeholder="admin@example.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input class="form-input" type="password" name="password" id="newPassField" placeholder="Enter strong password" required>
                </div>
                
                <div class="telegram-field-group">
                    <div class="form-group">
                        <label class="form-label"><i class="fab fa-telegram"></i> Telegram Chat ID</label>
                        <input class="form-input" type="text" name="telegram_chat_id" placeholder="e.g., 5060323552">
                        <small style="color: var(--muted); font-size: 10px;">Get from @userinfobot</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="fab fa-telegram"></i> Telegram Username</label>
                        <input class="form-input" type="text" name="telegram_username" placeholder="e.g., simbalonewolf">
                        <small style="color: var(--muted); font-size: 10px;">Without @ symbol</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Create Admin</button>
                    <button type="button" class="btn-cancel" onclick="toggleAddForm()"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </form>
        </div>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                    <?php 
                        // Safe check for current user
                        $is_current_user = (isset($current_user_id) && $admin['id'] == $current_user_id);
                    ?>
                    <tr>
                        <td>#<?php echo $admin['id']; ?></td>
                        <td>
                            <span class="user-avatar"><?php echo strtoupper(substr($admin['username'], 0, 2)); ?></span>
                            <span class="user-name"><?php echo htmlspecialchars($admin['username']); ?></span>
                            <?php if ($is_current_user): ?>
                                <span style="color: var(--success); font-size: 10px;">(You)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($admin['email'])): ?>
                                <?php echo htmlspecialchars($admin['email']); ?>
                            <?php else: ?>
                                <span style="color: var(--warn);">No email</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($admin['telegram_chat_id']) || !empty($admin['telegram_username'])): ?>
                                <span class="telegram-badge">
                                    <i class="fab fa-telegram"></i>
                                    <?php if (!empty($admin['telegram_username'])): ?>
                                        @<?php echo htmlspecialchars($admin['telegram_username']); ?>
                                    <?php else: ?>
                                        ID: <?php echo htmlspecialchars($admin['telegram_chat_id']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--warn);">Not connected</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="role-pill">admin</span></td>
                        <td><?php echo date('Y-m-d', strtotime($admin['created_at'] ?? 'now')); ?></td>
                       <td>
    <?php if (!$is_current_user): ?>
        <!-- Generate Temporary Password Button -->
        <button class="btn-icon btn-warning" title="Generate Temporary Password (Replaces current password in password_hash column)" 
                onclick="generateTempPassword(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')">
            <i class="fas fa-key"></i>
        </button>
        
        <!-- Delete Button -->
        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="user_id" value="<?php echo $admin['id']; ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn-icon btn-delete" title="Delete Admin" onclick="return confirm('Delete <?php echo htmlspecialchars($admin['username']); ?>?')">
                <i class="fas fa-trash-alt"></i>
            </button>
        </form>
    <?php else: ?>
        <span style="color: var(--muted);"><i class="fas fa-lock"></i> You</span>
    <?php endif; ?>
</td>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="info-box">
        <div class="info-row"><div class="info-dot"></div><span>Add <strong>email</strong> for OTP verification</span></div>
        <div class="info-row"><div class="info-dot"></div><span>Add <strong>Telegram Chat ID</strong> for bot notifications</span></div>
        <div class="info-row"><div class="info-dot"></div><span>You <strong>cannot delete</strong> your own account</span></div>
    </div>

    <div class="foot-links">
        <a href="dashboard.php" class="foot-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

</div>

<script>
function toggleAddForm() {
    const form = document.getElementById('addUserForm');
    form.classList.toggle('show');
}
function generateTempPassword(userId, username) {
    if (!confirm(`Generate temporary password for ${username}?\n\n⚠️ This will REPLACE their current password in the password_hash column.\n⚠️ The admin must change this password after first login.`)) {
        return;
    }
    
    fetch('generate_temp_password.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'user_id=' + userId + '&csrf_token=<?php echo $csrf_token; ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`✅ TEMPORARY PASSWORD GENERATED\n\nUser: ${data.username}\nPassword: ${data.temp_password}\n\n⚠️ IMPORTANT:\n• This password REPLACED their old password in the database\n• Give this password to the admin\n• They MUST change it after login`);
            
            // Copy to clipboard
            if (confirm('Copy password to clipboard?')) {
                navigator.clipboard.writeText(data.temp_password);
                alert('Password copied to clipboard!');
            }
        } else {
            alert('❌ Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('❌ Network error: ' + error);
    });
}
</script>

</body>
</html>