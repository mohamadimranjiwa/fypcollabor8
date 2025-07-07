<?php
// Database credentials
$host = 'localhost';        // Hostname
$db_name = 'FYPManagementSystem'; // Database name
$username = 'root';         // MySQL username
$password = '';             // MySQL password
$port = 3306;               // Use port 3307 as specified in my.cnf

// Establish a global connection
try {
    $conn = new mysqli($host, $username, $password, $db_name, $port);


} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage(), 3, 'errors.log');
    die("Database connection failed. Please try again later. Error: " . $e->getMessage());
}
?>