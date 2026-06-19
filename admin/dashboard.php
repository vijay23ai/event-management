<?php
$page_title = "Admin Dashboard";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';

// Enforce login and admin role
require_role('admin');

$admin = get_logged_in_user();
$error = '';
$success = '';

// 1. Process Event Approval Actions
if (isset($_GET['approve_id'])) {
    $ev_id = (int)$_GET['approve_id'];
    try {
        $stmt = $pdo->prepare("SELECT e.*, u.name as org_name, u.email as org_email FROM events e JOIN users u ON e.organizer_id = u.id WHERE e.id = ?");
        $stmt->execute([$ev_id]);
        $ev = $stmt->fetch();
        
        if ($ev) {
            $up_stmt = $pdo->prepare("UPDATE events SET status = 'approved' WHERE id = ?");
            $up_stmt->execute([$ev_id]);
            
            // Log notification for organizer
            send_notification($pdo, $ev['organizer_id'], "Event Approved!", "Your event '{$ev['title']}' has been reviewed and approved by the administrator. It is now active for discovery and booking.", 'email');
            send_notification($pdo, $ev['organizer_id'], "Event Approved!", "Your event '{$ev['title']}' has been approved.", 'system');

            set_flash_message('success', "Event '{$ev['title']}' approved successfully.");
        }
    } catch (PDOException $e) {
        set_flash_message('error', "Failed to approve event: " . $e->getMessage());
    }
    header('Location: dashboard.php');
    exit;
}

if (isset($_GET['reject_id'])) {
    $ev_id = (int)$_GET['reject_id'];
    try {
        $stmt = $pdo->prepare("SELECT e.* FROM events e WHERE e.id = ?");
        $stmt->execute([$ev_id]);
        $ev = $stmt->fetch();
        
        if ($ev) {
            $up_stmt = $pdo->prepare("UPDATE events SET status = 'rejected' WHERE id = ?");
            $up_stmt->execute([$ev_id]);

            // Notify organizer
            send_notification($pdo, $ev['organizer_id'], "Event Rejected", "We regret to inform you that your event '{$ev['title']}' was not approved and has been rejected.", 'email');
            
            set_flash_message('success', "Event '{$ev['title']}' rejected.");
        }
    } catch (PDOException $e) {
        set_flash_message('error', "Failed to reject event: " . $e->getMessage());
    }
    header('Location: dashboard.php');
    exit;
}

// 2. Process User Suspension Actions
if (isset($_GET['toggle_user_id'])) {
    $user_id = (int)$_GET['toggle_user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $target_user = $stmt->fetch();
        
        if ($target_user && $target_user['role'] !== 'admin') {
            $new_status = $target_user['status'] === 'active' ? 'suspended' : 'active';
            $up_stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $up_stmt->execute([$new_status, $user_id]);
            
            set_flash_message('success', "Account status for '{$target_user['name']}' set to {$new_status}.");
        }
    } catch (PDOException $e) {
        set_flash_message('error', "Failed to update user status: " . $e->getMessage());
    }
    header('Location: dashboard.php#users-section');
    exit;
}

// 3. Process Notification Settings Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $resend_api_key = trim($_POST['resend_api_key'] ?? '');
    $telegram_bot_token = trim($_POST['telegram_bot_token'] ?? '');
    $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'resend_api_key'");
        $stmt->execute([$resend_api_key]);

        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'telegram_bot_token'");
        $stmt->execute([$telegram_bot_token]);

        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'telegram_chat_id'");
        $stmt->execute([$telegram_chat_id]);

        $success = "System settings updated successfully.";
    } catch (PDOException $e) {
        $error = "Failed to update settings: " . $e->getMessage();
    }
}

