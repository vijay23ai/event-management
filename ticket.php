<?php
$page_title = "My Ticket";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

require_login();

$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($booking_id <= 0) {
    set_flash_message('error', 'Invalid booking ID.');
    header('Location: /dashboard.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT b.*, e.title as event_title, e.date_time, e.venue, e.city, e.address, e.banner_image,
                                   tt.name as ticket_name, u.name as user_name, u.email as user_email, e.organizer_id,
                                   org.name as organizer_name
                           FROM bookings b
                           JOIN events e ON b.event_id = e.id
                           JOIN tickets_types tt ON b.ticket_type_id = tt.id
                           JOIN users u ON b.user_id = u.id
                           JOIN users org ON e.organizer_id = org.id
                           WHERE b.id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        set_flash_message('error', 'Ticket not found.');
        header('Location: /dashboard.php');
        exit;
    }

    $current_user = get_logged_in_user();
    if ($current_user['role'] === 'user' && $booking['user_id'] != $current_user['id']) {
        set_flash_message('error', 'You are not authorized to view this ticket.');
        header('Location: /dashboard.php');
        exit;
    }
    if ($current_user['role'] === 'organizer' && $booking['organizer_id'] != $current_user['id']) {
        set_flash_message('error', 'Access denied.');
        header('Location: /organizer/dashboard.php');
        exit;
    }

    if ($booking['status'] !== 'confirmed') {
        if ($booking['status'] === 'pending') {
            set_flash_message('error', 'Your booking is pending payment verification. Ticket is not generated yet.');
        } elseif ($booking['status'] === 'cancelled') {
            set_flash_message('error', 'This ticket is invalid because the booking was cancelled.');
        } else {
            set_flash_message('error', 'This ticket is not available.');
        }
        header('Location: ' . ($current_user['role'] === 'organizer' ? '/organizer/dashboard.php' : '/dashboard.php'));
        exit;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$verify_data  = "https://vmp-event-management.infinityfree.me/organizer/checkin.php?qr_token=" . urlencode($booking['qr_code_token']);
$qr_api_url   = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($verify_data);
$org_initial  = strtoupper(substr($booking['organizer_name'] ?? 'O', 0, 1));
$ticket_ref   = strtoupper(substr(md5($booking['qr_code_token']), 0, 8));
$booking_num  = str_pad($booking['id'], 6, '0', STR_PAD_LEFT);
$event_date   = date('D, d M Y', strtotime($booking['date_time']));
$event_time   = date('h:i A', strtotime($booking['date_time']));
$event_banner = !empty($booking['banner_image'])
    ? htmlspecialchars($booking['banner_image'])
    : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=1200';

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ─── Page wrapper ─────────────────────────────────── */
.ticket-page {
    min-height: 100vh;
    padding: 40px 16px 80px;
    background: var(--bg-primary);
}

/* ─── Outer card ───────────────────────────────────── */
.tkt {
    max-width: 700px;
    margin: 0 auto;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,0.18), 0 8px 24px rgba(79,70,229,0.12);
    background: #fff;
    font-family: 'Outfit', sans-serif;
}

/* ─── Hero banner ──────────────────────────────────── */
.tkt-hero {
    position: relative;
    height: 230px;
    overflow: hidden;
}

.tkt-hero img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.tkt-hero-gradient {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        160deg,
        rgba(10,10,30,0.25) 0%,
        rgba(10,10,30,0.75) 60%,
        rgba(10,10,30,0.97) 100%
    );
}

.tkt-hero-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 20px 28px 22px;
}

.tkt-org-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.22);
    backdrop-filter: blur(8px);
    border-radius: 50px;
    padding: 4px 12px 4px 5px;
    margin-bottom: 10px;
}

.tkt-org-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.7rem;
    color: #fff;
}

.tkt-org-name {
    font-size: 0.78rem;
    font-weight: 500;
    color: rgba(255,255,255,0.9);
}

.tkt-event-title {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: 1.5rem;
    color: #fff;
    line-height: 1.2;
    margin: 0;
    text-shadow: 0 2px 12px rgba(0,0,0,0.4);
}

/* ─── Status ribbon ────────────────────────────────── */
.tkt-status-ribbon {
    position: absolute;
    top: 20px;
    right: 20px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
}
.tkt-status-ribbon.confirmed {
    background: rgba(16,185,129,0.18);
    border: 1px solid rgba(16,185,129,0.4);
    color: #6ee7b7;
}
.tkt-status-ribbon.confirmed::before {
    content: '';
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    flex-shrink: 0;
    animation: blink-dot 1.6s infinite;
}
@keyframes blink-dot {
    0%,100% { opacity:1; transform:scale(1); }
    50% { opacity:0.4; transform:scale(0.65); }
}

/* ─── Date & Location bar ──────────────────────────── */
.tkt-meta-bar {
    display: flex;
    align-items: stretch;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
}

