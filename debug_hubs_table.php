<?php
require 'db_connect.php';

try {
    echo "<h2>Checking table 'hubs' structure:</h2>";
    $stmt = $pdo->query("DESCRIBE hubs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'><tr><th>Field</th><th>Type</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>" . $col['Field'] . "</td><td>" . $col['Type'] . "</td></tr>";
    }
    echo "</table>";
    
    echo "<p><strong>Instructions:</strong> Look at the table above. If you see 'hub_name' or 'address', this script is working. If you see different names (like 'name' or 'hubname'), we need to rename them to match your code.</p>";

} catch (PDOException $e) {
    echo "<h3>Error: " . $e->getMessage() . "</h3>";
}
?>