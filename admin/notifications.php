<?php
$page_title = "Notification System Logs";
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce login and admin role
require_role('admin');

$admin = get_logged_in_user();

$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

try {
    // Build dynamic query
    $query = "SELECT n.*, u.name as recipient_name, u.email as recipient_email 
              FROM notifications n 
              LEFT JOIN users u ON n.user_id = u.id";
    
    $conditions = [];
    $params = [];

    if (!empty($filter_type)) {
        $conditions[] = "n.type = :type";
        $params[':type'] = $filter_type;
    }

    if (!empty($filter_status)) {
        $conditions[] = "n.status = :status";
        $params[':status'] = $filter_status;
    }

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY n.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

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
                <i class="bi bi-list-columns fs-1 text-indigo"></i>
                <h5 class="fw-bold text-white mt-2">Admin Portal</h5>
                <span class="badge badge-custom badge-indigo">System Administrator</span>
            </div>
            
            <div class="d-flex flex-column gap-1">
                <a href="dashboard.php" class="sidebar-link"><i class="bi bi-shield-lock"></i> Controls & Settings</a>
                <a href="refunds.php" class="sidebar-link"><i class="bi bi-currency-dollar"></i> Manage Refunds</a>
                <a href="payments.php" class="sidebar-link"><i class="bi bi-credit-card"></i> Manage Payments</a>
                <a href="notifications.php" class="sidebar-link active"><i class="bi bi-list-columns"></i> Notification Logs</a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="col-md-9">
        <div class="glass-panel p-4">
            <h4 class="fw-bold text-white mb-2"><i class="bi bi-envelope-paper-heart text-indigo me-2"></i>System Notification Logs</h4>
            <p class="text-secondary small mb-4">Monitor outbound transactional alerts, message payloads, and channel routing diagnostics.</p>

            <!-- Filter Controls -->
            <form action="notifications.php" method="GET" class="row g-3 mb-4 pb-4 border-bottom border-secondary">
                <div class="col-md-4">
                    <label for="type" class="form-label-glass">Filter Channel Type</label>
                    <select name="type" id="type" class="form-select form-select-glass" onchange="this.form.submit()">
                        <option value="">All Channels</option>
                        <option value="email" <?php echo $filter_type === 'email' ? 'selected' : ''; ?>>Email</option>
                        <option value="telegram" <?php echo $filter_type === 'telegram' ? 'selected' : ''; ?>>Telegram</option>
                        <option value="system" <?php echo $filter_type === 'system' ? 'selected' : ''; ?>>System</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="status" class="form-label-glass">Filter Status</label>
                    <select name="status" id="status" class="form-select form-select-glass" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent / Logged</option>
                        <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-4 text-end d-flex align-items-end justify-content-end mt-3 mt-md-0">
                    <a href="notifications.php" class="btn btn-glass btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset Filters</a>
                </div>
            </form>

            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-bell-slash fs-1 text-secondary"></i>
                    <h5 class="mt-3 text-white">No Logs Found</h5>
                    <p class="text-secondary small mb-0">No matching notification entries exist in the logging records.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-dark table-hover border-secondary align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-secondary small" style="border-bottom: 2px solid var(--glass-border);">
                                <th>Log ID</th>
                                <th>Recipient Info</th>
                                <th>Channel</th>
                                <th>Notification Title</th>
                                <th>Message Content Preview</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr style="border-bottom: 1px solid var(--glass-border);">
                                    <td class="font-monospace text-white" style="font-size: 0.8rem;">#<?php echo $log['id']; ?></td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <div class="text-white small fw-bold"><?php echo htmlspecialchars($log['recipient_name']); ?></div>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($log['recipient_email']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small">Global System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['type'] === 'email'): ?>
                                            <span class="badge bg-primary text-uppercase" style="font-size: 0.65rem;"><i class="bi bi-envelope"></i> Email</span>
                                        <?php elseif ($log['type'] === 'telegram'): ?>
                                            <span class="badge bg-info text-dark text-uppercase" style="font-size: 0.65rem;"><i class="bi bi-telegram"></i> Telegram</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-uppercase" style="font-size: 0.65rem;"><i class="bi bi-cpu"></i> System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-white small fw-bold" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['title']); ?>">
                                        <?php echo htmlspecialchars($log['title']); ?>
                                    </td>
                                    <td class="text-secondary small" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['message']); ?>">
                                        <?php echo htmlspecialchars($log['message']); ?>
                                    </td>
                                    <td>
                                        <?php if ($log['status'] === 'sent'): ?>
                                            <span class="badge bg-success" style="font-size: 0.65rem;">Sent</span>
                                        <?php elseif ($log['status'] === 'failed'): ?>
                                            <span class="badge bg-danger" style="font-size: 0.65rem;">Failed</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted" style="font-size: 0.7rem;"><?php echo date('M d H:i:s', strtotime($log['created_at'])); ?></td>
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
