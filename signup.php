<?php
$page_title = "Sign Up";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';

// Redirect if already logged in
if (is_logged_in()) {
    $curr_user = get_logged_in_user();
    if ($curr_user['role'] === 'organizer') {
        header('Location: /organizer/dashboard.php');
    } elseif ($curr_user['role'] === 'admin') {
        header('Location: /admin/dashboard.php');
    } else {
        header('Location: /dashboard.php');
    }
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');

    // Validate
    if (empty($name) || empty($email) || empty($password)) {
        $error = "Name, Email and Password are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (!in_array($role, ['user', 'organizer'])) {
        $error = "Invalid role selected.";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "Email address is already registered.";
            } else {
                // Insert User
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $ins_stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, telegram_chat_id, created_at) VALUES (?, ?, ?, ?, 'active', ?, NOW())");
                $ins_stmt->execute([$name, $email, $pass_hash, $role, !empty($telegram_chat_id) ? $telegram_chat_id : null]);
                
                $user_id = $pdo->lastInsertId();
                
                // Trigger notification log & email
                notify_user_register($pdo, $user_id, $name);
                
                // Automatically log in the user
                login_user($user_id, $name, $email, $role);
                
                // Redirect based on role
                if ($role === 'organizer') {
                    header('Location: /organizer/dashboard.php');
                } else {
                    header('Location: /dashboard.php');
                }
                exit;
            }
        } catch (PDOException $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center my-4">
    <div class="col-md-6">
        <div class="glass-panel p-5">
            <h3 class="text-center fw-bold mb-4" style="color: var(--text-primary);">Create Your Account</h3>
            
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="signup.php">
                <div class="mb-3">
                    <label for="name" class="form-label-glass">Full Name</label>
                    <input type="text" name="name" id="name" class="form-control form-control-glass" placeholder="e.g. Jane Doe" required autocomplete="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label-glass">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control form-control-glass" placeholder="e.g. jane@example.com" required autocomplete="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="row mb-3">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label for="password" class="form-label-glass">Password</label>
                        <input type="password" name="password" id="password" class="form-control form-control-glass" placeholder="Min. 6 characters" required autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label for="role" class="form-label-glass">I want to register as</label>
                        <select name="role" id="role" class="form-select form-select-glass" required>
                            <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : ''; ?>>Attendee (Discover & Book)</option>
                            <option value="organizer" <?php echo (isset($_POST['role']) && $_POST['role'] === 'organizer') ? 'selected' : ''; ?>>Organizer (Manage Events)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="telegram_chat_id" class="form-label-glass">Telegram Chat ID (Optional)</label>
                    <input type="text" name="telegram_chat_id" id="telegram_chat_id" class="form-control form-control-glass" placeholder="e.g. 987654321" value="<?php echo isset($_POST['telegram_chat_id']) ? htmlspecialchars($_POST['telegram_chat_id']) : ''; ?>">
                    <div class="form-text text-muted" style="font-size: 0.75rem;">Used to receive real-time ticket confirmation notifications via Telegram Bot.</div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary-gradient py-2">Create Account</button>
                </div>
            </form>
            
            <p class="text-center small mb-0" style="color: var(--text-secondary);">
                Already have an account? <a href="login.php" class="text-decoration-none fw-semibold" style="color: var(--accent-indigo);">Sign In</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
