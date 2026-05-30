<?php
session_start();

$host = 'localhost';
$dbname = 'student_grade_management';
$username = 'root';
$password = '';

// --- Environment Configuration ---
// Define if the application is in development mode.
// In a real-world scenario, this would typically be set via an environment variable
// (e.g., in Apache/Nginx config, .env file, or Docker).
// For XAMPP, you might set it directly here or in php.ini.
define('APP_ENV', 'development'); // Change to 'production' for live deployment

// --- Source Protection Trigger ---
if (APP_ENV === 'production') {
    // Security Headers
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
}

if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0); // No error reporting to users in production
    // Also, consider logging errors to a file in production
    // ini_set('log_errors', 1);
    // ini_set('error_log', '/path/to/your/php-error.log');
}
// --- End Environment Configuration ---

try {
    // Added charset to ensure special characters in passwords are handled correctly
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    if (APP_ENV === 'development') {
        die("Connection failed: " . $e->getMessage());
    } else {
        // Log the error securely (e.g., to a file, not to the browser)
        error_log("Database connection failed: " . $e->getMessage());
        die("A system error occurred. Please try again later.");
    }
}
?>