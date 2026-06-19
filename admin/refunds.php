<?php
$page_title = "Manage Refund Requests";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

// Enforce login and admin role
require_role('admin');

$admin = get_logged_in_user();

// 1. Process Refund Approvals / Rejections
if (isset($_GET['action']) && isset($_GET['id'])) {
    $refund_id = (int)$_GET['id'];
    $action = $_GET['action'];

    try {
        $stmt = $pdo->prepare("SELECT r.*, b.user_id, b.event_id, e.title as event_title 
                               FROM refund_requests r 
                               JOIN bookings b ON r.booking_id = b.id
                               JOIN events e ON b.event_id = e.id
                               WHERE r.id = ? FOR UPDATE");
        $stmt->execute([$refund_id]);
        $refund = $stmt->fetch();

        if ($refund && $refund['status'] === 'requested') {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                // Set refund request status to refunded
                $up_refund = $pdo->prepare("UPDATE refund_requests SET status = 'refunded' WHERE id = ?");
                $up_refund->execute([$refund_id]);

                // Set booking status to refunded and payment_status to refunded
                $up_booking = $pdo->prepare("UPDATE bookings SET status = 'refunded', payment_status = 'refunded' WHERE id = ?");
                $up_booking->execute([$refund['booking_id']]);

                $pdo->commit();
                set_flash_message('success', "Refund approved and issued successfully for Booking #{$refund['booking_id']}.");

                // Dispatch notification
                notify_refund_approved($pdo, $refund['user_id'], $refund['event_title'], $refund['amount'], $refund['booking_id']);

            } elseif ($action === 'reject') {
                // Set refund request status to rejected
                $up_refund = $pdo->prepare("UPDATE refund_requests SET status = 'rejected' WHERE id = ?");
                $up_refund->execute([$refund_id]);

                // Update booking status back to cancelled (but keep payment_status as paid, since refund was denied)
                $up_booking = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
                $up_booking->execute([$refund['booking_id']]);

                $pdo->commit();
                set_flash_message('success', "Refund request #{$refund_id} rejected. Ticket remains cancelled without payout.");

                // Notify attendee
                send_notification($pdo, $refund['user_id'], "Refund Request Rejected", "Your refund request for the event '{$refund['event_title']}' has been reviewed and rejected in accordance with our terms of service.", 'email');
            }
        } else {
            set_flash_message('error', "Refund request not found or already processed.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash_message('error', "Transaction failed: " . $e->getMessage());
    }

    header('Location: refunds.php');
    exit;
}

// 2. Fetch all refund requests from DB
try {
    $stmt = $pdo->query("SELECT r.*, b.id as booking_id, u.name as attendee_name, u.email as attendee_email, e.title as event_title 
                         FROM refund_requests r 
                         JOIN bookings b ON r.booking_id = b.id
                         JOIN users u ON b.user_id = u.id
                         JOIN events e ON b.event_id = e.id
                         ORDER BY r.created_at DESC");
    $refunds = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Sidebar Navigation -->
    <div class="col-md-3 mb-4 no-print">
        <div class="glass-panel p-3">
            <div class="text-center py-3 mb-3 border-bottom border-secondary">
                <i class="bi bi-currency-rupee fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2">Admin Portal</h5>
                <span class="badge badge-custom badge-indigo">System Administrator</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-shield-lock"></i> Controls & Settings</a>
                <a href="refunds.php" class="sidebar-link active"><i class="bi bi-currency-rupee"></i> Manage Refunds</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Manage Payments</a>
                <a href="notifications.php" class="sidebar-link"><i class="bi bi-list-columns"></i> Notification Logs</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <div class="glass-panel p-4">
            <h4 class="fw-bold text-white mb-2"><i class="bi bi-credit-card-2-back text-indigo me-2"></i>Refund Claims Processing</h4>
            <p class="text-secondary small mb-4">Review, approve, or reject user-submitted ticket cancellation refund claims.</p>

            <?php if (empty($refunds)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-cash-stack fs-1 text-secondary"></i>
                    <h5 class="mt-3 text-white">No Refund Claims</h5>
                    <p class="text-secondary small mb-0">There are currently no refund requests submitted in the database.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                <th>Claim ID</th>
                                <th>Booking Ref</th>
                                <th>Attendee Details</th>
                                <th>Event Title</th>
                                <th>Refund Value</th>
                                <th>State</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($refunds as $ref): ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td class="font-monospace text-white">#<?php echo $ref['id']; ?></td>
                                    <td class="font-monospace text-muted">#<?php echo $ref['booking_id']; ?></td>
                                    <td>
                                        <div class="text-white fw-bold"><?php echo htmlspecialchars($ref['attendee_name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($ref['attendee_email']); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-white small"><?php echo htmlspecialchars($ref['event_title']); ?></div>
                                        <div class="text-secondary font-italic" style="font-size: 0.75rem; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($ref['reason']); ?>">
                                            "<?php echo htmlspecialchars($ref['reason']); ?>"
                                        </div>
                                    </td>
                                    <td class="font-monospace text-white fw-bold">₹<?php echo number_format($ref['amount'], 0); ?></td>
                                    <td>
                                        <?php if ($ref['status'] === 'requested'): ?>
                                            <span class="badge bg-warning text-dark">Requested</span>
                                        <?php elseif ($ref['status'] === 'refunded'): ?>
                                            <span class="badge bg-success">Refunded</span>
                                        <?php elseif ($ref['status'] === 'rejected'): ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($ref['status'] === 'requested'): ?>
                                            <div class="d-flex justify-content-end gap-1">
                                                <a href="refunds.php?action=approve&id=<?php echo $ref['id']; ?>" class="btn btn-success btn-sm" title="Approve & Pay Out"><i class="bi bi-check-lg"></i> Approve</a>
                                                <a href="refunds.php?action=reject&id=<?php echo $ref['id']; ?>" onclick="return confirm('Reject this refund request?')" class="btn btn-outline-danger btn-sm" title="Deny Claim"><i class="bi bi-x-lg"></i> Reject</a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">Processed</span>
                                        <?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
