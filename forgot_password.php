<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/mailer.php';

$message = '';
$message_type = '';
$email = '';
$userId = null;
$otpVerified = false;
$showOtpForm = false;
$showResetForm = false;

// Clear reset session on fresh GET visit so it always starts at Step 1
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['otp_verified']);
    unset($_SESSION['otp_verified_user_id']);
}

// Handle "Send OTP" request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = 'Please enter your email address.';
        $message_type = 'warning';
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $sent = sendPasswordResetEmail($pdo, $user);
            if ($sent) {
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_user_id'] = $user['id'];
                $userId = $user['id'];
                $email = $user['email'];
                $showOtpForm = true;
                $message = 'A 6-digit OTP code has been sent to your email.';
                $message_type = 'success';

                // Create notification for forgot password OTP sent
                try {
                    $pdo->prepare("INSERT INTO notifications (sender_id, recipient_id, message) VALUES (?, ?, ?)")
                        ->execute([$user['id'], $user['id'], 'Forgot password OTP code sent to your email.']);
                } catch (Exception $e) {
                    error_log("Notification insert error (OTP sent): " . $e->getMessage());
                }
            } else {
                $message = isset($_SESSION['mail_error']) ? $_SESSION['mail_error'] : 'Failed to send reset OTP. Please contact the administrator.';
                unset($_SESSION['mail_error']);
                $message_type = 'danger';
            }
        } else {
            $message = 'No active account found with that email.';
            $message_type = 'danger';
        }
    }
}

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $email = $_SESSION['reset_email'] ?? trim($_POST['email'] ?? '');
    $userId = $_SESSION['reset_user_id'] ?? null;
    $otp = trim($_POST['otp'] ?? '');

    if (empty($otp)) {
        $message = 'Please enter the OTP code.';
        $message_type = 'warning';
        $showOtpForm = true;
    } elseif (!$userId) {
        $message = 'Invalid request. Please request a password reset first.';
        $message_type = 'danger';
    } else {
        $user = verifyOTP($pdo, $email, $otp, 'password_reset');
        if ($user) {
            $_SESSION['otp_verified'] = true;
            $_SESSION['otp_verified_user_id'] = $userId;
            $otpVerified = true;
            $showResetForm = true;
            $message = 'OTP verified successfully! Please enter your new password.';
            $message_type = 'success';
        } else {
            $message = 'Invalid or expired OTP code. Please request a new one.';
            $message_type = 'danger';
            $showOtpForm = true;
        }
    }
}

// Handle resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $userId = $_SESSION['reset_user_id'] ?? null;
    $email = $_SESSION['reset_email'] ?? '';

    if (!$userId) {
        $message = 'Invalid request. Please request a password reset first.';
        $message_type = 'danger';
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $sent = sendPasswordResetEmail($pdo, $user);
            if ($sent) {
                $message = 'A new OTP code has been sent to your email.';
                $message_type = 'success';
                $showOtpForm = true;
            } else {
                $message = isset($_SESSION['mail_error']) ? $_SESSION['mail_error'] : 'Failed to resend OTP.';
                unset($_SESSION['mail_error']);
                $message_type = 'danger';
            }
        } else {
            $message = 'User account not found.';
            $message_type = 'danger';
        }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $userId = $_SESSION['otp_verified_user_id'] ?? null;

    if (!$userId) {
        $message = 'Invalid request. Please verify OTP first.';
        $message_type = 'danger';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $message_type = 'danger';
            $showResetForm = true;
        } elseif ($password !== $confirm) {
            $message = 'Passwords do not match.';
            $message_type = 'danger';
            $showResetForm = true;
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expires = NULL WHERE id = ?");
            $stmt->execute([$hashed, $userId]);

            // Create notification for password reset
            try {
                $pdo->prepare("INSERT INTO notifications (sender_id, recipient_id, message) VALUES (?, ?, ?)")
                    ->execute([$userId, $userId, 'You have successfully reset your password.']);
            } catch (Exception $e) {
                error_log("Notification insert error (password reset): " . $e->getMessage());
            }

            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['otp_verified']);
            unset($_SESSION['otp_verified_user_id']);

            $resetMsg = 'Password reset successfully! You can now log in.';
            header("Location: redirect.php?url=" . urlencode('index.php') . "&fade=1&msg=" . urlencode($resetMsg) . "&type=success");
            exit;
        }
    }
}

// Determine what to show based on session state
if (!empty($_SESSION['reset_user_id']) && !empty($_SESSION['reset_email'])) {
    $userId = $_SESSION['reset_user_id'];
    $email = $_SESSION['reset_email'];
}