// 4. Fetch System-wide Metrics
try {
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_organizers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'organizer'")->fetchColumn();
    $total_events = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $total_bookings = $pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    $total_revenue = $pdo->query("SELECT SUM(total_price) FROM bookings WHERE status = 'confirmed'")->fetchColumn() ?: 0;

    // Fetch Pending Events list
    $pending_stmt = $pdo->query("SELECT e.*, u.name as org_name FROM events e JOIN users u ON e.organizer_id = u.id WHERE e.status = 'pending' ORDER BY e.created_at ASC");
    $pending_events = $pending_stmt->fetchAll();

    // Fetch All Users list
    $users_stmt = $pdo->query("SELECT * FROM users ORDER BY role ASC, created_at DESC");
    $all_users = $users_stmt->fetchAll();

    // Fetch Settings
    $sett_stmt = $pdo->query("SELECT * FROM system_settings");
    $settings = $sett_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch Chart Data: Events per Category
    $cat_stmt = $pdo->query("SELECT category, COUNT(*) as count FROM events GROUP BY category");
    $category_dist = $cat_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch Chart Data: Daily Bookings Trend (Last 7 days)
    $daily_bookings = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $bk_stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = ?");
        $bk_stmt->execute([$date]);
        $qty = $bk_stmt->fetchColumn() ?: 0;
        $daily_bookings[$date] = $qty;
    }

} catch (PDOException $e) {
    die("Database stats query failed: " . $e->getMessage());
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
                <i class="bi bi-shield-check fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2">Admin Portal</h5>
                <span class="badge badge-custom badge-indigo">System Administrator</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link active"><i class="bi bi-shield-lock"></i> Controls & Settings</a>
                <a href="refunds.php" class="sidebar-link"><i class="bi bi-currency-rupee"></i> Manage Refunds</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Manage Payments</a>
                <a href="notifications.php" class="sidebar-link"><i class="bi bi-list-columns"></i> Notification Logs</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <!-- System Stats Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-2">
                <div class="glass-panel stat-card text-center h-100" style="padding: 16px;">
                    <div class="stat-icon bg-indigo mx-auto text-indigo" style="background: rgba(99,102,241,0.15); width: 36px; height: 36px; font-size: 1.25rem;"><i class="bi bi-people"></i></div>
                    <div class="text-secondary" style="font-size: 0.8rem;">Total Users</div>
                    <div class="fs-4 fw-bold text-white"><?php echo $total_users; ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="glass-panel stat-card text-center h-100" style="padding: 16px;">
                    <div class="stat-icon bg-indigo mx-auto text-indigo" style="background: rgba(99,102,241,0.15); width: 36px; height: 36px; font-size: 1.25rem;"><i class="bi bi-person-badge"></i></div>
                    <div class="text-secondary" style="font-size: 0.8rem;">Organizers</div>
                    <div class="fs-4 fw-bold text-white"><?php echo $total_organizers; ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="glass-panel stat-card text-center h-100" style="padding: 16px;">
                    <div class="stat-icon bg-indigo mx-auto text-indigo" style="background: rgba(99,102,241,0.15); width: 36px; height: 36px; font-size: 1.25rem;"><i class="bi bi-calendar-check"></i></div>
                    <div class="text-secondary" style="font-size: 0.8rem;">Events</div>
                    <div class="fs-4 fw-bold text-white"><?php echo $total_events; ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="glass-panel stat-card text-center h-100" style="padding: 16px;">
                    <div class="stat-icon bg-indigo mx-auto text-indigo" style="background: rgba(99,102,241,0.15); width: 36px; height: 36px; font-size: 1.25rem;"><i class="bi bi-cart"></i></div>
                    <div class="text-secondary" style="font-size: 0.8rem;">Bookings</div>
                    <div class="fs-4 fw-bold text-white"><?php echo $total_bookings; ?></div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="glass-panel stat-card text-center h-100" style="background: rgba(16,185,129,0.08); border-color: rgba(16,185,129,0.15); padding: 16px;">
                    <div class="stat-icon bg-success mx-auto text-success" style="background: rgba(16,185,129,0.15); width: 36px; height: 36px; font-size: 1.25rem;"><i class="bi bi-currency-rupee"></i></div>
                    <div class="text-secondary" style="font-size: 0.8rem;">Platform Gross Revenue</div>
                    <div class="fs-4 fw-bold text-white">₹<?php echo number_format($total_revenue, 0); ?></div>
                </div>
            </div>
        </div>

        <!-- Admin Charts Row -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="glass-panel p-4">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-graph-up text-indigo me-2"></i>Platform Booking Activity (Last 7 Days)</h5>
                    <div style="height: 250px; position: relative;">
                        <canvas id="bookingsTrendChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="glass-panel p-4">
                    <h5 class="fw-bold text-white mb-3"><i class="bi bi-pie-chart text-indigo me-2"></i>Event Categories</h5>
                    <div style="height: 250px; position: relative;">
                        <canvas id="categoryDistChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Approvals Module -->
        <div class="glass-panel p-4 mb-4">
            <h4 class="fw-bold text-white mb-3"><i class="bi bi-shield-exclamation text-indigo me-2"></i>Pending Event Approvals</h4>
            <?php if (empty($pending_events)): ?>
                <p class="text-secondary small py-3 mb-0 text-center"><i class="bi bi-check-circle me-1"></i> All events reviewed. No pending approvals.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small">
                                <th>Event Title</th>
                                <th>Organizer</th>
                                <th>Category</th>
                                <th>Date & Location</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_events as $pev): ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td class="fw-bold text-white"><?php echo htmlspecialchars($pev['title']); ?></td>
                                    <td><?php echo htmlspecialchars($pev['org_name']); ?></td>
                                    <td><span class="badge badge-custom badge-indigo"><?php echo $pev['category']; ?></span></td>
                                    <td>
                                        <div class="small text-white"><?php echo date('M d, Y', strtotime($pev['date_time'])); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($pev['city']); ?></div>
                                    </td>
                                    <td class="text-end">
                                        <a href="dashboard.php?approve_id=<?php echo $pev['id']; ?>" class="btn btn-success btn-sm me-1"><i class="bi bi-check-lg"></i> Approve</a>
                                        <a href="dashboard.php?reject_id=<?php echo $pev['id']; ?>" onclick="return confirm('Reject this event?')" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Reject</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- User Access Control Module -->
        <div class="glass-panel p-4 mb-4" id="users-section">
            <h4 class="fw-bold text-white mb-3"><i class="bi bi-people text-indigo me-2"></i>User Access Control</h4>
            <div class="table-responsive" style="max-height: 350px; overflow-y: auto;">
                <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-secondary small">
                            <th>Name</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-end">Access Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $u): ?>
                            <tr style="border-bottom: 1px solid var(--glass-border);">
                                <td class="fw-bold text-white"><?php echo htmlspecialchars($u['name']); ?></td>
                                <td class="small font-monospace"><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <span class="badge badge-custom <?php echo $u['role'] === 'admin' ? 'badge-sky' : 'badge-indigo'; ?> text-uppercase">
                                        <?php echo $u['role']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="text-muted small">Protected</span>
                                    <?php else: ?>
                                        <a href="dashboard.php?toggle_user_id=<?php echo $u['id']; ?>" class="btn <?php echo $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-success'; ?> btn-sm">
                                            <?php echo $u['status'] === 'active' ? '<i class="bi bi-slash-circle"></i> Suspend' : '<i class="bi bi-check-circle"></i> Activate'; ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Credentials System Settings Module -->
        <div class="glass-panel p-4">
            <h4 class="fw-bold text-white mb-3"><i class="bi bi-gear-fill text-indigo me-2"></i>Notification APIs Configuration</h4>
            <p class="text-secondary small mb-4">Set up active api key environments. Leaving these blank fallbacks to simulated offline notification logging (`notifications.log`).</p>
            
            <?php if ($error && isset($_POST['save_settings'])): ?>
                <div class="alert alert-danger border-0 text-white mb-3" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success && isset($_POST['save_settings'])): ?>
                <div class="alert alert-success border-0 text-white mb-3" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="save_settings" value="1">
                
                <div class="mb-3">
                    <label for="resend_api_key" class="form-label-glass">Resend Mail API Key</label>
                    <input type="password" name="resend_api_key" id="resend_api_key" class="form-control form-control-glass font-monospace" placeholder="re_..." value="<?php echo htmlspecialchars($settings['resend_api_key'] ?? ''); ?>">
                    <div class="form-text text-muted" style="font-size: 0.75rem;">Used to send transactional emails via resend API.</div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label for="telegram_bot_token" class="form-label-glass">Telegram Bot Token</label>
                        <input type="password" name="telegram_bot_token" id="telegram_bot_token" class="form-control form-control-glass font-monospace" placeholder="123456789:ABC..." value="<?php echo htmlspecialchars($settings['telegram_bot_token'] ?? ''); ?>">
                        <div class="form-text text-muted" style="font-size: 0.75rem;">Bot token from BotFather.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="telegram_chat_id" class="form-label-glass">Telegram Admin Chat ID (Default Alert)</label>
                        <input type="text" name="telegram_chat_id" id="telegram_chat_id" class="form-control form-control-glass font-monospace" placeholder="e.g. 987654321" value="<?php echo htmlspecialchars($settings['telegram_chat_id'] ?? ''); ?>">
                        <div class="form-text text-muted" style="font-size: 0.75rem;">Default admin chat ID to log global notifications.</div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary-gradient px-4 py-2">Update Credentials Settings</button>
            </form>
        </div>
    </div>
</div>

<script>
// Chart.js Theme Configurations
Chart.defaults.color = '#9ca3af';
Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';

// 1. Bookings Activity Chart
var ctxTrend = document.getElementById('bookingsTrendChart').getContext('2d');
new Chart(ctxTrend, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($daily_bookings)); ?>,
        datasets: [{
            label: 'Bookings',
            data: <?php echo json_encode(array_values($daily_bookings)); ?>,
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

// 2. Category Distribution Chart
var ctxDist = document.getElementById('categoryDistChart').getContext('2d');
new Chart(ctxDist, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($category_dist)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($category_dist)); ?>,
            backgroundColor: ['#6366f1', '#0ea5e9', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6'],
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
