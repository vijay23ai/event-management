<?php
require_once __DIR__ . '/db.php';

/**
 * Log a notification in the database and attempt delivery (Email/Telegram).
 */
function send_notification($pdo, $user_id, $title, $message, $type = 'system') {
    global $pdo; // fallback
    
    // 1. Insert into database logs
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $title, $message, $type, 'pending']);
        $notification_id = $pdo->lastInsertId();
    } catch (Exception $e) {
        // Log locally if DB query fails
        error_log("Failed to insert notification: " . $e->getMessage());
        return false;
    }

    $delivery_status = 'failed';
    $system_log_file = __DIR__ . '/../notifications.log';

    // 2. Load settings from db if they exist
    $smtp_host = '';
    $resend_api_key = '';
    $telegram_bot_token = '';
    $telegram_chat_id = '';
    
    try {
        // We'll create a simple table or query admin settings from the system config if it exists
        $stmt = $pdo->prepare("SELECT * FROM system_settings WHERE setting_key IN ('resend_api_key', 'telegram_bot_token', 'telegram_chat_id')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $resend_api_key = $settings['resend_api_key'] ?? '';
        $telegram_bot_token = $settings['telegram_bot_token'] ?? '';
        $telegram_chat_id = $settings['telegram_chat_id'] ?? '';
    } catch (Exception $e) {
        // Settings table might not exist yet during installation
    }

    // 3. Perform dispatch
    if ($type === 'telegram') {
        // If the user has a custom chat ID, prioritize it
        $target_chat_id = $telegram_chat_id;
        if ($user_id) {
            $user_stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $u_chat = $user_stmt->fetchColumn();
            if (!empty($u_chat)) {
                $target_chat_id = $u_chat;
            }
        }

        if (!empty($telegram_bot_token) && !empty($target_chat_id)) {
            $url = "https://api.telegram.org/bot" . urlencode($telegram_bot_token) . "/sendMessage";
            $post_data = [
                'chat_id' => $target_chat_id,
                'text' => "🔔 *{$title}*\n\n{$message}",
                'parse_mode' => 'Markdown'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if (!$err && $response) {
                $res_json = json_decode($response, true);
                if (isset($res_json['ok']) && $res_json['ok'] === true) {
                    $delivery_status = 'sent';
                } else {
                    $delivery_status = 'failed';
                    file_put_contents($system_log_file, "[" . date('Y-m-d H:i:s') . "] Telegram API Error: " . $response . "\n", FILE_APPEND);
                }
            } else {
                $delivery_status = 'failed';
                file_put_contents($system_log_file, "[" . date('Y-m-d H:i:s') . "] Telegram connection error: " . $err . "\n", FILE_APPEND);
            }
        } else {
            // Simulated delivery log
            $delivery_status = 'sent'; // Marked as sent (simulated)
            $sim_log = "[" . date('Y-m-d H:i:s') . "] [SIMULATED TELEGRAM] To Chat ID: {$target_chat_id} | Title: {$title} | Message: {$message}\n";
            file_put_contents($system_log_file, $sim_log, FILE_APPEND);
        }
    } else if ($type === 'email') {
        // Retrieve user's email
        $user_email = '';
        if ($user_id) {
            $user_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user_email = $user_stmt->fetchColumn();
        }

        if (!empty($resend_api_key) && !empty($user_email)) {
            // Try Resend API
            $url = "https://api.resend.com/emails";
            $post_data = json_encode([
                'from' => 'Events Portal <onboarding@resend.dev>',
                'to' => $user_email,
                'subject' => $title,
                'html' => '<div style="font-family: sans-serif; padding: 20px; color: #1e293b;">' .
                           '<h2 style="color: #6366f1;">' . htmlspecialchars($title) . '</h2>' .
                           '<p>' . nl2br(htmlspecialchars($message)) . '</p>' .
                           '</div>'
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$resend_api_key}",
                "Content-Type: application/json"
            ]);
            $response = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if (!$err && $response) {
                $res_json = json_decode($response, true);
                if (isset($res_json['id'])) {
                    $delivery_status = 'sent';
                } else {
                    $delivery_status = 'failed';
                    file_put_contents($system_log_file, "[" . date('Y-m-d H:i:s') . "] Resend API Error: " . $response . "\n", FILE_APPEND);
                }
            } else {
                $delivery_status = 'failed';
                file_put_contents($system_log_file, "[" . date('Y-m-d H:i:s') . "] Resend connection error: " . $err . "\n", FILE_APPEND);
            }
        } else {
            // Simulation
            $delivery_status = 'sent';
            $sim_log = "[" . date('Y-m-d H:i:s') . "] [SIMULATED EMAIL] To: " . ($user_email ?: 'System') . " | Subject: {$title} | Body: {$message}\n";
            file_put_contents($system_log_file, $sim_log, FILE_APPEND);
        }
    } else {
        // System logs
        $delivery_status = 'sent';
        $sim_log = "[" . date('Y-m-d H:i:s') . "] [SYSTEM NOTIFICATION] User ID: {$user_id} | Title: {$title} | Message: {$message}\n";
        file_put_contents($system_log_file, $sim_log, FILE_APPEND);
    }

    // 4. Update delivery status in DB
    try {
        $up_stmt = $pdo->prepare("UPDATE notifications SET status = ? WHERE id = ?");
        $up_stmt->execute([$delivery_status, $notification_id]);
    } catch (Exception $e) {
        error_log("Failed to update status: " . $e->getMessage());
    }

    return $delivery_status === 'sent';
}

