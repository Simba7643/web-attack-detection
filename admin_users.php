<?php
require_once 'config.php';
requireLogin();

// Only admins can access this page
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$success = '';
$error = '';
$showAddForm = false;

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (isset($_POST['csrf_token']) && verifyCSRFToken($_POST['csrf_token'])) {
        
        // Add new admin user
        if ($_POST['action'] == 'add') {
            $new_username = trim($_POST['username']);
            $new_password = $_POST['password'];
            
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
                        // Force role as 'admin'
                        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
                        $stmt->execute([$new_username, $hash]);
                        logActivity($_SESSION['user_id'], 'ADD_ADMIN', "Added new admin: $new_username");
                        $success = "Admin user '$new_username' created successfully";
                    }
                } catch(PDOException $e) {
                    $error = "Failed to add admin: " . $e->getMessage();
                }
            }
        }
        
        // Delete admin user
        if ($_POST['action'] == 'delete') {
            $user_id = intval($_POST['user_id']);
            
            // Prevent deleting own account
            if ($user_id == $_SESSION['user_id']) {
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
                        logActivity($_SESSION['user_id'], 'DELETE_ADMIN', "Deleted admin: $username (ID: $user_id)");
                        $success = "Admin user '$username' deleted successfully";
                    } else {
                        $error = "User not found or not an admin";
                    }
                } catch(PDOException $e) {
                    $error = "Failed to delete admin: " . $e->getMessage();
                }
            }
        }
        
        // Reset admin password
        if ($_POST['action'] == 'reset_password') {
            $user_id = intval($_POST['user_id']);
            try {
                $new_password = bin2hex(random_bytes(4));
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'");
                $stmt->execute([$hash, $user_id]);
                
                if ($stmt->rowCount() > 0) {
                    // Get username
                    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $username = $stmt->fetchColumn();
                    
                    logActivity($_SESSION['user_id'], 'RESET_ADMIN_PASSWORD', "Reset password for admin: $username (ID: $user_id)");
                    $success = "Password reset for admin '$username'. New password: <strong>$new_password</strong>";
                } else {
                    $error = "Admin user not found";
                }
            } catch(PDOException $e) {
                $error = "Failed to reset password: " . $e->getMessage();
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

// Get ONLY admin users
try {
    $table_check = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($table_check->rowCount() == 0) {
        $error = "Users table does not exist. Please create it first.";
        $admins = [];
    } else {
        // Get only users with role = 'admin'
        $query = "SELECT id, username, role, created_at FROM users WHERE role = 'admin' ORDER BY id";
        $admins = $pdo->query($query)->fetchAll();
        
        // If no admin exists, create a default one
        if (count($admins) == 0) {
            $default_password = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')")->execute([$default_password]);
            $admins = $pdo->query($query)->fetchAll();
            $success = "Default admin user created (username: admin, password: admin123)";
        }
    }
} catch(PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $admins = [];
}

$totalAdmins = count($admins);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Management</title>
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
            margin: 0;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header Stats */
        .stats-header {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 48px;
            font-weight: bold;
            color: #ff4757;
        }
        
        .stat-label {
            color: rgba(255,255,255,0.8);
            margin-top: 5px;
            font-size: 14px;
        }
        
        /* Main Card */
        .card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .card-header h2 {
            color: white;
            font-size: 24px;
        }
        
        .btn-add {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-add:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        /* Add User Form */
        .add-user-form {
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: none;
        }
        
        .add-user-form.show {
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: white;
            font-weight: bold;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #ff4757;
        }
        
        .form-group input::placeholder {
            color: rgba(255,255,255,0.5);
        }
        
        .btn-submit {
            background: #ff4757;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .btn-submit:hover {
            background: #ff3344;
        }
        
        .btn-cancel {
            background: #70a1ff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        /* Table */
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
        
        .admin-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            background: #ff4757;
            color: white;
        }
        
        button {
            background: #ff4757;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            margin: 2px;
            font-size: 12px;
            transition: all 0.2s;
        }
        
        button:hover {
            opacity: 0.9;
            transform: scale(1.05);
        }
        
        .btn-reset {
            background: #ffa502;
        }
        
        .success {
            background: #28a745;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: white;
            animation: slideDown 0.3s ease-out;
        }
        
        .error {
            background: #dc3545;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: white;
            animation: slideDown 0.3s ease-out;
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
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: white;
        }
        
        .warning-note {
            background: rgba(255, 71, 87, 0.2);
            border-left: 4px solid #ff4757;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 5px;
            color: #ffaa00;
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
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
        <!-- Stats Header -->
        <div class="stats-header">
            <div class="stat-box">
                <div class="stat-number"><?php echo $totalAdmins; ?></div>
                <div class="stat-label">Total Administrators</div>
            </div>
            <div class="stat-box">
                <div class="stat-number">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="stat-label">Admin Access Only</div>
            </div>
        </div>
        
        <!-- Main Card -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-shield"></i> Administrator Management</h2>
                <button class="btn-add" onclick="toggleAddForm()">
                    <i class="fas fa-user-plus"></i> Add New Admin
                </button>
            </div>
            
            <!-- Warning Note -->
            <div class="warning-note">
                <i class="fas fa-exclamation-triangle"></i> 
                This section manages <strong>Administrator accounts only</strong>. Regular users are not displayed here.
            </div>
            
            <!-- Add User Form -->
            <div id="addUserForm" class="add-user-form <?php echo $showAddForm ? 'show' : ''; ?>">
                <h3 style="color: white; margin-bottom: 15px;">
                    <i class="fas fa-user-shield"></i> Create New Administrator
                </h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Username</label>
                        <input type="text" name="username" placeholder="Enter admin username" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password</label>
                        <input type="password" name="password" placeholder="Enter password" required>
                    </div>
                    
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Create Admin
                    </button>
                    <button type="button" class="btn-cancel" onclick="toggleAddForm()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </form>
            </div>
            
            <!-- Messages -->
            <?php if ($success): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Admins Table -->
            <?php if (count($admins) > 0): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($admin['id']); ?></td>
                                <td>
                                    <i class="fas fa-user-shield"></i> 
                                    <?php echo htmlspecialchars($admin['username']); ?>
                                    <?php if ($admin['id'] == $_SESSION['user_id']): ?>
                                        <span style="color: #ff4757; font-size: 11px;">(You)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-badge">
                                        <i class="fas fa-crown"></i> ADMINISTRATOR
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($admin['created_at'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($admin['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $admin['id']; ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <button type="submit" class="btn-reset" title="Reset Admin Password">
                                                <i class="fas fa-key"></i> Reset PW
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ WARNING: This will permanently delete admin <?php echo htmlspecialchars($admin['username']); ?>!\n\nAre you absolutely sure?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $admin['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" title="Delete Admin">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #ffa502;">
                                            <i class="fas fa-lock"></i> Cannot modify own account
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 15px; color: rgba(255,255,255,0.6); font-size: 12px;">
                    <i class="fas fa-info-circle"></i> 
                    Total Administrators: <strong><?php echo $totalAdmins; ?></strong>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-shield" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <p>No administrators found in database.</p>
                    <p>Click "Add New Admin" to create your first administrator.</p>
                </div>
            <?php endif; ?>
            
            <br>
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <script>
        function toggleAddForm() {
            const form = document.getElementById('addUserForm');
            form.classList.toggle('show');
        }
    </script>
</body>
</html>