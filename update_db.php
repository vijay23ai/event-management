<?php
require_once __DIR__ . '/includes/db.php';

echo "<h2>Database Migration Runner</h2>";

try {
    // 1. Create organizer_payment_details table
    $pdo->exec("CREATE TABLE IF NOT EXISTS organizer_payment_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organizer_id INT NOT NULL,
        upi_id VARCHAR(100) NOT NULL,
        phone_number VARCHAR(20) NOT NULL,
        qr_image VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    echo "✓ Created Table: organizer_payment_details<br>";

    // 2. Create payment_proofs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_proofs (
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
    ) ENGINE=InnoDB");
    echo "✓ Created Table: payment_proofs<br>";

    // 3. Alter events table
    // Check if column exists first
    $col_check = $pdo->query("SHOW COLUMNS FROM events LIKE 'payment_instructions'");
    if ($col_check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE events ADD COLUMN payment_instructions TEXT NULL");
        echo "✓ Added Column: payment_instructions to events table<br>";
    } else {
        echo "ℹ Column: payment_instructions already exists in events table<br>";
    }

    // 4. Alter bookings table status enum
    $pdo->exec("ALTER TABLE bookings MODIFY COLUMN status ENUM('pending', 'confirmed', 'cancelled', 'refund_requested', 'refunded') NOT NULL DEFAULT 'pending'");
    echo "✓ Modified bookings.status column enum<br>";

    // 5. Alter bookings table payment_status enum
    $pdo->exec("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('pending', 'paid', 'refunded', 'rejected') NOT NULL DEFAULT 'pending'");
    echo "✓ Modified bookings.payment_status column enum<br>";

    // 6. Seed default details for existing organizers
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE role = 'organizer'");
    $stmt->execute();
    $organizers = $stmt->fetchAll();

    $upi_stmt = $pdo->prepare("SELECT id FROM organizer_payment_details WHERE organizer_id = ?");
    $ins_upi = $pdo->prepare("INSERT INTO organizer_payment_details (organizer_id, upi_id, phone_number, qr_image) VALUES (?, ?, ?, ?)");

    // Ensure uploads/upi directory exists
    $upload_dir = __DIR__ . '/uploads/upi/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Copy a dummy file or create one for dummy QR codes if it doesn't exist
    $dummy_qr = $upload_dir . 'default_qr.png';
    if (!file_exists($dummy_qr)) {
        // Use base64 decoded 1x1 transparent PNG to avoid GD dependency
        $png_base64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=";
        file_put_contents($dummy_qr, base64_decode($png_base64));
    }

    foreach ($organizers as $org) {
        $upi_stmt->execute([$org['id']]);
        if ($upi_stmt->rowCount() === 0) {
            $upi_id = strtolower(str_replace(' ', '', $org['name'])) . "@upi";
            $phone = "98765" . sprintf("%05d", $org['id']);
            $ins_upi->execute([$org['id'], $upi_id, $phone, '/uploads/upi/default_qr.png']);
            echo "✓ Seeded default UPI details for organizer: {$org['name']}<br>";
        }
    }

    echo "<h3>Migration completed successfully!</h3>";
    echo "<p><a href='index.php'>Back to Homepage</a></p>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
?>
