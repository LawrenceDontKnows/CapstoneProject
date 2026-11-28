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
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            min-height: 100dvh;
            overflow-x: hidden;
            background: #0b1020;
            color: #ffffff;
        }
        /* Vibrant mesh fallback (visible when slideshow disabled) */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -4;
            background:
                radial-gradient(60% 60% at 12% 18%, #4f46e5 0%, transparent 60%),
                radial-gradient(50% 50% at 88% 12%, #06b6d4 0%, transparent 55%),
                radial-gradient(60% 60% at 82% 88%, #db2777 0%, transparent 60%),
                linear-gradient(135deg, #0b1020, #111827);
        }

        /* Slideshow Background */
        .slideshow-bg {
            position: fixed;
            inset: 0;
            z-index: -3;
            background: #0b1020;
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
        .slideshow-bg .slide.active { opacity: 1; z-index: 1; }
        .slideshow-overlay {
            position: fixed;
            inset: 0;
            z-index: -2;
            background: linear-gradient(135deg,
                rgba(11, 16, 32, var(--slideshow-opacity, 0.3)) 0%,
                rgba(11, 16, 32, 0.88) 100%);
        }

        /* Two-column layout */
        .login-container {
            position: relative;
            z-index: 1;
            min-height: 100dvh;
            width: 100%;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: clamp(24px, 5vw, 72px);
            padding: clamp(16px, 4vw, 56px) clamp(14px, 4vw, 24px);
        }

        /* Left brand showcase */
        .brand-showcase { max-width: 560px; }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .4rem .8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-size: .72rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 700;
        }
        .brand-showcase h1 {
            font-size: clamp(2rem, 5vw, 3.4rem);
            line-height: 1.05;
            font-weight: 800;
            margin: 1rem 0;
            letter-spacing: -1px;
        }
        .brand-showcase p {
            font-size: clamp(.95rem, 2.2vw, 1.15rem);
            color: rgba(255, 255, 255, 0.85);
            max-width: 46ch;
            margin: 0;
        }
        .brand-pills {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: 1.5rem;
        }
        .brand-pills span {
            padding: .45rem .8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: .8rem;
            font-weight: 600;
        }

        /* Right glass auth card */
        .login-card {
            width: 100%;
            max-width: 440px;
            justify-self: end;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0.04) 45%, rgba(255, 255, 255, 0.02) 100%),
                rgba(20, 24, 38, 0.45);
            backdrop-filter: blur(22px) saturate(140%);
            -webkit-backdrop-filter: blur(22px) saturate(140%);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.35);
            color: #ffffff;
            color-scheme: dark;
        }
        .login-card h2 { font-size: 1.6rem; font-weight: 800; margin: 0 0 .25rem; }
        .login-card .subtitle { color: #cbd5e1; margin: 0 0 1.4rem; font-size: .95rem; }
        .login-card .form-label { font-weight: 600; color: #cbd5e1; font-size: .85rem; }
        .input-group {
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-group:focus-within {
            border-color: rgba(255, 255, 255, 0.65);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.18);
        }
        .input-group-text { background: transparent; border: 0; color: #cbd5e1; }
        .form-control {
            border: 0;
            box-shadow: none !important;
            background: transparent;
            color: #ffffff;
            min-height: 44px;
        }
        .form-control::placeholder { color: rgba(255, 255, 255, 0.65); }
        #togglePassword { cursor: pointer; background: transparent; border: 0; color: #cbd5e1; }
        .btn-custom {
            width: 100%;
            min-height: 46px;
            border: 0;
            border-radius: 12px;
            background: #ffffff;
            color: #111827;
            font-weight: 600;
            letter-spacing: .02em;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.35);
        }
        .btn-custom:hover { transform: translateY(-2px); color: #111827; background: #fff; box-shadow: 0 14px 30px rgba(0, 0, 0, 0.45); }
        .footer-link a { color: #a5b4fc; font-weight: 600; text-decoration: none; transition: color 0.2s; }
        .footer-link a:hover { color: #ffffff; text-decoration: underline; }
        .footer-link .text-muted { color: rgba(255, 255, 255, 0.7) !important; }
        .toast-container { z-index: 1060; }
        .alert { background: rgba(255, 255, 255, 0.92); color: #1f2937; }

        /* Tablet / mobile stack */
        @media (max-width: 991px) {
            .login-container {
                grid-template-columns: 1fr;
                justify-items: center;
                gap: 24px;
                padding: 24px 16px;
            }
            .brand-showcase { text-align: center; max-width: 520px; margin: 0 auto; }
            .brand-pills { justify-content: center; }
            .login-card { justify-self: center; max-width: 460px; }
        }
        @media (max-width: 400px) {
            .login-card { padding: 18px; border-radius: 16px; }
            .brand-showcase h1 { font-size: 1.9rem; }
        }
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
        <section class="brand-showcase">
            <span class="brand-badge"><i class="fas fa-graduation-cap"></i> GradeView Portal</span>
            <h1>Every grade,<br>clearly in view.</h1>
            <p>One secure portal to manage students, teachers, subjects, and grade records across your institution.</p>
            <div class="brand-pills">
                <span>Role-based access</span>
                <span>Real-time grades</span>
                <span>Secure &amp; reliable</span>
            </div>
        </section>

        <div class="login-card">
            <h2>Sign in</h2>
            <p class="subtitle">Good to see you again.</p>

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
                    <a href="forgot_password.php" class="text-decoration-none">Forgot password?</a>
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