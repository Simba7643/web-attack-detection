<?php
 
require_once 'config.php';

// Clear any existing session when accessing login page
if (!isset($_POST['username'])) {
    session_destroy();
    session_start();
}

// If already fully verified, go to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['admin_verified']) && $_SESSION['admin_verified'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$remaining_attempts = 3;
$wait_time = 0;

// Function to get client IP
function getClientIP() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 
          $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
          $_SERVER['REMOTE_ADDR'] ?? 
          '0.0.0.0';
    
    if (strpos($ip, ',') !== false) {
        $ips = explode(',', $ip);
        $ip = trim($ips[0]);
    }
    return $ip;
}

$ip = getClientIP();

// Check if user is in cooldown period
if (isset($_SESSION['login_blocked_until']) && $_SESSION['login_blocked_until'] > time()) {
    $wait_time = $_SESSION['login_blocked_until'] - time();
    $error = "⏳ Too many failed attempts! Please wait " . ceil($wait_time) . " seconds before trying again.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $wait_time <= 0) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Initialize or get failed attempts counter
    if (!isset($_SESSION['failed_attempts'])) {
        $_SESSION['failed_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    
    // Reset counter if more than 5 minutes passed since first attempt
    if (time() - $_SESSION['first_attempt_time'] > 300) {
        $_SESSION['failed_attempts'] = 0;
        $_SESSION['first_attempt_time'] = time();
    }
    
    $remaining_attempts = 3 - $_SESSION['failed_attempts'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['role'] !== 'admin') {
            $error = "Access denied. Admin privileges required.";
            // Still count as failed attempt for non-admin
            $_SESSION['failed_attempts']++;
        } else {
            // SUCCESS - reset all counters
            $_SESSION['failed_attempts'] = 0;
            $_SESSION['first_attempt_time'] = 0;
            unset($_SESSION['login_blocked_until']);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            
            header('Location: verify_otp.php');
            exit();
        }
    } else {
        // FAILED ATTEMPT
        $_SESSION['failed_attempts']++;
        $remaining_attempts = 3 - $_SESSION['failed_attempts'];
        
        if ($_SESSION['failed_attempts'] >= 3) {
            // Block for 1 MINUTE (60 seconds)
            $_SESSION['login_blocked_until'] = time() + 60;
            $_SESSION['failed_attempts'] = 0;
            $_SESSION['first_attempt_time'] = 0;
            $error = "⛔ TOO MANY FAILED ATTEMPTS! Please wait 60 seconds before trying again.";
        } else {
            $error = "Invalid username or password";
            if ($remaining_attempts > 0) {
                $error .= " You have {$remaining_attempts} attempt(s) remaining.";
            }
        }
    }
}

// Calculate remaining attempts for display
if (isset($_SESSION['failed_attempts']) && !isset($_SESSION['login_blocked_until'])) {
    $remaining_attempts = 3 - $_SESSION['failed_attempts'];
    if ($remaining_attempts < 0) $remaining_attempts = 0;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - Attack Detection System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 40px;
            width: 400px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-container h2 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
        }
        
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
            font-size: 14px;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
            font-size: 14px;
        }
        
        .attempts-counter {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            padding: 10px;
            border-radius: 8px;
            background: #f0f0f0;
        }
        
        .attempts-counter .danger {
            color: #ff4757;
            font-weight: bold;
        }
        
        .attempts-counter .warning-text {
            color: #ffa502;
        }
        
        .timer-display {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            padding: 10px;
            background: #f8d7da;
            border-radius: 8px;
            color: #721c24;
            font-weight: bold;
        }
        
        .step-info {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #999;
        }
        
        .step-info i {
            color: #667eea;
        }
        
        .block-warning {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>🔒 Attack Detection System</h2>
 
        
        <?php if (isset($error) && $error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($wait_time > 0): ?>
            <div class="timer-display" id="timerDisplay">
                ⏳ Please wait <span id="waitSeconds"><?php echo $wait_time; ?></span> seconds...
            </div>
        <?php endif; ?>
        
        <?php if (!isset($_SESSION['login_blocked_until']) && isset($remaining_attempts) && $remaining_attempts > 0 && $remaining_attempts < 3): ?>
            <div class="attempts-counter">
                <?php if ($remaining_attempts == 1): ?>
                    <span class="danger">⚠️ LAST ATTEMPT! ⚠️</span><br>
                    <span>Be careful - Next wrong password will lock you out for 60 seconds!</span>
                <?php else: ?>
                    <span>🔐 You have <strong class="warning-text"><?php echo $remaining_attempts; ?></strong> attempt(s) remaining</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="loginForm">
            <div class="form-group">
                <input type="text" name="username" placeholder="Username" required autofocus <?php echo $wait_time > 0 ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group">
                <input type="password" name="password" placeholder="Password" required <?php echo $wait_time > 0 ? 'disabled' : ''; ?>>
            </div>
            <button type="submit" id="loginBtn" <?php echo $wait_time > 0 ? 'disabled' : ''; ?>>Login</button>
        </form>
        
        <div class="step-info">
            <i class="fas fa-shield-alt"></i> Step 1 of 2: Enter your credentials
        </div>
        <div class="step-info" style="margin-top: 5px; font-size: 11px;">
            <i class="fas fa-clock"></i> 3 wrong attempts = 60 second delay
        </div>
    </div>
    
    <script>
        // Timer countdown for block period
        let waitTime = <?php echo $wait_time; ?>;
        let timerInterval;
        
        function startTimer() {
            if (waitTime > 0) {
                timerInterval = setInterval(function() {
                    waitTime--;
                    document.getElementById('waitSeconds').innerText = waitTime;
                    
                    if (waitTime <= 0) {
                        clearInterval(timerInterval);
                        document.getElementById('timerDisplay').style.display = 'none';
                        // Re-enable form
                        document.querySelector('input[name="username"]').disabled = false;
                        document.querySelector('input[name="password"]').disabled = false;
                        document.getElementById('loginBtn').disabled = false;
                        // Reload page to clear block state
                        location.reload();
                    }
                }, 1000);
            }
        }
        
        // Start timer if blocked
        if (waitTime > 0) {
            startTimer();
        }
        
        // Prevent multiple rapid submissions
        let submitted = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (submitted) {
                e.preventDefault();
                return false;
            }
            submitted = true;
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Verifying...';
            
            // Re-enable after 5 seconds if something went wrong
            setTimeout(function() {
                if (btn.disabled && btn.textContent !== 'Login') {
                    btn.disabled = false;
                    btn.textContent = 'Login';
                    submitted = false;
                }
            }, 5000);
        });
    </script>
</body>
</html>
