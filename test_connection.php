<?php
// Include the database connection file
include 'connection.php';

// Run a test query
$sql = "SHOW TABLES";
$result = $conn->query($sql);

if ($result) {
    echo "<p>Connected to database and fetched table list successfully.</p>";
    echo "<ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>" . $row[0] . "</li>"; // Display each table name
    }
    echo "</ul>";
} else {
    echo "<p>Connection established but no tables found or query failed.</p>";
}

// Close the connection
$conn->close();
?>
