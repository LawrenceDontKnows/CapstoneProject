<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/mailer.php';

$message = '';
$message_type = '';
$email = '';
$isRegistration = false;
$userId = null;

// Determine if this is a registration verification flow
if (!empty($_SESSION['pending_verify_user_id'])) {
    $isRegistration = true;
    $userId = $_SESSION['pending_verify_user_id'];
    $email = $_SESSION['pending_verify_email'] ?? '';
}

// Handle resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $resendEmail = trim($_POST['email'] ?? '');
    if (empty($resendEmail)) {
        $message = 'Please enter your email address.';
        $message_type = 'warning';
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$resendEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $sent = sendVerificationEmail($pdo, $user);
            if ($sent) {
                $message = 'A new OTP code has been sent to your email.';
                $message_type = 'success';
                $email = $resendEmail;
                if ($isRegistration) {
                    $_SESSION['pending_verify_user_id'] = $user['id'];
                    $_SESSION['pending_verify_email'] = $user['email'];
                }
            } else {
                $message = isset($_SESSION['mail_error']) ? $_SESSION['mail_error'] : 'Failed to resend OTP.';
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    $verifyEmail = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');

    if (empty($verifyEmail) || empty($otp)) {
        $message = 'Please enter both email and OTP code.';
        $message_type = 'warning';
    } else {
        $user = verifyOTP($pdo, $verifyEmail, $otp, 'verification');
        if ($user) {
            // Fetch full user record for session data
            $fullStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
            $fullStmt->execute([$user['id']]);
            $fullUser = $fullStmt->fetch(PDO::FETCH_ASSOC);
            markEmailVerified($pdo, $user['id']);

            if ($isRegistration && $userId == $user['id']) {
                // Complete registration: log the user in directly
                setupSessionSecurity();
                $_SESSION['user_id'] = $fullUser['id'];
                $_SESSION['username'] = $fullUser['username'];
                $_SESSION['role'] = $fullUser['role'];
                $_SESSION['first_name'] = $fullUser['first_name'];
                $_SESSION['last_name'] = $fullUser['last_name'];
                $_SESSION['display_name'] = $fullUser['first_name'] . ' ' . $fullUser['last_name'];
                $_SESSION['email'] = $fullUser['email'];
                unset($_SESSION['pending_verify_user_id']);
                unset($_SESSION['pending_verify_email']);
                unset($_SESSION['registration_success']);
                $dashboardMsg = 'Account verified successfully! Welcome to GradeView.';
                header("Location: redirect.php?url=" . urlencode(getDashboardUrl()) . "&delay=3&msg=" . urlencode($dashboardMsg) . "&type=success");
                exit;
            }

            $message = 'Email verified successfully! You can now log in.';
            $message_type = 'success';
            $email = $verifyEmail;
        } else {
            $message = 'Invalid or expired OTP code. Please request a new one.';
            $message_type = 'danger';
            $email = $verifyEmail;
        }
    }
}

// If user is already verified (registration complete), redirect
if ($isRegistration && !empty($_SESSION['user_id'])) {
    header("Location: redirect.php?url=" . urlencode('students.php') . "&delay=3");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .login-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { background: #ffffff; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); padding: 2.5rem; width: 100%; max-width: 420px; border: none; }
        .logo { text-align: center; margin-bottom: 2rem; }
        .logo img { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-bottom: 1rem; }
        .logo h3 { color: #1e293b; font-weight: 800; }
        .otp-input { width: 50px; height: 50px; text-align: center; font-size: 24px; font-weight: bold; }
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
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <img src="image/aclc.jpg" alt="Logo">
                <h3><?php echo $isRegistration ? 'Verify Your Account' : 'Verify Email'; ?></h3>
                <p class="text-muted">GradeView Portal</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <input type="hidden" name="action" value="verify">
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required placeholder="Enter your registered email">
                </div>
                <div class="mb-3">
                    <label class="form-label">OTP Code</label>
                    <input type="hidden" name="otp" id="combinedOtp">
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
                <button type="submit" class="btn btn-primary w-100">Verify OTP</button>
            </form>

            <form method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <button type="submit" class="btn btn-outline-secondary w-100"><i class="fas fa-redo me-1"></i> Resend OTP Code</button>
            </form>

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

            otpBoxes.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
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
        });
    </script>
</body>
</html>
