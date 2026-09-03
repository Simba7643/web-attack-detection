<?php
// disable_webhook.php - Turn off webhook so we can use polling
$bot_token = '8773269762:AAGePzp2b0ZMRxW2VqykohFJmjP9wYhJ0z4';  // ← PASTE YOUR TOKEN

$url = "https://api.telegram.org/bot{$bot_token}/deleteWebhook";
$response = file_get_contents($url);
$result = json_decode($response, true);

if ($result['ok']) {
    echo "✅ Webhook disabled! You can now use polling mode.";
} else {
    echo "❌ Error: " . $result['description'];
}
?>