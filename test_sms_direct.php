<?php
// test_sms_direct.php - Direct SMS test
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test SMS - Direct</title>
    <style>
        body {
            font-family: monospace;
            background: #1e3c72;
            color: white;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: rgba(0,0,0,0.5);
            padding: 20px;
            border-radius: 10px;
        }
        input, button {
            padding: 10px;
            margin: 5px;
            border-radius: 5px;
            border: none;
        }
        button {
            background: #28a745;
            color: white;
            cursor: pointer;
        }
        .result {
            margin-top: 20px;
            padding: 10px;
            background: rgba(0,0,0,0.3);
            border-radius: 5px;
            white-space: pre-wrap;
        }
        .success { color: #28a745; }
        .error { color: #ff4757; }
    </style>
</head>
<body>
    <div class="container">
        <h2>📱 Direct SMS Test</h2>
        
        <div>
            <input type="text" id="apiKey" placeholder="API Key" style="width: 100%;">
            <input type="text" id="username" placeholder="Username (sandbox)" value="sandbox" style="width: 100%; margin-top: 10px;">
            <input type="text" id="phone" placeholder="Phone Number (+251...)" style="width: 100%; margin-top: 10px;">
            <input type="text" id="message" placeholder="Test message" value="Test SMS from Attack Detection System" style="width: 100%; margin-top: 10px;">
            <button onclick="sendSMS()">Send Test SMS</button>
        </div>
        
        <div id="result" class="result"></div>
        
        <hr>
        
        <h3>Instructions:</h3>
        <ol>
            <li>Go to <a href="https://account.africastalking.com" target="_blank">Africa's Talking Dashboard</a></li>
            <li>Go to SMS → Sandbox → Add Phone Number</li>
            <li>Verify your phone number with OTP</li>
            <li>Copy your API Key from Dashboard → Settings → API Key</li>
            <li>Paste API Key above and click Send</li>
        </ol>
    </div>
    
    <script>
        async function sendSMS() {
            const apiKey = document.getElementById('apiKey').value;
            const username = document.getElementById('username').value;
            const to = document.getElementById('phone').value;
            const message = document.getElementById('message').value;
            
            if (!apiKey || !to) {
                alert('Please enter API Key and Phone Number');
                return;
            }
            
            document.getElementById('result').innerHTML = 'Sending...';
            
            try {
                const response = await fetch('send_sms_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        api_key: apiKey,
                        username: username,
                        to: to,
                        message: message
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('result').innerHTML = `<div class="success">✅ SMS Sent Successfully! Check your phone.</div>`;
                } else {
                    document.getElementById('result').innerHTML = `<div class="error">❌ Failed: ${data.error}</div>`;
                }
            } catch(e) {
                document.getElementById('result').innerHTML = `<div class="error">Error: ${e.message}</div>`;
            }
        }
    </script>
</body>
</html>