if (!empty($_SESSION['otp_verified']) && $_SESSION['otp_verified_user_id'] == $userId) {
    $otpVerified = true;
    $showResetForm = true;
} elseif (!empty($_SESSION['reset_user_id'])) {
    $showOtpForm = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/GradeView Online.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .login-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { background: #ffffff; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); padding: 2.5rem; width: 100%; max-width: 420px; border: none; }
        .logo { text-align: center; margin-bottom: 2rem; }
        .logo img { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-bottom: 1rem; }
        .logo h3 { color: #1e293b; font-weight: 800; }
        .otp-group {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .otp-box {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
        }
        .otp-box:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            outline: none;
        }
        .otp-box.filled {
            border-color: #10b981;
            background-color: #f0fdf4;
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .pw-toggle {
            cursor: pointer;
            background-color: transparent;
            border: none;
            color: #94a3b8;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pw-toggle:hover { color: #1e3a8a; }
        .input-group .form-control {
            flex: 1 1 auto;
            min-width: 0;
        }
        @media (max-width: 576px) {
            .login-card { padding: 1.5rem; }
            .otp-box { width: 44px; height: 44px; font-size: 20px; }
            .otp-group { gap: 6px; }
            .input-group { flex-direction: column; gap: 4px; }
            .input-group .form-control { width: 100%; }
            .input-group .pw-toggle { align-self: flex-end; padding: 4px 8px; font-size: 14px; width: auto; height: auto; }
            .btn { width: 100%; padding: 10px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <img src="image/aclc.jpg" alt="Logo">
                <h3>Forgot Password</h3>
                <p class="text-muted">GradeView Portal</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if (!$showOtpForm && !$showResetForm && !$otpVerified): ?>
            <!-- Step 1: Send OTP -->
            <form method="POST">
                <input type="hidden" name="action" value="send_otp">
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required placeholder="Enter your registered email">
                    <div class="form-text">We'll send a 6-digit OTP code to your email.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Send OTP Code</button>
            </form>
            <?php endif; ?>

            <?php if ($showOtpForm && !$otpVerified): ?>
            <!-- Step 2: Verify OTP -->
            <form method="POST" id="otpForm">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="otp" id="combinedOtp">
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">OTP Code</label>
                    <div class="otp-group">
                        <input type="text" name="otp_digit_1" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                        <input type="text" name="otp_digit_2" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                        <input type="text" name="otp_digit_3" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                        <input type="text" name="otp_digit_4" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                        <input type="text" name="otp_digit_5" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                        <input type="text" name="otp_digit_6" class="form-control otp-box" maxlength="1" required inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                    </div>
                    <div class="form-text">Enter the 6-digit code sent to your email.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="verifyOtpBtn" disabled>Verify OTP</button>
            </form>

            <form method="POST" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn btn-outline-secondary w-100"><i class="fas fa-redo me-1"></i> Resend OTP Code</button>
            </form>
            <?php endif; ?>

            <?php if ($showResetForm && $otpVerified): ?>
            <!-- Step 3: Reset Password -->
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="password" id="newPassword" required minlength="8">
                        <span class="pw-toggle" onclick="togglePassword('newPassword', 'newEye')">
                            <i class="fas fa-eye" id="newEye"></i>
                        </span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="confirm_password" id="confirmPassword" required minlength="8">
                        <span class="pw-toggle" onclick="togglePassword('confirmPassword', 'confirmEye')">
                            <i class="fas fa-eye" id="confirmEye"></i>
                        </span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Reset Password</button>
            </form>
            <?php endif; ?>

            <div class="mt-3 text-center">
                <a href="index.php" class="text-decoration-none">Back to Login</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpBoxes = document.querySelectorAll('.otp-box');
            const form = document.getElementById('otpForm');
            const combinedOtp = document.getElementById('combinedOtp');

            if (otpBoxes.length > 0) {
                const verifyBtn = document.getElementById('verifyOtpBtn');

                function checkComplete() {
                    let allFilled = true;
                    otpBoxes.forEach(input => {
                        if (input.value) {
                            input.classList.add('filled');
                        } else {
                            input.classList.remove('filled');
                        }
                        if (!input.value) allFilled = false;
                    });
                    verifyBtn.disabled = !allFilled;

                    // Auto-submit when all 6 digits are entered
                    if (allFilled) {
                        let digits = '';
                        otpBoxes.forEach(input => { digits += input.value; });
                        combinedOtp.value = digits;
                        form.submit();
                    }
                }

                otpBoxes.forEach((input, index) => {
                    input.addEventListener('input', function(e) {
                        this.value = this.value.replace(/[^0-9]/g, '');
                        checkComplete();
                        if (this.value.length === 1 && index < otpBoxes.length - 1) {
                            otpBoxes[index + 1].focus();
                        }
                    });

                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && !this.value && index > 0) {
                            otpBoxes[index - 1].focus();
                        }
                    });
                });

                form.addEventListener('submit', function() {
                    let digits = '';
                    otpBoxes.forEach(input => { digits += input.value; });
                    combinedOtp.value = digits;
                });
            }
        });

        function togglePassword(inputId, eyeId) {
            var input = document.getElementById(inputId);
            var eye = document.getElementById(eyeId);
            if (input && eye) {
                if (input.type === "password") {
                    input.type = "text";
                    eye.classList.remove('fa-eye');
                    eye.classList.add('fa-eye-slash');
                } else {
                    input.type = "password";
                    eye.classList.remove('fa-eye-slash');
                    eye.classList.add('fa-eye');
                }
            }
        }
    </script>
</body>
</html>
