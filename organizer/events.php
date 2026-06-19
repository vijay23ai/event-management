<?php
$page_title = "Manage Events";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

// Enforce login and organizer role
require_role('organizer');

$organizer = get_logged_in_user();
$error = '';
$success = '';

// Handle CRUD Operations
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$edit_event = null;
$price_general = 25.00;
$price_vip = 75.00;
$price_student = 15.00;

// 1. Fetch Event for Editing and global organizer UPI details
$stmt_upi = $pdo->prepare("SELECT * FROM organizer_payment_details WHERE organizer_id = ?");
$stmt_upi->execute([$organizer['id']]);
$upi_details = $stmt_upi->fetch();

if ($action === 'edit' && isset($_GET['id'])) {
    $ev_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND organizer_id = ?");
    $stmt->execute([$ev_id, $organizer['id']]);
    $edit_event = $stmt->fetch();
    
    if (!$edit_event) {
        set_flash_message('error', 'Event not found or access denied.');
        header('Location: events.php');
        exit;
    }

    // Load ticket prices
    $stmt_prices = $pdo->prepare("SELECT * FROM tickets_types WHERE event_id = ?");
    $stmt_prices->execute([$ev_id]);
    $prices = $stmt_prices->fetchAll();
    foreach ($prices as $p) {
        if ($p['name'] === 'General') $price_general = $p['price'];
        if ($p['name'] === 'VIP') $price_vip = $p['price'];
        if ($p['name'] === 'Student') $price_student = $p['price'];
    }
}