/**
 * Convenience functions for specific notification triggers
 */

function notify_user_register($pdo, $user_id, $name) {
    $title = "Welcome to Event management Portal!";
    $msg = "Hi {$name},\nThank you for signing up on the City-Wide Event Management Portal. Discover and book amazing events in your city!";
    send_notification($pdo, $user_id, $title, $msg, 'email');
    send_notification($pdo, $user_id, $title, $msg, 'system');
}

function notify_booking_confirmed($pdo, $user_id, $event_title, $ticket_type, $qty, $total_price, $booking_id) {
    $title = "Booking Confirmed: {$event_title}";
    $msg = "Success! Your booking (ID: #{$booking_id}) is confirmed. You purchased {$qty}x {$ticket_type} tickets for a total of \${$total_price}. Access your ticket on your dashboard.";
    send_notification($pdo, $user_id, $title, $msg, 'email');
    send_notification($pdo, $user_id, $title, $msg, 'telegram');
    send_notification($pdo, $user_id, $title, $msg, 'system');
}

function notify_event_cancelled($pdo, $user_id, $event_title, $refund_status_msg = '') {
    $title = "Event Cancelled: {$event_title}";
    $msg = "Important Notice: The event '{$event_title}' has been cancelled by the organizer. {$refund_status_msg} We apologize for the inconvenience.";
    send_notification($pdo, $user_id, $title, $msg, 'email');
    send_notification($pdo, $user_id, $title, $msg, 'telegram');
    send_notification($pdo, $user_id, $title, $msg, 'system');
}

function notify_refund_approved($pdo, $user_id, $event_title, $amount, $booking_id) {
    $title = "Refund Request Approved";
    $msg = "Good news! Your refund request for Booking #{$booking_id} ('{$event_title}') has been approved. A total of \${$amount} has been processed back to your payment method.";
    send_notification($pdo, $user_id, $title, $msg, 'email');
    send_notification($pdo, $user_id, $title, $msg, 'telegram');
    send_notification($pdo, $user_id, $title, $msg, 'system');
}

function notify_event_reminder($pdo, $user_id, $event_title, $date_time, $venue) {
    $title = "Reminder: Upcoming Event Tomorrow!";
    $msg = "Friendly reminder that the event '{$event_title}' is taking place tomorrow ({$date_time}) at {$venue}. We look forward to seeing you there!";
    send_notification($pdo, $user_id, $title, $msg, 'email');
    send_notification($pdo, $user_id, $title, $msg, 'telegram');
    send_notification($pdo, $user_id, $title, $msg, 'system');
}
?>
