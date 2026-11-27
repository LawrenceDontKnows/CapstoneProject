<?php
/**
 * GradeView Online - Desktop Sync API
 * Operations: token, list, download, upload, status
 *
 * Auth:
 *  - "token" issues a device token and requires a logged-in teacher session.
 *  - All other operations authenticate with the device token
 *    (header X-GV-Token or ?token=...), scoped to the owning teacher.
 *
 * Storage: Railway volume mounted at /var/www/html/uploads/grade_imports
 */

include __DIR__ . '/includes/conn.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function gv_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function gv_fail($msg, $code = 400) {
    gv_json(['ok' => false, 'error' => $msg], $code);
}

function gv_token_from_request() {
    $t = $_SERVER['HTTP_X_GV_TOKEN'] ?? '';
    if ($t === '') { $t = $_GET['token'] ?? ''; }
    if ($t === '') { $t = $_POST['token'] ?? ''; }
    return trim((string)$t);
}

function gv_auth_device($pdo) {
    $token = gv_token_from_request();
    if ($token === '' || strlen($token) < 20) { gv_fail('Missing device token.', 401); }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT id, teacher_id FROM sync_devices WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) { gv_fail('Invalid device token.', 401); }
    $pdo->prepare("UPDATE sync_devices SET last_used_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return (int)$row['teacher_id'];
}

function gv_manifest($dir) {
    $f = $dir . '/manifest.json';
    if (!is_file($f)) return [];
    $m = json_decode(@file_get_contents($f), true);
    return is_array($m) ? $m : [];
}

function gv_find_entry($manifest, $stored, $teacherId) {
    foreach ($manifest as $m) {
        if (($m['stored'] ?? '') === $stored) {
            if ((int)($m['uploader_id'] ?? 0) === $teacherId) return $m;
            return null; // owned by someone else
        }
    }
    return null;
}

function gv_save_manifest($dir, $manifest) {
    @file_put_contents($dir . '/manifest.json', json_encode(array_values($manifest), JSON_PRETTY_PRINT), LOCK_EX);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$dir = __DIR__ . '/uploads/grade_imports';

/* ---- Issue a device token (requires teacher session) ---- */
if ($action === 'token') {
    if (php_sapi_name() === 'cli') { gv_fail('CLI not allowed.', 403); }
    include __DIR__ . '/includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) { gv_fail('No session.', 401); }
    if (($_SESSION['role'] ?? '') !== 'teacher' || empty($_SESSION['user_id'])) {
        gv_fail('Teacher login required.', 401);
    }
    $teacherId = (int)$_SESSION['user_id'];
    $label = trim($_POST['label'] ?? 'Desktop helper');
    $raw = bin2hex(random_bytes(24)); // 48-char token, shown once
    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare("INSERT INTO sync_devices (teacher_id, token_hash, label) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$teacherId, $hash, $label]);
    } catch (PDOException $e) {
        gv_fail('Could not create token. Run add_sync_devices.sql first.', 500);
    }
    gv_json(['ok' => true, 'token' => $raw, 'label' => $label]);
}

/* ---- Everything below needs a valid device token ---- */
$teacherId = gv_auth_device($pdo);

/* ---- List the teacher's imported files ---- */
if ($action === 'list') {
    $manifest = gv_manifest($dir);
    $files = [];
    foreach ($manifest as $m) {
        if ((int)($m['uploader_id'] ?? 0) !== $teacherId) continue;
        $stored = (string)($m['stored'] ?? '');
        $path = $dir . '/' . $stored;
        $files[] = [
            'stored'        => $stored,
            'original_name' => $m['original_name'] ?? $stored,
            'subject_id'    => (int)($m['subject_id'] ?? 0),
            'subject_name'  => $m['subject_name'] ?? '',
            'uploaded_at'   => $m['uploaded_at'] ?? '',
            'size'          => is_file($path) ? filesize($path) : 0,
            'sha1'          => is_file($path) ? sha1_file($path) : '',
        ];
    }
    gv_json(['ok' => true, 'files' => $files]);
}

/* ---- Download the latest server workbook ---- */
if ($action === 'download') {
    $stored = basename((string)($_GET['stored'] ?? ''));
    $entry = gv_find_entry(gv_manifest($dir), $stored, $teacherId);
    if (!$entry) { gv_fail('File not found or not owned by you.', 404); }
    $path = $dir . '/' . $stored;
    if (!is_file($path)) { gv_fail('File missing on server.', 404); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $entry['original_name'] ?? $stored) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---- Status (hash/size) for conflict detection ---- */
if ($action === 'status') {
    $stored = basename((string)($_GET['stored'] ?? ''));
    $entry = gv_find_entry(gv_manifest($dir), $stored, $teacherId);
    if (!$entry) { gv_fail('File not found or not owned by you.', 404); }
    $path = $dir . '/' . $stored;
    if (!is_file($path)) { gv_fail('File missing on server.', 404); }
    gv_json([
        'ok'   => true,
        'stored' => $stored,
        'size' => filesize($path),
        'sha1' => sha1_file($path),
    ]);
}

/* ---- Upload an edited workbook (atomic, validated) ---- */
if ($action === 'upload') {
    $stored = basename((string)($_POST['stored'] ?? ''));
    $entry = gv_find_entry(gv_manifest($dir), $stored, $teacherId);
    if (!$entry) { gv_fail('File not found or not owned by you.', 404); }

    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { gv_fail('No file uploaded.', 400); }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { gv_fail('Upload error.', 400); }
    if (($f['size'] ?? 0) <= 0 || $f['size'] > 20 * 1024 * 1024) { gv_fail('Empty or too large (max 20MB).', 400); }

    // Validate it is a real .xlsx (ZIP) workbook, not a partial/HTML file.
    $tmp = $f['tmp_name'];
    $fh = fopen($tmp, 'rb');
    $magic = fread($fh, 2);
    fclose($fh);
    if ($magic !== 'PK') { gv_fail('Not a valid .xlsx file (bad signature).', 400); }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { gv_fail('Workbook is incomplete or corrupt.', 400); }
        $hasContent = ($zip->locateName('xl/workbook.xml') !== false) || ($zip->locateName('[Content_Types].xml') !== false);
        $zip->close();
        if (!$hasContent) { gv_fail('Workbook missing Excel content.', 400); }
    }

    // Conflict check: client must send the sha1 it based its edits on.
    $base = (string)($_POST['base_sha1'] ?? '');
    $current = sha1_file($dir . '/' . $stored);
    $force = (($_POST['force'] ?? '0') === '1');
    if ($base !== '' && $base !== $current && !$force) {
        gv_fail('The server copy changed since you opened it. Re-open to get the latest, or resend with force=1.', 409);
    }

    // Atomic replace: write to temp in same dir, then rename.
    $dest = $dir . '/' . $stored;
    $tmpOut = $dest . '.sync-' . bin2hex(random_bytes(4)) . '.tmp';
    if (!move_uploaded_file($tmp, $tmpOut)) { gv_fail('Could not store upload.', 500); }
    if (!@rename($tmpOut, $dest)) { @unlink($tmpOut); gv_fail('Could not replace file.', 500); }
    @chmod($dest, 0664);

    gv_json([
        'ok'      => true,
        'stored'  => $stored,
        'size'    => filesize($dest),
        'sha1'    => sha1_file($dest),
    ]);
}

gv_fail('Unknown action.', 400);