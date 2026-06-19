<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = get_logged_in_user();
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
        })();
    </script>
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . " | Event Portal" : "City-Wide Event Management Portal"; ?></title>
    <!-- Google Fonts: Poppins & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css">
    <!-- AOS CSS -->
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Additional Styles if set -->
    <?php echo isset($additional_styles) ? $additional_styles : ''; ?>
</head>
<body style="font-family: 'Inter', sans-serif;">
    <nav class="navbar navbar-expand-lg navbar-light glass-nav sticky-top no-print py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/index.php">
                <i class="bi bi-calendar-event-fill me-2 fs-4 text-indigo"></i>
                <span class="fw-bold">EVENTIFY</span>
            </a>
            
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 align-items-lg-center">
                    <?php if ($current_page === 'index.php'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="#hero">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#about">About</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#categories">Categories</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#events">Events</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#gallery">Gallery</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#organizers">Organizers</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#reviews">Reviews</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#faq">FAQ</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#contact">Contact</a>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <a class="btn btn-glass btn-sm rounded-pill px-3" href="/discover.php">Explore Events</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/index.php">Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'discover.php' ? 'active' : ''; ?>" href="/discover.php">Discover Events</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'calendar.php' ? 'active' : ''; ?>" href="/calendar.php">Calendar View</a>
                        </li>
                        
                        <?php if ($user): ?>
                            <?php if ($user['role'] === 'user'): ?>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>" href="/dashboard.php">My Bookings</a>
                                </li>
                            <?php elseif ($user['role'] === 'organizer'): ?>
                                <li class="nav-item border-start border-light ps-3 ms-2 d-none d-lg-block"></li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $current_page === 'dashboard.php' && strpos($_SERVER['REQUEST_URI'], '/organizer/') !== false ? 'active' : ''; ?>" href="/organizer/dashboard.php">Organizer Panel</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="/organizer/events.php">Manage Events</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="/organizer/checkin.php">Attendance Check-in</a>
                                </li>
                            <?php elseif ($user['role'] === 'admin'): ?>
                                <li class="nav-item border-start border-light ps-3 ms-2 d-none d-lg-block"></li>
                                <li class="nav-item">
                                    <a class="nav-link" href="/admin/dashboard.php">Admin Panel</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="/admin/refunds.php">Refunds</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="/admin/notifications.php">Notifications Logs</a>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Theme Toggle Button -->
                    <button class="theme-toggle-btn" id="themeToggleBtn" title="Toggle Dark/Light Mode" type="button">
                        <i class="bi bi-moon-fill" id="themeToggleIcon"></i>
                    </button>
                    <?php if ($user): ?>
                        <div class="dropdown">
                            <button class="btn btn-glass dropdown-toggle d-flex align-items-center gap-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle fs-5"></i>
                                <span><?php echo htmlspecialchars($user['name']); ?></span>
                                <span class="badge badge-custom badge-indigo text-uppercase ms-1" style="font-size: 0.65rem;"><?php echo $user['role']; ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end border-light bg-white shadow" aria-labelledby="userDropdown">
                                <?php if ($user['role'] === 'user'): ?>
                                    <li><a class="dropdown-item py-2 text-dark" href="/dashboard.php"><i class="bi bi-ticket-detailed me-2 text-indigo"></i> My Tickets</a></li>
                                 <?php elseif ($user['role'] === 'organizer'): ?>
                                    <li><a class="dropdown-item py-2 text-dark" href="/organizer/dashboard.php"><i class="bi bi-speedometer2 me-2 text-indigo"></i> Dashboard</a></li>
                                <?php elseif ($user['role'] === 'admin'): ?>
                                    <li><a class="dropdown-item py-2 text-dark" href="/admin/dashboard.php"><i class="bi bi-shield-check me-2 text-indigo"></i> Admin Panel</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider border-light"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/login.php" class="btn btn-glass px-4">Login</a>
                        <a href="/signup.php" class="btn btn-primary-gradient px-4">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <div class="container py-4">
        <!-- Display Flash Messages globally if they exist -->
        <?php if ($flash_error = get_flash_message('error')): ?>
            <div class="alert alert-danger border-0 text-white alert-dismissible fade show" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $flash_error; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($flash_success = get_flash_message('success')): ?>
            <div class="alert alert-success border-0 text-white alert-dismissible fade show" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo $flash_success; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Theme Switcher JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggleBtn = document.getElementById('themeToggleBtn');
        const themeToggleIcon = document.getElementById('themeToggleIcon');
        
        if (themeToggleBtn && themeToggleIcon) {
            function updateIcon(theme) {
                if (theme === 'dark') {
                    themeToggleIcon.className = 'bi bi-sun-fill';
                } else {
                    themeToggleIcon.className = 'bi bi-moon-fill';
                }
            }
            
            const currentTheme = localStorage.getItem('theme') || 'light';
            updateIcon(currentTheme);
            
            themeToggleBtn.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('theme', next);
                updateIcon(next);
            });
        }
    });
    </script>
