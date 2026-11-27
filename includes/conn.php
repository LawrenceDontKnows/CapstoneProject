<?php
// --- DATABASE CONFIGURATION ---
// Production (Render/Docker) reads credentials from environment variables.
// Local XAMPP falls back to the defaults below when env vars are absent.
$db_env = getenv('APP_ENV') ?: (getenv('DB_HOST') ? 'production' : 'local');

// Allow manual override via constant (can be set in a local config file)
if (defined('FORCE_DB_ENV')) {
    $db_env = FORCE_DB_ENV;
}

$db_config = [
    'local' => [
        'host' => '127.0.0.1',
        'dbname' => 'gradeview system', // legacy local schema uses a space; underscore variant is auto-tried below
        'username' => 'root',
        'password' => '',
        'port' => 3306,
    ],
    'production' => [
        'host' => getenv('DB_HOST') ?: 'sql306.infinityfree.com',
        'dbname' => getenv('DB_NAME') ?: 'if0_42425008_student_grade_management',
        'username' => getenv('DB_USER') ?: 'if0_42425008',
        'password' => getenv('DB_PASSWORD') ?: '',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
    ],
];



$host = $db_config[$db_env]['host'];
$dbname = $db_config[$db_env]['dbname'];
$username = $db_config[$db_env]['username'];
$password = $db_config[$db_env]['password'];

// --- Environment Configuration ---
// Automatically set environment based on database selection
define('APP_ENV', $db_env === 'local' ? 'development' : 'production');

// Only define APP_BASE_URL in web context
if (php_sapi_name() !== 'cli') {
    define('APP_BASE_URL', $db_env === 'local' 
        ? 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) 
        : 'https://' . $_SERVER['HTTP_HOST']
    );
} else {
    define('APP_BASE_URL', '');
}
// --- End Database Configuration ---

// --- Error Handling Configuration ---
// Configure error handling based on environment
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', '/tmp/php_errors.log');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Custom error handler for production (register AFTER APP_ENV is defined)
function errorType($type) {
    static $types = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED',
    ];
    return $types[$type] ?? 'UNKNOWN';
}

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // Let PHP handle it
    }
    $log = sprintf(
        "[%s] PHP %s: %s in %s on line %d\n",
        date('Y-m-d H:i:s'),
        errorType($severity),
        $message,
        $file,
        $line
    );
    error_log($log, 3, '/tmp/php_errors.log');
    if (defined('APP_ENV') && APP_ENV === 'production') {
        return true; // Don't execute PHP internal handler
    }
    return false;
});

// Fatal error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $log = sprintf(
            "[%s] FATAL %s: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            errorType($error['type']),
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($log, 3, '/tmp/php_errors.log');
        // Show fatal details only when ?debug=1 is present (for troubleshooting).
        if (defined('APP_ENV') && APP_ENV === 'production' && !headers_sent()
            && isset($_GET['debug']) && $_GET['debug'] === '1') {
            echo "DEBUG FATAL: " . htmlspecialchars($error['message'] . " in " . $error['file'] . ":" . $error['line']);
        }
    }
});

// Catch uncaught exceptions (e.g. uncaught PDOException) so they are logged
// instead of producing a blank HTTP 500. In production, show a friendly message.
set_exception_handler(function($e) {
    error_log("Uncaught " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (defined('APP_ENV') && APP_ENV === 'production' && !headers_sent()) {
        http_response_code(500);
        // Show details only when ?debug=1 is present (for troubleshooting).
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            echo 'DEBUG: ' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        } else {
            echo 'A system error occurred. Please try again later.';
        }
    }
});
// --- End Error Handling Configuration ---

// --- Secure Session Configuration ---
// Skip session config in CLI mode
if (php_sapi_name() !== 'cli') {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    // Use a custom, secure session name.
    if (APP_ENV === 'production') {
        session_name('__Secure-GVSESSID');
    } else {
        session_name('GVSESSID');
    }

    // Use a host-only cookie (empty domain) so it works on any host,
    // including multi-part domains like *.up.railway.app where a
    // last-two-labels domain would be a rejected public suffix.
    $cookie_domain = '';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookie_domain,
        'secure' => APP_ENV === 'production',
        'httponly' => true,
        'samesite' => 'Lax'  // Lax works better cross-subdomain than Strict
    ]);

    // Start the session AFTER setting the cookie parameters
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// --- Security Headers ---
if (APP_ENV === 'production' && php_sapi_name() !== 'cli') {
    $csp = "default-src 'self'; ";
    $csp .= "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; ";
    $csp .= "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; ";
    $csp .= "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; ";
    $csp .= "img-src 'self' data: https:; ";  // Allow HTTPS images
    $csp .= "connect-src 'self' https://generativelanguage.googleapis.com; ";  // AI assistant
    $csp .= "frame-ancestors 'none'; ";
    header("Content-Security-Policy: " . $csp);
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}
// --- End Environment Configuration ---

// Some local installs created the schema with a space in the database name while
// others use an underscore. Try the configured name first, then the alternate
// spelling, so the app connects regardless of how the DB was originally named.
$dbCandidates = [];
foreach ([$dbname, str_replace(' ', '_', $dbname), str_replace('_', ' ', $dbname)] as $c) {
    $c = trim((string)$c);
    if ($c !== '' && !in_array($c, $dbCandidates, true)) { $dbCandidates[] = $c; }
}

$pdo = null;
$lastErr = null;
foreach ($dbCandidates as $candidate) {
    try {
        $pdo = new PDO("mysql:host=$host;port=" . $db_config[$db_env]['port'] . ";dbname=$candidate;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $dbname = $candidate;
        break;
    } catch (PDOException $e) {
        $lastErr = $e;
    }
}
if ($pdo === null) {
    $msg = $lastErr ? $lastErr->getMessage() : 'unknown error';
    if (APP_ENV === 'development') {
        die("Connection failed: " . $msg);
    } else {
        error_log("Database connection failed: " . $msg);
        die("A system error occurred. Please try again later.");
    }
}