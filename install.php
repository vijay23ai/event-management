<?php
// Prevent session redirect loops
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'sql102.infinityfree.com';
    $db_user = $_POST['db_user'] ?? 'if0_42213782';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = $_POST['db_name'] ?? 'if0_42213782_event_management';

    try {
        // 1. Initial Connection to MySQL (without selecting DB)
        $dsn = "mysql:host={$db_host};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        $temp_pdo = new PDO($dsn, $db_user, $db_pass, $options);

        // 2. Create Database
        $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // 3. Connect to Created Database
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);

        // 4. Create Tables
        $queries = [
            "DROP TABLE IF EXISTS system_settings",
            "DROP TABLE IF EXISTS refund_requests",
            "DROP TABLE IF EXISTS payment_proofs",
            "DROP TABLE IF EXISTS notifications",
            "DROP TABLE IF EXISTS reviews",
            "DROP TABLE IF EXISTS bookings",
            "DROP TABLE IF EXISTS tickets_types",
            "DROP TABLE IF EXISTS events",
            "DROP TABLE IF EXISTS organizer_payment_details",
            "DROP TABLE IF EXISTS users",

            "CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('user', 'organizer', 'admin') NOT NULL DEFAULT 'user',
                status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
                telegram_chat_id VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

            "CREATE TABLE organizer_payment_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organizer_id INT NOT NULL,
                upi_id VARCHAR(100) NOT NULL,
                phone_number VARCHAR(20) NOT NULL,
                qr_image VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organizer_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL,
                category ENUM('Concert', 'Workshop', 'Sports', 'Seminar', 'Festival', 'Meetup') NOT NULL,
                city VARCHAR(100) NOT NULL,
                venue VARCHAR(200) NOT NULL,
                address VARCHAR(255) NOT NULL,
                date_time DATETIME NOT NULL,
                capacity INT NOT NULL,
                remaining_seats INT NOT NULL,
                banner_image VARCHAR(255) NULL,
                payment_instructions TEXT NULL,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE tickets_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                name ENUM('General', 'VIP', 'Student') NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                capacity INT NOT NULL,
                remaining_seats INT NOT NULL,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE bookings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                event_id INT NOT NULL,
                ticket_type_id INT NOT NULL,
                quantity INT NOT NULL,
                total_price DECIMAL(10,2) NOT NULL,
                status ENUM('pending', 'confirmed', 'cancelled', 'refund_requested', 'refunded') NOT NULL DEFAULT 'pending',
                payment_status ENUM('pending', 'paid', 'refunded', 'rejected') NOT NULL DEFAULT 'pending',
                qr_code_token VARCHAR(100) NOT NULL UNIQUE,
                attendance_status ENUM('not_attended', 'present') NOT NULL DEFAULT 'not_attended',
                checked_in_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                FOREIGN KEY (ticket_type_id) REFERENCES tickets_types(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE payment_proofs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                user_id INT NOT NULL,
                utr_number VARCHAR(100) NOT NULL UNIQUE,
                amount DECIMAL(10,2) NOT NULL,
                screenshot VARCHAR(255) NOT NULL,
                payment_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                verified_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB",

            "CREATE TABLE reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                event_id INT NOT NULL,
                rating INT NOT NULL CHECK(rating BETWEEN 1 AND 5),
                comment TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                title VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('email', 'telegram', 'system') NOT NULL DEFAULT 'system',
                status ENUM('sent', 'failed', 'pending') NOT NULL DEFAULT 'sent',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB",

            "CREATE TABLE refund_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                organizer_id INT NOT NULL,
                reason TEXT NOT NULL,
                status ENUM('requested', 'approved', 'refunded', 'rejected') NOT NULL DEFAULT 'requested',
                amount DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

            "CREATE TABLE system_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value TEXT NULL
            ) ENGINE=InnoDB"
        ];

        foreach ($queries as $q) {
            $pdo->exec($q);
        }

        // 5. Seed Users (passwords are 'password123')
        $pass_hash = password_hash('password123', PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Portal Administrator', 'admin@example.com', $pass_hash, 'admin', 'active']);
        $admin_id = $pdo->lastInsertId();

        $stmt->execute(['Epic Events Organizer', 'organizer@example.com', $pass_hash, 'organizer', 'active']);
        $organizer_id = $pdo->lastInsertId();
        
        $stmt->execute(['Techno Productions', 'organizer2@example.com', $pass_hash, 'organizer', 'active']);
        $organizer2_id = $pdo->lastInsertId();

        $stmt->execute(['John Attendee', 'user@example.com', $pass_hash, 'user', 'active']);
        $user_id = $pdo->lastInsertId();
        
        $stmt->execute(['Alice Smith', 'alice@example.com', $pass_hash, 'user', 'active']);
        $alice_id = $pdo->lastInsertId();
        
        $stmt->execute(['Bob Jones', 'bob@example.com', $pass_hash, 'user', 'active']);
        $bob_id = $pdo->lastInsertId();

        // Seed organizer payment details
        $upload_dir = __DIR__ . '/uploads/upi/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $dummy_qr = $upload_dir . 'default_qr.png';
        if (!file_exists($dummy_qr)) {
            $png_base64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=";
            file_put_contents($dummy_qr, base64_decode($png_base64));
        }

        $ins_upi = $pdo->prepare("INSERT INTO organizer_payment_details (organizer_id, upi_id, phone_number, qr_image) VALUES (?, ?, ?, ?)");
        $ins_upi->execute([$organizer_id, 'epicevents@upi', '9876500002', '/uploads/upi/default_qr.png']);
        $ins_upi->execute([$organizer2_id, 'technoprod@upi', '9876500003', '/uploads/upi/default_qr.png']);

        // 6. Seed Events
        $events_data = [
            [
                'organizer_id' => $organizer_id,
                'title' => 'Neon Dreams Rock Concert',
                'description' => 'Experience the biggest rock and synthwave event of the year featuring live laser show, visual art, and international headliners. Feel the heavy synth basses and rocking guitar riffs under the neon sky.',
                'category' => 'Concert',
                'city' => 'San Francisco',
                'venue' => 'The Regency Ballroom',
                'address' => '1300 Van Ness Ave, San Francisco, CA 94109',
                'date_time' => date('Y-m-d H:i:s', strtotime('+5 days 19:00:00')),
                'capacity' => 500,
                'banner_image' => 'https://images.unsplash.com/photo-1506157786151-b8491531f063?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer_id,
                'title' => 'Interactive UI/UX Design Workshop',
                'description' => 'A masterclass on modern web UI trends, glassmorphism, responsive styling systems, and prototyping. Hands-on coding session with Figma and Bootstrap 5.',
                'category' => 'Workshop',
                'city' => 'New York',
                'venue' => 'Midtown Tech Center',
                'address' => '220 W 42nd St, New York, NY 10036',
                'date_time' => date('Y-m-d H:i:s', strtotime('+12 days 10:00:00')),
                'capacity' => 100,
                'banner_image' => 'https://images.unsplash.com/photo-1531403009284-440f080d1e12?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer2_id,
                'title' => 'Global Artificial Intelligence Seminar',
                'description' => 'Keynotes and panels from top machine learning scientists discussing agentic architectures, neural networks, ethics, and future models.',
                'category' => 'Seminar',
                'city' => 'San Francisco',
                'venue' => 'Silicon Valley Innovation Hub',
                'address' => '3200 Bridge Pkwy, Redwood City, CA 94065',
                'date_time' => date('Y-m-d H:i:s', strtotime('+15 days 09:00:00')),
                'capacity' => 300,
                'banner_image' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer_id,
                'title' => 'Championship Basketball Open',
                'description' => 'Watch the city\'s top basketball clubs compete in the annual summer streetball tournament. High-energy, food trucks, and local DJ.',
                'category' => 'Sports',
                'city' => 'Chicago',
                'venue' => 'Millennium Park Courts',
                'address' => '201 E Randolph St, Chicago, IL 60602',
                'date_time' => date('Y-m-d H:i:s', strtotime('+8 days 15:00:00')),
                'capacity' => 200,
                'banner_image' => 'https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer2_id,
                'title' => 'Summer Cherry Blossom Festival',
                'description' => 'Immerse yourself in cultural dances, traditional street foods, art booths, and authentic performances at the Cherry Blossom Festival.',
                'category' => 'Festival',
                'city' => 'Los Angeles',
                'venue' => 'Little Tokyo Plaza',
                'address' => '319 E 2nd St, Los Angeles, CA 90012',
                'date_time' => date('Y-m-d H:i:s', strtotime('+20 days 11:00:00')),
                'capacity' => 1000,
                'banner_image' => 'https://images.unsplash.com/photo-1467307983825-619ab40a85c6?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer_id,
                'title' => 'Indie Developers Meetup',
                'description' => 'Network with local software engineers, indie hackers, and SaaS founders. Pitch your project in 2 minutes and find co-founders.',
                'category' => 'Meetup',
                'city' => 'New York',
                'venue' => 'Grid Workspace Office',
                'address' => '54 W 21st St, New York, NY 10010',
                'date_time' => date('Y-m-d H:i:s', strtotime('+4 days 18:30:00')),
                'capacity' => 80,
                'banner_image' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ],
            [
                'organizer_id' => $organizer_id,
                'title' => 'Pending Startup Pitch Night',
                'description' => 'A pitch night for early stage ideas seeking feedback from developers and local angel mentors. Requires admin approval.',
                'category' => 'Meetup',
                'city' => 'San Francisco',
                'venue' => 'Soma Incubator Room',
                'address' => '800 Market St, San Francisco, CA 94102',
                'date_time' => date('Y-m-d H:i:s', strtotime('+6 days 18:00:00')),
                'capacity' => 50,
                'banner_image' => 'https://images.unsplash.com/photo-1515187029135-18ee286d815b?auto=format&fit=crop&q=80&w=800',
                'status' => 'pending' // pending approval
            ],
            // Past event for reviews
            [
                'organizer_id' => $organizer_id,
                'title' => 'Spring Electronic Dance Carnival',
                'description' => 'An epic retro electronic dance music festival. Featuring retro EDM hits and strobe light setups.',
                'category' => 'Festival',
                'city' => 'San Francisco',
                'venue' => 'Union Square Arena',
                'address' => 'Union Square, San Francisco, CA 94102',
                'date_time' => date('Y-m-d H:i:s', strtotime('-10 days 20:00:00')),
                'capacity' => 1500,
                'banner_image' => 'https://images.unsplash.com/photo-1470225620780-dba8ba36b745?auto=format&fit=crop&q=80&w=800',
                'status' => 'approved'
            ]
        ];

        $ins_event = $pdo->prepare("INSERT INTO events (organizer_id, title, description, category, city, venue, address, date_time, capacity, remaining_seats, banner_image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $event_ids = [];
        foreach ($events_data as $e) {
            $ins_event->execute([
                $e['organizer_id'],
                $e['title'],
                $e['description'],
                $e['category'],
                $e['city'],
                $e['venue'],
                $e['address'],
                $e['date_time'],
                $e['capacity'],
                $e['capacity'], // initially remaining_seats = capacity
                $e['banner_image'],
                $e['status']
            ]);
            $event_ids[$e['title']] = $pdo->lastInsertId();
        }

        // 7. Seed Ticket Types
        $ticket_stmt = $pdo->prepare("INSERT INTO tickets_types (event_id, name, price, capacity, remaining_seats) VALUES (?, ?, ?, ?, ?)");
        
        // Loop and add tickets for the created events
        foreach ($event_ids as $title => $ev_id) {
            // Get original capacity
            $cap_stmt = $pdo->prepare("SELECT capacity FROM events WHERE id = ?");
            $cap_stmt->execute([$ev_id]);
            $cap = $cap_stmt->fetchColumn();

            // Distribute ticket types: 60% General, 20% VIP, 20% Student
            $gen_cap = ceil($cap * 0.6);
            $vip_cap = floor($cap * 0.2);
            $stud_cap = floor($cap * 0.2);

            if ($title === 'Neon Dreams Rock Concert') {
                $ticket_stmt->execute([$ev_id, 'General', 45.00, $gen_cap, $gen_cap]);
                $ticket_stmt->execute([$ev_id, 'VIP', 120.00, $vip_cap, $vip_cap]);
                $ticket_stmt->execute([$ev_id, 'Student', 30.00, $stud_cap, $stud_cap]);
            } else if ($title === 'Interactive UI/UX Design Workshop') {
                $ticket_stmt->execute([$ev_id, 'General', 75.00, $gen_cap, $gen_cap]);
                $ticket_stmt->execute([$ev_id, 'VIP', 150.00, $vip_cap, $vip_cap]);
                $ticket_stmt->execute([$ev_id, 'Student', 50.00, $stud_cap, $stud_cap]);
            } else {
                // Default prices
                $ticket_stmt->execute([$ev_id, 'General', 25.00, $gen_cap, $gen_cap]);
                $ticket_stmt->execute([$ev_id, 'VIP', 75.00, $vip_cap, $vip_cap]);
                $ticket_stmt->execute([$ev_id, 'Student', 15.00, $stud_cap, $stud_cap]);
            }
        }

        // 8. Seed Bookings & Decrement Seats (For Analytics/Demos)
        // Bookings for Neon Dreams Rock Concert (user_id booking VIP tickets)
        $ev_neon_id = $event_ids['Neon Dreams Rock Concert'];
        $tt_stmt = $pdo->prepare("SELECT id, price, remaining_seats FROM tickets_types WHERE event_id = ? AND name = ?");
        
        $tt_stmt->execute([$ev_neon_id, 'VIP']);
        $vip_ticket = $tt_stmt->fetch();
        
        $tt_stmt->execute([$ev_neon_id, 'General']);
        $gen_ticket = $tt_stmt->fetch();

        // Let's perform some inserts
        $book_stmt = $pdo->prepare("INSERT INTO bookings (user_id, event_id, ticket_type_id, quantity, total_price, status, payment_status, qr_code_token, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // 10 days ago (for charts)
        $book_stmt->execute([
            $user_id, $ev_neon_id, $vip_ticket['id'], 2, 240.00, 'confirmed', 'paid', 
            'QR_CONF_VIP_' . uniqid(), date('Y-m-d H:i:s', strtotime('-10 days 14:30:00'))
        ]);
        // Update ticket and event capacity
        $pdo->exec("UPDATE tickets_types SET remaining_seats = remaining_seats - 2 WHERE id = {$vip_ticket['id']}");
        $pdo->exec("UPDATE events SET remaining_seats = remaining_seats - 2 WHERE id = {$ev_neon_id}");

        // 5 days ago
        $book_stmt->execute([
            $alice_id, $ev_neon_id, $gen_ticket['id'], 3, 135.00, 'confirmed', 'paid', 
            'QR_CONF_GEN_' . uniqid(), date('Y-m-d H:i:s', strtotime('-5 days 10:15:00'))
        ]);
        $pdo->exec("UPDATE tickets_types SET remaining_seats = remaining_seats - 3 WHERE id = {$gen_ticket['id']}");
        $pdo->exec("UPDATE events SET remaining_seats = remaining_seats - 3 WHERE id = {$ev_neon_id}");

        // Booking on Spring Electronic Dance Carnival (Past Event)
        $ev_dance_id = $event_ids['Spring Electronic Dance Carnival'];
        $tt_stmt->execute([$ev_dance_id, 'General']);
        $dance_gen_ticket = $tt_stmt->fetch();
        
        $book_stmt->execute([
            $bob_id, $ev_dance_id, $dance_gen_ticket['id'], 1, 25.00, 'confirmed', 'paid', 
            'QR_CONF_DANCE_' . uniqid(), date('Y-m-d H:i:s', strtotime('-12 days 18:20:00'))
        ]);
        $pdo->exec("UPDATE tickets_types SET remaining_seats = remaining_seats - 1 WHERE id = {$dance_gen_ticket['id']}");
        $pdo->exec("UPDATE events SET remaining_seats = remaining_seats - 1 WHERE id = {$ev_dance_id}");

        // Create a refund request demo
        $book_stmt->execute([
            $bob_id, $ev_neon_id, $gen_ticket['id'], 1, 45.00, 'refund_requested', 'paid', 
            'QR_REFUND_REQ_' . uniqid(), date('Y-m-d H:i:s', strtotime('-2 days 12:00:00'))
        ]);
        $booking_ref_id = $pdo->lastInsertId();
        
        $ref_req_stmt = $pdo->prepare("INSERT INTO refund_requests (booking_id, organizer_id, reason, status, amount) VALUES (?, ?, ?, ?, ?)");
        $ref_req_stmt->execute([$booking_ref_id, $organizer_id, 'I have a business meeting that overlaps with the concert time.', 'requested', 45.00]);

        // 9. Seed Reviews for Past Event
        $rev_stmt = $pdo->prepare("INSERT INTO reviews (user_id, event_id, rating, comment, created_at) VALUES (?, ?, ?, ?, ?)");
        $rev_stmt->execute([$bob_id, $ev_dance_id, 5, 'The sound system was incredible, and the lighting crew did an outstanding job! Best night ever.', date('Y-m-d H:i:s', strtotime('-9 days 11:30:00'))]);
        $rev_stmt->execute([$alice_id, $ev_dance_id, 4, 'Very well organized and friendly staff, though the drink queues were a bit long.', date('Y-m-d H:i:s', strtotime('-9 days 13:00:00'))]);

        // 10. Seed System Settings
        $sys_stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $sys_stmt->execute(['resend_api_key', '']);
        $sys_stmt->execute(['telegram_bot_token', '']);
        $sys_stmt->execute(['telegram_chat_id', '']);

        // 11. Write config.php
        $config_content = "<?php\n" .
            "// Database configuration settings\n" .
            "define('DB_HOST', " . var_export($db_host, true) . ");\n" .
            "define('DB_USER', " . var_export($db_user, true) . ");\n" .
            "define('DB_PASS', " . var_export($db_pass, true) . ");\n" .
            "define('DB_NAME', " . var_export($db_name, true) . ");\n" .
            "?>";
        
        file_to_write_path: file_put_contents(__DIR__ . '/config.php', $config_content);

        $success = "Database '{$db_name}' installed and seeded successfully! Config saved.";

    } catch (PDOException $e) {
        $error = "Database setup failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Seeder | Event Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="d-flex align-items-center justify-content-center py-5">
    <div class="container" style="max-width: 600px;">
        <div class="glass-panel p-5">
            <div class="text-center mb-4">
                <i class="bi bi-database-fill-gear text-indigo fs-1" style="color: #6366f1;"></i>
                <h2 class="mt-3 fw-bold">Platform Installer</h2>
                <p class="text-secondary">Configure and Seed the City-Wide Event Database</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger border-0 text-white" style="background: rgba(225,29,72,0.2); border: 1px solid rgba(225,29,72,0.3) !important;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success border-0 text-white" style="background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.3) !important;">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                </div>
                
                <div class="d-grid gap-2 mt-4">
                    <a href="login.php" class="btn btn-primary-gradient py-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Proceed to Login Page
                    </a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label for="db_host" class="form-label-glass">MySQL Database Host</label>
                        <input type="text" name="db_host" id="db_host" class="form-control form-control-glass" value="sql102.infinityfree.com" required autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label for="db_user" class="form-label-glass">MySQL Username</label>
                        <input type="text" name="db_user" id="db_user" class="form-control form-control-glass" value="if0_42213782" required autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label for="db_pass" class="form-label-glass">MySQL Password</label>
                        <input type="password" name="db_pass" id="db_pass" class="form-control form-control-glass" placeholder="Leave empty for XAMPP default" autocomplete="off">
                    </div>
                    
                    <div class="mb-4">
                        <label for="db_name" class="form-label-glass">Database Name</label>
                        <input type="text" name="db_name" id="db_name" class="form-control form-control-glass" value="if0_42213782_event_management" required autocomplete="off">
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary-gradient py-3">
                            <i class="bi bi-lightning-charge-fill me-2"></i> Initialize & Seed Database
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
