<?php
$page_title = "My Dashboard";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';

// Enforce login and user role
require_role('user');

$user = get_logged_in_user();
$error = '';
$success = '';

// 1. Process Profile Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');

    if (empty($name)) {
        $error = "Name cannot be empty.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, telegram_chat_id = ? WHERE id = ?");
            $stmt->execute([$name, !empty($telegram_chat_id) ? $telegram_chat_id : null, $user['id']]);
            
            // Refresh session details
            $_SESSION['user_name'] = $name;
            $user['name'] = $name;
            
            $success = "Profile updated successfully.";
        } catch (PDOException $e) {
            $error = "Failed to update profile: " . $e->getMessage();
        }
    }
}

// 2. Process Cancellation / Refund Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($booking_id <= 0 || empty($reason)) {
        $error = "A cancellation reason is required.";
    } else {
        try {
            // Lock booking for update and verify it belongs to user, is confirmed, and the event is in the future
            $stmt = $pdo->prepare("SELECT b.*, e.title as event_title, e.date_time, e.organizer_id 
                                   FROM bookings b
                                   JOIN events e ON b.event_id = e.id
                                   WHERE b.id = ? AND b.user_id = ? AND b.status = 'confirmed' AND e.date_time > NOW() FOR UPDATE");
            $stmt->execute([$booking_id, $user['id']]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $error = "Invalid booking or event has already started/passed. Cancellations are only allowed for upcoming events.";
            } else {
                $pdo->beginTransaction();
                
                // Update booking status
                $up_book = $pdo->prepare("UPDATE bookings SET status = 'refund_requested' WHERE id = ?");
                $up_book->execute([$booking_id]);

                // Create refund request
                $ins_refund = $pdo->prepare("INSERT INTO refund_requests (booking_id, organizer_id, reason, status, amount, created_at) VALUES (?, ?, ?, 'requested', ?, NOW())");
                $ins_refund->execute([$booking_id, $booking['organizer_id'], $reason, $booking['total_price']]);

                // Increment remaining tickets/seats back since it was cancelled
                $inc_tt = $pdo->prepare("UPDATE tickets_types SET remaining_seats = remaining_seats + ? WHERE id = ?");
                $inc_tt->execute([$booking['quantity'], $booking['ticket_type_id']]);

                $inc_ev = $pdo->prepare("UPDATE events SET remaining_seats = remaining_seats + ? WHERE id = ?");
                $inc_ev->execute([$booking['quantity'], $booking['event_id']]);

                $pdo->commit();
                $success = "Cancellation request for '{$booking['event_title']}' submitted successfully. Refund is being processed.";
                
                // Dispatch notification
                send_notification($pdo, $user['id'], "Refund Requested", "You requested a cancellation for '{$booking['event_title']}'. Your refund request for \${$booking['total_price']} has been forwarded to administration.", 'system');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to submit cancellation: " . $e->getMessage();
        }
    }
}

// 3. Fetch Bookings details for current user
try {
    $stmt = $pdo->prepare("SELECT b.*, e.title as event_title, e.date_time, e.venue, e.city, e.banner_image,
                               tt.name as ticket_name, org.name as organizer_name
                           FROM bookings b
                           JOIN events e ON b.event_id = e.id
                           JOIN tickets_types tt ON b.ticket_type_id = tt.id
                           LEFT JOIN users org ON e.organizer_id = org.id
                           WHERE b.user_id = ?
                           ORDER BY b.created_at DESC");
    $stmt->execute([$user['id']]);
    $bookings = $stmt->fetchAll();

    // Fetch user's current telegram ID from db directly
    $tele_stmt = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
    $tele_stmt->execute([$user['id']]);
    $telegram_chat_id_db = $tele_stmt->fetchColumn();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4 mb-5">
    <!-- Profile Card (Left Column) -->
    <div class="col-lg-4">
        <div class="glass-panel p-4">
            <h4 class="fw-bold text-white mb-4"><i class="bi bi-person-gear text-indigo me-2"></i>Profile Settings</h4>
            
            <?php if ($error && isset($_POST['update_profile'])): ?>
                <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success && isset($_POST['update_profile'])): ?>
                <div class="alert alert-success border-0 text-white" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="mb-3">
                    <label class="form-label-glass">Full Name</label>
                    <input type="text" name="name" class="form-control form-control-glass" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label-glass">Email Address</label>
                    <input type="email" class="form-control form-control-glass bg-dark" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="opacity: 0.65;">
                    <div class="form-text text-muted" style="font-size: 0.75rem;">To change your email address, please contact support.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label-glass">Telegram Chat ID</label>
                    <input type="text" name="telegram_chat_id" class="form-control form-control-glass" value="<?php echo htmlspecialchars($telegram_chat_id_db ?? ''); ?>" placeholder="e.g. 987654321">
                    <div class="form-text text-muted" style="font-size: 0.75rem;">Link your chat ID to receive ticket alerts on Telegram.</div>
                </div>
                
                <button type="submit" class="btn btn-primary-gradient w-100 py-2">Save Profile Settings</button>
            </form>
        </div>
    </div>

    <!-- Bookings list (Right Column) -->
    <div class="col-lg-8">
        <div class="glass-panel p-4 h-100">
            <h4 class="fw-bold text-white mb-4"><i class="bi bi-ticket-detailed text-indigo me-2"></i>My Bookings & Tickets</h4>
            
            <?php if ($error && isset($_POST['cancel_booking'])): ?>
                <div class="alert alert-danger border-0 text-white mb-4" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success && isset($_POST['cancel_booking'])): ?>
                <div class="alert alert-success border-0 text-white mb-4" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-ticket-perforated fs-1 text-secondary"></i>
                    <h5 class="mt-3 text-white">No Tickets Booked</h5>
                    <p class="text-secondary small mb-4">You haven't booked any events yet. Check out the discover page!</p>
                    <a href="discover.php" class="btn btn-primary-gradient px-4">Browse Upcoming Events</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                <th>Booking ID</th>
                                <th>Event Details</th>
                                <th>Ticket Tier</th>
                                <th>Total Cost</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): 
                                $is_upcoming = (strtotime($b['date_time']) > time());
                                $thumb = !empty($b['banner_image']) ? htmlspecialchars($b['banner_image']) : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=200';
                            ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td class="font-monospace" style="color:var(--text-primary);">#<?php echo str_pad($b['id'],4,'0',STR_PAD_LEFT); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="<?php echo $thumb; ?>" alt="" style="width:48px;height:38px;object-fit:cover;border-radius:8px;flex-shrink:0;">
                                            <div>
                                                <div class="fw-bold" style="color:var(--text-primary);"><?php echo htmlspecialchars($b['event_title']); ?></div>
                                                <div class="small" style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($b['date_time'])) . ' · ' . htmlspecialchars($b['city']); ?></div>
                                                <?php if (!empty($b['organizer_name'])): ?>
                                                    <div class="small" style="color:var(--text-muted);"><i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($b['organizer_name']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-custom badge-indigo"><?php echo $b['ticket_name']; ?> &times; <?php echo $b['quantity']; ?></span>
                                    </td>
                                    <td class="font-monospace text-white">₹<?php echo number_format($b['total_price'], 0); ?></td>
                                    <td>
                                        <?php if ($b['payment_status'] === 'rejected'): ?>
                                            <span class="badge bg-danger">Payment Rejected</span>
                                        <?php elseif ($b['status'] === 'pending'): ?>
                                            <span class="badge bg-warning text-dark">Pending Verification</span>
                                        <?php elseif ($b['status'] === 'confirmed'): ?>
                                            <span class="badge bg-success">Confirmed</span>
                                        <?php elseif ($b['status'] === 'cancelled'): ?>
                                            <span class="badge bg-danger">Cancelled</span>
                                        <?php elseif ($b['status'] === 'refund_requested'): ?>
                                            <span class="badge bg-warning text-dark">Refund Requested</span>
                                        <?php elseif ($b['status'] === 'refunded'): ?>
                                            <span class="badge bg-info text-dark">Refunded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <?php if ($b['status'] === 'confirmed'): ?>
                                                <a href="ticket.php?id=<?php echo $b['id']; ?>" class="btn btn-glass btn-sm" title="View Ticket Details">
                                                    <i class="bi bi-eye"></i> Ticket
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-glass btn-sm text-secondary" disabled title="Ticket locked until payment is verified">
                                                    <i class="bi bi-lock-fill"></i> Locked
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($b['status'] === 'confirmed' && $is_upcoming): ?>
                                                <button onclick="openCancelModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['event_title'])); ?>')" class="btn btn-outline-danger btn-sm" title="Cancel Booking">
                                                    Cancel
                                                </button>
                                            <?php elseif ($b['status'] === 'confirmed' && !$is_upcoming): ?>
                                                <a href="event.php?id=<?php echo $b['event_id']; ?>#reviews" class="btn btn-outline-warning btn-sm" title="Review Event">
                                                    Review
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cancellation Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="cancelModalLabel">Cancel Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="cancel_booking" value="1">
                <input type="hidden" name="booking_id" id="modal-booking-id" value="">
                
                <div class="modal-body">
                    <p class="text-secondary small">You are requesting to cancel your ticket for <strong id="modal-event-title" class="text-white"></strong>. This action will release your tickets and submit a refund request to administration.</p>
                    
                    <div class="mb-3">
                        <label for="cancel-reason" class="form-label-glass">Reason for Cancellation</label>
                        <textarea name="reason" id="cancel-reason" class="form-control form-control-glass" rows="3" placeholder="Please explain why you are cancelling..." required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger btn-sm px-3">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCancelModal(bookingId, eventTitle) {
    document.getElementById('modal-booking-id').value = bookingId;
    document.getElementById('modal-event-title').innerText = eventTitle;
    
    // Open Bootstrap Modal
    var myModal = new bootstrap.Modal(document.getElementById('cancelModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
