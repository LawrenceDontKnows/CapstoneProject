<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Reads SMTP settings from the database and returns a normalized config array.
 * Returns false if minimum required settings are missing.
 */

/**
 * Check whether Gmail API credentials are configured.
 */
function isGmailConfigured() {
    return !empty(getenv('GMAIL_CLIENT_ID'))
        && !empty(getenv('GMAIL_CLIENT_SECRET'))
        && !empty(getenv('GMAIL_REFRESH_TOKEN'))
        && !empty(getenv('GMAIL_SENDER_EMAIL'));
}

/**
 * Send an email through the Gmail API.
 */
function sendViaGmailApi($to, $subject, $body) {
    if (!isGmailConfigured()) {
        return false;
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('Gmail API: Invalid recipient email address.');
        return false;
    }

    $clientId = getenv('GMAIL_CLIENT_ID');
    $clientSecret = getenv('GMAIL_CLIENT_SECRET');
    $refreshToken = getenv('GMAIL_REFRESH_TOKEN');
    $sender = getenv('GMAIL_SENDER_EMAIL');

    // Request a fresh access token.
    $ch = curl_init('https://oauth2.googleapis.com/token');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $tokenResponse = curl_exec($ch);
    $tokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($ch);
    curl_close($ch);

    if ($tokenResponse === false || $tokenCode !== 200) {
        error_log('Gmail token error: HTTP ' . $tokenCode . ' ' . $tokenError);
        return false;
    }

    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';

    if (!$accessToken) {
        error_log('Gmail API: Access token missing from response.');
        return false;
    }

    // Build the email message.
    $fromName = 'GradeView Online';

    $mime = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8')
        . ' <' . $sender . ">\r\n";

    $mime .= 'To: ' . $to . "\r\n";
    $mime .= 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n";
    $mime .= "MIME-Version: 1.0\r\n";
    $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
    $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $mime .= chunk_split(base64_encode($body));

    // Gmail requires URL-safe Base64.
    $rawMessage = rtrim(
        strtr(base64_encode($mime), '+/', '-_'),
        '='
    );

    // Send through Gmail API.
    $payload = json_encode([
        'raw' => $rawMessage
    ]);

    $ch = curl_init(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log(
            'Gmail send error: HTTP ' . $httpCode . ' ' .
            ($curlError ?: $response)
        );

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = 'Gmail API could not send the email. Check application logs.';
        }

        return false;
    }

    return true;
}

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
    $mail->Timeout = 10;

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
        error_log('Database connection not available');
        return false;
    }

    // Priority 1: Gmail API
    if (isGmailConfigured()) {
        return sendViaGmailApi($to, $subject, $body);
    }

    // Priority 2: Resend
    $resendKey = getenv('RESEND_API_KEY');

    if ($resendKey) {
        return sendViaResend($resendKey, $to, $subject, $body);
    }

    // Priority 3: SMTP
    $config = getSmtpConfig($pdo);

    if ($config === false) {
        $err = 'Email not configured: Gmail, Resend, or SMTP settings are missing.';
        error_log($err);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }

        return false;
    }

    $mail = new PHPMailer(true);

    try {
        configureMailer($mail, $config);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->msgHTML($body);
        $mail->AltBody = strip_tags(str_replace(
            ['<br>', '</p>'],
            ["\n", "\n"],
            $body
        ));

        return $mail->send();

    } catch (Exception $e) {
        $err = 'SMTP error: ' . $mail->ErrorInfo;
        error_log($err);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }

        return false;
    }
}



function sendOTP($to, $otp, $purpose = 'verification', $firstName = '') {
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo) {
        return false;
    }

    $purposeLabel = ($purpose === 'password_reset')
        ? 'Password Reset'
        : 'Email Verification';

    $subject = "GradeView Online - $purposeLabel OTP Code";

    $body = loadTemplate('otp_email.html', [
        'purpose_label' => $purposeLabel,
        'first_name' => $firstName ?: 'User',
        'otp_code' => $otp,
        'expires_in' => '10',
    ]);

    if ($body === false) {
        $err = 'Email template otp_email.html not found.';
        error_log($err);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }

        return false;
    }

    // Priority 1: Gmail API
    if (isGmailConfigured()) {
        return sendViaGmailApi($to, $subject, $body);
    }

    // Priority 2: Resend
    $resendKey = getenv('RESEND_API_KEY');

    if ($resendKey) {
        return sendViaResend($resendKey, $to, $subject, $body);
    }

    // Priority 3: SMTP
    $config = getSmtpConfig($pdo);

    if ($config === false) {
        $err = 'Email not configured: Gmail, Resend, or SMTP settings are missing.';
        error_log($err);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }

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

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }

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
    return isGmailConfigured()
        || !empty(getenv('RESEND_API_KEY'))
        || !empty(getSystemSetting($pdo, 'smtp_from_email', ''));
}

/**
 * Send an email via the Resend HTTP API (https://resend.com).
 * Used when RESEND_API_KEY is set, since many hosts (e.g. Railway) block SMTP.
 */
function sendViaResend($apiKey, $to, $subject, $body) {
    $from = getenv('RESEND_FROM') ?: getSystemSetting($GLOBALS['pdo'] ?? null, 'smtp_from_email', 'onboarding@resend.dev');
    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $body,
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code < 200 || $code >= 300) {
        $err = 'Resend error (HTTP ' . $code . '): ' . ($curlErr ?: $response);
        error_log($err);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mail_error'] = $err;
        }
        return false;
    }
    return true;
}
