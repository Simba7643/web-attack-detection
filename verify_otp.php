<?php
// verify_otp.php - OTP Verification with Email Only (No on-screen display)
require_once 'config.php';
require_once 'send_otp.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: login.php');
    exit();
}

// If already verified, go to dashboard
if (isset($_SESSION['admin_verified']) && $_SESSION['admin_verified'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$email_sent = false;

// Handle reset request (Back to Login)
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['user_id']);
    unset($_SESSION['username']);
    unset($_SESSION['role']);
    unset($_SESSION['user_email']);
    unset($_SESSION['admin_verified']);
    unset($_SESSION['admin_verified_time']);
    unset($_SESSION['otp']);
    unset($_SESSION['otp_time']);
    header('Location: login.php');
    exit();
}

// Generate OTP if not exists or resend requested
if (!isset($_SESSION['otp']) || isset($_GET['resend'])) {
    $otp = rand(100000, 999999);
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_time'] = time();
    
    // Check if email exists
    if (isset($_SESSION['user_email']) && !empty($_SESSION['user_email']) && filter_var($_SESSION['user_email'], FILTER_VALIDATE_EMAIL)) {
        $email_sent = sendOTPEmail($_SESSION['user_email'], $_SESSION['username'], $otp);
        
        if ($email_sent) {
            $success = "✓ Verification code sent to " . hideEmail($_SESSION['user_email']);
        } else {
            $error = "❌ Failed to send email. Please check your email configuration or contact administrator.";
        }
    } else {
        $error = "❌ No valid email address found for this account. Please contact administrator to configure your email.";
    }
}

// Verify OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $entered_otp = trim($_POST['otp'] ?? '');
    
    if (!isset($_SESSION['otp_time']) || (time() - $_SESSION['otp_time'] > 300)) {
        $error = "❌ OTP has expired. Please request a new code.";
        unset($_SESSION['otp']);
        unset($_SESSION['otp_time']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['admin_verified'] = true;
        $_SESSION['admin_verified_time'] = time();
        
        unset($_SESSION['otp']);
        unset($_SESSION['otp_time']);
        
        header('Location: dashboard.php');
        exit();
    } else {
        $error = "❌ Invalid verification code. Please try again.";
    }
}

