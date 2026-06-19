<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce login and organizer role
require_role('organizer');

$organizer = get_logged_in_user();
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// 1. CSV EXPORT ENGINE
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($event_id <= 0) {
        die("Invalid event ID for export.");
    }

    try {
        // Verify organizer owns the event
        $stmt_check = $pdo->prepare("SELECT title FROM events WHERE id = ? AND organizer_id = ?");
        $stmt_check->execute([$event_id, $organizer['id']]);
        $event_title = $stmt_check->fetchColumn();

        if (!$event_title) {
            die("Access denied: You do not own this event.");
        }

        // Fetch attendees
        $stmt = $pdo->prepare("SELECT b.id as booking_id, u.name as attendee_name, u.email as attendee_email, 
                                      tt.name as ticket_tier, b.quantity, b.total_price, b.status, b.created_at
                               FROM bookings b
                               JOIN users u ON b.user_id = u.id
                               JOIN tickets_types tt ON b.ticket_type_id = tt.id
                               WHERE b.event_id = ?
                               ORDER BY b.created_at DESC");
        $stmt->execute([$event_id]);
        $attendees = $stmt->fetchAll();

        // Send CSV headers
        $filename = "attendees_" . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($event_title)) . "_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Write header row
        fputcsv($output, ['Booking ID', 'Attendee Name', 'Attendee Email', 'Ticket Tier', 'Quantity', 'Total Price (₹)', 'Status', 'Booking Date']);
        
        // Write data rows
        foreach ($attendees as $row) {
            fputcsv($output, [
                '#' . $row['booking_id'],
                $row['attendee_name'],
                $row['attendee_email'],
                $row['ticket_tier'],
                $row['quantity'],
                number_format($row['total_price'], 0),
                ucfirst($row['status']),
                $row['created_at']
            ]);
        }
        
        fclose($output);
        exit;

    } catch (PDOException $e) {
        die("CSV generation failed: " . $e->getMessage());
    }
}

// 2. REGULAR VIEW ENGINE
try {
    // Fetch organizer's events for dropdown filter
    $events_stmt = $pdo->prepare("SELECT id, title FROM events WHERE organizer_id = ? ORDER BY date_time DESC");
    $events_stmt->execute([$organizer['id']]);
    $my_events = $events_stmt->fetchAll();

    // Query registrations
    $query = "SELECT b.id as booking_id, u.name as attendee_name, u.email as attendee_email, 
                     e.title as event_title, e.id as ev_id, tt.name as ticket_tier, b.quantity, 
                     b.total_price, b.status, b.created_at
              FROM bookings b
              JOIN users u ON b.user_id = u.id
              JOIN events e ON b.event_id = e.id
              JOIN tickets_types tt ON b.ticket_type_id = tt.id
              WHERE e.organizer_id = :org_id";
    
    $params = [':org_id' => $organizer['id']];

    if ($event_id > 0) {
        $query .= " AND e.id = :event_id";
        $params[':event_id'] = $event_id;
    }

    $query .= " ORDER BY b.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database query error: " . $e->getMessage());
}

$page_title = "Attendee Registrations";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Sidebar Navigation -->
    <div class="col-md-3 mb-4 no-print">
        <div class="glass-panel p-3">
            <div class="text-center py-3 mb-3 border-bottom border-secondary">
                <i class="bi bi-people-fill fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2"><?php echo htmlspecialchars($organizer['name']); ?></h5>
                <span class="badge badge-custom badge-indigo">Organizer Panel</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-pie-chart-fill"></i> Analytics Overview</a>
                <a href="events.php" class="sidebar-link"><i class="bi bi-calendar-event"></i> Manage Events</a>
                <a href="registrations.php" class="sidebar-link active"><i class="bi bi-people"></i> Registrations list</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Verify Payments</a>
                <a href="checkin.php" class="sidebar-link"><i class="bi bi-qr-code-scan"></i> QR Code Check-in</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <div class="glass-panel p-4 mb-4">
            <h4 class="fw-bold text-white mb-4">Attendee Registration Records</h4>
            
            <!-- Filter Dropdown & Export CSV Button -->
            <form action="registrations.php" method="GET" class="row g-3 align-items-end mb-4 pb-4 border-bottom border-secondary">
                <div class="col-md-6">
                    <label for="event_id" class="form-label-glass">Filter by Event</label>
                    <select name="event_id" id="event_id" class="form-select form-select-glass" onchange="this.form.submit()">
                        <option value="">All Events</option>
                        <?php foreach ($my_events as $ev): ?>
                            <option value="<?php echo $ev['id']; ?>" <?php echo $event_id === (int)$ev['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ev['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <?php if ($event_id > 0): ?>
                        <a href="registrations.php?export=csv&event_id=<?php echo $event_id; ?>" class="btn btn-secondary-gradient px-4">
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i> Export CSV Roster
                        </a>
                    <?php else: ?>
                        <div class="small text-muted mb-2">Select a specific event above to export CSV attendee rosters.</div>
                        <button class="btn btn-secondary-gradient px-4" disabled>
                            <i class="bi bi-file-earmark-spreadsheet me-2"></i> Export CSV
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($registrations)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-person-x fs-1 text-secondary"></i>
                    <h5 class="mt-3 text-white">No Registrations Found</h5>
                    <p class="text-secondary small">No purchases exist for the selected event filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                <th>Booking ID</th>
                                <th>Event Title</th>
                                <th>Attendee Details</th>
                                <th>Ticket Tier</th>
                                <th>Revenue</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $reg): ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td class="font-monospace text-white">#<?php echo $reg['booking_id']; ?></td>
                                    <td class="fw-bold text-white"><?php echo htmlspecialchars($reg['event_title']); ?></td>
                                    <td>
                                        <div class="text-white"><?php echo htmlspecialchars($reg['attendee_name']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($reg['attendee_email']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-custom badge-indigo"><?php echo $reg['ticket_tier']; ?> &times; <?php echo $reg['quantity']; ?></span>
                                    </td>
                                    <td class="font-monospace text-white">₹<?php echo number_format($reg['total_price'], 0); ?></td>
                                    <td>
                                        <?php if ($reg['status'] === 'confirmed'): ?>
                                            <span class="badge bg-success">Confirmed</span>
                                        <?php elseif ($reg['status'] === 'cancelled'): ?>
                                            <span class="badge bg-danger">Cancelled</span>
                                        <?php elseif ($reg['status'] === 'refund_requested'): ?>
                                            <span class="badge bg-warning text-dark">Refund Req</span>
                                        <?php elseif ($reg['status'] === 'refunded'): ?>
                                            <span class="badge bg-info text-dark">Refunded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo date('M d, Y', strtotime($reg['created_at'])); ?></td>
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
