<?php
if (session_status() == PHP_SESSION_NONE) {
    // Set secure session parameters
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // Check if HTTPS is used
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    ini_set('session.cookie_secure', $is_secure ? 1 : 0);
    
    session_start();
}

/**
 * Checks if the user is currently logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Retrieves the current logged in user details from the session.
 */
function get_logged_in_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role']
    ];
}

/**
 * Enforces that a user is logged in. Redirects to login page if not.
 */
function require_login() {
    if (!is_logged_in()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: /login.php');
        exit;
    }
}

/**
 * Enforces that the logged in user belongs to one of the allowed roles.
 * Accepts a string role or an array of roles.
 */
function require_role($allowed_roles) {
    require_login();
    
    $user = get_logged_in_user();
    $roles = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
    
    if (!in_array($user['role'], $roles)) {
        // Redirect to access-denied page or homepage
        $_SESSION['flash_error'] = "Access denied: You do not have permission to view that page.";
        header('Location: /discover.php');
        exit;
    }
}

/**
 * Log in a user and set up session variables.
 */
function login_user($user_id, $name, $email, $role) {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;
}

/**
 * Log out the current user and destroy session variables.
 */
function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Generate CSRF token for security.
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify form CSRF token matches session.
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Helper to display flash messages.
 */
function get_flash_message($type) {
    $key = 'flash_' . $type;
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return null;
}

function set_flash_message($type, $message) {
    $_SESSION['flash_' . $type] = $message;
}
?>
