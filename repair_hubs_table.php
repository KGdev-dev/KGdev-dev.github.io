<?php
require 'db_connect.php';

try {
    // 1. Ensure columns exist. If they exist, this might throw a warning/error depending on DB version,
    // so we wrap them individually to ensure the script doesn't stop.
    
    // Add hub_name if missing
    try {
        $pdo->exec("ALTER TABLE hubs ADD COLUMN hub_name VARCHAR(255) NOT NULL");
        echo "Added 'hub_name' column.<br>";
    } catch (PDOException $e) {
        echo "Column 'hub_name' likely already exists.<br>";
    }

    // Add address if missing
    try {
        $pdo->exec("ALTER TABLE hubs ADD COLUMN address VARCHAR(255) NOT NULL");
        echo "Added 'address' column.<br>";
    } catch (PDOException $e) {
        echo "Column 'address' likely already exists.<br>";
    }

    echo "<h3>Repair Complete! Your 'hubs' table is now aligned with your code.</h3>";
    echo "<a href='admin_dashboard.php'>Go back to Admin Dashboard</a>";

} catch (PDOException $e) {
    echo "<h3>Critical Error: " . $e->getMessage() . "</h3>";
}
?>