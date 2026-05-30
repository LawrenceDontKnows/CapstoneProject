<?php
include 'includes/conn.php'; 
include 'includes/functions.php';

$message = "";
$toast_msg = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $errors = [];

    $email = $_POST['email'];
    $confirm_email = $_POST['confirm_email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $username = $_POST['username']; 
    $age = (int)$_POST['age'];
    $birth_date = $_POST['birth_date']; 
    $phone = $_POST['phone_number'];
    
    // Server-side Birth Date Limitation (1900 to Present)
    $current_year = date("Y");
    $birth_year = date("Y", strtotime($birth_date));
    
    if ($birth_year < 1900 || $birth_year > $current_year) {
        $errors[] = "Invalid Birth Date";
    }
    // Check if year format is exactly 4 digits
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

            $toast_msg = "Registration successful! Redirecting to login...";
            header("refresh:2;url=index.php");
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
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e3a8a 0%, #dc2626 100%);
            display: flex; align-items: center; justify-content: center; padding: 40px 0;
        }
        .reg-card {
            background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            padding: 1.5rem; width: 100%; max-width: 850px; position: relative;
        }
        .header-box { text-align: center; margin-bottom: 1.5rem; }
        .header-box img { 
            width: 100px; 
            height: auto; 
            margin-bottom: 0.5rem;
        }
        .btn-back-container { position: absolute; top: 25px; left: 25px; }
        .btn-back { color: #dc2626; text-decoration: none; font-weight: 700; display: flex; align-items: center; gap: 5px; }
        h3 { font-size: 1.1rem; font-weight: 700; color: #1e3a8a; margin: 20px 0 15px 0; text-transform: uppercase; border-bottom: 2px solid #f0f0f0; padding-bottom: 5px; }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #444; margin-bottom: 2px; }
        .form-control, .form-select { border-radius: 8px; padding: 10px 12px; border: 1px solid #ddd; }
        .btn-primary { background: #1e3a8a; border: none; padding: 12px; font-weight: 700; border-radius: 8px; transition: 0.3s; }
        .btn-primary:hover { background: #dc2626; transform: translateY(-2px); }
        .is-invalid-custom { border: 2px solid #dc2626 !important; background-color: #fff8f8; }
        .is-valid-custom { border: 2px solid #10b981 !important; background-color: #f0fdf4; }
        .invalid-feedback-custom { color: #dc2626; font-size: 0.75rem; font-weight: 600; margin-top: 4px; display: none; }
        .confirm-label { font-weight: 700; color: #1e3a8a; font-size: 0.75rem; text-transform: uppercase; margin-top: 8px; }
        .confirm-value { color: #333; margin-bottom: 5px; border-bottom: 1px dashed #eee; padding-bottom: 2px; font-size: 0.95rem; word-break: break-all; }
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .modal-header { background: #1e3a8a; color: white; }
        
        @media (max-width: 576px) {
            .reg-card { padding: 1rem; margin: 10px; border-radius: 10px; }
            .btn-back-container { position: relative; top: 0; left: 0; margin-bottom: 1rem; }
            h3 { font-size: 0.95rem; }
            .header-box h2 { font-size: 1.5rem; }
        }
        @media (max-width: 360px) {
            .form-label { font-size: 0.75rem; }
            .form-control, .form-select { padding: 8px 10px; font-size: 0.85rem; }
            .btn-primary { padding: 10px; font-size: 0.9rem; }
        }
        /* Password Toggle Styling */
        .toggle-password {
            cursor: pointer;
            background: #f8f9fa;
            border-left: none;
            border-radius: 0 8px 8px 0;
            display: flex;
            align-items: center;
        }
        .password-field {
            border-right: none;
        }
    </style>
</head>
<body>

<div class="toast-container">
    <div id="errorToast" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="reg-card">
    <div class="btn-back-container"><a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Login</a></div>
    <div class="header-box">
        <img src="image/aclc.jpg" alt="ACLC Logo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
        <h2 class="mt-2" style="font-weight: 800; color: #333;">Student Registration</h2>
    </div>

    <form method="POST" id="registrationForm" novalidate>
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
                <div class="input-group">
                    <input type="password" class="form-control password-field" name="password" id="password" placeholder="Min. 8 characters" required>
                    <span class="input-group-text toggle-password" onclick="toggleVisibility('password', 'eye1')">
                        <i class="fas fa-eye" id="eye1"></i>
                    </span>
                </div>
                <div class="invalid-feedback-custom" id="pass_feedback">Password must be at least 8 characters</div>
                <div class="progress mt-1" style="height: 5px;">
                    <div id="pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
                <small id="pw-strength-text" class="text-muted" style="font-size: 0.7rem;"></small>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Confirm Password</label>
                <div class="input-group">
                    <input type="password" class="form-control password-field" name="confirm_password" id="confirm_password" placeholder="Repeat password" required>
                    <span class="input-group-text toggle-password" onclick="toggleVisibility('confirm_password', 'eye2')">
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
            <div class="col-12"><button type="button" class="btn btn-primary w-100 mt-4" onclick="showConfirmation()"><i class="fas fa-eye me-2"></i> Review Information</button></div>
        </div>
    </form>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Review Registration Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="text-danger small mb-3">Please ensure all details are accurate before confirming.</p>
                <div class="row">
                    <div class="col-md-4 border-end">
                        <div class="confirm-label">First Name</div><div id="c_fname" class="confirm-value"></div>
                        <div class="confirm-label">Last Name</div><div id="c_lname" class="confirm-value"></div>
                        <div class="confirm-label">Suffix</div><div id="c_suffix" class="confirm-value"></div>
                        <div class="confirm-label">Birth Date</div><div id="c_bdate" class="confirm-value"></div>
                        <div class="confirm-label">Age / Sex</div><div id="c_agesex" class="confirm-value"></div>
                    </div>
                    <div class="col-md-4 border-end">
                        <div class="confirm-label">Course / Year</div><div id="c_course" class="confirm-value"></div>
                        <div class="confirm-label">Phone</div><div id="c_phone" class="confirm-value"></div>
                        <div class="confirm-label">Email</div><div id="c_email" class="confirm-value"></div>
                        <div class="confirm-label">Username</div><div id="c_user" class="confirm-value"></div>
                        <div class="confirm-label">Full Address</div><div id="c_addr" class="confirm-value"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="confirm-label">Guardian Name</div><div id="c_gname" class="confirm-value"></div>
                        <div class="confirm-label">Status</div><div id="c_gstatus" class="confirm-value"></div>
                        <div class="confirm-label">Occupation</div><div id="c_gocc" class="confirm-value"></div>
                        <div class="confirm-label">Guardian Phone</div><div id="c_gphone" class="confirm-value"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Edit Details</button><button type="button" class="btn btn-primary" onclick="submitForm()">Final Submit</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Global Declarations */
var form = document.getElementById('registrationForm');
var confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
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

window.validateField = function(input) {
    let isValid = true;
    let isNeutral = false;
    const val = input.value.trim();
    const isRequired = input.hasAttribute('required');
    
    // Finds the parent column container to locate the feedback div
    const container = input.closest('div[class*="col-"]');
    const feedback = container ? container.querySelector('.invalid-feedback-custom') : null;
    
    if (input.id === "val_suffix") {
        input.classList.remove('is-invalid-custom', 'is-valid-custom');
        if (val !== "") {
            input.classList.add('is-valid-custom');
        } else {
            input.classList.remove('is-valid-custom');
        }
        return true;
    }

    /* 1. Check for empty state */
    if (val === "" || val === null || (input.tagName === "SELECT" && input.value === "")) {
        if (isRequired) isValid = false;
        else isNeutral = true;
    } else {
        /* 2. Apply specific pattern restrictions */
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
    
    /* 3. Visual Feedback Application */
    input.classList.remove('is-invalid-custom', 'is-valid-custom');
    if (isNeutral) {
        if (feedback) feedback.style.display = 'none';
    } else if (!isValid) {
        input.classList.add('is-invalid-custom');
        if (feedback) feedback.style.display = 'block';
    } else {
        input.classList.add('is-valid-custom');
        if (feedback) feedback.style.display = 'none';
    }
    
    return isValid;
};

/* RE-VALIDATION LOGIC: Ensures confirm fields update when primary fields change */
form.querySelectorAll('input, select, textarea').forEach(el => {
    el.addEventListener('input', function() {
        window.validateField(el);
        if (el.id === 'val_email') window.validateField(document.getElementById('val_cemail'));
        if (el.id === 'password') {
            window.validateField(document.getElementById('confirm_password'));
            
            // Real-time Password Strength Measurement
            const val = el.value;
            const bar = document.getElementById('pw-strength-bar');
            const text = document.getElementById('pw-strength-text');
            let strength = 0;
            
            if (val.length >= 8) strength++;
            if (/[A-Z]/.test(val)) strength++;
            if (/[0-9]/.test(val)) strength++;
            if (/[^A-Za-z0-9]/.test(val)) strength++;
            
            let color = 'bg-danger', label = 'Weak', width = '25%';
            if (strength === 2) { color = 'bg-warning'; label = 'Fair'; width = '50%'; }
            else if (strength === 3) { color = 'bg-info'; label = 'Good'; width = '75%'; }
            else if (strength === 4) { color = 'bg-success'; label = 'Strong'; width = '100%'; }
            
            if (bar) { bar.className = 'progress-bar ' + color; bar.style.width = val.length > 0 ? width : '0%'; }
            if (text) text.innerText = val.length > 0 ? 'Strength: ' + label : '';
        }

        // Auto-calculate Age based on Birth Date
        if (el.id === 'val_bdate' && el.value) {
            // Split string to avoid timezone/UTC shift issues
            const birthParts = el.value.split('-');
            const bYear = parseInt(birthParts[0]);
            const bMonth = parseInt(birthParts[1]) - 1; // JS months are 0-indexed
            const bDay = parseInt(birthParts[2]);

            const today = new Date();
            let age = today.getFullYear() - bYear;
            
            // Check if birthday has occurred yet this year
            if (today.getMonth() < bMonth || (today.getMonth() === bMonth && today.getDate() < bDay)) {
                age--;
            }

            const ageInput = document.getElementById('val_age');
            if (ageInput && !isNaN(age)) {
                ageInput.value = age >= 0 ? age : 0;
                window.validateField(ageInput);
            }
        }
    });
    el.addEventListener('blur', function() { window.validateField(el); });
});

window.showConfirmation = function() {
    let allValid = true;
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(el => { if (!window.validateField(el)) allValid = false; });

    if (!allValid) {
        window.showPopup("Please complete all required fields correctly.");
        return;
    }

    document.getElementById('c_fname').innerText = document.getElementById('val_fname').value;
    document.getElementById('c_lname').innerText = document.getElementById('val_lname').value;
    document.getElementById('c_suffix').innerText = document.getElementById('val_suffix').value || "None";
    document.getElementById('c_bdate').innerText = document.getElementById('val_bdate').value;
    document.getElementById('c_agesex').innerText = document.getElementById('val_age').value + " / " + document.getElementById('val_sex').value;
    document.getElementById('c_phone').innerText = document.getElementById('val_phone').value;
    document.getElementById('c_course').innerText = document.getElementById('val_course').value + " (" + document.getElementById('val_year').value + ")";
    document.getElementById('c_email').innerText = document.getElementById('val_email').value;
    document.getElementById('c_user').innerText = document.getElementById('val_user').value;
    document.getElementById('c_addr').innerText = document.getElementById('val_addr').value + ", " + document.getElementById('val_city').value + ", " + document.getElementById('val_province').value;
    document.getElementById('c_gname').innerText = document.getElementById('val_gname').value;
    document.getElementById('c_gstatus').innerText = document.getElementById('val_gstatus').value;
    document.getElementById('c_gocc').innerText = document.getElementById('val_gocc').value;
    document.getElementById('c_gphone').innerText = document.getElementById('val_gphone').value;

    confirmModal.show();
};

window.submitForm = function() { form.submit(); };

<?php if ($toast_msg): ?>
    window.showPopup(<?php echo json_encode($toast_msg); ?>);
<?php endif; ?>
</script>
</body>
</html>