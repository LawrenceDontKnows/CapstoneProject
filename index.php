<?php
include 'includes/conn.php';
include 'includes/functions.php';

if (isLoggedIn()) {
    redirect(getDashboardUrl());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $user = null;
    $found_role = null;

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $res = $stmt->fetch();

        if ($res) {
            $user = $res;
            $found_role = $res['role'];
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $error = "System Error: The login service is currently unavailable. Please contact the administrator.";
    }

    if ($user && password_verify($password, $user['password'])) {
        setupSessionSecurity();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $found_role;
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['display_name'] = $user['first_name'] . ' ' . $user['last_name'];

        logActivity($pdo, $user['id'], $found_role, 'login', 'Successfully signed in');
        redirect(getDashboardUrl());
    } else {
        $error = "Invalid username or password!";
    }
}

$session_message = '';
$session_message_type = '';
if (!empty($_SESSION['message'])) {
    $session_message = $_SESSION['message'];
    $session_message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// CSRF Protection: Generate a token if one doesn't exist.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get slideshow settings
$slideshowEnabled = getSystemSetting($pdo, 'login_slideshow_enabled', '1') === '1';
$slideshowInterval = (int)getSystemSetting($pdo, 'login_slideshow_interval', '6') * 1000;
$slideshowOpacity = (int)getSystemSetting($pdo, 'login_slideshow_opacity', '30');
$formGlass = getSystemSetting($pdo, 'login_form_glass', '1') === '1';

// Get individual slideshow images
$slideshowImages = [];
for ($i = 1; $i <= 3; $i++) {
    $img = getSystemSetting($pdo, "login_slideshow_image_$i", '');
    if ($img) {
        $slideshowImages[] = $img;
    }
}
if (empty($slideshowImages)) {
    $slideshowImages = ['/image/slide1.jpg', '/image/slide2.jpg', '/image/slide3.jpg'];
}

// Default slideshow background color for footer detection (average of slides)
$slideshowBgColor = '#f1f5f9';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="css/responsive.css" rel="stylesheet">
    <style>
        :root {
            --slideshow-interval: <?php echo $slideshowInterval; ?>;
            --slideshow-opacity: <?php echo $slideshowOpacity / 100; ?>;
            --slideshow-bg-color: <?php echo $slideshowBgColor; ?>;
        }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        /* Slideshow Background */
        .slideshow-bg {
            position: fixed;
            inset: 0;
            z-index: -2;
            background: #f1f5f9;
            overflow: hidden;
        }
        .slideshow-bg .slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1s ease-in-out;
            will-change: opacity;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .slideshow-bg .slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        .slideshow-bg .slide.active {
            opacity: 1;
            z-index: 1;
        }
        .slideshow-overlay {
            position: fixed;
            inset: 0;
            z-index: -1;
            background: rgba(0, 0, 0, var(--slideshow-opacity, 0.3));
        }

        .login-card {
            background: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.15)' : '#ffffff'; ?>;
            backdrop-filter: <?php echo $formGlass ? 'blur(20px)' : 'none'; ?>;
            -webkit-backdrop-filter: <?php echo $formGlass ? 'blur(20px)' : 'none'; ?>;
            border: <?php echo $formGlass ? '1px solid rgba(255, 255, 255, 0.3)' : 'none'; ?>;
            box-shadow: <?php echo $formGlass ? '0 8px 32px rgba(0, 0, 0, 0.1)' : '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)'; ?>;
            border-radius: 1rem;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            border: none;
            color: <?php echo $formGlass ? '#ffffff' : 'inherit'; ?>;
        }
        .login-card .form-label {
            font-weight: 600;
            color: <?php echo $formGlass ? '#ffffff' : '#475569'; ?>;
        }
        .btn-custom {
            background: #1e3a8a;
            color: white;
            border: none;
            font-weight: 700;
            padding: 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.3);
        }
        .btn-custom:hover {
            background: #172a61;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 7px 10px -1px rgba(30, 58, 138, 0.4);
        }
        .input-group {
            border: <?php echo $formGlass ? '1px solid rgba(255, 255, 255, 0.3)' : '1px solid #e2e8f0'; ?>;
            border-radius: 8px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.2)' : 'transparent'; ?>;
        }
        .input-group:focus-within {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        .input-group-text {
            background-color: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.2)' : 'transparent'; ?>;
            border: none;
            color: <?php echo $formGlass ? '#ffffff' : '#94a3b8'; ?>;
        }
        .form-control {
            border: none;
            box-shadow: none !important;
            background: <?php echo $formGlass ? 'transparent' : 'white'; ?>;
            color: <?php echo $formGlass ? '#ffffff' : 'inherit'; ?>;
        }
        .form-control::placeholder {
            color: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.7)' : '#94a3b8'; ?>;
        }
        #togglePassword {
            cursor: pointer;
            background-color: transparent;
            border: none;
        }
        .toast-container { z-index: 1060; }
        .footer-link a {
            color: <?php echo $formGlass ? '#ffffff' : '#1e3a8a'; ?>;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-link a:hover {
            color: <?php echo $formGlass ? '#ffffff' : '#dc2626'; ?>;
            text-decoration: underline;
        }
        .footer-link .text-muted {
            color: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.8)' : ''; ?> !important;
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            margin-bottom: 1rem;
        }
        .logo h3 {
            color: <?php echo $formGlass ? '#ffffff' : '#1e293b'; ?>;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .alert {
            background: <?php echo $formGlass ? 'rgba(255, 255, 255, 0.9)' : 'inherit'; ?>;
            backdrop-filter: <?php echo $formGlass ? 'blur(10px)' : 'none'; ?>;
        }

        @media (max-width: 400px) {
            .login-card { padding: 1.5rem; max-width: 100%; }
            .logo h3 { font-size: 1.4rem; }
        }
        @media (max-width: 320px) {
            .login-card { padding: 1.25rem; }
            .btn-custom { font-size: 0.85rem; padding: 10px; }
        }

        /* Auto-adaptive text color for glass card over light/dark slideshow */
        .login-card.glass { --l-text:#ffffff; --l-muted:rgba(255,255,255,0.85); --l-link:#ffffff; --l-icon:#ffffff; --l-placeholder:rgba(255,255,255,0.7); }
        .login-card.glass.on-light-bg { --l-text:#1e293b; --l-muted:#475569; --l-link:#1e3a8a; --l-icon:#475569; --l-placeholder:#94a3b8; }
        .login-card.glass .logo h3,
        .login-card.glass .form-label,
        .login-card.glass .form-control { color: var(--l-text) !important; }
        .login-card.glass .form-control::placeholder { color: var(--l-placeholder) !important; }
        .login-card.glass .input-group-text { color: var(--l-icon) !important; }
        .login-card.glass .footer-link .text-muted { color: var(--l-muted) !important; }
        .login-card.glass .footer-link a { color: var(--l-link) !important; }
    </style>
</head>
<body>
    <div class="slideshow-bg" id="slideshowBg" style="display: <?php echo $slideshowEnabled ? 'block' : 'none'; ?>;">
        <?php foreach ($slideshowImages as $i => $img): ?>
            <div class="slide <?php echo $i === 0 ? 'active' : ''; ?>">
                <img src="<?php echo htmlspecialchars($img); ?>" alt="Slideshow <?php echo $i + 1; ?>">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="slideshow-overlay" style="display: <?php echo $slideshowEnabled ? 'block' : 'none'; ?>;"></div>

    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="msgToast" class="toast fade align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="login-container">
        <div class="login-card text-center<?php echo $formGlass ? ' glass' : ''; ?>">
            <div class="logo">
                <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="Logo" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                <h3>GradeView</h3>
            </div>

            <form method="POST" class="text-start">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($session_message): ?>
                    <div class="alert alert-<?php echo $session_message_type; ?>"><?php echo htmlspecialchars($session_message); ?></div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user-circle"></i></span>
                        <input type="text" class="form-control" name="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                               placeholder="Enter your username" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                        <input type="password" class="form-control" name="password" id="password" 
                               placeholder="Enter password" required>
                        <span class="input-group-text" id="togglePassword">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="btn btn-custom w-100 mb-3">
                    SIGN IN TO PORTAL
                </button>

                <div class="d-flex justify-content-between small">
                    <a href="forgot_password.php" class="text-decoration-none" style="color: <?php echo $formGlass ? '#ffffff' : 'inherit'; ?>;">Forgot password?</a>
                </div>
            </form>

            <div class="mt-4 pt-3 border-top footer-link">
                <p class="mb-0 small text-muted">Don't have an account?</p>
                <a href="register.php">Create one here</a>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

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

            // Slideshow
            const slides = document.querySelectorAll('.slideshow-bg .slide');
            if (slides.length > 1) {
                let current = 0;
                const interval = <?php echo $slideshowInterval; ?>;
                function nextSlide() {
                    slides[current].classList.remove('active');
                    current = (current + 1) % slides.length;
                    slides[current].classList.add('active');
                    updateLoginText();
                }
                setInterval(nextSlide, interval);
            }

            // Auto-switch login text color based on slideshow brightness
            const loginCard = document.querySelector('.login-card.glass');
            function updateLoginText() {
                if (!loginCard) return;
                const active = document.querySelector('.slideshow-bg .slide.active img');
                const overlay = document.querySelector('.slideshow-overlay');
                let op = 0;
                if (overlay && getComputedStyle(overlay).display !== 'none') {
                    const m = getComputedStyle(overlay).backgroundColor.match(/rgba?\(([^)]+)\)/);
                    if (m) { const p = m[1].split(',').map(Number); op = p.length > 3 ? p[3] : 0; }
                }
                const apply = (lum) => {
                    if (lum == null) return;
                    loginCard.classList.toggle('on-light-bg', (lum * (1 - op)) > 0.5);
                };
                if (active && active.complete && active.naturalWidth) {
                    try {
                        const c = document.createElement('canvas'); c.width = c.height = 40;
                        const ctx = c.getContext('2d'); ctx.drawImage(active, 0, 0, 40, 40);
                        const d = ctx.getImageData(0, 0, 40, 40).data;
                        let r = 0, g = 0, b = 0, n = 0;
                        for (let i = 0; i < d.length; i += 16) { r += d[i]; g += d[i + 1]; b += d[i + 2]; n++; }
                        apply((0.299 * (r / n) + 0.587 * (g / n) + 0.114 * (b / n)) / 255);
                    } catch (e) {}
                } else if (active) {
                    active.addEventListener('load', updateLoginText, { once: true });
                }
            }
            updateLoginText();
            document.querySelectorAll('.slideshow-bg .slide img').forEach(img => img.addEventListener('load', updateLoginText));

            const togglePassword = document.querySelector('#togglePassword');
            const passwordInput = document.querySelector('#password');
            const eyeIcon = document.querySelector('#eyeIcon');

            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                eyeIcon.classList.toggle('fa-eye');
                eyeIcon.classList.toggle('fa-eye-slash');
            });
        });
    </script>
</body>
</html>