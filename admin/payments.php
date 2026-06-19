<?php
$page_title = "Manage Payments";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

// Enforce login and admin role
require_role('admin');

$admin = get_logged_in_user();
$error = '';
$success = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $proof_id = (int)($_POST['proof_id'] ?? 0);
    $action = $_POST['action']; // 'approve' or 'reject'
    $rejection_reason = trim($_POST['reason'] ?? '');

    try {
        // Fetch the payment proof details
        $stmt = $pdo->prepare("SELECT p.*, b.quantity, b.ticket_type_id, b.event_id, e.title as event_title, u.id as attendee_id, u.name as attendee_name, u.telegram_chat_id
                               FROM payment_proofs p
                               JOIN bookings b ON p.booking_id = b.id
                               JOIN events e ON b.event_id = e.id
                               JOIN users u ON p.user_id = u.id
                               WHERE p.id = ? FOR UPDATE");
        $stmt->execute([$proof_id]);
        $proof = $stmt->fetch();

        if (!$proof) {
            $error = "Payment proof record not found.";
        } elseif ($proof['payment_status'] !== 'pending') {
            $error = "This payment has already been verified.";
        } else {
            $pdo->beginTransaction();

            if ($action === 'approve') {
                // Update proof status
                $up_proof = $pdo->prepare("UPDATE payment_proofs SET payment_status = 'approved', verified_by = ? WHERE id = ?");
                $up_proof->execute([$admin['id'], $proof_id]);

                // Update booking status to confirmed and paid
                $up_book = $pdo->prepare("UPDATE bookings SET status = 'confirmed', payment_status = 'paid' WHERE id = ?");
                $up_book->execute([$proof['booking_id']]);

                $pdo->commit();
                $success = "Payment proof #{$proof_id} approved successfully by Admin!";

                // Notify attendee
                $notif_msg = "Your payment of ₹" . (int)$proof['amount'] . " for event '{$proof['event_title']}' has been verified by the administrator. Your ticket is now ready! You can view and download it from your dashboard.";
                send_notification($pdo, $proof['attendee_id'], "Payment Verified & Ticket Generated", $notif_msg, 'system');
                
                // Telegram Notification if enabled
                if (!empty($proof['telegram_chat_id'])) {
                    send_notification($pdo, $proof['attendee_id'], "Payment Verified & Ticket Generated", $notif_msg, 'telegram');
                }
            } elseif ($action === 'reject') {
                // Update proof status
                $up_proof = $pdo->prepare("UPDATE payment_proofs SET payment_status = 'rejected', verified_by = ? WHERE id = ?");
                $up_proof->execute([$admin['id'], $proof_id]);

                // Update booking status to cancelled and payment to rejected
                $up_book = $pdo->prepare("UPDATE bookings SET status = 'cancelled', payment_status = 'rejected' WHERE id = ?");
                $up_book->execute([$proof['booking_id']]);

                // Revert seats reservation
                $up_tt = $pdo->prepare("UPDATE tickets_types SET remaining_seats = remaining_seats + ? WHERE id = ?");
                $up_tt->execute([$proof['quantity'], $proof['ticket_type_id']]);

                $up_ev = $pdo->prepare("UPDATE events SET remaining_seats = remaining_seats + ? WHERE id = ?");
                $up_ev->execute([$proof['quantity'], $proof['event_id']]);

                $pdo->commit();
                $success = "Payment proof #{$proof_id} rejected by Admin. Seats have been restored.";

                // Notify attendee
                $reason_suffix = !empty($rejection_reason) ? " Reason: " . $rejection_reason : "";
                $notif_msg = "Your payment proof for event '{$proof['event_title']}' was rejected by the administrator because the transaction could not be verified (UTR: {$proof['utr_number']}).{$reason_suffix} Please contact support.";
                send_notification($pdo, $proof['attendee_id'], "Payment Proof Rejected", $notif_msg, 'system');
                
                // Telegram Notification if enabled
                if (!empty($proof['telegram_chat_id'])) {
                    send_notification($pdo, $proof['attendee_id'], "Payment Proof Rejected", $notif_msg, 'telegram');
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Failed to process payment verification: " . $e->getMessage();
    }
}

// Fetch all payment proofs in the system
try {
    $stmt = $pdo->prepare("SELECT p.*, b.status as booking_status, b.total_price, b.quantity, u.name as attendee_name, u.email as attendee_email, e.title as event_title, org.name as organizer_name
                           FROM payment_proofs p
                           JOIN bookings b ON p.booking_id = b.id
                           JOIN users u ON p.user_id = u.id
                           JOIN events e ON b.event_id = e.id
                           JOIN users org ON e.organizer_id = org.id
                           ORDER BY p.created_at DESC");
    $stmt->execute();
    $payments = $stmt->fetchAll();
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
                <i class="bi bi-shield-check fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2"><?php echo htmlspecialchars($admin['name']); ?></h5>
                <span class="badge badge-custom badge-indigo">System Admin</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-shield-lock"></i> Controls & Settings</a>
                <a href="refunds.php" class="sidebar-link"><i class="bi bi-currency-rupee"></i> Manage Refunds</a>
                <a href="payments.php" class="sidebar-link active"><i class="bi bi-credit-card"></i> Manage Payments</a>
                <a href="notifications.php" class="sidebar-link"><i class="bi bi-list-columns"></i> Notification Logs</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <div class="glass-panel p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="fw-bold text-white mb-1">Manage Payments</h4>
                    <p class="text-secondary mb-0 small">Overview of all system-wide payment proofs and verification transactions</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 text-white mb-4" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success border-0 text-white mb-4" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($payments)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-credit-card-2-back fs-1 text-secondary"></i>
                    <h5 class="mt-3 text-white">No Payments Submitted Yet</h5>
                    <p class="text-secondary small">Transaction receipts submitted by attendees will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                <th>Event & Organizer</th>
                                <th>Attendee</th>
                                <th>UTR Number</th>
                                <th>Amount</th>
                                <th>Screenshot</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td>
                                        <div class="fw-bold text-white"><?php echo htmlspecialchars($p['event_title']); ?></div>
                                        <div class="text-secondary small">Organizer: <?php echo htmlspecialchars($p['organizer_name']); ?></div>
                                        <div class="text-muted small">Date: <?php echo date('M d, h:i A', strtotime($p['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-white"><?php echo htmlspecialchars($p['attendee_name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($p['attendee_email']); ?></div>
                                    </td>
                                    <td class="font-monospace text-warning"><?php echo htmlspecialchars($p['utr_number']); ?></td>
                                    <td class="font-monospace text-white">₹<?php echo number_format($p['amount'], 0); ?></td>
                                    <td>
                                        <button onclick="viewScreenshot('<?php echo htmlspecialchars($p['screenshot']); ?>')" class="btn btn-sm btn-glass text-info py-1 px-2">
                                            <i class="bi bi-image me-1"></i> View Proof
                                        </button>
                                    </td>
                                    <td>
                                        <?php if ($p['payment_status'] === 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php elseif ($p['payment_status'] === 'rejected'): ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($p['payment_status'] === 'pending'): ?>
                                            <div class="d-flex justify-content-end gap-2">
                                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve this payment and generate ticket?');">
                                                    <input type="hidden" name="proof_id" value="<?php echo $p['id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                                </form>
                                                <button onclick="openRejectModal(<?php echo $p['id']; ?>)" class="btn btn-outline-danger btn-sm">Reject</button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-secondary small">Verified</span>
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

<!-- Screenshot Viewer Modal -->
<div class="modal fade" id="screenshotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold">Payment Receipt Proof</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <img id="modal-screenshot-img" src="" class="img-fluid rounded border border-secondary" style="max-height: 500px;" alt="Receipt Image">
            </div>
            <div class="modal-footer border-secondary">
                <a id="modal-download-link" href="" target="_blank" class="btn btn-primary-gradient btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab</a>
                <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="rejectModalLabel">Reject Payment Proof</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="proof_id" id="modal-reject-proof-id" value="">
                
                <div class="modal-body">
                    <p class="text-secondary small">Are you sure you want to reject this payment proof? This will cancel the booking request and release the reserved seats back to the ticket tier.</p>
                    
                    <div class="mb-3">
                        <label for="reject-reason" class="form-label-glass">Reason for Rejection (Optional)</label>
                        <textarea name="reason" id="reject-reason" class="form-control form-control-glass" rows="3" placeholder="e.g. UTR number not found, incorrect payment amount, etc..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger btn-sm px-3">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function viewScreenshot(src) {
    document.getElementById('modal-screenshot-img').src = src;
    document.getElementById('modal-download-link').href = src;
    var myModal = new bootstrap.Modal(document.getElementById('screenshotModal'));
    myModal.show();
}

function openRejectModal(proofId) {
    document.getElementById('modal-reject-proof-id').value = proofId;
    var myModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    myModal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
