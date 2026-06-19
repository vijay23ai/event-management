<?php
$page_title = "Attendance Check-in";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce login and organizer role
require_role('organizer');

$organizer = get_logged_in_user();
$error = '';
$success = '';
$scanned_ticket = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    $qr_token = trim($_POST['qr_token']);

    if (empty($qr_token)) {
        $error = "Please scan or enter a ticket QR code token.";
    } else {
        try {
            // Find booking and join event and user details
            $stmt = $pdo->prepare("SELECT b.*, u.name as attendee_name, u.email as attendee_email, 
                                          e.title as event_title, e.organizer_id, tt.name as ticket_name 
                                   FROM bookings b
                                   JOIN users u ON b.user_id = u.id
                                   JOIN events e ON b.event_id = e.id
                                   JOIN tickets_types tt ON b.ticket_type_id = tt.id
                                   WHERE b.qr_code_token = ?");
            $stmt->execute([$qr_token]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $error = "Invalid Ticket Token: No booking found matches '{$qr_token}'.";
            } elseif ($booking['organizer_id'] != $organizer['id']) {
                $error = "Access Denied: This ticket belongs to an event you do not organize.";
            } elseif ($booking['status'] !== 'confirmed') {
                $error = "Invalid Ticket: This booking is currently in status '{$booking['status']}' (Cancelled/Refunded). Access Denied.";
            } elseif ($booking['attendance_status'] === 'present') {
                $checkin_time = date('Y-m-d H:i:s', strtotime($booking['checked_in_at']));
                $error = "DUPLICATE ENTRY WARNING: This ticket was already scanned and checked in on {$checkin_time}!";
                $scanned_ticket = $booking; // Still show attendee info for security checking
            } else {
                // Perform Check-in
                $up_stmt = $pdo->prepare("UPDATE bookings SET attendance_status = 'present', checked_in_at = NOW() WHERE id = ?");
                $up_stmt->execute([$booking['id']]);
                
                $success = "Check-in Successful! Welcome, {$booking['attendee_name']}.";
                
                // Fetch updated booking details
                $booking['attendance_status'] = 'present';
                $booking['checked_in_at'] = date('Y-m-d H:i:s');
                $scanned_ticket = $booking;
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Check if a GET parameter `qr_token` was passed (e.g. from scanning a ticket link directly)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['qr_token'])) {
    // Inject into POST flow for ease of processing
    $_POST['qr_token'] = $_GET['qr_token'];
    // Let it re-run the check-in script as POST
}

// Additional styles for HTML5-QRCode scanner script inclusion
$additional_styles = '<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Sidebar Navigation -->
    <div class="col-md-3 mb-4 no-print">
        <div class="glass-panel p-3">
            <div class="text-center py-3 mb-3 border-bottom border-secondary">
                <i class="bi bi-qr-code-scan fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2"><?php echo htmlspecialchars($organizer['name']); ?></h5>
                <span class="badge badge-custom badge-indigo">Organizer Panel</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-pie-chart-fill"></i> Analytics Overview</a>
                <a href="events.php" class="sidebar-link"><i class="bi bi-calendar-event"></i> Manage Events</a>
                <a href="registrations.php" class="sidebar-link"><i class="bi bi-people"></i> Registrations list</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Verify Payments</a>
                <a href="checkin.php" class="sidebar-link active"><i class="bi bi-qr-code-scan"></i> QR Code Check-in</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <div class="row g-4">
            <!-- QR Scanner Column -->
            <div class="col-lg-6">
                <div class="glass-panel p-4 h-100">
                    <h4 class="fw-bold text-white mb-3"><i class="bi bi-camera-video text-indigo me-2"></i>Live QR Code Scanner</h4>
                    <p class="text-secondary small mb-4">Allow camera access to scan ticket QR codes instantly. Ensure good lighting and center the code.</p>
                    
                    <div id="qr-reader" style="width: 100%;" class="mb-4"></div>
                    
                    <!-- Manual Input Fallback -->
                    <form method="POST" action="checkin.php" id="checkin-form">
                        <div class="mb-3">
                            <label for="qr_token" class="form-label-glass">Manual Ticket Code / Token Input</label>
                            <div class="input-group">
                                <input type="text" name="qr_token" id="qr_token" class="form-control form-control-glass font-monospace" placeholder="e.g. QR_CONF_VIP_65d0a..." value="<?php echo isset($_POST['qr_token']) ? htmlspecialchars($_POST['qr_token']) : ''; ?>">
                                <button type="submit" class="btn btn-primary-gradient px-4"><i class="bi bi-check2-circle me-1"></i> Verify</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Verification Status Column -->
            <div class="col-lg-6">
                <div class="glass-panel p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <h4 class="fw-bold text-white mb-4"><i class="bi bi-shield-check text-indigo me-2"></i>Verification status</h4>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger border-0 text-white p-3 mb-4" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                                <i class="bi bi-x-circle-fill fs-4 me-2 align-middle"></i>
                                <span class="align-middle fw-bold"><?php echo $error; ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success border-0 text-white p-3 mb-4" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                                <i class="bi bi-check-circle-fill fs-4 me-2 align-middle"></i>
                                <span class="align-middle fw-bold"><?php echo $success; ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($scanned_ticket): ?>
                            <!-- Scanned Ticket Profile Info -->
                            <div class="p-4 rounded-4 bg-dark" style="background: rgba(15,23,42,0.4) !important; border: 1px solid var(--glass-border);">
                                <h5 class="fw-bold text-white mb-3 border-bottom border-secondary pb-2">Ticket Holder Details</h5>
                                <div class="mb-2">
                                    <span class="text-secondary small">Attendee Name:</span>
                                    <div class="fw-bold text-white fs-5"><?php echo htmlspecialchars($scanned_ticket['attendee_name']); ?></div>
                                </div>
                                <div class="mb-2">
                                    <span class="text-secondary small">Email Address:</span>
                                    <div class="text-white"><?php echo htmlspecialchars($scanned_ticket['attendee_email']); ?></div>
                                </div>
                                <hr class="border-secondary">
                                <div class="mb-2">
                                    <span class="text-secondary small">Event Name:</span>
                                    <div class="text-white fw-bold"><?php echo htmlspecialchars($scanned_ticket['event_title']); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-2">
                                        <span class="text-secondary small">Ticket Tier:</span>
                                        <div class="fw-bold text-indigo" style="color: #a5b4fc;"><?php echo $scanned_ticket['ticket_name']; ?></div>
                                    </div>
                                    <div class="col-6 mb-2">
                                        <span class="text-secondary small">Quantity:</span>
                                        <div class="text-white fw-bold"><?php echo $scanned_ticket['quantity']; ?> ticket(s)</div>
                                    </div>
                                </div>
                                <hr class="border-secondary">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-secondary small">Attendance:</span>
                                    <span class="badge <?php echo $scanned_ticket['attendance_status'] === 'present' ? 'bg-success' : 'bg-warning text-dark'; ?> text-uppercase">
                                        <?php echo $scanned_ticket['attendance_status'] === 'present' ? 'Present' : 'Not Checked In'; ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-secondary">
                                <i class="bi bi-qr-code fs-1 opacity-25"></i>
                                <p class="small mt-3">Awaiting ticket scan. Scanned ticket profile will be displayed here for validation.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="border-top border-secondary pt-3 mt-4 text-muted small">
                        <i class="bi bi-info-circle me-1"></i> Checked-in attendees will be registered in database records with exact timestamps, blocking multi-entry fraud.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Scanner Integration using HTML5-QRCode
document.addEventListener('DOMContentLoaded', function() {
    function onScanSuccess(decodedText, decodedResult) {
        // Extract token if scanner read the full verification URL
        let token = decodedText;
        if (decodedText.includes('qr_token=')) {
            let urlObj = new URL(decodedText);
            token = urlObj.searchParams.get('qr_token');
        }
        
        // Stop scanner to prevent multiple triggers
        html5QrcodeScanner.clear().then(_ => {
            // Set input value and submit form
            document.getElementById('qr_token').value = token;
            document.getElementById('checkin-form').submit();
        }).catch(err => {
            console.error("Failed to clear scanner: ", err);
        });
    }

    function onScanFailure(error) {
        // Handle failure, usually can ignore to keep scanning
    }

    var html5QrcodeScanner = new Html5QrcodeScanner(
        "qr-reader", { fps: 10, qrbox: 250 }, /* verbose= */ false
    );
    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
