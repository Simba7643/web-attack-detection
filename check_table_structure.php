<?php
// check_table_structure.php
require_once 'config.php';

echo "<h2>Database Table Structures</h2>";

// Check ip_reputation table
echo "<h3>ip_reputation table:</h3>";
$result = $pdo->query("DESCRIBE ip_reputation");
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $result->fetch()) {
    echo "<tr>";
    echo "<td>{$row['Field']}</td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "<td>{$row['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check attacks table
echo "<h3>attacks table:</h3>";
$result = $pdo->query("DESCRIBE attacks");
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $result->fetch()) {
    echo "<tr>";
    echo "<td>{$row['Field']}</td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "<td>{$row['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

// Show sample data
echo "<h3>Sample data from ip_reputation:</h3>";
$result = $pdo->query("SELECT * FROM ip_reputation LIMIT 5");
echo "<table border='1' cellpadding='8'>";
if ($result->rowCount() > 0) {
    echo "<tr>";
    for ($i = 0; $i < $result->columnCount(); $i++) {
        $col = $result->getColumnMeta($i);
        echo "<th>{$col['name']}</th>";
    }
    echo "</tr>";
    while ($row = $result->fetch()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
} else {
    echo "<tr><td>No data found</td></tr>";
}
echo "</table>";
?>