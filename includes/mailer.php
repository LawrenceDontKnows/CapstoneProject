<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Reads SMTP settings from the database and returns a normalized config array.
 * Returns false if minimum required settings are missing.
 */
function getSmtpConfig($pdo) {
    $from = getSystemSetting($pdo, 'smtp_from_email', '');
    $host = getSystemSetting($pdo, 'smtp_host', '');
    $port = (int)(getSystemSetting($pdo, 'smtp_port', '') ?: 587);
    $username = getSystemSetting($pdo, 'smtp_username', '');
    $password = getSystemSetting($pdo, 'smtp_password', '');
    $encryption = getSystemSetting($pdo, 'smtp_encryption', '');
    $fromName = getSystemSetting($pdo, 'smtp_from_name', 'GradeView System');

    if (!$from || !$host) {
        return false;
    }

    // Normalize encryption: 'none' or empty means no encryption
    if ($encryption === 'none' || $encryption === '') {
        $encryption = '';
    }

    return [
        'from' => $from,
        'fromName' => $fromName,
        'host' => $host,
        'port' => $port,
        'username' => $username ?: $from,
        'password' => $password,
        'encryption' => $encryption,
    ];
}

function generateOTP($length = 6) {
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= random_int(0, 9);
    }
    return $otp;
}

function configureMailer(PHPMailer $mail, array $config) {
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPSecure = $config['encryption'] ?: null;
    $mail->Port = $config['port'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;

    // Debug level: 0 = off, 1 = client, 2 = client + server, 3 = connection, 4 = low-level
    $mail->SMTPDebug = defined('APP_ENV') && APP_ENV === 'development' ? 2 : 0;
    $mail->Debugoutput = function($str, $level) {
        error_log("PHPMailer DEBUG [$level]: $str");
    };

    $mail->setFrom($config['from'], $config['fromName']);
}

function sendMail($to, $subject, $body) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        $err = 'Database connection not available';
        error_log($err);
        return false;
    }
    
    $config = getSmtpConfig($pdo);

    if ($config === false) {
        $err = 'SMTP not configured: smtp_from_email or smtp_host is missing in Settings.';
        error_log($err);
        $_SESSION['mail_error'] = $err;
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        configureMailer($mail, $config);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->msgHTML($body);
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], $body));
        return $mail->send();
    } catch (Exception $e) {
        $err = 'SMTP error: ' . $mail->ErrorInfo;
        error_log($err);
        $_SESSION['mail_error'] = $err;
        return false;
    }
}

function sendOTP($to, $otp, $purpose = 'verification', $firstName = '') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return false;
    
    $config = getSmtpConfig($pdo);

    if ($config === false) {
        $err = 'SMTP not configured: smtp_from_email or smtp_host is missing in Settings.';
        error_log($err);
        $_SESSION['mail_error'] = $err;
        return false;
    }

    $purposeLabel = ($purpose === 'password_reset') ? 'Password Reset' : 'Email Verification';
    $subject = "GradeView System - $purposeLabel OTP Code";

    $body = loadTemplate('otp_email.html', [
        'purpose_label' => $purposeLabel,
        'first_name' => $firstName ?: 'User',
        'otp_code' => $otp,
        'expires_in' => '10',
    ]);

    if ($body === false) {
        $err = 'Email template otp_email.html not found.';
        error_log($err);
        $_SESSION['mail_error'] = $err;
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        configureMailer($mail, $config);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->msgHTML($body);
        $mail->AltBody = "Your $purposeLabel OTP code is: $otp. It expires in 10 minutes.";
        return $mail->send();
    } catch (Exception $e) {
        $err = 'SMTP error: ' . $mail->ErrorInfo;
        error_log($err);
        $_SESSION['mail_error'] = $err;
        return false;
    }
}

function storeOTP($pdo, $userId, $otp, $purpose) {
    $expires = date('Y-m-d H:i:s', time() + 600);
    if ($purpose === 'password_reset') {
        $stmt = $pdo->prepare("UPDATE users SET reset_otp = ?, reset_otp_expires = ? WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE users SET email_otp = ?, email_otp_expires = ? WHERE id = ?");
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['otp_attempts'][$purpose . '|' . (int)$userId]);
    }
    return $stmt->execute([$otp, $expires, $userId]);
}

function verifyOTP($pdo, $emailOrId, $otp, $purpose, $isId = false) {
    if ($purpose === 'password_reset') {
        $otpCol = 'reset_otp';
        $expCol = 'reset_otp_expires';
    } else {
        $otpCol = 'email_otp';
        $expCol = 'email_otp_expires';
    }

    if ($isId) {
        $stmt = $pdo->prepare("SELECT id, email, $otpCol, $expCol FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$emailOrId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, email, $otpCol, $expCol FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$emailOrId]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    $attemptKey = $purpose . '|' . (int)$user['id'];
    $maxAttempts = 5;
    $sessionActive = (session_status() === PHP_SESSION_ACTIVE);
    $attempts = ($sessionActive && isset($_SESSION['otp_attempts'][$attemptKey]))
        ? (int)$_SESSION['otp_attempts'][$attemptKey]
        : 0;
    if ($sessionActive && $attempts >= $maxAttempts) {
        return false;
    }

    $stored = (string)($user[$otpCol] ?? '');
    $valid = ($stored !== ''
        && hash_equals($stored, (string)$otp)
        && !empty($user[$expCol])
        && strtotime($user[$expCol]) >= time());

    if (!$valid) {
        if ($sessionActive) {
            $_SESSION['otp_attempts'][$attemptKey] = $attempts + 1;
        }
        return false;
    }

    if ($sessionActive) {
        unset($_SESSION['otp_attempts'][$attemptKey]);
    }
    return $user;
}

function clearOTP($pdo, $userId, $purpose) {
    if ($purpose === 'password_reset') {
        $pdo->prepare("UPDATE users SET reset_otp = NULL, reset_otp_expires = NULL WHERE id = ?")->execute([$userId]);
    } else {
        $pdo->prepare("UPDATE users SET email_otp = NULL, email_otp_expires = NULL WHERE id = ?")->execute([$userId]);
    }
}

function markEmailVerified($pdo, $userId) {
    $pdo->prepare("UPDATE users SET email_otp = NULL, email_otp_expires = NULL WHERE id = ?")->execute([$userId]);
}

function loadTemplate($templateName, $placeholders = []) {
    $templatePath = __DIR__ . '/../templates/email/' . $templateName;
    if (!file_exists($templatePath)) {
        return false;
    }

    $content = file_get_contents($templatePath);
    if ($content === false) {
        return false;
    }

    foreach ($placeholders as $key => $value) {
        $content = str_replace('{{' . $key . '}}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $content);
    }

    return $content;
}

function sendVerificationEmail($pdo, $user) {
    $otp = generateOTP(6);
    storeOTP($pdo, $user['id'], $otp, 'verification');
    return sendOTP($user['email'], $otp, 'verification', $user['first_name'] ?? 'User');
}

function sendPasswordResetEmail($pdo, $user) {
    $otp = generateOTP(6);
    storeOTP($pdo, $user['id'], $otp, 'password_reset');
    return sendOTP($user['email'], $otp, 'password_reset', $user['first_name'] ?? 'User');
}

function isEmailConfigured($pdo) {
    return !empty(getSystemSetting($pdo, 'smtp_from_email', ''));
}
