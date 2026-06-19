<?php
$page_title = "Login";
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// If already logged in, redirect to respective dashboard
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            // Find user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] === 'suspended') {
                    $error = "Your account has been suspended by the administrator.";
                } else {
                    // Log in
                    login_user($user['id'], $user['name'], $user['email'], $user['role']);
                    
                    // Redirect based on role
                    $redirect_url = $_SESSION['redirect_url'] ?? '/index.php';
                    unset($_SESSION['redirect_url']);
                    
                    // Default dashboard redirects if going to index
                    if ($redirect_url === '/index.php') {
                        if ($user['role'] === 'organizer') {
                            $redirect_url = '/organizer/dashboard.php';
                        } elseif ($user['role'] === 'admin') {
                            $redirect_url = '/admin/dashboard.php';
                        } else {
                            $redirect_url = '/dashboard.php';
                        }
                    }
                    
                    header("Location: " . $redirect_url);
                    exit;
                }
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center my-5">
    <div class="col-md-5">
        <div class="glass-panel p-5">
            <h3 class="text-center fw-bold mb-4" style="color: var(--text-primary);">Welcome Back</h3>
            
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="email" class="form-label-glass">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control form-control-glass" placeholder="e.g. user@example.com" required autocomplete="email">
                </div>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label for="password" class="form-label-glass mb-0">Password</label>
                        <a href="forgot-password.php" class="text-decoration-none small" style="color: var(--accent-indigo);">Forgot Password?</a>
                    </div>
                    <input type="password" name="password" id="password" class="form-control form-control-glass" placeholder="••••••••" required autocomplete="current-password">
                </div>
                
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary-gradient py-2">Sign In</button>
                </div>
            </form>
            
            <p class="text-center small mb-0" style="color: var(--text-secondary);">
                Don't have an account? <a href="signup.php" class="text-decoration-none fw-semibold" style="color: var(--accent-indigo);">Sign Up</a>
            </p>
            

        </div>
    </div>
</div>



<?php require_once __DIR__ . '/includes/footer.php'; ?>
