<?php
$config_path = __DIR__ . '/../config.php';

if (!file_exists($config_path)) {
    // If not on the installer page itself, redirect
    $current_script = basename($_SERVER['SCRIPT_NAME']);
    if ($current_script !== 'install.php') {
        header('Location: /install.php');
        exit;
    }
} else {
    require_once $config_path;
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Handle connection failure, redirect to installer or show message
        $current_script = basename($_SERVER['SCRIPT_NAME']);
        if ($current_script !== 'install.php') {
            die("Database connection failed. Please run <a href='/install.php'>install.php</a>. Error: " . htmlspecialchars($e->getMessage()));
        }
    }
}
?>
