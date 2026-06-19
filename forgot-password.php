<?php
$page_title = "Forgot Password";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';

$error = '';
$success = '';
$recovery_link = '';

$email = trim($_GET['email'] ?? '');
$token = trim($_GET['token'] ?? '');
$show_reset_form = false;

if (!empty($email) && !empty($token)) {
    // Validate the token against the database
    try {
        $tok_stmt = $pdo->prepare("SELECT id FROM password_reset_tokens WHERE email = ? AND token = ? AND expires_at > NOW() LIMIT 1");
        $tok_stmt->execute([$email, $token]);
        $show_reset_form = ($tok_stmt->fetch() !== false);
    } catch (PDOException $e) {
        // Table may not exist yet — will be created on first reset request
        $show_reset_form = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_reset'])) {
        // Step 1: Request Reset Link
        $email_input = trim($_POST['email'] ?? '');
        
        if (empty($email_input)) {
            $error = "Please enter your email address.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email_input]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Ensure the reset tokens table exists
                    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(200) NOT NULL,
                        token VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_email_token (email, token)
                    ) ENGINE=InnoDB");

                    // Invalidate any previous tokens for this email
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?")->execute([$email_input]);

                    // Generate a secure random token (64 hex chars)
                    $token_val = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    $ins_tok = $pdo->prepare("INSERT INTO password_reset_tokens (email, token, expires_at) VALUES (?, ?, ?)");
                    $ins_tok->execute([$email_input, $token_val, $expires_at]);

                    $recovery_link = "https://vmp-event-management.infinityfree.me/forgot-password.php?email=" . urlencode($email_input) . "&token=" . $token_val;
                    
                    // Dispatch notification log
                    $msg = "A password reset request was made for your account. Click the following link to reset your password: " . $recovery_link;
                    send_notification($pdo, $user['id'], "Password Reset Request", $msg, 'email');
                    
                    $success = "A password reset link has been generated and logged in the system notifications.";
                } else {
                    $error = "No account found with that email address.";
                }
            } catch (PDOException $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        // Step 2: Perform Reset
        $email_input = trim($_POST['email'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email_input]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $up_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $up_stmt->execute([$new_hash, $user['id']]);
                    
                    // Log system notification
                    send_notification($pdo, $user['id'], "Password Changed Successfully", "Your password has been successfully updated. You can now log in with your new credentials.", 'system');
                    
            // Once reset is successful, delete the used token
                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?")->execute([$email_input]);

                    $success = "Password updated successfully! Redirecting to login...";
                    header("refresh:2;url=/login.php");
                } else {
                    $error = "Invalid request or account not found.";
                }
            } catch (PDOException $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center my-5">
    <div class="col-md-6">
        <div class="glass-panel p-5">
            <?php if ($show_reset_form): ?>
                <h3 class="text-center fw-bold mb-4">Set New Password</h3>
                <p class="text-secondary text-center mb-4">Resetting password for: <strong><?php echo htmlspecialchars($email); ?></strong></p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success border-0 text-white" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <input type="hidden" name="reset_password" value="1">
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label-glass">New Password</label>
                        <input type="password" name="new_password" id="new_password" class="form-control form-control-glass" placeholder="Min. 6 characters" required autocomplete="new-password">
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label-glass">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-glass" placeholder="Repeat new password" required autocomplete="new-password">
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary-gradient py-2">Update Password</button>
                    </div>
                </form>
            <?php else: ?>
                <h3 class="text-center fw-bold mb-3">Recover Password</h3>
                <p class="text-secondary text-center mb-4">Enter your email and we'll log a recovery link for you to click.</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success border-0 text-white mb-3" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                    </div>
                    
                    <?php if ($recovery_link): ?>
                        <div class="alert alert-info border-0 text-white p-3 font-monospace small mb-4" style="background: rgba(99,102,241,0.15); border: 1px solid rgba(99,102,241,0.3) !important;">
                            <div class="fw-bold mb-2"><i class="bi bi-link-45deg me-1"></i>Password Reset Link:</div>
                            <a href="<?php echo $recovery_link; ?>" class="text-info text-break"><?php echo $recovery_link; ?></a>
                            <div class="text-secondary mt-2" style="font-size:0.7rem;">This link expires in 1 hour. Copy and open it to set your new password.</div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <form method="POST">
                    <input type="hidden" name="request_reset" value="1">
                    
                    <div class="mb-4">
                        <label for="email" class="form-label-glass">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control form-control-glass" placeholder="e.g. user@example.com" required autocomplete="email">
                    </div>
                    
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary-gradient py-2">Generate Reset Link</button>
                    </div>
                </form>
                
                <p class="text-center text-secondary small mb-0">
                    Remembered credentials? <a href="login.php" class="text-indigo text-decoration-none" style="color: #a5b4fc;">Back to Sign In</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