function hideEmail($email) {
    if (empty($email)) return 'No email configured';
    $parts = explode('@', $email);
    $username = $parts[0];
    $domain = $parts[1] ?? '';
    $hidden_username = substr($username, 0, 2) . str_repeat('*', max(3, strlen($username) - 2));
    return $hidden_username . '@' . $domain;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify OTP - Attack Detection System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .verify-container {
            background: white;
            border-radius: 24px;
            padding: 40px;
            width: 480px;
            max-width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            animation: fadeIn 0.5s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .verify-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #ff4757);
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
        
        .email-sent-box {
            background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
            color: #2d3748;
        }
        
        .email-sent-box i {
            font-size: 24px;
            margin-bottom: 8px;
            color: #28a745;
        }
        
        .icon {
            text-align: center;
            font-size: 56px;
            margin-bottom: 20px;
        }
        
        h2 {
            text-align: center;
            color: #1a1a2e;
            margin-bottom: 8px;
            font-size: 24px;
            font-weight: 700;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 15px;
            text-align: center;
            color: #667eea;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .user-info i {
            font-size: 18px;
        }
        
        .email-info {
            background: #e8f0fe;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            color: #4a5568;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            word-break: break-all;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #1a1a2e;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            transition: all 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        
        .form-group input::placeholder {
            font-size: 16px;
            letter-spacing: normal;
            color: #cbd5e0;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102,126,234,0.3);
        }
        
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .resend-link, .back-link {
            text-align: center;
            margin-top: 15px;
        }
        
        .resend-link a, .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .resend-link a:hover, .back-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        .back-link a {
            color: #a0aec0;
        }
        
        .error {
            background: #fff5f5;
            color: #e53e3e;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #e53e3e;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success {
            background: #f0fff4;
            color: #38a169;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #38a169;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .step-info {
            text-align: center;
            margin-top: 25px;
            font-size: 12px;
            color: #a0aec0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .timer {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #ff4757;
            font-weight: 600;
            background: #fff0f0;
            padding: 8px;
            border-radius: 20px;
            display: inline-block;
            width: auto;
            margin-left: auto;
            margin-right: auto;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #cbd5e0;
            font-size: 12px;
        }
        
        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        
        .divider span {
            padding: 0 10px;
        }
        
        .instruction-text {
            text-align: center;
            font-size: 13px;
            color: #718096;
            margin-top: 20px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }
        
        @media (max-width: 480px) {
            .verify-container {
                padding: 25px;
            }
            .form-group input {
                font-size: 18px;
                letter-spacing: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="icon">
            ✉️
        </div>
        <h2>Verify Your Identity</h2>
        <div class="subtitle">Step 2 of 2: Enter the verification code</div>
        
        <div class="user-info">
            <i class="fas fa-user-shield"></i> 
            <?php echo htmlspecialchars($_SESSION['username']); ?>
        </div>
        
        <div class="email-info">
            <i class="fas fa-envelope"></i> 
            Code sent to: <?php echo hideEmail($_SESSION['user_email'] ?? 'No email configured'); ?>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <span>⚠️</span> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <span>✓</span> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($email_sent): ?>
            <div class="email-sent-box">
                <i class="fas fa-paper-plane"></i>
                <div><strong>📨 Email Sent!</strong></div>
                <div style="font-size: 12px; margin-top: 5px;">Check your inbox and spam folder for the 6-digit verification code</div>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="otpForm">
            <div class="form-group">
                <label>Enter 6-digit verification code</label>
                <input type="text" name="otp" id="otpInput" maxlength="6" placeholder="000000" required autofocus>
            </div>
            <button type="submit" id="verifyBtn">
                <i class="fas fa-check-circle"></i> Verify & Access Dashboard
            </button>
        </form>
        
        <div class="resend-link">
            <a href="?resend=1" id="resendLink">
                <i class="fas fa-redo-alt"></i> Didn't receive code? Click to resend
            </a>
        </div>
        
        <div class="divider">
            <span></span>
        </div>
        
        <div class="back-link">
            <a href="?reset=1">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
        
        <div class="step-info">
            <i class="fas fa-clock"></i> Code expires in <span id="timer" style="font-weight: 700; color: #ff4757;">5:00</span>
        </div>
        
        <div class="instruction-text">
            <i class="fas fa-info-circle"></i> A 6-digit verification code has been sent to your registered email address.
            Please check your inbox (and spam folder) and enter the code above.
        </div>
    </div>
    
    <script>
        let timeLeft = 300;
        let timerInterval;
        const timerElement = document.getElementById('timer');
        const resendLink = document.getElementById('resendLink');
        const verifyBtn = document.getElementById('verifyBtn');
        const otpInput = document.getElementById('otpInput');
        
        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            timerElement.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft <= 0) {
                timerElement.textContent = 'Expired';
                timerElement.style.color = '#e53e3e';
                clearInterval(timerInterval);
            }
            timeLeft--;
        }
        
        // Start timer only if OTP exists and not expired
        <?php if (isset($_SESSION['otp_time']) && (time() - $_SESSION['otp_time'] <= 300)): ?>
        timerInterval = setInterval(updateTimer, 1000);
        <?php endif; ?>
        
        // Focus on input
        otpInput.focus();
        
        // Auto submit when 6 digits entered
        otpInput.addEventListener('input', function(e) {
            const value = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = value;
            
            if (value.length === 6) {
                verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
                verifyBtn.disabled = true;
                document.getElementById('otpForm').submit();
            }
        });
        
        // Allow only numbers
        otpInput.addEventListener('keypress', function(e) {
            if (e.key < '0' || e.key > '9') {
                e.preventDefault();
            }
        });
        
        // Resend click handler
        resendLink.addEventListener('click', function(e) {
            resendLink.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            resendLink.style.pointerEvents = 'none';
        });
    </script>
</body>
</html>