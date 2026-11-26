<?php
/**
 * Database Setup Script
 * Run this ONCE to create the local database and tables
 * For InfinityFree, run the SQL output in phpMyAdmin
 * 
 * Usage: http://localhost/gradeview system/setup_database.php
 */

require_once 'includes/conn.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>GradeView Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #1a2a4a; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 400px; }
        textarea { width: 100%; font-family: monospace; font-size: 12px; }
        button { background: #1a2a4a; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #2c3e6b; }
        button:disabled { background: #6c757d; cursor: not-allowed; }
        .step { border-left: 3px solid #1a2a4a; padding-left: 15px; margin: 15px 0; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>GradeView Database Setup</h1>";
echo "<p><strong>Environment:</strong> " . APP_ENV . "</p>";
echo "<p><strong>Database Host:</strong> $host</p>";
echo "<p><strong>Database Name:</strong> $dbname</p>";
echo "<hr>";

// Load combined schema
$schema_sql = require 'database/schema.php';

if (APP_ENV === 'development') {
    echo "<div class='step'><h3>Step 1: Create/Verify Database</h3>";
    
    try {
        // Connect without database to create it
        $temp_pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
        $temp_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database
        $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "<div class='status success'>✓ Database '$dbname' created/verified</div>";
        
        // Use the database
        $temp_pdo->exec("USE `$dbname`");
        
        echo "</div><div class='step'><h3>Step 2: Create Tables</h3>";
        
        // Split and execute SQL statements
        $statements = array_filter(array_map('trim', explode(';', $schema_sql)));
        $success_count = 0;
        $error_count = 0;
        
        foreach ($statements as $stmt) {
            if (empty($stmt) || strpos($stmt, '--') === 0 || strpos($stmt, '/*') === 0) continue;
            try {
                $temp_pdo->exec($stmt);
                $success_count++;
            } catch (PDOException $e) {
                // Ignore "already exists" errors
                if ($e->getCode() !== '42S01' && strpos($e->getMessage(), 'already exists') === false) {
                    echo "<div class='status error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
                    $error_count++;
                }
            }
        }
        
        echo "<div class='status success'>✓ Executed $success_count SQL statements</div>";
        if ($error_count > 0) {
            echo "<div class='status warning'>⚠ $error_count non-critical errors (likely already exists)</div>";
        }
        
        // Insert default admin user if not exists
        echo "</div><div class='step'><h3>Step 3: Create Default Admin User</h3>";
        $stmt = $temp_pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $temp_pdo->prepare("INSERT INTO users (username, password, email, role, first_name, last_name, status) VALUES ('admin', ?, 'admin@school.edu', 'admin', 'System', 'Admin', 'active')")->execute([$hash]);
            echo "<div class='status success'>✓ Default admin user created (username: admin, password: admin123)</div>";
        } else {
            echo "<div class='status info'>ℹ Admin user already exists</div>";
        }
        
        echo "</div><div class='status success'><strong>✓ Local database setup complete!</strong></div>";
        echo "<p><a href='index.php' style='color:#1a2a4a;'>→ Go to Login Page</a></p>";
        
    } catch (PDOException $e) {
        echo "<div class='status error'>✗ Connection Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<div class='status info'>Make sure MySQL is running in XAMPP Control Panel</div>";
    }
    
} else {
    echo "<div class='step'><h3>Production Environment Detected</h3>";
    echo "<div class='status info'>Running on " . APP_ENV . ". Please run the SQL below in your InfinityFree phpMyAdmin.</div>";
    echo "<p>1. Log into InfinityFree cPanel → phpMyAdmin</p>";
    echo "<p>2. Select database: <strong>$dbname</strong></p>";
    echo "<p>3. Click 'SQL' tab and paste the following:</p>";
    echo "<textarea rows='30' cols='100' readonly>" . htmlspecialchars($schema_sql) . "</textarea>";
    echo "<br><br>";
    echo "<button onclick='copySQL()'>Copy SQL to Clipboard</button>";
    echo "<script>
        function copySQL() {
            const textarea = document.querySelector('textarea');
            textarea.select();
            document.execCommand('copy');
            alert('SQL copied to clipboard!');
        }
    </script>";
}

echo "</div></body></html>";