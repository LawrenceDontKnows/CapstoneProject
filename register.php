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
                        guardian_status, guardian_phone, guardian_address, password, role
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student')";
            
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --register-bg-color: <?php echo htmlspecialchars($registerBgColor); ?>;
        }
        body { 
            font-family: 'Inter', sans-serif; 
            margin: 0;
            min-height: 100vh;
            position: relative;
        }
        /* Background styles */
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
        .reg-card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: 2.5rem;
            width: 100%;
            max-width: 900px;
            margin: 2rem auto;
            border: none;
        }
        .header-box { text-align: center; margin-bottom: 1.5rem; }
        .header-box h2 { font-weight: 800; color: #1e293b; }
        .btn-back { color: #1e3a8a; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .btn-back:hover { color: #dc2626; }
        h3 { font-size: 1.1rem; font-weight: 700; color: #1e3a8a; margin: 25px 0 15px 0; text-transform: uppercase; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #475569; margin-bottom: 4px; }
        .form-control, .form-select { 
            border-radius: 8px; 
            padding: 10px 12px; 
            border: 1px solid #e2e8f0; 
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            outline: none;
        }
        .btn-primary { 
            background: #1e3a8a; border: none; padding: 12px; font-weight: 700; border-radius: 8px; transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.3);
        }
        .btn-primary:hover { 
            background: #172a61; transform: translateY(-3px);
            box-shadow: 0 7px 10px -1px rgba(30, 58, 138, 0.4);
        }
        .is-invalid-custom { border: 2px solid #dc2626 !important; background-color: #fff8f8; }
        .is-valid-custom { border: 2px solid #10b981 !important; background-color: #f0fdf4; }
        .invalid-feedback-custom { color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 4px; display: none; }
        .confirm-label { font-weight: 700; color: #1e3a8a; font-size: 0.75rem; text-transform: uppercase; margin-top: 8px; }
        .confirm-value { color: #333; margin-bottom: 5px; border-bottom: 1px dashed #eee; padding-bottom: 2px; font-size: 0.95rem; word-break: break-all; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .modal-header { background: #1e3a8a; color: white; border-bottom: none; }
        
        @media (max-width: 576px) {
            body { padding: 10px 0; }
            .reg-card { padding: 1.5rem; margin: 10px; border-radius: 10px; }
            h3 { font-size: 0.95rem; }
            .header-box h2 { font-size: 1.5rem; }
        }
        .input-group {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-group:focus-within {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        .input-group .form-control {
            border: none;
            box-shadow: none !important;
        }
        .input-group-text {
            cursor: pointer;
            background-color: transparent;
            border: none;
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
            <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
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