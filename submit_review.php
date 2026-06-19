<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Enforce login and role 'user'
require_role('user');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = trim($_POST['comment'] ?? '');
    
    $user = get_logged_in_user();

    if ($event_id <= 0 || $rating < 1 || $rating > 5 || empty($comment)) {
        set_flash_message('error', 'All fields are required. Rating must be 1 to 5 stars.');
        header("Location: /event.php?id=" . $event_id);
        exit;
    }

    try {
        // Double check booking exists and user has not reviewed already
        $check_book = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND event_id = ? AND status = 'confirmed'");
        $check_book->execute([$user['id'], $event_id]);
        
        $check_rev = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id = ? AND event_id = ?");
        $check_rev->execute([$user['id'], $event_id]);

        if ($check_book->fetchColumn() == 0) {
            set_flash_message('error', 'You must attend this event before leaving a review.');
        } elseif ($check_rev->fetchColumn() > 0) {
            set_flash_message('error', 'You have already reviewed this event.');
        } else {
            // Write review
            $ins_rev = $pdo->prepare("INSERT INTO reviews (user_id, event_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
            $ins_rev->execute([$user['id'], $event_id, $rating, $comment]);
            set_flash_message('success', 'Thank you for your feedback! Your review has been published.');
        }
    } catch (PDOException $e) {
        set_flash_message('error', 'Failed to submit review: ' . $e->getMessage());
    }

    header("Location: /event.php?id=" . $event_id);
    exit;
} else {
    header('Location: /discover.php');
    exit;
}
?>
