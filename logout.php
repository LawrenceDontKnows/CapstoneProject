<?php
// To ensure the correct session is targeted for destruction, we must first
// initialize the session with the same custom settings used across the application.
// Including 'conn.php' handles this by setting the session name before starting.
include 'includes/conn.php';

// CSRF and Method Check for Secure Logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    // Invalid request, just destroy session and redirect without logging activity
    session_unset();
    session_destroy();
    header("Location: redirect.php?url=" . urlencode('index.php?reason=invalid_logout') . "&fade=1");
    exit();
}

// Now that the correct session ('GVSESSID') is active, we can proceed with a secure logout.

// 1. Unset all of the session variables.
$_SESSION = array();

// 2. Invalidate the session cookie on the client's browser.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finally, destroy the session data on the server.
session_destroy();

header("Location: redirect.php?url=" . urlencode('index.php') . "&fade=1");
exit();