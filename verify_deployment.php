<?php
/**
 * Deployment Verification Script
 * Run this on InfinityFree to verify system health
 * Delete after verification!
 */

require_once 'includes/conn.php';

echo "<!DOCTYPE html>
<html><head>
    <link rel="icon" type="image/png" href="image/GradeView Online.png"><title>Deployment Verification</title>
<style>body{font-family:Arial;margin:20px;}.ok{color:green;}.fail{color:red;}.warn{color:orange;}pre{background:#f5f5f5;padding:10px;}</style></head><body>";
echo "<h1>GradeView Deployment Verification</h1>";

$checks = [];

// 1. Database Connection
try {
    $pdo->query("SELECT 1");
    $checks[] = ['Database Connection', 'OK', 'ok'];
} catch (Exception $e) {
    $checks[] = ['Database Connection', 'FAIL: ' . $e->getMessage(), 'fail'];
}

// 2. Required Tables
$requiredTables = ['users', 'settings', 'subjects', 'grades', 'notifications', 'audit_log', 'login_logs', 'broadcast_templates', 'teacher_settings'];
foreach ($requiredTables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            $checks[] = ["Table: $table", 'OK', 'ok'];
        } else {
            $checks[] = ["Table: $table", 'MISSING', 'fail'];
        }
    } catch (Exception $e) {
        $checks[] = ["Table: $table", 'ERROR: ' . $e->getMessage(), 'fail'];
    }
}

// 3. OTP Columns
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_otp'");
    if ($stmt->fetch()) {
        $checks[] = ['OTP Columns (email_otp, reset_otp)', 'OK', 'ok'];
    } else {
        $checks[] = ['OTP Columns', 'MISSING - run add_email_otp.sql', 'fail'];
    }
} catch (Exception $e) {
    $checks[] = ['OTP Columns', 'ERROR: ' . $e->getMessage(), 'fail'];
}

// 4. Admin User
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin' AND role = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        $checks[] = ['Default Admin User', 'EXISTS', 'ok'];
    } else {
        $checks[] = ['Default Admin User', 'MISSING - run setup_database.php', 'warn'];
    }
} catch (Exception $e) {
    $checks[] = ['Default Admin User', 'ERROR: ' . $e->getMessage(), 'fail'];
}

// 5. SMTP Settings
$smtpKeys = ['smtp_from_email', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'];
$smtpConfigured = true;
foreach ($smtpKeys as $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if (empty($val) && $key !== 'smtp_password') {
        $smtpConfigured = false;
        break;
    }
}
$checks[] = ['SMTP Configuration', $smtpConfigured ? 'CONFIGURED' : 'NOT CONFIGURED (configure in Admin Panel)', $smtpConfigured ? 'ok' : 'warn'];

// 6. PHP Version
$checks[] = ['PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail'];

// 7. Required Extensions
$requiredExt = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'gd', 'zip', 'openssl'];
foreach ($requiredExt as $ext) {
    $checks[] = ["Extension: $ext", extension_loaded($ext) ? 'LOADED' : 'MISSING', extension_loaded($ext) ? 'ok' : 'fail'];
}

// 8. Vendor/Autoload
$checks[] = ['Composer Autoload', file_exists(__DIR__ . '/vendor/autoload.php') ? 'FOUND' : 'MISSING', file_exists(__DIR__ . '/vendor/autoload.php') ? 'ok' : 'fail'];

// 9. Write Permissions
$uploadDirs = ['uploads/avatars', 'uploads/slideshow', 'uploads/register_bg'];
foreach ($uploadDirs as $dir) {
    $fullPath = __DIR__ . '/' . $dir;
    $checks[] = ["Write: $dir", is_writable($fullPath) ? 'WRITABLE' : 'NOT WRITABLE', is_writable($fullPath) ? 'ok' : 'warn'];
}

// 10. Environment Detection
$checks[] = ['Environment', APP_ENV, 'ok'];
$checks[] = ['App Base URL', APP_BASE_URL, 'ok'];

// Display Results
echo "<table style='width:100%;border-collapse:collapse;'><tr><th style='text-align:left;padding:8px;border:1px solid #ddd'>Check</th><th style='text-align:left;padding:8px;border:1px solid #ddd'>Result</th><th style='text-align:left;padding:8px;border:1px solid #ddd'>Status</th></tr>";
$failCount = 0;
foreach ($checks as $c) {
    $class = $c[2];
    if ($class === 'fail') $failCount++;
    echo "<tr><td style='padding:8px;border:1px solid #ddd'>{$c[0]}</td><td style='padding:8px;border:1px solid #ddd'>{$c[1]}</td><td style='padding:8px;border:1px solid #ddd'><span class='$class'>{$c[2]}</span></td></tr>";
}
echo "</table>";

echo "<hr>";
if ($failCount === 0) {
    echo "<h2 class='ok'>✓ All critical checks passed!</h2>";
    echo "<p>System is ready. <a href='index.php'>Go to Login</a></p>";
} else {
    echo "<h2 class='fail'>✗ $failCount critical issue(s) found</h2>";
    echo "<p>Fix the issues above before using the system.</p>";
}

echo "<hr><h3>Quick Actions:</h3>";
echo "<ul>";
echo "<li><a href='setup_database.php'>Run Database Setup</a></li>";
echo "<li><a href='admin.php'>Admin Panel</a> (login: admin/admin123)</li>";
echo "<li><a href='index.php'>Login Page</a></li>";
echo "</ul>";

echo "</body></html>";