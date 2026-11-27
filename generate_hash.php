<?php
$password_to_hash = '';
$hashed_password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
    $password_to_hash = $_POST['password'];
    // Use the default algorithm (currently bcrypt)
    $hashed_password = password_hash($password_to_hash, PASSWORD_DEFAULT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/GradeView Online.png">
    <meta charset="UTF-8">
    <title>Password Hash Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .hash-box { max-width: 600px; margin: 5rem auto; }
        .result { word-break: break-all; background-color: #e9ecef; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card hash-box shadow-sm">
            <div class="card-header">
                <h3 class="card-title">Password Hash Generator</h3>
            </div>
            <div class="card-body">
                <p class="card-text">Use this tool to generate a secure password hash for manual database entry. Copy the generated hash and paste it into the `password` column of the `users` table.</p>
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="password" class="form-label fw-bold">Enter Password:</label>
                        <input type="text" class="form-control" id="password" name="password" value="<?php echo htmlspecialchars($password_to_hash); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Generate Hash</button>
                </form>

                <?php if ($hashed_password): ?>
                <hr>
                <div class="mt-4">
                    <h5>Generated Hash:</h5>
                    <div class="p-3 rounded result">
                        <code><?php echo htmlspecialchars($hashed_password); ?></code>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>