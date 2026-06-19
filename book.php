<?php
$page_title = "Book Tickets";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';

// Check if user is logged in as Attendee
require_role('user');

$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($event_id <= 0) {
    set_flash_message('error', 'Invalid booking request parameters.');
    header('Location: /discover.php');
    exit;
}

// Self-healing database check: add attendee_details column to bookings table if not exists
try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN attendee_details TEXT NULL");
} catch (PDOException $e) {
    // Column may already exist, ignore
}

try {
    // 1. Fetch Event details
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND status = 'approved'");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        set_flash_message('error', 'Event not found or not approved.');
        header('Location: /discover.php');
        exit;
    }

    // Fetch all ticket types/tiers for this event
    $tt_stmt = $pdo->prepare("SELECT * FROM tickets_types WHERE event_id = ? ORDER BY price ASC");
    $tt_stmt->execute([$event_id]);
    $ticket_types = $tt_stmt->fetchAll();

    if (empty($ticket_types)) {
        set_flash_message('error', 'No ticket categories defined for this event yet.');
        header("Location: /event.php?id=" . $event_id);
        exit;
    }

    $user = get_logged_in_user();

    // Fetch Organizer's UPI payment details
    $upi_stmt = $pdo->prepare("SELECT * FROM organizer_payment_details WHERE organizer_id = ?");
    $upi_stmt->execute([$event['organizer_id']]);
    $upi_details = $upi_stmt->fetch();
    if (!$upi_details) {
        $upi_details = [
            'upi_id' => 'admin@upi',
            'phone_number' => '9876543210',
            'qr_image' => '/uploads/upi/default_qr.png'
        ];
    }

    // 2. Handle Booking form submission
    $booking_success_id = 0;
    $submitted_data = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
        $ticket_type_id = (int)($_POST['ticket_type_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 1);
        $utr_number = trim($_POST['utr_number'] ?? '');
        
        // Retrieve ticket details
        $sel_tt_stmt = $pdo->prepare("SELECT * FROM tickets_types WHERE id = ? AND event_id = ?");
        $sel_tt_stmt->execute([$ticket_type_id, $event_id]);
        $ticket_type = $sel_tt_stmt->fetch();

        if (!$ticket_type) {
            $error = "Selected Ticket category not found.";
        } elseif ($ticket_type['remaining_seats'] < $quantity) {
            $error = "Not enough tickets available. Only {$ticket_type['remaining_seats']} remaining.";
        } elseif ($quantity <= 0) {
            $error = "Please select at least 1 ticket.";
        } elseif (empty($utr_number)) {
            $error = "Transaction ID / UTR Number is required.";
        } else {
            $total_price = $ticket_type['price'] * $quantity;
            
            // Gather attendee details
            $attendee_names = $_POST['attendee_name'] ?? [];
            $attendee_emails = $_POST['attendee_email'] ?? [];
            $attendee_list = [];
            for ($i = 0; $i < $quantity; $i++) {
                $attendee_list[] = [
                    'name' => trim($attendee_names[$i] ?? ''),
                    'email' => trim($attendee_emails[$i] ?? '')
                ];
            }
            $attendee_json = json_encode($attendee_list);

            // Handle Screenshot upload
            $screenshot_path = '';
            if (isset($_FILES['payment_screenshot']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['payment_screenshot']['tmp_name'];
                $file_name = time() . '_proof_' . basename($_FILES['payment_screenshot']['name']);
                $upload_dir = __DIR__ . '/uploads/proofs/';
                
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                if (move_uploaded_file($file_tmp, $upload_dir . $file_name)) {
                    $screenshot_path = '/uploads/proofs/' . $file_name;
                }
            }

            if (empty($screenshot_path)) {
                $error = "Payment screenshot upload is required.";
            } else {
                // Check if UTR is duplicate
                $utr_check = $pdo->prepare("SELECT COUNT(*) FROM payment_proofs WHERE utr_number = ?");
                $utr_check->execute([$utr_number]);
                if ($utr_check->fetchColumn() > 0) {
                    $error = "This Transaction ID / UTR Number has already been submitted.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        
                        // Re-check capacity with lock
                        $lock_stmt = $pdo->prepare("SELECT remaining_seats FROM tickets_types WHERE id = ? FOR UPDATE");
                        $lock_stmt->execute([$ticket_type_id]);
                        $curr_seats = $lock_stmt->fetchColumn();

                        if ($curr_seats < $quantity) {
                            $pdo->rollBack();
                            $error = "Sorry, those seats were booked by someone else during checkout.";
                        } else {
                            // Generate unique QR code token
                            $qr_token = 'QR_' . strtoupper(bin2hex(random_bytes(10)));

                            // Insert booking (pending verification) with attendee_details
                            $ins_book = $pdo->prepare("INSERT INTO bookings (user_id, event_id, ticket_type_id, quantity, total_price, status, payment_status, qr_code_token, attendee_details, created_at) 
                                                   VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, NOW())");
                            $ins_book->execute([$user['id'], $event_id, $ticket_type_id, $quantity, $total_price, $qr_token, $attendee_json]);
                            $booking_id = $pdo->lastInsertId();

                            // Insert payment proof details
                            $ins_proof = $pdo->prepare("INSERT INTO payment_proofs (booking_id, user_id, utr_number, amount, screenshot, payment_status, created_at) 
                                                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                            $ins_proof->execute([$booking_id, $user['id'], $utr_number, $total_price, $screenshot_path]);

                            // Decrement remaining seats in ticket types and events tables (reserve seats)
                            $dec_tt = $pdo->prepare("UPDATE tickets_types SET remaining_seats = remaining_seats - ? WHERE id = ?");
                            $dec_tt->execute([$quantity, $ticket_type_id]);

                            $dec_ev = $pdo->prepare("UPDATE events SET remaining_seats = remaining_seats - ? WHERE id = ?");
                            $dec_ev->execute([$quantity, $event_id]);

                            $pdo->commit();
                            $booking_success_id = $booking_id;
                            
                            $submitted_data = [
                                'ticket_name' => $ticket_type['name'],
                                'quantity' => $quantity,
                                'total_price' => $total_price,
                                'utr_number' => $utr_number
                            ];

                            // Dispatch Notifications to Organizer
                            send_notification($pdo, $event['organizer_id'], "Payment Proof Submitted", "Attendee '{$user['name']}' has submitted payment proof of ₹" . number_format($total_price, 0) . " for event '{$event['title']}' (UTR: {$utr_number}). Please verify it.", 'system');
                        }
                    } catch (Exception $e) {
                        if (isset($pdo) && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = "Booking failed due to a system error: " . $e->getMessage();
                    }
                }
            }
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = "Booking failed due to a system error: " . $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Progress wizard nodes */
.booking-steps {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    max-width: 600px;
    margin: 0 auto 40px auto;
}
.booking-steps::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--glass-border);
    z-index: 1;
    transform: translateY(-50%);
}
.booking-steps-progress {
    position: absolute;
    top: 50%;
    left: 0;
    height: 3px;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    z-index: 1;
    transform: translateY(-50%);
    width: 0%;
    transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}
.step-node {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: var(--bg-secondary);
    border: 2px solid var(--glass-border);
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.95rem;
    z-index: 2;
    transition: all 0.3s ease;
    cursor: default;
}
.step-node.active {
    background: #4f46e5;
    border-color: #6366f1;
    color: white;
    box-shadow: 0 0 15px rgba(99, 102, 241, 0.4);
}
.step-node.completed {
    background: #10b981;
    border-color: #059669;
    color: white;
}
.ticket-type-card {
    border: 1px solid var(--glass-border);
    background: var(--glass-card-bg);
    border-radius: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
}
.ticket-type-card:hover {
    border-color: rgba(99, 102, 241, 0.4);
    transform: translateY(-2px);
}
.ticket-type-card.selected {
    border-color: #6366f1;
    background: rgba(99, 102, 241, 0.08);
    box-shadow: 0 0 15px rgba(99, 102, 241, 0.15);
}
</style>

<div class="row justify-content-center my-4">
    <div class="col-md-9">
        <div class="glass-panel p-5">
            <!-- Step 5: Success Screen -->
            <?php if ($booking_success_id > 0): ?>
                <div class="text-center py-4">
                    <div class="animate__animated animate__zoomIn">
                        <i class="bi bi-clock-history text-warning fs-1 mb-3 d-inline-block animate__animated animate__pulse animate__infinite" style="color: #f59e0b;"></i>
                    </div>
                    <h2 class="fw-bold text-white mb-2">Booking Request Submitted!</h2>
                    <p class="text-secondary mb-4">Your payment proof for Booking #<?php echo $booking_success_id; ?> has been received and is **Pending Verification**. The event organizer has been notified and will verify your transaction shortly.</p>
                    
                    <div class="row justify-content-center mb-4">
                        <div class="col-md-8 text-start p-4 rounded-4 bg-dark" style="background: rgba(15,23,42,0.4) !important; border: 1px solid var(--glass-border);">
                            <h5 class="fw-bold text-white mb-3 border-bottom border-secondary pb-2">Order Summary</h5>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Event Name:</span>
                                <span class="text-white fw-bold"><?php echo htmlspecialchars($event['title']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Ticket Category:</span>
                                <span class="text-white"><?php echo htmlspecialchars($submitted_data['ticket_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Quantity:</span>
                                <span class="text-white"><?php echo $submitted_data['quantity']; ?> ticket(s)</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Total Amount Paid:</span>
                                <span class="text-indigo fw-bold" style="color: #38bdf8;">₹<?php echo number_format($submitted_data['total_price'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Transaction UTR:</span>
                                <span class="text-white font-monospace"><?php echo htmlspecialchars($submitted_data['utr_number']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-secondary">Status:</span>
                                <span class="badge bg-warning text-dark">Pending Verification</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex flex-wrap justify-content-center gap-3">
                        <a href="dashboard.php" class="btn btn-primary-gradient px-4 py-2">Go to Dashboard</a>
                    </div>
                </div>
            <!-- Checkout Form Wizard -->
            <?php else: ?>
                <h3 class="fw-bold text-white mb-4 text-center">Complete Your Booking</h3>
                
                <!-- Step Nodes Bar -->
                <div class="booking-steps mb-5">
                    <div class="booking-steps-progress" id="steps-progress"></div>
                    <div class="step-node active" id="step-node-1" title="Select Tickets">1</div>
                    <div class="step-node" id="step-node-2" title="Attendee Information">2</div>
                    <div class="step-node" id="step-node-3" title="UPI Payment">3</div>
                    <div class="step-node" id="step-node-4" title="Confirm Payment">4</div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger border-0 text-white mb-4" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="checkout-wizard-form" enctype="multipart/form-data" onsubmit="return validateFinalStep()">
                    <input type="hidden" name="process_payment" value="1">
                    <input type="hidden" name="ticket_type_id" id="selected-ticket-type-id" value="<?php echo isset($_GET['ticket_type_id']) ? (int)$_GET['ticket_type_id'] : $ticket_types[0]['id']; ?>">

                    <!-- Step 1 Panel: Select Ticket Tier & Quantity -->
                    <div id="step-panel-1" class="step-panel">
                        <h5 class="fw-bold text-white mb-4"><i class="bi bi-ticket-perforated-fill text-indigo me-2"></i> Step 1: Select Ticket Category & Quantity</h5>
                        
                        <div class="row g-3 mb-4">
                            <?php foreach ($ticket_types as $tt): 
                                $is_selected = (isset($_GET['ticket_type_id']) && (int)$_GET['ticket_type_id'] === $tt['id']) || (!isset($_GET['ticket_type_id']) && $ticket_types[0]['id'] === $tt['id']);
                                $sold_out = ($tt['remaining_seats'] <= 0);
                            ?>
                                <div class="col-md-6">
                                    <div class="ticket-type-card p-4 h-100 d-flex flex-column justify-content-between <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $sold_out ? 'opacity-50' : ''; ?>" 
                                         onclick="selectTicketTier(<?php echo $tt['id']; ?>, <?php echo $tt['price']; ?>, <?php echo $tt['remaining_seats']; ?>, <?php echo $sold_out ? 'true' : 'false'; ?>)"
                                         id="tt-card-<?php echo $tt['id']; ?>">
                                        <div>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($tt['name']); ?></h5>
                                                <span class="fs-5 fw-bold text-indigo" style="color: #6366f1;">₹<?php echo number_format($tt['price'], 0); ?></span>
                                            </div>
                                            <p class="text-secondary small mb-3"><?php echo htmlspecialchars($tt['description']); ?></p>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-secondary">
                                            <?php if ($sold_out): ?>
                                                <span class="badge bg-danger">Sold Out</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success"><?php echo $tt['remaining_seats']; ?> seats left</span>
                                            <?php endif; ?>
                                            <span class="text-indigo select-indicator <?php echo $is_selected ? 'd-block' : 'd-none'; ?>" id="tt-indicator-<?php echo $tt['id']; ?>"><i class="bi bi-check-circle-fill fs-5"></i></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="quantity-selector" class="form-label-glass">Select Quantity</label>
                                <select name="quantity" id="quantity-selector" class="form-select form-select-glass" onchange="updateCalculatedTotals()">
                                    <!-- Options populated dynamically by JS based on selection -->
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="p-3 w-100 rounded-3 bg-dark" style="background: rgba(15,23,42,0.4) !important; border: 1px solid var(--glass-border);">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-secondary">Estimated Price:</span>
                                        <span class="text-white fw-bold fs-5" id="step-1-estimated-price">₹0</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="button" class="btn btn-primary-gradient px-5 py-2" onclick="goToStep(2)">
                                Next: Attendee Details <i class="bi bi-arrow-right-short ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2 Panel: Attendee Details -->
                    <div id="step-panel-2" class="step-panel d-none">
                        <h5 class="fw-bold text-white mb-4"><i class="bi bi-person-badge-fill text-indigo me-2"></i> Step 2: Attendee Details</h5>
                        <p class="text-secondary small mb-4">Please enter details for each attendee. Tickets will be issued in these names.</p>
                        
                        <div id="attendees-container">
                            <!-- Dynamic attendee inputs go here -->
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-glass px-4 py-2" onclick="goToStep(1)">
                                <i class="bi bi-arrow-left-short me-1"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary-gradient px-4 py-2" onclick="goToStep(3)">
                                Next: Proceed to Pay <i class="bi bi-credit-card ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3 Panel: UPI Payment Details -->
                    <div id="step-panel-3" class="step-panel d-none">
                        <h5 class="fw-bold text-white mb-4"><i class="bi bi-wallet2 text-indigo me-2"></i> Step 3: Complete UPI Payment</h5>
                        
                        <div class="p-4 mb-4 rounded-4 bg-dark" style="background: rgba(15,23,42,0.4) !important; border: 1px solid var(--glass-border);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-secondary d-block">Booking Category</span>
                                    <strong class="text-white fs-5" id="step-3-category-name">General</strong>
                                </div>
                                <div class="text-end">
                                    <span class="text-secondary d-block">Amount Payable</span>
                                    <strong class="text-indigo fs-3" style="color: #6366f1;" id="step-3-total-amount">₹0</strong>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4 mb-4">
                            <div class="col-md-5 text-center border-md-end border-secondary">
                                <h6 class="fw-bold text-white mb-3">Scan UPI QR Code</h6>
                                <div class="p-2 bg-white d-inline-block rounded-3 mb-2" style="border: 4px solid var(--glass-border);">
                                    <img src="<?php echo htmlspecialchars($upi_details['qr_image']); ?>" class="img-fluid" style="max-width: 170px;" alt="UPI QR Code">
                                </div>
                                <div class="text-secondary small mt-2">Use GooglePay, PhonePe, Paytm, BHIM, or any UPI banking app.</div>
                            </div>
                            <div class="col-md-7 text-start">
                                <h6 class="fw-bold text-white mb-3">Pay to Organizer Details</h6>
                                <div class="mb-3">
                                    <span class="text-secondary small d-block">UPI Address (VPA):</span>
                                    <div class="d-flex align-items-center">
                                        <strong class="text-white fs-5 me-2" id="vpa-address"><?php echo htmlspecialchars($upi_details['upi_id']); ?></strong>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyText('vpa-address')"><i class="bi bi-clipboard"></i> Copy</button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <span class="text-secondary small d-block">Linked Phone Number:</span>
                                    <div class="d-flex align-items-center">
                                        <strong class="text-white" id="phone-address"><?php echo htmlspecialchars($upi_details['phone_number']); ?></strong>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-2" onclick="copyText('phone-address')"><i class="bi bi-clipboard"></i> Copy</button>
                                    </div>
                                </div>
                                <?php if (!empty($event['payment_instructions'])): ?>
                                    <div class="p-3 rounded-3 bg-dark small" style="background: rgba(15,23,42,0.6) !important; border: 1px solid var(--glass-border);">
                                        <span class="text-warning fw-bold d-block mb-1">Instructions:</span>
                                        <span class="text-secondary"><?php echo nl2br(htmlspecialchars($event['payment_instructions'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input check-glass" type="checkbox" id="payment-sent-confirm">
                            <label class="form-check-label text-secondary small" for="payment-sent-confirm">
                                I have transferred the exact amount to the organizer and retrieved the 12-digit UTR/Transaction ID.
                            </label>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-glass px-4 py-2" onclick="goToStep(2)">
                                <i class="bi bi-arrow-left-short me-1"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary-gradient px-4 py-2" onclick="goToStep(4)">
                                Next: Upload Receipt <i class="bi bi-cloud-arrow-up ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 4 Panel: Submit Proof -->
                    <div id="step-panel-4" class="step-panel d-none">
                        <h5 class="fw-bold text-white mb-4"><i class="bi bi-shield-lock text-indigo me-2"></i> Step 4: Upload Receipt & Confirm</h5>
                        
                        <div class="mb-4 text-start">
                            <label for="utr_number" class="form-label-glass fw-bold">12-Digit Transaction ID / UTR Number</label>
                            <input type="text" name="utr_number" id="utr_number" class="form-control form-control-glass font-monospace text-uppercase" placeholder="E.g. 304212984572" required autocomplete="off" maxlength="22">
                            <div class="form-text text-muted" style="font-size: 0.75rem;">This is the unique reference number displayed on your UPI receipt.</div>
                        </div>

                        <div class="mb-4 text-start">
                            <label for="payment_screenshot" class="form-label-glass fw-bold">Upload Transaction Screenshot (JPG, PNG, JPEG)</label>
                            <input type="file" name="payment_screenshot" id="payment_screenshot" class="form-control form-control-glass" accept="image/jpeg, image/png, image/jpg" required>
                            <div class="form-text text-muted" style="font-size: 0.75rem;">Upload the payment success screen demonstrating organizer UPI, UTR, and amount.</div>
                        </div>

                        <div class="row mb-4 text-start">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="payment_amount" class="form-label-glass">Calculated Amount (₹)</label>
                                <input type="text" id="payment_amount" class="form-control form-control-glass text-indigo fw-bold" readonly value="₹0">
                            </div>
                            <div class="col-md-6">
                                <label for="payment_datetime" class="form-label-glass">Payment Date & Time</label>
                                <input type="datetime-local" id="payment_datetime" class="form-control form-control-glass" required value="<?php echo date('Y-m-d\TH:i'); ?>">
                            </div>
                        </div>

                        <div id="payment-error-alert" class="alert alert-danger d-none border-0 text-white text-start" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <span id="payment-error-msg"></span>
                        </div>

                        <div class="d-flex justify-content-between mt-5">
                            <button type="button" class="btn btn-glass px-4 py-2" onclick="goToStep(3)">
                                <i class="bi bi-arrow-left-short me-1"></i> Back
                            </button>
                            <button type="submit" class="btn btn-primary-gradient px-5 py-2">
                                <i class="bi bi-shield-lock me-2"></i> Submit Booking Request
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Keep ticket details in memory
const ticketTiers = {
    <?php foreach ($ticket_types as $tt): ?>
        "<?php echo $tt['id']; ?>": {
            id: <?php echo $tt['id']; ?>,
            name: <?php echo json_encode($tt['name']); ?>,
            price: <?php echo $tt['price']; ?>,
            seats: <?php echo $tt['remaining_seats']; ?>
        },
    <?php endforeach; ?>
};

let selectedTierId = <?php echo isset($_GET['ticket_type_id']) ? (int)$_GET['ticket_type_id'] : $ticket_types[0]['id']; ?>;

function selectTicketTier(id, price, seats, soldOut) {
    if (soldOut) {
        alert("This ticket category is sold out!");
        return;
    }
    
    // Reset selected styles
    document.querySelectorAll('.ticket-type-card').forEach(card => card.classList.remove('selected'));
    document.querySelectorAll('.select-indicator').forEach(ind => ind.classList.add('d-none'));
    
    // Select new tier
    selectedTierId = id;
    document.getElementById('selected-ticket-type-id').value = id;
    document.getElementById('tt-card-' + id).classList.add('selected');
    document.getElementById('tt-indicator-' + id).classList.remove('d-none');
    
    populateQuantityOptions(seats);
    updateCalculatedTotals();
}

function populateQuantityOptions(maxSeats) {
    const selector = document.getElementById('quantity-selector');
    if (!selector) return;
    
    selector.innerHTML = '';
    const limit = Math.min(10, maxSeats);
    
    for (let i = 1; i <= limit; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.innerText = i + ' ticket' + (i > 1 ? 's' : '');
        selector.appendChild(opt);
    }
    
    // Use quantity from URL if applicable and available
    const getQty = <?php echo isset($_GET['quantity']) ? (int)$_GET['quantity'] : 1; ?>;
    if (getQty <= limit) {
        selector.value = getQty;
    }
}

function updateCalculatedTotals() {
    const qtySelector = document.getElementById('quantity-selector');
    if (!qtySelector) return;
    
    const qty = parseInt(qtySelector.value) || 1;
    const tier = ticketTiers[selectedTierId];
    if (!tier) return;
    
    const total = tier.price * qty;
    
    // Format to INR
    const formatted = '₹' + total.toLocaleString('en-IN');
    
    document.getElementById('step-1-estimated-price').innerText = formatted;
    document.getElementById('step-3-total-amount').innerText = formatted;
    document.getElementById('payment_amount').value = '₹' + total.toLocaleString('en-IN');
    document.getElementById('step-3-category-name').innerText = tier.name + ' (' + qty + ' × ₹' + tier.price.toLocaleString('en-IN') + ')';
}

function generateAttendeeFields() {
    const container = document.getElementById('attendees-container');
    const qtySelector = document.getElementById('quantity-selector');
    if (!container || !qtySelector) return;
    
    const qty = parseInt(qtySelector.value) || 1;
    container.innerHTML = '';
    
    for (let i = 1; i <= qty; i++) {
        const attendeeCard = document.createElement('div');
        attendeeCard.className = 'card bg-dark border-secondary p-4 mb-3 animate__animated animate__fadeInUp';
        attendeeCard.style.background = 'rgba(15,23,42,0.3) !important; border: 1px solid var(--glass-border);';
        
        // Default values for first attendee is the current logged-in user
        const defaultName = (i === 1) ? <?php echo json_encode($user['name']); ?> : '';
        const defaultEmail = (i === 1) ? <?php echo json_encode($user['email']); ?> : '';
        
        attendeeCard.innerHTML = `
            <h6 class="fw-bold text-white mb-3">Attendee #${i} Details</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label-glass">Full Name</label>
                    <input type="text" name="attendee_name[]" class="form-control form-control-glass attendee-name" required value="${defaultName}" placeholder="Enter full name">
                </div>
                <div class="col-md-6">
                    <label class="form-label-glass">Email Address</label>
                    <input type="email" name="attendee_email[]" class="form-control form-control-glass attendee-email" required value="${defaultEmail}" placeholder="name@example.com">
                </div>
            </div>
        `;
        container.appendChild(attendeeCard);
    }
}

function goToStep(step) {
    // Hide all step panels
    document.querySelectorAll('.step-panel').forEach(panel => {
        panel.classList.add('d-none');
    });
    
    // Reset nodes progress UI
    document.getElementById('step-node-1').className = 'step-node';
    document.getElementById('step-node-2').className = 'step-node';
    document.getElementById('step-node-3').className = 'step-node';
    document.getElementById('step-node-4').className = 'step-node';
    
    const progressBar = document.getElementById('steps-progress');
    
    if (step === 1) {
        document.getElementById('step-panel-1').classList.remove('d-none');
        document.getElementById('step-node-1').className = 'step-node active';
        progressBar.style.width = '0%';
    } else if (step === 2) {
        // Prepare dynamic attendee list fields
        generateAttendeeFields();
        
        document.getElementById('step-panel-2').classList.remove('d-none');
        document.getElementById('step-node-1').className = 'step-node completed';
        document.getElementById('step-node-2').className = 'step-node active';
        progressBar.style.width = '33%';
    } else if (step === 3) {
        // Validate Step 2 details first
        let valid = true;
        document.querySelectorAll('.attendee-name, .attendee-email').forEach(el => {
            if (el.value.trim() === '') {
                valid = false;
                el.classList.add('is-invalid');
            } else {
                el.classList.remove('is-invalid');
            }
        });
        
        if (!valid) {
            alert("Please fill out all attendee detail fields before proceeding.");
            return;
        }
        
        document.getElementById('step-panel-3').classList.remove('d-none');
        document.getElementById('step-node-1').className = 'step-node completed';
        document.getElementById('step-node-2').className = 'step-node completed';
        document.getElementById('step-node-3').className = 'step-node active';
        progressBar.style.width = '66%';
    } else if (step === 4) {
        // Validate checkbox
        const confirmCheck = document.getElementById('payment-sent-confirm');
        if (!confirmCheck.checked) {
            alert("Please confirm that you have sent the payment by checking the confirmation box.");
            return;
        }
        
        document.getElementById('step-panel-4').classList.remove('d-none');
        document.getElementById('step-node-1').className = 'step-node completed';
        document.getElementById('step-node-2').className = 'step-node completed';
        document.getElementById('step-node-3').className = 'step-node completed';
        document.getElementById('step-node-4').className = 'step-node active';
        progressBar.style.width = '100%';
    }
}

function validateFinalStep() {
    let utr = document.getElementById('utr_number').value.trim();
    let screenshot = document.getElementById('payment_screenshot').value;
    let errAlert = document.getElementById('payment-error-alert');
    let errMsg = document.getElementById('payment-error-msg');
    
    errAlert.classList.add('d-none');

    if (utr.length < 8 || utr.length > 22 || !/^[a-zA-Z0-9]+$/.test(utr)) {
        errMsg.innerText = "Please enter a valid Transaction ID / UTR Number (8-22 alphanumeric characters).";
        errAlert.classList.remove('d-none');
        return false;
    }
    if (!screenshot) {
        errMsg.innerText = "Please upload a payment screenshot as proof of transaction.";
        errAlert.classList.remove('d-none');
        return false;
    }
    
    return true;
}

function copyText(elementId) {
    const text = document.getElementById(elementId).innerText;
    navigator.clipboard.writeText(text).then(() => {
        alert("Copied to clipboard: " + text);
    }).catch(err => {
        console.error("Could not copy: ", err);
    });
}

// Initialize Step 1
window.addEventListener('DOMContentLoaded', () => {
    const selected = ticketTiers[selectedTierId];
    if (selected) {
        populateQuantityOptions(selected.seats);
    }
    updateCalculatedTotals();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
