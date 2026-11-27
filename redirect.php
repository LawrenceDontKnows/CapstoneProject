<?php
// Universal redirect page with fade animation and countdown
$target = $_GET['url'] ?? 'index.php';
$delay = (int)($_GET['delay'] ?? 3);
$message = $_GET['msg'] ?? '';
$messageType = $_GET['type'] ?? 'info';
$fadeOnly = isset($_GET['fade']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .redirect-card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            padding: 3rem;
            width: 100%;
            max-width: 420px;
            text-align: center;
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            margin: 0 auto 1rem;
            display: block;
        }
        .countdown-badge {
            font-size: 2rem;
            font-weight: 800;
            color: #1e3a8a;
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            display: inline-block;
            margin-top: 1rem;
        }
        .progress-container {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            margin-top: 1.5rem;
            overflow: hidden;
            <?php echo $fadeOnly ? 'display: none;' : ''; ?>
        }
        .progress-bar-fill {
            height: 100%;
            background: #1e3a8a;
            width: 100%;
            transition: width <?php echo $delay; ?>s linear;
        }
        .alert {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="redirect-card">
        <img src="image/aclc.jpg" alt="Logo" class="logo">
        <h4 class="mb-3">Redirecting...</h4>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <p class="text-muted" <?php echo $fadeOnly ? 'style="display:none;"' : ''; ?>>You will be redirected in <span id="countdown"><?php echo $delay; ?></span> seconds</p>
        <div class="countdown-badge" id="badge" <?php echo $fadeOnly ? 'style="display:none;"' : ''; ?>><?php echo $delay; ?></div>
        <div class="progress-container">
            <div class="progress-bar-fill" id="progressFill"></div>
        </div>
        <div class="mt-3">
            <a href="<?php echo htmlspecialchars($target); ?>" class="btn btn-outline-primary">Go now</a>
        </div>
    </div>

    <script>
        let timeLeft = <?php echo $delay; ?>;
        const countdownEl = document.getElementById('countdown');
        const badgeEl = document.getElementById('badge');
        const progressFill = document.getElementById('progressFill');
        const targetUrl = "<?php echo addslashes($target); ?>";
        const isFadeOnly = <?php echo $fadeOnly ? 'true' : 'false'; ?>;

        if (isFadeOnly) {
            window.location.href = targetUrl;
        } else {
            function updateCountdown() {
                countdownEl.textContent = timeLeft;
                badgeEl.textContent = timeLeft;
                const progressPercent = (timeLeft / <?php echo $delay; ?>) * 100;
                progressFill.style.width = progressPercent + '%';
                timeLeft--;
                if (timeLeft < 0) {
                    window.location.href = targetUrl;
                }
            }

            updateCountdown();
            const timer = setInterval(updateCountdown, 1000);

            // Allow clicking anywhere to skip
            document.querySelector('.redirect-card').addEventListener('click', function(e) {
                if (!e.target.closest('a')) {
                    clearInterval(timer);
                    window.location.href = targetUrl;
                }
            });
        }
    </script>
</body>
</html>