.tkt-meta-cell {
    flex: 1;
    padding: 14px 20px;
    border-right: 1px solid rgba(255,255,255,0.15);
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.tkt-meta-cell:last-child { border-right: none; }

.tkt-meta-label {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: rgba(255,255,255,0.6);
}

.tkt-meta-value {
    font-size: 0.9rem;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ─── Main body ────────────────────────────────────── */
.tkt-body {
    padding: 28px 28px 0;
}

/* ─── Info grid ────────────────────────────────────── */
.tkt-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px 24px;
    margin-bottom: 24px;
}

.tkt-field-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #94a3b8;
    margin-bottom: 4px;
}

.tkt-field-value {
    font-weight: 700;
    color: #0f172a;
    font-size: 0.92rem;
    line-height: 1.3;
}

.tkt-field-value.accent { color: #4f46e5; }
.tkt-field-value.sub {
    font-size: 0.78rem;
    font-weight: 500;
    color: #64748b;
    margin-top: 2px;
}

.tkt-full { grid-column: 1 / -1; }

/* ─── Tear line ────────────────────────────────────── */
.tkt-tear {
    position: relative;
    margin: 0 -28px 24px;
    display: flex;
    align-items: center;
}

.tkt-tear-line {
    flex: 1;
    border: none;
    border-top: 2px dashed #e2e8f0;
    margin: 0;
}

.tkt-tear-notch {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-primary, #f8fafc);
    border: 1px solid #e2e8f0;
    flex-shrink: 0;
}

.tkt-tear-notch.left { margin-left: -16px; }
.tkt-tear-notch.right { margin-right: -16px; }

/* ─── QR section ───────────────────────────────────── */
.tkt-qr-row {
    display: flex;
    gap: 20px;
    align-items: flex-start;
    padding-bottom: 28px;
}

.tkt-qr-wrap {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.tkt-qr-frame {
    background: #fff;
    border: 3px solid #e2e8f0;
    border-radius: 16px;
    padding: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
}

.tkt-qr-frame img {
    display: block;
    width: 150px;
    height: 150px;
    border-radius: 8px;
}

.tkt-scan-hint {
    font-size: 0.6rem;
    color: #94a3b8;
    text-align: center;
    margin-top: 7px;
    letter-spacing: 0.3px;
}

.tkt-ids {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tkt-id-pill {
    background: linear-gradient(135deg, #f0f4ff 0%, #eef2ff 100%);
    border: 1px solid rgba(79,70,229,0.12);
    border-radius: 14px;
    padding: 12px 16px;
}

.tkt-id-pill .label {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #6366f1;
    margin-bottom: 3px;
}

.tkt-id-pill .val {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 1.15rem;
    color: #0f172a;
    letter-spacing: 0.5px;
}

.tkt-price-pill {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border-radius: 14px;
    padding: 12px 16px;
    text-align: center;
    color: #fff;
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.tkt-price-pill .label {
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    opacity: 0.75;
    margin-bottom: 4px;
}

.tkt-price-pill .val {
    font-family: 'Poppins', sans-serif;
    font-weight: 800;
    font-size: 1.5rem;
}

/* ─── Footer strip ─────────────────────────────────── */
.tkt-footer-strip {
    background: linear-gradient(90deg, #4f46e5, #7c3aed);
    padding: 12px 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.tkt-footer-strip span {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(255,255,255,0.75);
}

.tkt-footer-dot {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: rgba(255,255,255,0.4);
}

/* ─── Action buttons ───────────────────────────────── */
.ticket-actions {
    max-width: 700px;
    margin: 0 auto 28px;
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
}

/* ─── Print mode ───────────────────────────────────── */
@media print {
    .no-print, nav, footer { display: none !important; }
    .ticket-page { padding: 0 !important; background: #fff !important; }
    .tkt { box-shadow: none !important; border-radius: 0 !important; max-width: 100% !important; }
    .tkt-tear-notch { background: #fff !important; }
}

/* ─── Mobile ───────────────────────────────────────── */
@media (max-width: 600px) {
    .tkt-hero { height: 185px; }
    .tkt-event-title { font-size: 1.15rem; }
    .tkt-meta-value { font-size: 0.78rem; }
    .tkt-meta-cell { padding: 10px 14px; }
    .tkt-body { padding: 20px 18px 0; }
    .tkt-grid { gap: 14px 16px; }
    .tkt-qr-row { flex-direction: column; align-items: center; }
    .tkt-ids { width: 100%; }
    .tkt-tear { margin: 0 -18px 20px; }
    .tkt-footer-strip { padding: 10px 18px; }
    .tkt-qr-frame img { width: 130px; height: 130px; }
}
</style>

<div class="ticket-page">

    <!-- Action Buttons -->
    <div class="ticket-actions no-print">
        <a href="/dashboard.php" class="btn btn-glass rounded-pill px-4 py-2">
            <i class="bi bi-arrow-left me-2"></i>Back
        </a>
        <button onclick="window.print()" class="btn btn-primary-gradient rounded-pill px-4 py-2">
            <i class="bi bi-download me-2"></i>Download / Print
        </button>
    </div>

    <!-- Ticket Card -->
    <div class="tkt">

        <!-- Hero Banner -->
        <div class="tkt-hero">
            <img src="<?php echo $event_banner; ?>" alt="<?php echo htmlspecialchars($booking['event_title']); ?>">
            <div class="tkt-hero-gradient"></div>

            <div class="tkt-hero-content">
                <!-- Organizer chip -->
                <div class="tkt-org-chip">
                    <div class="tkt-org-avatar"><?php echo $org_initial; ?></div>
                    <span class="tkt-org-name"><?php echo htmlspecialchars($booking['organizer_name']); ?></span>
                </div>
                <h1 class="tkt-event-title"><?php echo htmlspecialchars($booking['event_title']); ?></h1>
            </div>

            <!-- Status badge -->
            <div class="tkt-status-ribbon confirmed">Confirmed</div>
        </div>

        <!-- Purple meta bar: date / time / city -->
        <div class="tkt-meta-bar">
            <div class="tkt-meta-cell">
                <div class="tkt-meta-label">Date</div>
                <div class="tkt-meta-value"><?php echo $event_date; ?></div>
            </div>
            <div class="tkt-meta-cell">
                <div class="tkt-meta-label">Time</div>
                <div class="tkt-meta-value"><?php echo $event_time; ?></div>
            </div>
            <div class="tkt-meta-cell">
                <div class="tkt-meta-label">City</div>
                <div class="tkt-meta-value"><?php echo htmlspecialchars($booking['city']); ?></div>
            </div>
        </div>

        <!-- Body -->
        <div class="tkt-body">
            <div class="tkt-grid">

                <!-- Attendee -->
                <div>
                    <div class="tkt-field-label"><i class="bi bi-person-fill me-1"></i>Attendee</div>
                    <div class="tkt-field-value"><?php echo htmlspecialchars($booking['user_name']); ?></div>
                    <div class="tkt-field-value sub"><?php echo htmlspecialchars($booking['user_email']); ?></div>
                </div>

                <!-- Ticket Tier -->
                <div>
                    <div class="tkt-field-label"><i class="bi bi-ticket-perforated-fill me-1"></i>Ticket Tier</div>
                    <div class="tkt-field-value accent"><?php echo htmlspecialchars($booking['ticket_name']); ?></div>
                    <div class="tkt-field-value sub"><?php echo $booking['quantity']; ?> ticket<?php echo $booking['quantity'] > 1 ? 's' : ''; ?></div>
                </div>

                <!-- Venue (full width) -->
                <div class="tkt-full">
                    <div class="tkt-field-label"><i class="bi bi-geo-alt-fill me-1"></i>Venue</div>
                    <div class="tkt-field-value"><?php echo htmlspecialchars($booking['venue']); ?></div>
                    <div class="tkt-field-value sub"><?php echo htmlspecialchars($booking['address']); ?>, <?php echo htmlspecialchars($booking['city']); ?></div>
                </div>

            </div>

            <!-- Tear-line perforation -->
            <div class="tkt-tear">
                <div class="tkt-tear-notch left"></div>
                <hr class="tkt-tear-line">
                <div class="tkt-tear-notch right"></div>
            </div>

            <!-- QR + IDs -->
            <div class="tkt-qr-row">

                <!-- QR code -->
                <div class="tkt-qr-wrap">
                    <div class="tkt-qr-frame">
                        <img src="<?php echo $qr_api_url; ?>" alt="Check-in QR">
                    </div>
                    <div class="tkt-scan-hint">Scan to verify at entry</div>
                </div>

                <!-- IDs + price -->
                <div class="tkt-ids">
                    <div class="tkt-id-pill">
                        <div class="label">Ticket Ref</div>
                        <div class="val">#<?php echo $ticket_ref; ?></div>
                    </div>
                    <div class="tkt-id-pill">
                        <div class="label">Booking ID</div>
                        <div class="val">#<?php echo $booking_num; ?></div>
                    </div>
                    <div class="tkt-price-pill">
                        <div class="label">Amount Paid</div>
                        <div class="val">₹<?php echo number_format($booking['total_price'], 0); ?></div>
                    </div>
                </div>

            </div>
        </div><!-- /tkt-body -->

        <!-- Footer strip -->
        <div class="tkt-footer-strip">
            <i class="bi bi-shield-check" style="color:rgba(255,255,255,0.6);"></i>
            <span>Eventify</span>
            <div class="tkt-footer-dot"></div>
            <span>Secure Ticket</span>
            <div class="tkt-footer-dot"></div>
            <span>Valid for One Entry</span>
        </div>

    </div><!-- /tkt -->

</div><!-- /ticket-page -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
