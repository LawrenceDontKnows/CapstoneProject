<?php
include 'includes/conn.php'; 
include 'includes/functions.php';
include 'includes/mailer.php';

$regBgType = getSystemSetting($pdo, 'register_bg_type', 'color');
$regBgColor = getSystemSetting($pdo, 'register_bg_color', '#f1f5f9');
$regBgImage = getSystemSetting($pdo, 'register_bg_image', '');
$regBgOpacity = (int)getSystemSetting($pdo, 'register_bg_opacity', 100);

// Default register background color for footer detection
$registerBgColor = $regBgColor;

// CSRF Protection: Generate a token if one doesn't exist.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$toast_msg = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $errors = [];

    // 1. CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid form submission. Please try again.";
    }

    $email = trim($_POST['email']);
    $confirm_email = trim($_POST['confirm_email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $username = trim($_POST['username']); 
    $age = (int)$_POST['age'];
    $birth_date = $_POST['birth_date']; 
    $phone = trim($_POST['phone_number']);

    // 2. Simple Honeypot & Rate Limiting
    if (!empty($_POST['website_url'])) {
        $errors[] = "Bot detected.";
    }
    $submit_time = time() - (int)$_POST['form_load_time'];
    if ($submit_time < 5) {
        $errors[] = "Form submitted too quickly. Please try again.";
    }
   
    // Server-side Birth Date Limitation (1900 to Present)
    $current_year = date("Y");
    $birth_year = date("Y", strtotime($birth_date));
    
    if ($birth_year < 1900 || $birth_year > $current_year) {
        $errors[] = "Invalid Birth Date";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $errors[] = "Invalid: Birth date format is incorrect.";
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        $errors[] = "Invalid: Email or Username already exists.";
    }

    if ($age < 18 || $age > 80) {
        $errors[] = "Invalid: Age must be between 18 and 80.";
    }

    if (strlen($password) < 8) $errors[] = "Invalid: Password must be at least 8 characters long.";

    if (!preg_match('/^09[0-9]{9}$/', $phone)) $errors[] = "Invalid: Student phone must start with 09 and be 11 digits.";
    if (!preg_match('/^09[0-9]{9}$/', $_POST['guardian_phone'])) $errors[] = "Invalid: Guardian phone must start with 09 and be 11 digits.";
    if ($email !== $confirm_email) $errors[] = "Invalid: Emails do not match.";
    if ($password !== $confirm_password) $errors[] = "Invalid: Passwords do not match.";

    if (empty($errors)) {
        try {
            $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (
                        first_name, last_name, suffix, age, birth_date, sex,
                        phone_number, course, year_level, email, username, home_address, 
                        city, province, guardian_name, guardian_occupation, 
                        guardian_status, guardian_phone, guardian_address, password, role, profile_picture
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'image/default.png')";
            
            $insert = $pdo->prepare($sql);
            $insert->execute([
                $_POST['first_name'], $_POST['last_name'], $_POST['suffix'] ?? "", 
                $age, $birth_date, $_POST['sex'], $phone, $_POST['course'], $_POST['year_level'], 
                $email, $username, $_POST['home_address'], $_POST['city'], $_POST['province'],
                $_POST['guardian_name'], $_POST['guardian_occupation'], $_POST['guardian_status'],
                $_POST['guardian_phone'], $_POST['guardian_address'], $hashed_pw
            ]);

            $user_id = $pdo->lastInsertId();
            $user = [
                'id' => $user_id,
                'first_name' => $_POST['first_name'],
                'email' => $email
            ];
            $sent = sendVerificationEmail($pdo, $user);
            if (!$sent) {
                $toast_msg = isset($_SESSION['mail_error']) ? $_SESSION['mail_error'] : 'Failed to send OTP email. You can resend it on the next screen.';
                unset($_SESSION['mail_error']);
            }

            $_SESSION['registration_success'] = [
                'user_id' => $user_id,
                'first_name' => $_POST['first_name'],
                'last_name' => $_POST['last_name'],
                'suffix' => $_POST['suffix'] ?? "",
                'birth_date' => $birth_date,
                'age' => $age,
                'sex' => $_POST['sex'],
                'course' => $_POST['course'],
                'year_level' => $_POST['year_level'],
                'phone' => $phone,
                'email' => $email,
                'username' => $username,
                'address' => $_POST['home_address'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'guardian_name' => $_POST['guardian_name'],
                'guardian_status' => $_POST['guardian_status'],
                'guardian_phone' => $_POST['guardian_phone'],
                'guardian_occupation' => $_POST['guardian_occupation'],
                'guardian_address' => $_POST['guardian_address']
            ];
            $_SESSION['pending_verify_user_id'] = $user_id;
            $_SESSION['pending_verify_email'] = $email;
            header("Location: redirect.php?url=" . urlencode('verify_email.php') . "&delay=3&msg=" . urlencode('Registration complete! Please verify your email.') . "&type=success");
            exit;
        } catch (PDOException $e) {
            $toast_msg = "Database Error: " . $e->getMessage();
        }
    } else {
        $toast_msg = implode("\n", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/responsive.css" rel="stylesheet">
    <style>
        :root {
            --register-bg-color: <?php echo htmlspecialchars($registerBgColor); ?>;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            min-height: 100dvh;
            position: relative;
            overflow-x: hidden;
            background: #0b1020;
            color: #f8fafc;
        }
        /* Rich abstract fluid-ink backdrop */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -3;
            background:
                radial-gradient(38% 38% at 18% 22%, rgba(255, 255, 255, 0.35) 0%, rgba(255, 255, 255, 0.06) 40%, transparent 70%),
                radial-gradient(30% 30% at 82% 30%, rgba(156, 163, 175, 0.45) 0%, transparent 70%),
                radial-gradient(45% 45% at 78% 82%, rgba(255, 255, 255, 0.18) 0%, transparent 70%),
                radial-gradient(40% 40% at 22% 80%, rgba(113, 113, 122, 0.4) 0%, transparent 70%),
                linear-gradient(135deg, #121417 0%, #1f2227 100%);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        /* Background image layer (admin-configured) */
        .register-bg {
            position: fixed;
            inset: 0;
            z-index: -2;
            background: <?php echo htmlspecialchars($regBgColor); ?>;
            overflow: hidden;
        }
        .register-bg .bg-image {
            position: absolute;
            inset: 0;
            opacity: <?php echo $regBgOpacity / 100; ?>;
            display: <?php echo ($regBgType === 'image' && $regBgImage) ? 'flex' : 'none'; ?>;
            align-items: center;
            justify-content: center;
        }
        .register-bg .bg-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        /* Frosted glass window container */
        .reg-card {
            position: relative;
            background: rgba(26, 28, 32, 0.52);
            backdrop-filter: blur(24px) saturate(125%);
            -webkit-backdrop-filter: blur(24px) saturate(125%);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 18px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28), 0 30px 70px rgba(0, 0, 0, 0.65);
            padding: 40px;
            padding-top: 64px;
            width: 100%;
            max-width: 920px;
            margin: 80px auto clamp(12px, 3vw, 32px);
            color: #f5f5f4;
            color-scheme: dark;
        }
        .header-box { text-align: center; margin-bottom: 1.5rem; }
        .header-box h2 { font-weight: 800; color: #f5f5f4; letter-spacing: -0.5px; }
        .header-box img {
            display: block;
            position: absolute;
            top: -64px;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
            width: 128px !important;
            height: 128px !important;
            object-fit: cover !important;
            border-radius: 50%;
            background: transparent;
            z-index: 2;
            filter: drop-shadow(0 10px 22px rgba(0, 0, 0, 0.55));
        }
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-14px) scale(1.04); }
        }
        @media (prefers-reduced-motion: reduce) {
            .header-box img { animation: none; }
        }
        .btn-back {
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: background 0.2s ease, color 0.2s ease;
        }
        .btn-back:hover { color: #ffffff; background: rgba(255, 255, 255, 0.2); }
        h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #d1d5db;
            margin: 26px 0 14px 0;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 8px;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.82rem;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            min-height: 44px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #ffffff;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .form-control::placeholder { color: rgba(255, 255, 255, 0.65); }
        .form-control:focus, .form-select:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(255, 255, 255, 0.7);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        .form-select option { background-color: #14192b; color: #ffffff; }
        .btn-primary {
            width: 100%;
            background: linear-gradient(135deg, #6366f1, #22d3ee);
            border: none;
            padding: 14px 20px;
            min-height: 46px;
            font-weight: 700;
            border-radius: 12px;
            color: #ffffff;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            box-shadow: 0 10px 24px rgba(99, 102, 241, 0.45);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            color: #ffffff;
            background: linear-gradient(135deg, #6366f1, #22d3ee);
            filter: brightness(1.05);
            box-shadow: 0 14px 30px rgba(34, 211, 238, 0.5);
        }
        .btn-primary:disabled { filter: grayscale(0.4) brightness(0.9); box-shadow: none; }
        .is-invalid-custom { border: 2px solid #f87171 !important; background-color: rgba(248, 113, 113, 0.12) !important; }
        .is-valid-custom { border: 2px solid #34d399 !important; background-color: rgba(52, 211, 153, 0.12) !important; }
        .invalid-feedback-custom { color: #fca5a5; font-size: 0.75rem; font-weight: 600; margin-top: 4px; display: none; }
        .confirm-label { font-weight: 700; color: #67e8f9; font-size: 0.75rem; text-transform: uppercase; margin-top: 8px; }
        .confirm-value { color: #e2e8f0; margin-bottom: 5px; border-bottom: 1px dashed rgba(255,255,255,0.2); padding-bottom: 2px; font-size: 0.95rem; word-break: break-all; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .modal-header { background: #1e3a8a; color: white; border-bottom: none; }
        .text-muted { color: #94a3b8 !important; }

        @media (max-width: 576px) {
            body { padding: 10px 0; }
            .reg-card { padding: 18px; padding-top: 56px; margin: 64px 10px 10px; border-radius: 18px; }
            .header-box img { width: 96px !important; height: 96px !important; top: -48px; }
            h3 { font-size: 0.95rem; }
            .header-box h2 { font-size: 1.5rem; }
        }
        .input-group {
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-group:focus-within {
            border-color: rgba(255, 255, 255, 0.65);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.18);
        }
        .input-group .form-control {
            border: none;
            box-shadow: none !important;
            background: transparent;
        }
        .input-group-text {
            cursor: pointer;
            background-color: transparent;
            border: none;
            color: #cbd5e1;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <div class="register-bg" id="registerBg">
        <?php if ($regBgType === 'image' && $regBgImage): ?>
            <div class="bg-image">
                <img src="<?php echo htmlspecialchars($regBgImage); ?>" alt="Registration Background">
            </div>
        <?php endif; ?>
    </div>

    <div class="toast-container">
        <div id="errorToast" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="reg-card">
        <a href="index.php" class="btn-back mb-3"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <div class="header-box">
            <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="Logo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
            <h2 class="mt-2">Student Registration</h2>
        </div>
        <form method="POST" id="registrationForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="form_load_time" value="<?php echo time(); ?>">
            <input type="text" name="website_url" style="display:none;">

            <h3><i class="fas fa-id-card me-2"></i>Personal Information</h3>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" name="first_name" id="val_fname" placeholder="Juan" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" name="last_name" id="val_lname" placeholder="Dela Cruz" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Suffix (Optional)</label>
                    <input type="text" class="form-control" name="suffix" id="val_suffix" placeholder="Jr / Sr / III" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Birth Date</label>
                    <input type="date" class="form-control" name="birth_date" id="val_bdate" 
                           min="1900-01-01" max="<?php echo date('Y-12-31'); ?>" required>
                    <div class="invalid-feedback-custom">Invalid Birth Date</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Age</label>
                    <input type="text" class="form-control" name="age" id="val_age" placeholder="Auto-Filled" required readonly>
                    <div class="invalid-feedback-custom">Required (18-80)</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sex</label>
                    <select class="form-select" name="sex" id="val_sex" required>
                        <option value="">-- Select --</option>
                        <option>Male</option><option>Female</option>
                    </select>
                    <div class="invalid-feedback-custom">Please select</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Course</label>
                    <select class="form-select" name="course" id="val_course" required>
                        <option value="">-- Choose Course --</option>
                        <option>BSCS</option><option>BSIT</option><option>BSHM</option><option>BSBA</option><option>BSA</option>
                    </select>
                    <div class="invalid-feedback-custom">Select a course</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Year Level</label>
                    <select class="form-select" name="year_level" id="val_year" required>
                        <option value="">-- Choose Year --</option>
                        <option>1st Year</option><option>2nd Year</option><option>3rd Year</option><option>4th Year</option>
                    </select>
                    <div class="invalid-feedback-custom">Select year level</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone Number</label>
                    <input type="text" class="form-control" name="phone_number" id="val_phone" maxlength="11" placeholder="09123456789" required oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div class="invalid-feedback-custom">Must start with 09 and be 11 digits</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" class="form-control" name="city" id="val_city" placeholder="Palo" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">City is required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control" name="province" id="val_province" placeholder="Leyte" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Province is required</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Home Address</label>
                    <textarea class="form-control" name="home_address" id="val_addr" rows="2" placeholder="Brgy. Name, Street Name" required></textarea>
                    <div class="invalid-feedback-custom">Complete address required</div>
                </div>
            </div>

            <h3><i class="fas fa-key me-2"></i>Account Credentials</h3>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" id="val_email" placeholder="e.g. juandelacruz@email.com" required>
                    <div class="invalid-feedback-custom">Valid email required</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Email</label>
                    <input type="email" class="form-control" name="confirm_email" id="val_cemail" placeholder="Re-type your email" required>
                    <div class="invalid-feedback-custom">Emails do not match</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" name="username" id="val_user" placeholder="Choose a unique username" required>
                    <div class="invalid-feedback-custom">Username is required</div>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <div class="input-group" id="pass-group">
                        <input type="password" class="form-control" name="password" id="password" placeholder="Min. 8 characters" required>
                        <span class="input-group-text" onclick="toggleVisibility('password', 'eye1')">
                            <i class="fas fa-eye" id="eye1"></i>
                        </span>
                    </div>
                    <div class="invalid-feedback-custom" id="pass_feedback">Password must be at least 8 characters</div>
                    <div class="progress mt-2" style="height: 5px;">
                        <div id="pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                    </div>
                    <small id="pw-strength-text" class="text-muted" style="font-size: 0.7rem;"></small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-group" id="cpass-group">
                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Repeat password" required>
                        <span class="input-group-text" onclick="toggleVisibility('confirm_password', 'eye2')">
                            <i class="fas fa-eye" id="eye2"></i>
                        </span>
                    </div>
                    <div class="invalid-feedback-custom" id="cpass_feedback">Passwords do not match</div>
                </div>
            </div>

            <h3><i class="fas fa-users me-2"></i>Guardian Details</h3>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Guardian Name</label>
                    <input type="text" class="form-control" name="guardian_name" id="val_gname" placeholder="Full Name" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Guardian name required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian Status</label>
                    <select class="form-select" name="guardian_status" id="val_gstatus" required>
                        <option value="">-- Select Status --</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                    </select>
                    <div class="invalid-feedback-custom">Status required</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian Phone</label>
                    <input type="text" class="form-control" name="guardian_phone" id="val_gphone" maxlength="11" placeholder="09123456789" required oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div class="invalid-feedback-custom">Must start with 09 and be 11 digits</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Guardian Occupation</label>
                    <input type="text" class="form-control" name="guardian_occupation" id="val_gocc" placeholder="e.g. Teacher" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Occupation required</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Guardian Address</label>
                    <textarea class="form-control" name="guardian_address" id="val_gaddr" rows="2" placeholder="Complete address" required></textarea>
                    <div class="invalid-feedback-custom">Complete address required</div>
                </div>
                <div class="col-12"><button type="submit" class="btn btn-primary w-100 mt-4"><i class="fas fa-user-plus me-2"></i> Register</button></div>
            </div>
        </form>
    </div>

    <?php include 'footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        /* Global Declarations */
        var form = document.getElementById('registrationForm');
        var toastInstance = new bootstrap.Toast(document.getElementById('errorToast'), { autohide: true, delay: 3000 });

        /* Toggle Visibility Function - Globally Scoped */
        window.toggleVisibility = function(inputId, eyeId) {
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
        };

        window.showPopup = function(msg) {
            var toastBody = document.getElementById('toastMsg');
            if (toastBody) {
                toastBody.innerText = msg;
                toastInstance.show();
            }
        };
<?php if (!empty($toast_msg)): ?>
    window.showPopup(<?php echo json_encode($toast_msg); ?>);
<?php endif; ?>
        window.validateField = function(input) {
            let isValid = true;
            let isNeutral = false;
            const val = input.value.trim();
            const isRequired = input.hasAttribute('required');
            
            const container = input.closest('div[class*="col-"], .input-group');
            const feedback = container ? container.querySelector('.invalid-feedback-custom') : null;
            
            if (input.id === "val_suffix") {
                input.classList.remove('is-invalid-custom');
                if (val !== "") {
                    input.classList.add('is-valid-custom');
                } else {
                    input.classList.remove('is-valid-custom');
                }
                return true;
            }

            if (val === "" || val === null || (input.tagName === "SELECT" && input.value === "")) {
                if (isRequired) isValid = false;
                else isNeutral = true;
            } else {
                if (input.id === "val_bdate") {
                    const year = new Date(val).getFullYear();
                    const currentYear = new Date().getFullYear();
                    if (year < 1900 || year > currentYear || isNaN(year)) isValid = false;
                }
            
                if (["val_fname", "val_lname", "val_gname", "val_city", "val_province"].includes(input.id)) {
                    if (val.length < 2) isValid = false;
                }
            
                if (input.id === "val_cemail") {
                    if (val !== document.getElementById('val_email').value) isValid = false;
                }
            
                if (input.id === "password") {
                    if (val.length < 8) isValid = false;
                }
            
                if (input.id === "confirm_password") {
                    if (val !== document.getElementById('password').value) isValid = false;
                }
            
                if (input.name === "age") {
                    const ageVal = parseInt(val);
                    if (isNaN(ageVal) || ageVal < 18 || ageVal > 80) isValid = false;
                }
            
                if ((input.name === "phone_number" || input.name === "guardian_phone") && !/^09\d{9}$/.test(val)) isValid = false;
                
                if (input.type === "email" && val !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) isValid = false;
            }
            
            input.classList.remove('is-invalid-custom', 'is-valid-custom');
            const group = input.closest('.input-group');
            if (group) group.classList.remove('is-invalid-custom', 'is-valid-custom');

            if (isNeutral) {
                if (feedback) feedback.style.display = 'none';
            } else if (!isValid) {
                input.classList.add('is-invalid-custom');
                if (group) group.classList.add('is-invalid-custom');
                if (feedback) feedback.style.display = 'block';
            } else {
                input.classList.add('is-valid-custom');
                if (group) group.classList.add('is-valid-custom');
                if (feedback) feedback.style.display = 'none';
            }
            
            return isValid;
        };

// ==========================================
// 1. AUTOMATIC AGE CALCULATION
// ==========================================
const birthDateInput = document.getElementById('val_bdate');
const ageInput = document.getElementById('val_age');

birthDateInput.addEventListener('change', function () {
    if (!this.value) {
        ageInput.value = '';
        validateField(ageInput);
        return;
    }

    const birthDate = new Date(this.value + 'T00:00:00');
    const today = new Date();

    let age = today.getFullYear() - birthDate.getFullYear();

    const monthDifference = today.getMonth() - birthDate.getMonth();

    if (
        monthDifference < 0 ||
        (monthDifference === 0 && today.getDate() < birthDate.getDate())
    ) {
        age--;
    }

    ageInput.value = age >= 0 ? age : '';

    validateField(ageInput);
});

// ==========================================
// 2. PASSWORD STRENGTH METER
// ==========================================
const passwordInput = document.getElementById('password');
const strengthBar = document.getElementById('pw-strength-bar');
const strengthText = document.getElementById('pw-strength-text');

passwordInput.addEventListener('input', function () {
    const password = this.value;
    let score = 0;

    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    const levels = [
        { width: '0%', text: '', color: '' },
        { width: '20%', text: 'Very Weak', color: 'bg-danger' },
        { width: '40%', text: 'Weak', color: 'bg-danger' },
        { width: '60%', text: 'Fair', color: 'bg-warning' },
        { width: '80%', text: 'Good', color: 'bg-info' },
        { width: '100%', text: 'Strong', color: 'bg-success' }
    ];

    const level = levels[score];

    strengthBar.style.width = level.width;
    strengthBar.className = 'progress-bar ' + level.color;
    strengthText.textContent = level.text;

    validateField(passwordInput);

    const confirmPassword = document.getElementById('confirm_password');

    if (confirmPassword.value !== '') {
        validateField(confirmPassword);
    }
});

// ==========================================
// 3. LIVE FIELD VALIDATION
// ==========================================
document.querySelectorAll(
    '#registrationForm input, #registrationForm select, #registrationForm textarea'
).forEach(function (field) {

    field.addEventListener('input', function () {
        if (this.id !== 'val_age') {
            validateField(this);
        }
    });

    field.addEventListener('change', function () {
        validateField(this);
    });
});

// ==========================================
// 4. REGISTRATION FORM VALIDATION
// ==========================================
form.addEventListener('submit', function (event) {

    let isFormValid = true;

    const fields = form.querySelectorAll(
        'input[required], select[required], textarea[required]'
    );

    fields.forEach(function (field) {
        if (!validateField(field)) {
            isFormValid = false;
        }
    });

    const email = document.getElementById('val_email').value.trim();
    const confirmEmail = document.getElementById('val_cemail').value.trim();

    const password = passwordInput.value;
    const confirmPassword = document.getElementById('confirm_password').value;

    if (email !== confirmEmail) {
        isFormValid = false;
        validateField(document.getElementById('val_cemail'));
    }

    if (password !== confirmPassword) {
        isFormValid = false;
        validateField(document.getElementById('confirm_password'));
    }

    if (!isFormValid) {
        event.preventDefault();
        window.showPopup('Please check the highlighted fields and correct the errors.');
    }
});
</script>
</body>
</html>