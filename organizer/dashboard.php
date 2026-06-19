<?php
$page_title = "Organizer Dashboard";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce login and organizer role
require_role('organizer');

$organizer = get_logged_in_user();

try {
    // 1. Fetch Key Metrics
    // Total Events
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = ?");
    $stmt->execute([$organizer['id']]);
    $total_events = $stmt->fetchColumn();

    // Total Tickets Sold
    $stmt = $pdo->prepare("SELECT SUM(b.quantity) 
                           FROM bookings b 
                           JOIN events e ON b.event_id = e.id 
                           WHERE e.organizer_id = ? AND b.status = 'confirmed'");
    $stmt->execute([$organizer['id']]);
    $tickets_sold = $stmt->fetchColumn() ?: 0;

    // Revenue Generated
    $stmt = $pdo->prepare("SELECT SUM(b.total_price) 
                           FROM bookings b 
                           JOIN events e ON b.event_id = e.id 
                           WHERE e.organizer_id = ? AND b.status = 'confirmed'");
    $stmt->execute([$organizer['id']]);
    $total_revenue = $stmt->fetchColumn() ?: 0;

    // Upcoming Events
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = ? AND date_time > NOW() AND status = 'approved'");
    $stmt->execute([$organizer['id']]);
    $upcoming_events = $stmt->fetchColumn();

    // 2. Fetch Chart Data: Ticket Distribution (General vs VIP vs Student)
    $stmt = $pdo->prepare("SELECT tt.name, SUM(b.quantity) as sold 
                           FROM bookings b 
                           JOIN tickets_types tt ON b.ticket_type_id = tt.id 
                           JOIN events e ON b.event_id = e.id 
                           WHERE e.organizer_id = ? AND b.status = 'confirmed' 
                           GROUP BY tt.name");
    $stmt->execute([$organizer['id']]);
    $distribution_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $distribution_data = [
        'General' => $distribution_raw['General'] ?? 0,
        'VIP' => $distribution_raw['VIP'] ?? 0,
        'Student' => $distribution_raw['Student'] ?? 0
    ];

    // 3. Fetch Chart Data: Revenue per Event
    $stmt = $pdo->prepare("SELECT e.title, SUM(b.total_price) as rev 
                           FROM bookings b 
                           JOIN events e ON b.event_id = e.id 
                           WHERE e.organizer_id = ? AND b.status = 'confirmed' 
                           GROUP BY e.id 
                           ORDER BY rev DESC LIMIT 5");
    $stmt->execute([$organizer['id']]);
    $revenue_events = $stmt->fetchAll();

    // 4. Fetch Chart Data: Daily Sales trend (Last 7 days)
    $daily_sales = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $stmt = $pdo->prepare("SELECT SUM(b.quantity) 
                               FROM bookings b 
                               JOIN events e ON b.event_id = e.id 
                               WHERE e.organizer_id = ? AND b.status = 'confirmed' AND DATE(b.created_at) = ?");
        $stmt->execute([$organizer['id'], $date]);
        $qty = $stmt->fetchColumn() ?: 0;
        $daily_sales[$date] = $qty;
    }

    // 5. Fetch Upcoming Organizer Events List
    $stmt = $pdo->prepare("SELECT * FROM events WHERE organizer_id = ? ORDER BY date_time ASC LIMIT 5");
    $stmt->execute([$organizer['id']]);
    $events_list = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database query error: " . $e->getMessage());
}

// Load Chart.js library
$additional_styles = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Sidebar Navigation -->
    <div class="col-md-3 mb-4 no-print">
        <div class="glass-panel p-3">
            <div class="text-center py-3 mb-3 border-bottom border-secondary">
                <i class="bi bi-speedometer2 fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2">Organizer Dashboard</h5>
                <span class="badge badge-custom badge-indigo">Control Panel</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link active"><i class="bi bi-pie-chart-fill"></i> Analytics Overview</a>
                <a href="events.php" class="sidebar-link"><i class="bi bi-calendar-event"></i> Manage Events</a>
                <a href="registrations.php" class="sidebar-link"><i class="bi bi-people"></i> Registrations list</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Verify Payments</a>
                <a href="checkin.php" class="sidebar-link"><i class="bi bi-qr-code-scan"></i> QR Code Check-in</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="glass-panel stat-card text-center h-100" style="background: rgba(99,102,241,0.08); border-color: rgba(99,102,241,0.15);">
                    <div class="stat-icon bg-indigo mx-auto text-indigo" style="background: rgba(99,102,241,0.15); color: #818cf8 !important;"><i class="bi bi-calendar4-event"></i></div>
                    <div class="text-muted small">Total Events</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $total_events; ?></div>
                </div>
            </div>
            
            <div class="col-6 col-lg-3">
                <div class="glass-panel stat-card text-center h-100" style="background: rgba(14,165,233,0.08); border-color: rgba(14,165,233,0.15);">
                    <div class="stat-icon bg-sky mx-auto text-sky" style="background: rgba(14,165,233,0.15); color: #38bdf8 !important;"><i class="bi bi-ticket-perforated"></i></div>
                    <div class="text-muted small">Tickets Sold</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $tickets_sold; ?></div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="glass-panel stat-card text-center h-100" style="background: rgba(16,185,129,0.08); border-color: rgba(16,185,129,0.15);">
                    <div class="stat-icon bg-success mx-auto text-success" style="background: rgba(16,185,129,0.15); color: #34d399 !important;"><i class="bi bi-currency-rupee"></i></div>
                    <div class="text-muted small">Revenue</div>
                    <div class="fs-3 fw-bold text-white">₹<?php echo number_format($total_revenue, 0); ?></div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="glass-panel stat-card text-center h-100" style="background: rgba(139,92,246,0.08); border-color: rgba(139,92,246,0.15);">
                    <div class="stat-icon bg-violet mx-auto text-violet" style="background: rgba(139,92,246,0.15); color: #a78bfa !important;"><i class="bi bi-clock-history"></i></div>
                    <div class="text-muted small">Upcoming Approved</div>
                    <div class="fs-3 fw-bold text-white"><?php echo $upcoming_events; ?></div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="glass-panel p-4">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-graph-up text-indigo me-2"></i>Daily Sales Trend (Last 7 Days)</h5>
                    <div style="height: 250px; position: relative;">
                        <canvas id="salesTrendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="glass-panel p-4">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-pie-chart text-indigo me-2"></i>Ticket Distribution</h5>
                    <div style="height: 250px; position: relative;">
                        <canvas id="ticketDistChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="glass-panel p-4 h-100">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-bar-chart-line text-indigo me-2"></i>Revenue per Event (Top 5)</h5>
                    <div style="height: 250px; position: relative;">
                        <canvas id="revenueEventChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="glass-panel p-4 h-100">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-list-task text-indigo me-2"></i>Events Overview</h5>
                    <?php if (empty($events_list)): ?>
                        <p class="text-secondary small text-center py-5">No events created yet. Start organizing today!</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($events_list as $ev): ?>
                                <div class="d-flex justify-content-between align-items-center p-3 rounded-4 bg-dark" style="background: rgba(15,23,42,0.4) !important; border: 1px solid var(--glass-border);">
                                    <div>
                                        <div class="fw-bold text-white small"><?php echo htmlspecialchars($ev['title']); ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-calendar3 me-1"></i><?php echo date('M d, Y', strtotime($ev['date_time'])) . ' &bull; ' . htmlspecialchars($ev['city']); ?></div>
                                    </div>
                                    <div>
                                        <?php if ($ev['status'] === 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success" style="font-size: 0.65rem;">Approved</span>
                                        <?php elseif ($ev['status'] === 'pending'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning" style="font-size: 0.65rem;">Pending Approval</span>
                                        <?php elseif ($ev['status'] === 'cancelled'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger" style="font-size: 0.65rem;">Cancelled</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Chart.js Theme Configurations
Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';

// 1. Daily Sales Trend Chart
var ctxTrend = document.getElementById('salesTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($daily_sales)); ?>,
        datasets: [{
            label: 'Tickets Sold',
            data: <?php echo json_encode(array_values($daily_sales)); ?>,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99, 102, 241, 0.15)',
            borderWidth: 3,
            fill: true,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// 2. Ticket Distribution Chart
var ctxDist = document.getElementById('ticketDistChart').getContext('2d');
new Chart(ctxDist, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($distribution_data)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($distribution_data)); ?>,
            backgroundColor: ['#6366f1', '#0ea5e9', '#f59e0b'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12 } }
        }
    }
});

// 3. Revenue Per Event Chart
var ctxRev = document.getElementById('revenueEventChart').getContext('2d');
new Chart(ctxRev, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($revenue_events, 'title')); ?>,
        datasets: [{
            label: 'Revenue (₹)',
            data: <?php echo json_encode(array_column($revenue_events, 'rev')); ?>,
            backgroundColor: 'rgba(14, 165, 233, 0.7)',
            borderColor: '#0ea5e9',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += '₹' + context.parsed.y.toLocaleString('en-IN');
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: { 
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₹' + value;
                    }
                }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
