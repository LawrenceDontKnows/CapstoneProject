<?php
include 'includes/conn.php';
include 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isLoggedIn()) {
    redirect(getDashboardUrl());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password']; // Removed trim: verification must use the exact string
    
    $user = null;
    $found_role = null;

    try {
        // Check the 'users' table for any role (Admin, Teacher, Student)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $res = $stmt->fetch();

        if ($res) {
            $user = $res;
            $found_role = $res['role'];
        }
    } catch (PDOException $e) {
        // Log the actual error for the developer and show a friendly message to the user
        error_log("Database Error: " . $e->getMessage());
        $error = "System Error: The login service is currently unavailable. Please contact the administrator.";
    }

    // 3. Verify password and set sessions
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $found_role;
        
        // Use columns from your DB screenshot
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['display_name'] = $user['first_name'] . ' ' . $user['last_name'];

        logActivity($pdo, $user['id'], $found_role, 'login', 'Successfully signed in');
        
        redirect(getDashboardUrl());
    } else {
        $error = "Invalid username or password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #1e3a8a 0%, #dc2626 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .logo img {
            width: 120px;
            height: auto;
            margin-bottom: 1rem;
        }
        .logo h3 {
            color: #333;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .role-badge {
            background: #f1f5f9;
            color: #475569;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        .btn-custom {
            background: #4f46e5;
            color: white;
            border: none;
            font-weight: 700;
            padding: 12px;
            border-radius: 8px;
            transition: 0.3s;
        }
        .btn-custom:hover {
            background: #4338ca;
            color: white;
            transform: translateY(-2px);
        }
        .input-group-text {
            background-color: #f8f9fa;
            color: #1e3a8a;
            border-right: none;
        }
        .form-control {
            border-left: none;
        }
        .form-control:focus {
            border-color: #dee2e6;
            box-shadow: none;
        }
        #togglePassword {
            cursor: pointer;
            border-left: none;
        }
        .toast-container { z-index: 1060; }
        
        @media (max-width: 400px) {
            .login-card { padding: 1.5rem; max-width: 100%; }
            .logo h3 { font-size: 1.4rem; }
            .role-badge { font-size: 0.65rem; }
        }
        @media (max-width: 320px) {
            .login-card { padding: 1.25rem; }
            .btn-custom { font-size: 0.85rem; padding: 10px; }
        }
    </style>
</head>
<body>
    <!-- Toast Notification Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="msgToast" class="toast fade align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    
    <div class="login-container">
        <div class="login-card text-center">
            <div class="logo">
                <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                <h3>GradeView</h3>
            </div>
            
            <div class="role-badge">
                <i class="fas fa-shield-alt me-1"></i> Multi-Role Access
            </div>
            
            <form method="POST" class="text-start">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-uppercase text-muted">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                        <input type="text" class="form-control" name="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                               placeholder="Enter your username" required>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label fw-bold small text-uppercase text-muted">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                        <input type="password" class="form-control" name="password" id="password" 
                               placeholder="Enter password" required style="border-right: none;">
                        <span class="input-group-text" id="togglePassword" style="background: white;">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-custom w-100">
                    SIGN IN TO PORTAL
                </button>
            </form>

            <div class="mt-4 pt-3 border-top">
                <p class="mb-0 small text-muted">Need help accessing your account?</p>
                <a href="register.php" class="small text-decoration-none fw-bold" style="color: #dc2626;">Register</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toastEl = document.getElementById('msgToast');
            const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
            
            <?php if (isset($error)): ?>
                document.getElementById('toastMsg').innerText = "<?php echo htmlspecialchars($error); ?>";
                toastEl.classList.add('bg-danger');
                toast.show();
            <?php endif; ?>
        });

        const togglePassword = document.querySelector('#togglePassword');
        const passwordInput = document.querySelector('#password');
        const eyeIcon = document.querySelector('#eyeIcon');

        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>