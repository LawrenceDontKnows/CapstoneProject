<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('admin');
validateSession();

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$toast_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $errors = [];

    // 1. CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid form submission. Please try again.";
    }

    // --- Collect and Sanitize Data ---
    $role = $_POST['role'];
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $suffix = trim($_POST['suffix'] ?? '');
    $phone = trim($_POST['phone_number']);

    // Student-specific fields
    $age = !empty($_POST['age']) ? (int)$_POST['age'] : null;
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $sex = $_POST['sex'] ?? null;
    $course = $_POST['course'] ?? null;
    $year_level = $_POST['year_level'] ?? null;
    $home_address = $_POST['home_address'] ?? null;
    $city = $_POST['city'] ?? null;
    $province = $_POST['province'] ?? null;
    $guardian_name = $_POST['guardian_name'] ?? null;
    $guardian_occupation = $_POST['guardian_occupation'] ?? null;
    $guardian_status = $_POST['guardian_status'] ?? null;
    $guardian_phone = $_POST['guardian_phone'] ?? null;
    $guardian_address = $_POST['guardian_address'] ?? null;

    // --- Validation ---
    if (empty($role) || !in_array($role, ['admin', 'teacher', 'student'])) {
        $errors[] = "A valid role must be selected (Admin, Teacher, or Student).";
    }
    if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
        $errors[] = "Username, Email, First Name, and Last Name are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);
    if ($stmt->fetch()) {
        $errors[] = "Email or Username already exists.";
    }

    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long.";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match.";

    // Phone validation: optional for admin/teacher if empty, but must be valid format if provided; required for students
    if (!empty($phone) && !preg_match('/^09[0-9]{9}$/', $phone)) {
        $errors[] = "Phone number must start with 09 and be 11 digits.";
    }

    if ($role === 'student') {
        if (empty($phone)) $errors[] = "Student phone number is required.";
        if (empty($birth_date)) $errors[] = "Birth date is required for students.";
        if (!empty($birth_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            $errors[] = "Invalid birth date format.";
        }
        if (empty($age) || $age < 15 || $age > 80) $errors[] = "For students, age must be between 15 and 80.";
        if (empty($sex)) $errors[] = "Sex is required for students.";
        if (empty($course)) $errors[] = "Course is required for students.";
        if (empty($year_level)) $errors[] = "Year level is required for students.";
        if (empty($home_address)) $errors[] = "Home address is required for students.";
        if (empty($city)) $errors[] = "City is required for students.";
        if (empty($province)) $errors[] = "Province is required for students.";
        if (empty($guardian_name)) $errors[] = "Guardian name is required for students.";
        if (empty($guardian_occupation)) $errors[] = "Guardian occupation is required for students.";
        if (empty($guardian_status)) $errors[] = "Guardian status is required for students.";
        if (empty($guardian_phone) || !preg_match('/^09[0-9]{9}$/', $guardian_phone)) $errors[] = "Guardian phone must start with 09 and be 11 digits.";
        if (empty($guardian_address)) $errors[] = "Guardian address is required for students.";
    }

    if (empty($errors)) {
        try {
            $hashed_pw = password_hash($password, PASSWORD_DEFAULT);

            // --- DYNAMIC QUERY BUILDING ---
            // Start with columns that are common to all roles.
            $columns = [
                'role', 'username', 'password', 'email', 
                'first_name', 'last_name', 'suffix', 'phone_number'
            ];
            $values = [
                $role, $username, $hashed_pw, $email, 
                $first_name, $last_name, $suffix, $phone
            ];

            // If the role is 'student', add all the student-specific columns and their values.
            if ($role === 'student') {
                $student_columns = [
                    'age', 'birth_date', 'sex', 'course', 'year_level', 'home_address',
                    'city', 'province', 'guardian_name', 'guardian_occupation',
                    'guardian_status', 'guardian_phone', 'guardian_address'
                ];
                $student_values = [
                    $age, $birth_date, $sex, $course, $year_level, $home_address,
                    $city, $province, $guardian_name, $guardian_occupation, $guardian_status,
                    $guardian_phone, $guardian_address
                ];
                $columns = array_merge($columns, $student_columns);
                $values = array_merge($values, $student_values);
            }

            $sql_columns = implode(', ', $columns);
            $sql_placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO users ($sql_columns) VALUES ($sql_placeholders)";

            $insert = $pdo->prepare($sql);
            $insert->execute($values);

            $new_user_id = $pdo->lastInsertId();
            // Log the creation action in the audit trail
            logAudit($pdo, $_SESSION['user_id'], 'user_create', $new_user_id, "Created new user '{$username}' with role '{$role}'.");

            $_SESSION['message'] = "Success: User '{$username}' has been created as a(n) " . ucfirst($role) . ".";
            $_SESSION['message_type'] = "success";
            $_SESSION['updated_user_id'] = $new_user_id;
            redirect("admin.php?tab=users");

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
    <title>Add New User - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f1f5f9;
            padding: 40px 0;
        }
        .reg-card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: 2.5rem;
            width: 100%;
            max-width: 900px;
            margin: 2rem auto;
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
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
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
        .is-invalid-custom { border-color: #dc3545 !important; }
        .is-valid-custom { border-color: #198754 !important; }
        .invalid-feedback-custom { color: #dc3545; font-size: 0.8rem; margin-top: 4px; display: none; }
        .input-group.is-invalid-custom { border-color: #dc3545 !important; }
        .input-group.is-valid-custom { border-color: #198754 !important; }
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
    <div class="header-box">
        <a href="admin.php?tab=users" class="btn-back mb-3"><i class="fas fa-arrow-left"></i> Back to User Management</a>
        <h2 class="mt-2">Create New User Account</h2>
    </div>

    <form method="POST" id="addUserForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <h3><i class="fas fa-user-shield me-2"></i>Core Account Details</h3>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" id="val_role" required onchange="toggleStudentFields(this.value)">
                    <option value="">-- Select Role --</option>
                    <option value="admin">Admin</option>
                    <option value="student">Student</option>
                    <option value="teacher">Teacher</option>
                </select>
                <div class="invalid-feedback-custom">A role must be selected.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" id="val_username" required>
                <div class="invalid-feedback-custom">Username is required.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" id="val_email" required>
                <div class="invalid-feedback-custom">A valid email is required.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <div class="input-group" id="pass-group">
                    <input type="password" class="form-control" name="password" id="password" placeholder="Min. 8 characters" required>
                    <span class="input-group-text" onclick="toggleVisibility('password', 'eye1')"><i class="fas fa-eye" id="eye1"></i></span>
                </div>
                <div class="invalid-feedback-custom" id="pass_feedback">Password must be at least 8 characters.</div>
                <div class="progress mt-2" style="height: 5px;">
                    <div id="pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
                <small id="pw-strength-text" class="text-muted" style="font-size: 0.7rem;"></small>
            </div>
            <div class="col-md-6">
                <label class="form-label">Confirm Password</label>
                <div class="input-group" id="cpass-group">
                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                    <span class="input-group-text" onclick="toggleVisibility('confirm_password', 'eye2')"><i class="fas fa-eye" id="eye2"></i></span>
                </div>
                <div class="invalid-feedback-custom" id="cpass_feedback">Passwords do not match.</div>
            </div>
        </div>

        <h3><i class="fas fa-id-card me-2"></i>Basic Information</h3>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="first_name" id="val_fname" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                <div class="invalid-feedback-custom">First name is required.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" name="last_name" id="val_lname" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                <div class="invalid-feedback-custom">Last name is required.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Suffix (Optional)</label>
                <input type="text" class="form-control" name="suffix" id="val_suffix" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
            </div>
             <div class="col-md-12">
                <label class="form-label">Phone Number <span class="text-danger student-only d-none">*</span></label>
                <input type="text" class="form-control" name="phone_number" id="val_phone" maxlength="11" placeholder="09123456789" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                <div class="invalid-feedback-custom">Phone must start with 09 and be 11 digits.</div>
            </div>
        </div>

        <div id="student_specific_fields" class="d-none">
            <h3><i class="fas fa-user-graduate me-2"></i>Student Information</h3>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Birth Date</label>
                    <input type="date" class="form-control" name="birth_date" id="val_bdate" min="1900-01-01" max="<?php echo date('Y-12-31'); ?>">
                    <div class="invalid-feedback-custom">Valid birth date is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Age</label>
                    <input type="text" class="form-control" name="age" id="val_age" placeholder="Auto-Filled" readonly>
                    <div class="invalid-feedback-custom">Age must be between 15 and 80.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Sex</label>
                    <select class="form-select" name="sex" id="val_sex">
                        <option value="">-- Select --</option>
                        <option>Male</option><option>Female</option>
                    </select>
                    <div class="invalid-feedback-custom">Sex is required.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Course</label>
                    <select class="form-select" name="course" id="val_course">
                        <option value="">-- Choose Course --</option>
                        <option>BSCS</option><option>BSIT</option><option>BSHM</option><option>BSBA</option><option>BSA</option>
                    </select>
                    <div class="invalid-feedback-custom">Course is required.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Year Level</label>
                    <select class="form-select" name="year_level" id="val_year">
                        <option value="">-- Choose Year --</option>
                        <option>1st Year</option><option>2nd Year</option><option>3rd Year</option><option>4th Year</option>
                    </select>
                    <div class="invalid-feedback-custom">Year level is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" class="form-control" name="city" id="val_city" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">City is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control" name="province" id="val_province" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Province is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Home Address</label>
                    <input type="text" class="form-control" name="home_address" id="val_address">
                    <div class="invalid-feedback-custom">Home address is required.</div>
                </div>
            </div>

            <h3><i class="fas fa-users me-2"></i>Guardian Details</h3>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Guardian Name</label>
                    <input type="text" class="form-control" name="guardian_name" id="val_gname" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Guardian name is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian Status</label>
                    <select class="form-select" name="guardian_status" id="val_gstatus">
                        <option value="">-- Select Status --</option>
                        <option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option>
                    </select>
                    <div class="invalid-feedback-custom">Guardian status is required.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian Phone</label>
                    <input type="text" class="form-control" name="guardian_phone" id="val_gphone" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    <div class="invalid-feedback-custom">Must start with 09 and be 11 digits.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Guardian Occupation</label>
                    <input type="text" class="form-control" name="guardian_occupation" id="val_gocc" oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                    <div class="invalid-feedback-custom">Guardian occupation is required.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Guardian Address</label>
                    <textarea class="form-control" name="guardian_address" id="val_gaddress" rows="2"></textarea>
                    <div class="invalid-feedback-custom">Guardian address is required.</div>
                </div>
            </div>
        </div>

        <div class="col-12 mt-4"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-user-plus me-2"></i> Create User Account</button></div>
    </form>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleStudentFields(role) {
        const studentFields = document.getElementById('student_specific_fields');
        const studentInputs = studentFields.querySelectorAll('input, select, textarea');
        const phoneInput = document.getElementById('val_phone');
        const phoneStar = document.querySelector('.student-only');
        if (role === 'student') {
            studentFields.classList.remove('d-none');
            if (phoneStar) phoneStar.classList.remove('d-none');
            if (phoneInput) phoneInput.required = true;
            studentInputs.forEach(input => {
                // Make all student fields required, except for the readonly age field
                if (input.id !== 'val_age') {
                    input.required = true;
                }
            });
        } else {
            studentFields.classList.add('d-none');
            if (phoneStar) phoneStar.classList.add('d-none');
            if (phoneInput) { phoneInput.required = false; phoneInput.value = ''; phoneInput.classList.remove('is-invalid-custom','is-valid-custom'); }
            studentInputs.forEach(input => {
                input.required = false;
                // Optional: Clear values when hiding
                if (input.type !== 'select-one') {
                    input.value = '';
                } else {
                    input.selectedIndex = 0;
                }
                input.classList.remove('is-invalid-custom', 'is-valid-custom');
                const fb = input.closest('div[class*="col-"]')?.querySelector('.invalid-feedback-custom');
                if (fb) fb.style.display = 'none';
            });
        }
    }

    function toggleVisibility(inputId, eyeId) {
        var input = document.getElementById(inputId);
        var eye = document.getElementById(eyeId);
        if (input.type === "password") {
            input.type = "text";
            eye.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = "password";
            eye.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('addUserForm');
        const toastEl = document.getElementById('errorToast');
        const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });

        function showPopup(msg) {
            document.getElementById('toastMsg').innerText = msg;
            toast.show();
        }

        function validateField(input) {
            let isValid = true;
            const val = input.value.trim();
            const isRequired = input.hasAttribute('required');
            
            const container = input.closest('div[class*="col-"], .input-group');
            const feedback = container ? container.querySelector('.invalid-feedback-custom') : null;
            
            if (!isRequired && val === '') {
                input.classList.remove('is-invalid-custom', 'is-valid-custom');
                if (feedback) feedback.style.display = 'none';
                const group = input.closest('.input-group');
                if (group) group.classList.remove('is-invalid-custom', 'is-valid-custom');
                return true;
            }

            if (val === "" || val === null) {
                if (isRequired) isValid = false;
            } else {
                switch (input.name) {
                    case 'email':
                        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) isValid = false;
                        break;
                    case 'password':
                        if (val.length < 8) isValid = false;
                        break;
                    case 'confirm_password':
                        if (val !== document.getElementById('password').value) isValid = false;
                        break;
                    case 'phone_number':
                        if (val !== '' && !/^09\d{9}$/.test(val)) isValid = false;
                        break;
                    case 'guardian_phone':
                        if (!/^09\d{9}$/.test(val)) isValid = false;
                        break;
                    case 'age':
                        const ageVal = parseInt(val, 10);
                        if (isNaN(ageVal) || ageVal < 15 || ageVal > 80) isValid = false;
                        break;
                    case 'birth_date':
                        const year = new Date(val).getFullYear();
                        if (isNaN(year) || year < 1900 || year > new Date().getFullYear()) isValid = false;
                        break;
                }
            }
            
            input.classList.remove('is-invalid-custom', 'is-valid-custom');
            const group = input.closest('.input-group');
            if (group) group.classList.remove('is-invalid-custom', 'is-valid-custom');

            if (!isValid) {
                input.classList.add('is-invalid-custom');
                if (group) group.classList.add('is-invalid-custom');
                if (feedback) feedback.style.display = 'block';
            } else {
                input.classList.add('is-valid-custom');
                if (group) group.classList.add('is-valid-custom');
                if (feedback) feedback.style.display = 'none';
            }
            
            return isValid;
        }

        form.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', () => validateField(el));
            el.addEventListener('blur', () => validateField(el));
        });

        const passwordField = document.getElementById('password');
        if (passwordField) {
            passwordField.addEventListener('input', function() {
                const val = this.value;
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

                const confirmPass = document.getElementById('confirm_password');
                if (confirmPass.value !== '') validateField(confirmPass);
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            let allValid = true;
            // Validate all fields that have a `name` attribute, respecting the `required` status.
            form.querySelectorAll('[name]').forEach(el => {
                if (!validateField(el)) {
                    // Only count as invalid if it's actually required.
                    if (el.hasAttribute('required')) {
                        allValid = false;
                    }
                }
            });

            if (allValid) {
                form.submit();
            } else {
                showPopup("Please fix the errors highlighted in red before submitting.");
                const firstInvalid = form.querySelector('.is-invalid-custom');
                if (firstInvalid) {
                    firstInvalid.focus();
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        const bdateInput = document.getElementById('val_bdate');
        if (bdateInput) {
            bdateInput.addEventListener('change', function() {
                if (!this.value) return;
                const birthDate = new Date(this.value);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
                const ageInput = document.getElementById('val_age');
                ageInput.value = age >= 0 ? age : 0;
                validateField(ageInput);
            });
        }

        <?php if ($toast_msg): ?>showPopup(<?php echo json_encode($toast_msg); ?>);<?php endif; ?>
    });
</script>
</body>
</html>