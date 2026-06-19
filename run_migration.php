<?php
require_once __DIR__ . '/includes/db.php';

try {
    echo "Starting database migration...\n";

    // 1. Modify category column to VARCHAR(100)
    $sqlAlterCategory = "ALTER TABLE events MODIFY COLUMN category VARCHAR(100) NOT NULL";
    $pdo->exec($sqlAlterCategory);
    echo "✓ Modified category column to VARCHAR(100).\n";

    // 2. Add building_name column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN building_name VARCHAR(150) NULL AFTER venue");
        echo "✓ Added building_name column.\n";
    } catch (PDOException $e) {
        echo "• building_name column already exists or skipped: " . $e->getMessage() . "\n";
    }

    // 3. Add state column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN state VARCHAR(100) NULL AFTER city");
        echo "✓ Added state column.\n";
    } catch (PDOException $e) {
        echo "• state column already exists or skipped: " . $e->getMessage() . "\n";
    }

    // 4. Add pincode column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN pincode VARCHAR(20) NULL AFTER state");
        echo "✓ Added pincode column.\n";
    } catch (PDOException $e) {
        echo "• pincode column already exists or skipped: " . $e->getMessage() . "\n";
    }

    // 5. Add google_maps_link column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN google_maps_link VARCHAR(500) NULL AFTER address");
        echo "✓ Added google_maps_link column.\n";
    } catch (PDOException $e) {
        echo "• google_maps_link column already exists or skipped: " . $e->getMessage() . "\n";
    }

    // 6. Add latitude column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER google_maps_link");
        echo "✓ Added latitude column.\n";
    } catch (PDOException $e) {
        echo "• latitude column already exists or skipped: " . $e->getMessage() . "\n";
    }

    // 7. Add longitude column
    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
        echo "✓ Added longitude column.\n";
    } catch (PDOException $e) {
        echo "• longitude column already exists or skipped: " . $e->getMessage() . "\n";
    }

    echo "Migration completed successfully!\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>
