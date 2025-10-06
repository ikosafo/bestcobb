<?php
session_start();

// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'bestcobb';
define('ENCRYPTION_KEY', 'vD4nKx7pL9qY8zR6mE2jH1iG3cB0aW5sUoTtFwYyQZcPvBnXgA');

// Connect to database
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Simulated user session (replace with your auth logic)
$user = isset($_SESSION['user']) ? $_SESSION['user'] : ['name' => 'John Doe', 'role' => 'Mall Admin', 'store' => 'All Stores'];

// Initialize cart in session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
?>

