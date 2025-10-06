<?php
require_once __DIR__ . '/config.php';

// Check if a session is active
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Unset all of the session variables
$_SESSION = [];

// 2. Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session itself
session_destroy();

error_log("User logged out successfully. Redirecting to login.php");

// Redirect to the login page
header('Location: login.php');
exit;
?>
