<?php
// send_otp.php - Email helper function with PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

function sendOTPEmail($to, $username, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Set to DEBUG_SERVER for testing
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        // ========== YOUR GMAIL CREDENTIALS ==========
        $mail->Username   = 'abebeabrham31@gmail.com';  // Your Gmail
        // IMPORTANT: REPLACE WITH YOUR ACTUAL APP PASSWORD (16 characters)
        $mail->Password   = 'goyk tgok utji zdsk';  // ← CHANGE THIS!
        // ============================================
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        
        // Additional settings for better compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom($mail->Username, 'Attack Detection System');
        $mail->addAddress($to, $username);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = '🔐 Your Verification Code - Attack Detection System';
        $mail->Body    = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; background: #f4f7fc; padding: 20px; }
                .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; color: white; }
                .content { padding: 30px; text-align: center; }
                .code { font-size: 48px; font-weight: bold; color: #ff4757; letter-spacing: 10px; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 12px; font-family: monospace; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 8px; margin-top: 20px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🛡️ Attack Detection System</h2>
                    <p>Multi-Factor Authentication</p>
                </div>
                <div class='content'>
                    <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                    <p>Your verification code is:</p>
                    <div class='code'>" . $otp . "</div>
                    <p>This code will expire in <strong>5 minutes</strong>.</p>
                    <div class='warning'>
                        ⚠️ If you didn't request this, please ignore this email.
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from Attack Detection System</p>
                    <p>© 2025 All rights reserved</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Hello $username,\n\nYour verification code is: $otp\n\nThis code will expire in 5 minutes.\n\nIf you didn't request this, please ignore this email.\n\n- Attack Detection System";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}
?>