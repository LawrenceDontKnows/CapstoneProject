<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/mailer.php';

if (empty($_SESSION['registration_success'])) {
    header("Location: redirect.php?url=" . urlencode('register.php') . "&delay=3");
    exit;
}

$data = $_SESSION['registration_success'];
unset($_SESSION['registration_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful | GradeView Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .success-container { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .success-card { background: #ffffff; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); padding: 2.5rem; width: 100%; max-width: 700px; border: none; }
        .logo { text-align: center; margin-bottom: 1.5rem; }
        .logo img { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-bottom: 1rem; }
        .logo h3 { color: #1e293b; font-weight: 800; }
        .info-row { display: flex; padding: 0.6rem 0; border-bottom: 1px solid #f1f5f9; }
        .info-label { font-weight: 600; color: #475569; width: 180px; flex-shrink: 0; font-size: 0.9rem; }
        .info-value { color: #1e293b; font-size: 0.95rem; word-break: break-word; }
        .section-title { font-size: 0.8rem; font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin: 1.5rem 0 0.75rem 0; padding-bottom: 0.5rem; border-bottom: 2px solid #f1f5f9; }
        .alert-custom { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; border-radius: 0.75rem; }
        .btn-back { color: #1e3a8a; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .btn-back:hover { color: #dc2626; }
        @media (max-width: 576px) {
            .success-card { padding: 1.5rem; }
            .info-row { flex-direction: column; gap: 4px; }
            .info-label { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card">
            <a href="index.php" class="btn-back mb-3"><i class="fas fa-arrow-left"></i> Back to Login</a>
            
            <div class="logo">
                <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="Logo">
                <h3>Registration Submitted</h3>
                <p class="text-muted">GradeView Portal</p>
            </div>

            <div class="alert-custom p-3 mb-4">
                <i class="fas fa-envelope-open-text me-2"></i>
                <strong>Verify your email to activate your account.</strong><br>
                We've sent a verification link to <strong><?php echo htmlspecialchars($data['email']); ?></strong>.
                Please check your inbox and click the link before logging in.
            </div>

            <div class="section-title">Personal Information</div>
            <div class="info-row">
                <div class="info-label">Full Name</div>
                <div class="info-value"><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name'] . ' ' . $data['suffix']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Birth Date / Age</div>
                <div class="info-value"><?php echo htmlspecialchars($data['birth_date'] . ' (' . $data['age'] . ' years old)'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Sex</div>
                <div class="info-value"><?php echo htmlspecialchars($data['sex']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Course / Year</div>
                <div class="info-value"><?php echo htmlspecialchars($data['course'] . ' - ' . $data['year_level']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Phone Number</div>
                <div class="info-value"><?php echo htmlspecialchars($data['phone']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Address</div>
                <div class="info-value"><?php echo htmlspecialchars($data['address'] . ', ' . $data['city'] . ', ' . $data['province']); ?></div>
            </div>

            <div class="section-title">Account Information</div>
            <div class="info-row">
                <div class="info-label">Username</div>
                <div class="info-value"><?php echo htmlspecialchars($data['username']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo htmlspecialchars($data['email']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Role</div>
                <div class="info-value">Student</div>
            </div>

            <div class="section-title">Guardian Information</div>
            <div class="info-row">
                <div class="info-label">Guardian Name</div>
                <div class="info-value"><?php echo htmlspecialchars($data['guardian_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Status</div>
                <div class="info-value"><?php echo htmlspecialchars($data['guardian_status']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Occupation</div>
                <div class="info-value"><?php echo htmlspecialchars($data['guardian_occupation']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Guardian Phone</div>
                <div class="info-value"><?php echo htmlspecialchars($data['guardian_phone']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Guardian Address</div>
                <div class="info-value"><?php echo htmlspecialchars($data['guardian_address']); ?></div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <a href="index.php" class="btn btn-primary flex-fill"><i class="fas fa-sign-in-alt me-2"></i>Go to Login</a>
                <a href="verify_email.php?resent=1&email=<?php echo urlencode($data['email']); ?>" class="btn btn-outline-secondary flex-fill"><i class="fas fa-envelope me-2"></i>Resend Verification</a>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>