// 2. Handle Create / Update Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create' || $action === 'edit')) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? '';
    $city = trim($_POST['city'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $date_time = $_POST['date_time'] ?? '';
    $capacity = (int)($_POST['capacity'] ?? 0);
    $banner_url = trim($_POST['banner_url'] ?? '');
    
    // UPI Fields
    $upi_id = trim($_POST['upi_id'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $payment_instructions = trim($_POST['payment_instructions'] ?? '');

    // Geolocation details
    $building_name = trim($_POST['building_name'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $google_maps_link = trim($_POST['google_maps_link'] ?? '');
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    // Ticket prices
    $price_general = (float)($_POST['price_general'] ?? 0);
    $price_vip = (float)($_POST['price_vip'] ?? 0);
    $price_student = (float)($_POST['price_student'] ?? 0);

    // File Upload Handler for Banner — saved to /uploads/events/
    $banner_path = $banner_url; // Fallback to URL
    if (isset($_FILES['banner_file']) && $_FILES['banner_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['banner_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['banner_file']['name'], PATHINFO_EXTENSION));
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['banner_file']['name']));
        $upload_dir = __DIR__ . '/../uploads/events/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (move_uploaded_file($file_tmp, $upload_dir . $file_name)) {
            $banner_path = '/uploads/events/' . $file_name;
        }
    }

    // File Upload Handler for UPI QR
    $qr_path = $upi_details ? $upi_details['qr_image'] : '';
    if (isset($_FILES['upi_qr_file']) && $_FILES['upi_qr_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['upi_qr_file']['tmp_name'];
        $file_name = time() . '_qr_' . basename($_FILES['upi_qr_file']['name']);
        $upload_dir = __DIR__ . '/../uploads/upi/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        if (move_uploaded_file($file_tmp, $upload_dir . $file_name)) {
            $qr_path = '/uploads/upi/' . $file_name;
        }
    }

    if (empty($title) || empty($description) || empty($category) || empty($city) || empty($venue) || empty($address) || empty($date_time) || $capacity <= 0) {
        $error = "Please fill in all required fields and define a positive capacity.";
    } elseif (empty($upi_id) || empty($phone_number)) {
        $error = "UPI ID and Phone Number are required.";
    } elseif (empty($qr_path) && (!isset($_FILES['upi_qr_file']) || $_FILES['upi_qr_file']['error'] !== UPLOAD_ERR_OK)) {
        $error = "UPI QR Code image file is required.";
    } else {
        try {
            $pdo->beginTransaction();

            // Save/Update UPI details
            if ($upi_details) {
                $up_upi = $pdo->prepare("UPDATE organizer_payment_details SET upi_id = ?, phone_number = ?, qr_image = ? WHERE organizer_id = ?");
                $up_upi->execute([$upi_id, $phone_number, $qr_path, $organizer['id']]);
            } else {
                $ins_upi = $pdo->prepare("INSERT INTO organizer_payment_details (organizer_id, upi_id, phone_number, qr_image) VALUES (?, ?, ?, ?)");
                $ins_upi->execute([$organizer['id'], $upi_id, $phone_number, $qr_path]);
            }

            if ($action === 'create') {
                // Create Event (default status pending)
                $stmt = $pdo->prepare("INSERT INTO events (organizer_id, title, description, category, city, venue, address, date_time, capacity, remaining_seats, banner_image, payment_instructions, status, building_name, state, pincode, google_maps_link, latitude, longitude, created_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $organizer['id'], $title, $description, $category, $city, $venue, $address, $date_time, $capacity, $capacity, $banner_path, $payment_instructions,
                    $building_name, $state, $pincode, $google_maps_link, $latitude, $longitude
                ]);
                $event_id = $pdo->lastInsertId();

                // Distribute ticket type capacities (60% Gen, 20% VIP, 20% Stud)
                $cap_gen = ceil($capacity * 0.6);
                $cap_vip = floor($capacity * 0.2);
                $cap_stud = floor($capacity * 0.2);

                $stmt_tt = $pdo->prepare("INSERT INTO tickets_types (event_id, name, price, capacity, remaining_seats) VALUES (?, ?, ?, ?, ?)");
                $stmt_tt->execute([$event_id, 'General', $price_general, $cap_gen, $cap_gen]);
                $stmt_tt->execute([$event_id, 'VIP', $price_vip, $cap_vip, $cap_vip]);
                $stmt_tt->execute([$event_id, 'Student', $price_student, $cap_stud, $cap_stud]);

                $pdo->commit();
                
                // Notify admin of new event pending approval
                $admin_id_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                $admin_id = $admin_id_stmt->fetchColumn();
                if ($admin_id) {
                    send_notification($pdo, $admin_id, "New Event Pending Approval", "Event '{$title}' has been submitted by organizer '{$organizer['name']}' and is pending approval.", 'system');
                }

                set_flash_message('success', "Event submitted successfully! It is pending administrator approval.");
                header('Location: events.php');
                exit;

            } else {
                // Edit / Update operation
                $event_id = (int)$_GET['id'];

                $stmt = $pdo->prepare("UPDATE events SET title = ?, description = ?, category = ?, city = ?, venue = ?, address = ?, date_time = ?, capacity = ?, remaining_seats = ?, banner_image = ?, payment_instructions = ?, building_name = ?, state = ?, pincode = ?, google_maps_link = ?, latitude = ?, longitude = ? WHERE id = ? AND organizer_id = ?");
                $stmt->execute([
                    $title, $description, $category, $city, $venue, $address, $date_time, $capacity, $capacity, $banner_path, $payment_instructions,
                    $building_name, $state, $pincode, $google_maps_link, $latitude, $longitude, $event_id, $organizer['id']
                ]);

                // Update ticket prices
                $stmt_tt = $pdo->prepare("UPDATE tickets_types SET price = ? WHERE event_id = ? AND name = ?");
                $stmt_tt->execute([$price_general, $event_id, 'General']);
                $stmt_tt->execute([$price_vip, $event_id, 'VIP']);
                $stmt_tt->execute([$price_student, $event_id, 'Student']);

                $pdo->commit();
                set_flash_message('success', "Event details updated successfully!");
                header('Location: events.php');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Operation failed: " . $e->getMessage();
        }
    }
}

// 3. Handle Cancel Event Action (Triggers refunds and alerts)
if (isset($_GET['cancel_id'])) {
    $cancel_id = (int)$_GET['cancel_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND organizer_id = ? FOR UPDATE");
        $stmt->execute([$cancel_id, $organizer['id']]);
        $ev = $stmt->fetch();

        if ($ev && $ev['status'] !== 'cancelled') {
            $pdo->beginTransaction();
            
            // Set status to cancelled
            $up_stmt = $pdo->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?");
            $up_stmt->execute([$cancel_id]);

            // Fetch all confirmed bookings to cancel them and create refund requests
            $book_stmt = $pdo->prepare("SELECT b.*, u.id as attendee_id, u.name as attendee_name FROM bookings b JOIN users u ON b.user_id = u.id WHERE b.event_id = ? AND b.status = 'confirmed'");
            $book_stmt->execute([$cancel_id]);
            $bookings = $book_stmt->fetchAll();

            $ins_refund = $pdo->prepare("INSERT INTO refund_requests (booking_id, organizer_id, reason, status, amount, created_at) VALUES (?, ?, ?, 'requested', ?, NOW())");
            $up_book = $pdo->prepare("UPDATE bookings SET status = 'refund_requested' WHERE id = ?");

            foreach ($bookings as $b) {
                // Cancel booking
                $up_book->execute([$b['id']]);
                
                // File refund request automatically
                $ins_refund->execute([$b['id'], $organizer['id'], "Event '{$ev['title']}' was cancelled by the organizer.", $b['total_price']]);
                
                // Notify attendee
                notify_event_cancelled($pdo, $b['attendee_id'], $ev['title'], "A full refund of ₹{$b['total_price']} is being processed.");
            }

            $pdo->commit();
            set_flash_message('success', "Event cancelled successfully! All tickets were cancelled and refunds requested.");
        } else {
            set_flash_message('error', "Event already cancelled or not found.");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash_message('error', "Cancellation failed: " . $e->getMessage());
    }
    header('Location: events.php');
    exit;
}

// 4. Fetch All Events Organized by this user
try {
    $stmt = $pdo->prepare("SELECT e.*, COUNT(b.id) as bookings_count, SUM(b.quantity) as tickets_qty 
                           FROM events e 
                           LEFT JOIN bookings b ON e.id = b.event_id AND b.status = 'confirmed'
                           WHERE e.organizer_id = ? 
                           GROUP BY e.id 
                           ORDER BY e.date_time DESC");
    $stmt->execute([$organizer['id']]);
    $my_events = $stmt->fetchAll();
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
                <i class="bi bi-calendar-event fs-1 text-indigo"></i>
                <h5 class="fw-bold mt-2" style="color: var(--text-primary);"><?php echo htmlspecialchars($organizer['name']); ?></h5>
                <span class="badge badge-custom badge-indigo">Organizer Panel</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-pie-chart-fill"></i> Analytics Overview</a>
                <a href="events.php" class="sidebar-link active"><i class="bi bi-calendar-event"></i> Manage Events</a>
                <a href="registrations.php" class="sidebar-link"><i class="bi bi-people"></i> Registrations list</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Verify Payments</a>
                <a href="checkin.php" class="sidebar-link"><i class="bi bi-qr-code-scan"></i> QR Code Check-in</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <?php if ($action === 'create' || $action === 'edit'): ?>
            <!-- Create / Edit Event Form Panel -->
            <div class="glass-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold mb-0" style="color: var(--text-primary);"><?php echo $action === 'create' ? 'Create New Event' : 'Edit Event Details'; ?></h4>
                    <a href="events.php" class="btn btn-glass btn-sm"><i class="bi bi-x-circle me-1"></i> Cancel</a>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="title" class="form-label-glass">Event Title</label>
                            <input type="text" name="title" id="title" class="form-control form-control-glass" placeholder="e.g. Acoustic Night Session" required value="<?php echo htmlspecialchars($edit_event['title'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="category" class="form-label-glass">Category</label>
                            <?php
                                $known_cats = ['Concert','Workshop','Sports','Seminar','Festival','Meetup'];
                                $current_cat = $edit_event['category'] ?? 'Concert';
                                $is_custom = $edit_event && !in_array($current_cat, $known_cats);
                            ?>
                            <select name="category" id="category" class="form-select form-select-glass" onchange="toggleCustomCategory(this)">
                                <option value="Concert"  <?php echo (!$is_custom && $current_cat==='Concert')  ? 'selected' : ''; ?>>🎵 Concert</option>
                                <option value="Workshop" <?php echo (!$is_custom && $current_cat==='Workshop') ? 'selected' : ''; ?>>🛠️ Workshop</option>
                                <option value="Sports"   <?php echo (!$is_custom && $current_cat==='Sports')   ? 'selected' : ''; ?>>🏆 Sports</option>
                                <option value="Seminar"  <?php echo (!$is_custom && $current_cat==='Seminar')  ? 'selected' : ''; ?>>💻 Seminar</option>
                                <option value="Festival" <?php echo (!$is_custom && $current_cat==='Festival') ? 'selected' : ''; ?>>🎭 Festival</option>
                                <option value="Meetup"   <?php echo (!$is_custom && $current_cat==='Meetup')   ? 'selected' : ''; ?>>👥 Meetup</option>
                                <option value="Exhibition"    <?php echo (!$is_custom && $current_cat==='Exhibition')    ? 'selected' : ''; ?>>🖼️ Exhibition</option>
                                <option value="Cultural Event" <?php echo (!$is_custom && $current_cat==='Cultural Event') ? 'selected' : ''; ?>>🎨 Cultural Event</option>
                                <option value="Corporate Event" <?php echo (!$is_custom && $current_cat==='Corporate Event') ? 'selected' : ''; ?>>💼 Corporate Event</option>
                                <option value="Other"    <?php echo $is_custom ? 'selected' : ''; ?>>✏️ Other (type your own)</option>
                            </select>
                            <div id="custom-category-wrap" class="mt-2" style="display: <?php echo $is_custom ? 'block' : 'none'; ?>;">
                                <input type="text" id="custom_category_input" class="form-control form-control-glass"
                                    placeholder="e.g. Charity Run, Food Fair..."
                                    value="<?php echo $is_custom ? htmlspecialchars($current_cat) : ''; ?>"
                                    oninput="syncCustomCategory(this)">
                                <div class="form-text" style="color: var(--text-muted); font-size: 0.75rem;">
                                    <i class="bi bi-pencil me-1"></i>Type your custom category name above.
                                </div>
                            </div>
                            <script>
                            function toggleCustomCategory(sel) {
                                const wrap = document.getElementById('custom-category-wrap');
                                if (sel.value === 'Other') {
                                    wrap.style.display = 'block';
                                    document.getElementById('custom_category_input').focus();
                                } else {
                                    wrap.style.display = 'none';
                                }
                            }
                            function syncCustomCategory(inp) {
                                // keep the select value as "Other" but the form will
                                // be intercepted on submit to swap it
                            }
                            document.addEventListener('DOMContentLoaded', function() {
                                const form = document.querySelector('form[method="POST"]');
                                if (form) {
                                    form.addEventListener('submit', function() {
                                        const sel = document.getElementById('category');
                                        if (sel.value === 'Other') {
                                            const customVal = document.getElementById('custom_category_input').value.trim();
                                            if (customVal) {
                                                // Temporarily add option and select it
                                                const opt = document.createElement('option');
                                                opt.value = customVal;
                                                opt.selected = true;
                                                sel.appendChild(opt);
                                                sel.value = customVal;
                                            }
                                        }
                                    });
                                }
                            });
                            </script>
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label-glass">Description</label>
                            <textarea name="description" id="description" class="form-control form-control-glass" rows="4" placeholder="Detail the event schedules, agendas, guidelines..." required><?php echo htmlspecialchars($edit_event['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-3 border-bottom border-secondary pb-2" style="color: var(--text-primary);">Venue & Geolocation Details</h5>
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="venue" class="form-label-glass">Venue Name</label>
                                    <input type="text" name="venue" id="venue" class="form-control form-control-glass" placeholder="e.g. Royal Opera House" required value="<?php echo htmlspecialchars($edit_event['venue'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="building_name" class="form-label-glass">Building / Wing Name (Optional)</label>
                                    <input type="text" name="building_name" id="building_name" class="form-control form-control-glass" placeholder="e.g. Ground Floor, West Wing" value="<?php echo htmlspecialchars($edit_event['building_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-8">
                                    <label for="address" class="form-label-glass">Street Address</label>
                                    <input type="text" name="address" id="address" class="form-control form-control-glass" placeholder="e.g. Girgaon, Charni Road" required value="<?php echo htmlspecialchars($edit_event['address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="pincode" class="form-label-glass">Pincode</label>
                                    <input type="text" name="pincode" id="pincode" class="form-control form-control-glass" placeholder="e.g. 400004" required value="<?php echo htmlspecialchars($edit_event['pincode'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="city" class="form-label-glass">City</label>
                                    <input type="text" name="city" id="city" class="form-control form-control-glass" placeholder="e.g. Mumbai" required value="<?php echo htmlspecialchars($edit_event['city'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="state" class="form-label-glass">State</label>
                                    <input type="text" name="state" id="state" class="form-control form-control-glass" placeholder="e.g. Maharashtra" required value="<?php echo htmlspecialchars($edit_event['state'] ?? ''); ?>">
                                </div>
                                
                                <div class="col-12 my-3">
                                    <label class="form-label-glass">Interactive Location Picker (Search or click to select exact spot)</label>
                                    <div class="input-group mb-2">
                                        <input type="text" id="map-search-query" class="form-control form-control-glass" placeholder="Search for a building, street, or city (e.g. Gateway of India)">
                                        <button class="btn btn-primary-gradient px-4" type="button" id="btn-map-search" onclick="searchMapLocation()"><i class="bi bi-search me-1"></i> Search Location</button>
                                    </div>
                                    
                                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                                    <div id="map-picker-container" style="height: 350px;" class="rounded-4 overflow-hidden border border-secondary mb-2 position-relative"></div>
                                    <span class="text-secondary small"><i class="bi bi-info-circle me-1"></i> Drag the marker or click anywhere on the map to pin the exact latitude and longitude.</span>
                                </div>

                                <div class="col-md-4">
                                    <label for="latitude" class="form-label-glass">Latitude</label>
                                    <input type="text" name="latitude" id="latitude" class="form-control form-control-glass font-monospace" placeholder="e.g. 18.9629" readonly value="<?php echo htmlspecialchars($edit_event['latitude'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="longitude" class="form-label-glass">Longitude</label>
                                    <input type="text" name="longitude" id="longitude" class="form-control form-control-glass font-monospace" placeholder="e.g. 72.8210" readonly value="<?php echo htmlspecialchars($edit_event['longitude'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="google_maps_link" class="form-label-glass">Google Maps Link</label>
                                    <input type="text" name="google_maps_link" id="google_maps_link" class="form-control form-control-glass small" placeholder="Auto-generated maps link" readonly value="<?php echo htmlspecialchars($edit_event['google_maps_link'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <script>
                        let mapPicker, markerPicker;
                        const defaultLat = <?php echo !empty($edit_event['latitude']) ? (float)$edit_event['latitude'] : 19.0760; ?>;
                        const defaultLng = <?php echo !empty($edit_event['longitude']) ? (float)$edit_event['longitude'] : 72.8777; ?>;

                        window.addEventListener('DOMContentLoaded', () => {
                            const container = document.getElementById('map-picker-container');
                            if (!container) return;

                            mapPicker = L.map('map-picker-container').setView([defaultLat, defaultLng], 13);
                            
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '&copy; OpenStreetMap contributors'
                            }).addTo(mapPicker);

                            markerPicker = L.marker([defaultLat, defaultLng], {
                                draggable: true
                            }).addTo(mapPicker);

                            markerPicker.on('dragend', function(e) {
                                const pos = markerPicker.getLatLng();
                                updateFormCoordinates(pos.lat, pos.lng);
                            });

                            mapPicker.on('click', function(e) {
                                markerPicker.setLatLng(e.latlng);
                                updateFormCoordinates(e.latlng.lat, e.latlng.lng);
                            });
                            
                            if (document.getElementById('latitude').value === '') {
                                updateFormCoordinates(defaultLat, defaultLng);
                            }
                        });

                        function updateFormCoordinates(lat, lng) {
                            const fixedLat = parseFloat(lat).toFixed(6);
                            const fixedLng = parseFloat(lng).toFixed(6);
                            document.getElementById('latitude').value = fixedLat;
                            document.getElementById('longitude').value = fixedLng;
                            document.getElementById('google_maps_link').value = `https://www.google.com/maps?q=${fixedLat},${fixedLng}`;
                        }

                        async function searchMapLocation() {
                            const query = document.getElementById('map-search-query').value.trim();
                            if (!query) {
                                alert("Please enter a location to search.");
                                return;
                            }

                            const btn = document.getElementById('btn-map-search');
                            btn.disabled = true;
                            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> Searching...`;

                            try {
                                const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`);
                                const data = await res.json();
                                
                                if (data && data.length > 0) {
                                    const loc = data[0];
                                    const lat = parseFloat(loc.lat);
                                    const lng = parseFloat(loc.lon);
                                    
                                    markerPicker.setLatLng([lat, lng]);
                                    mapPicker.setView([lat, lng], 15);
                                    updateFormCoordinates(lat, lng);

                                    if (loc.display_name) {
                                        const parts = loc.display_name.split(',');
                                        document.getElementById('address').value = parts.slice(0, 3).join(',').trim();
                                        
                                        const revRes = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`);
                                        const revData = await revRes.json();
                                        if (revData && revData.address) {
                                            const addr = revData.address;
                                            if (addr.city || addr.town || addr.village) {
                                                document.getElementById('city').value = addr.city || addr.town || addr.village;
                                            }
                                            if (addr.state) {
                                                document.getElementById('state').value = addr.state;
                                            }
                                            if (addr.postcode) {
                                                document.getElementById('pincode').value = addr.postcode;
                                            }
                                        }
                                    }
                                } else {
                                    alert("Location not found. Pin manually or search something else.");
                                }
                            } catch (e) {
                                console.error(e);
                                alert("Search failed. Please pin manually.");
                            } finally {
                                btn.disabled = false;
                                btn.innerHTML = `<i class="bi bi-search me-1"></i> Search Location`;
                            }
                        }
                        </script>

                        <div class="col-md-8">
                            <label for="date_time" class="form-label-glass">Event Date & Time</label>
                            <input type="datetime-local" name="date_time" id="date_time" class="form-control form-control-glass" required value="<?php echo isset($edit_event['date_time']) ? date('Y-m-d\TH:i', strtotime($edit_event['date_time'])) : ''; ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="capacity" class="form-label-glass">Total Event Capacity</label>
                            <input type="number" name="capacity" id="capacity" class="form-control form-control-glass" placeholder="e.g. 500" required value="<?php echo htmlspecialchars($edit_event['capacity'] ?? ''); ?>" <?php echo $action === 'edit' ? 'readonly style="opacity: 0.65;"' : ''; ?>>
                            <?php if ($action === 'edit'): ?><div class="form-text text-muted" style="font-size: 0.7rem;">Capacity is locked during edit.</div><?php endif; ?>
                        </div>

                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-3 border-bottom border-secondary pb-2" style="color: var(--text-primary);">Event Image / Banner</h5>
                            <div class="row g-3 align-items-start">
                                <!-- Preview box -->
                                <div class="col-md-4">
                                    <div class="img-preview-box" id="banner-preview-box">
                                        <?php if (!empty($edit_event['banner_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($edit_event['banner_image']); ?>" id="banner-preview-img" alt="Current banner">
                                            <div class="mt-2">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle small"><i class="bi bi-check-circle me-1"></i>Image set</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="img-preview-placeholder" id="banner-preview-placeholder">
                                                <i class="bi bi-image"></i>
                                                Image preview will appear here
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <!-- Upload controls -->
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="banner_file" class="form-label-glass">Upload Image File <span class="text-muted" style="font-size:0.75rem;">(JPG, PNG, WEBP — max 5MB)</span></label>
                                        <input type="file" name="banner_file" id="banner_file" class="form-control form-control-glass" accept="image/*" onchange="previewBannerImage(this)">
                                    </div>
                                    <div class="mb-3">
                                        <label for="banner_url" class="form-label-glass">Or use Image URL</label>
                                        <input type="text" name="banner_url" id="banner_url" class="form-control form-control-glass" placeholder="https://example.com/banner.jpg" value="<?php echo htmlspecialchars($edit_event['banner_image'] ?? ''); ?>" oninput="previewBannerUrl(this.value)">
                                    </div>
                                    <div class="form-text" style="color:var(--text-muted); font-size:0.78rem;">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Uploaded files are saved to <code>/uploads/events/</code>. File upload takes priority over URL.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <script>
                        function previewBannerImage(input) {
                            if (input.files && input.files[0]) {
                                const reader = new FileReader();
                                reader.onload = function(e) {
                                    showBannerPreview(e.target.result);
                                };
                                reader.readAsDataURL(input.files[0]);
                            }
                        }
                        function previewBannerUrl(url) {
                            if (url && url.startsWith('http')) {
                                showBannerPreview(url);
                            }
                        }
                        function showBannerPreview(src) {
                            const box = document.getElementById('banner-preview-box');
                            let img = document.getElementById('banner-preview-img');
                            const placeholder = document.getElementById('banner-preview-placeholder');
                            if (!img) {
                                img = document.createElement('img');
                                img.id = 'banner-preview-img';
                                img.alt = 'Banner preview';
                                box.innerHTML = '';
                                box.appendChild(img);
                            }
                            if (placeholder) placeholder.style.display = 'none';
                            img.src = src;
                            img.style.display = 'block';
                        }
                        </script>

                        <!-- Ticket Pricing Tiers -->
                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-3 border-bottom border-secondary pb-2" style="color: var(--text-primary);">Ticket Pricing Tiers</h5>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="price_general" class="form-label-glass">General Admission Price (₹)</label>
                                    <input type="number" name="price_general" id="price_general" class="form-control form-control-glass" placeholder="200" required value="<?php echo htmlspecialchars(number_format($price_general, 0, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="price_vip" class="form-label-glass">VIP Tier Price (₹)</label>
                                    <input type="number" name="price_vip" id="price_vip" class="form-control form-control-glass" placeholder="1000" required value="<?php echo htmlspecialchars(number_format($price_vip, 0, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="price_student" class="form-label-glass">Student Discount Price (₹)</label>
                                    <input type="number" name="price_student" id="price_student" class="form-control form-control-glass" placeholder="100" required value="<?php echo htmlspecialchars(number_format($price_student, 0, '.', '')); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- UPI Payment Details -->
                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-3 border-bottom border-secondary pb-2" style="color: var(--text-primary);">UPI Payment Details (Organizer Global Config)</h5>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="upi_id" class="form-label-glass">UPI ID</label>
                                    <input type="text" name="upi_id" id="upi_id" class="form-control form-control-glass" placeholder="e.g. organizer@upi" required value="<?php echo htmlspecialchars($upi_details['upi_id'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="phone_number" class="form-label-glass">UPI Phone Number</label>
                                    <input type="text" name="phone_number" id="phone_number" class="form-control form-control-glass" placeholder="e.g. 9876543210" required value="<?php echo htmlspecialchars($upi_details['phone_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="upi_qr_file" class="form-label-glass">UPI QR Code Image File <?php echo $upi_details ? '(Optional to replace)' : '(Required)'; ?></label>
                                    <input type="file" name="upi_qr_file" id="upi_qr_file" class="form-control form-control-glass" accept="image/*" <?php echo $upi_details ? '' : 'required'; ?>>
                                    <?php if ($upi_details && !empty($upi_details['qr_image'])): ?>
                                        <div class="form-text text-success small">✓ Current QR code image exists.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12">
                                    <label for="payment_instructions" class="form-label-glass">Payment Instructions (Event Specific)</label>
                                    <textarea name="payment_instructions" id="payment_instructions" class="form-control form-control-glass" rows="3" placeholder="Specify any instructions for this event, e.g. 'Please mention Event Name in notes.'"><?php echo htmlspecialchars($edit_event['payment_instructions'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-4 text-end">
                            <button type="submit" class="btn btn-primary-gradient px-5 py-3 fw-bold">
                                <i class="bi bi-save me-2"></i> Save Event & Ticket Settings
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Events list Page -->
            <div class="glass-panel p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">My Hosted Events</h4>
                        <p class="text-secondary mb-0 small">Create events and view their registration counts</p>
                    </div>
                    <a href="events.php?action=create" class="btn btn-primary-gradient px-4"><i class="bi bi-plus-circle-fill me-2"></i>Create Event</a>
                </div>

                <?php if (empty($my_events)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-calendar-range fs-1 text-secondary"></i>
                        <h5 class="mt-3" style="color: var(--text-primary);">No Events Found</h5>
                        <p class="text-secondary small">Click the "Create Event" button to set up your first event.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="background: transparent; color: var(--text-primary); border-color: var(--glass-border);">
                            <thead>
                                <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                    <th>Event details</th>
                                    <th>City & Venue</th>
                                    <th>Date</th>
                                    <th>Capacity & Sold</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                                <tbody>
                                <?php foreach ($my_events as $ev): 
                                    $ev_thumb = !empty($ev['banner_image']) ? htmlspecialchars($ev['banner_image']) : 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?auto=format&fit=crop&q=80&w=200';
                                ?>
                                    <tr style="border-bottom: 1px solid var(--glass-border);">
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <img src="<?php echo $ev_thumb; ?>" alt="" style="width:56px;height:42px;object-fit:cover;border-radius:10px;flex-shrink:0;border:1px solid var(--glass-border);">
                                                <div>
                                                    <div class="fw-bold" style="color: var(--text-primary);"><?php echo htmlspecialchars($ev['title']); ?></div>
                                                    <span class="badge badge-custom badge-indigo mt-1" style="font-size: 0.65rem;"><?php echo $ev['category']; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="color: var(--text-primary);"><?php echo htmlspecialchars($ev['venue']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($ev['city']); ?></div>
                                        </td>
                                        <td>
                                            <div class="small" style="color: var(--text-primary);"><?php echo date('M d, Y', strtotime($ev['date_time'])); ?></div>
                                            <div class="text-muted small"><?php echo date('h:i A', strtotime($ev['date_time'])); ?></div>
                                        </td>
                                        <td>
                                            <div style="color: var(--text-primary);"><?php echo $ev['tickets_qty'] ?: 0; ?> / <?php echo $ev['capacity']; ?></div>
                                            <div class="text-muted" style="font-size: 0.7rem;">Sold / Seats</div>
                                        </td>
                                        <td>
                                            <?php if ($ev['status'] === 'approved'): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php elseif ($ev['status'] === 'pending'): ?>
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            <?php elseif ($ev['status'] === 'rejected'): ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php elseif ($ev['status'] === 'cancelled'): ?>
                                                <span class="badge bg-secondary">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="events.php?action=edit&id=<?php echo $ev['id']; ?>" class="btn btn-glass btn-sm" title="Edit Event"><i class="bi bi-pencil"></i></a>
                                                <a href="registrations.php?event_id=<?php echo $ev['id']; ?>" class="btn btn-glass btn-sm text-info" title="View Registrations"><i class="bi bi-people"></i></a>
                                                <?php if ($ev['status'] !== 'cancelled'): ?>
                                                    <a href="events.php?cancel_id=<?php echo $ev['id']; ?>" onclick="return confirm('Are you sure you want to CANCEL this event? This will release all seats, trigger refunds, and notify all registered attendees.')" class="btn btn-outline-danger btn-sm" title="Cancel Event">Cancel</a>
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
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
