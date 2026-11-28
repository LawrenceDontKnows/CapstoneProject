<?php
// The ob_start("minifyHTML") function was causing JavaScript syntax errors by removing newlines after single-line comments (//).
// This is the primary reason graphs and other scripts were failing. It is now disabled.
// ob_start("minifyHTML");

/**
 * DATABASE MAINTENANCE
 * @warning This function is dangerous and can lead to data integrity issues by re-writing primary keys. Use with extreme caution, only on a fresh database, or not at all. Ensure all foreign keys have ON UPDATE CASCADE.
 */
function reorderIDs($pdo, $table) {
    // Resets the sequence of IDs to be gapless (1, 2, 3...)
    // This relies on ON UPDATE CASCADE being set in the databasedont 

    
    $pdo->exec("SET @count = 0;");
    $pdo->exec("UPDATE `$table` SET id = (@count := @count + 1) ORDER BY id ASC;");
    
    $result = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    $next_id = $result + 1;
    $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = $next_id;");
}

/** * RESET AUTO_INCREMENT */
function resetTableCounter($pdo, $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
    }
}

/** * AUTHENTICATION & ACCESS CONTROL */
function isLoggedIn() { return isset($_SESSION['user_id']); }
function redirect($url) { 
    header("Location: $url"); 
    exit(); 
}
function hasRole($role) { return isset($_SESSION['role']) && $_SESSION['role'] === $role; }

/**
 * Generates a consistent, hashed fingerprint of the user's client.
 * This helps mitigate session hijacking by checking for changes in the user's environment.
 *
 * @return string The SHA-256 hash of the user's browser properties.
 */
function generateSessionFingerprint() {
    // Key elements to build the fingerprint.
    // HTTP_USER_AGENT is a primary identifier.
    // REMOTE_ADDR has been removed as it is too volatile for mobile users or those behind certain proxies,
    // causing frequent, unnecessary logouts. The User-Agent and Accept headers provide a reasonable
    // level of protection against basic session hijacking without harming usability.
    $fingerprintData = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
    ];

    return hash('sha256', implode('|', $fingerprintData));
}

/**
 * Validates the current session against hijacking, fixation, and timeouts.
 * This should be called on every authenticated page.
 */
function validateSession() {
    $max_inactivity_time = 1800; // 30 minutes
    $max_session_lifetime = 28800; // 8 hours

    // 1. Check if user is logged in at all
    if (!isLoggedIn()) {
        // If this function is called, the user MUST be logged in.
        // If not, the session is invalid. Destroy and redirect to ensure a clean state.
        session_unset();
        session_destroy();
        redirect('index.php?reason=invalid_session');
    }

    // 2. Check for absolute session lifetime
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $max_session_lifetime)) {
        session_unset();
        session_destroy();
        redirect('index.php?reason=expired');
    }

    // 3. Check for inactivity timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_inactivity_time)) {
        session_unset();
        session_destroy();
        redirect('index.php?reason=inactive');
    }
    $_SESSION['last_activity'] = time(); // Update last activity time

    // 4. Periodically regenerate session ID to mitigate session fixation.
    // Regenerate every 15 minutes.
    if (empty($_SESSION['session_regenerated']) || (time() - $_SESSION['session_regenerated'] > 900)) {
        session_regenerate_id(true);
        $_SESSION['session_regenerated'] = time();
    }

    // 5. Check for session fingerprint to mitigate hijacking.
    if (isset($_SESSION['session_fingerprint'])) {
        if ($_SESSION['session_fingerprint'] !== generateSessionFingerprint()) {
            // Fingerprint mismatch. This could be a hijack attempt.
            session_unset();
            session_destroy();
            redirect('index.php?reason=hijack_attempt');
        }
    } else {
        // If fingerprint isn't set on a logged-in session, it's invalid.
        session_unset();
        session_destroy();
        redirect('index.php?reason=invalid_session');
    }
}

/**
 * Sets up the session with security values after a successful login.
 * This should be called from your login script.
 */
function setupSessionSecurity() {
    session_regenerate_id(true); // Prevent session fixation
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    // Store a hashed fingerprint of the user's environment.
    $_SESSION['session_fingerprint'] = generateSessionFingerprint();
}

function requireRole($role) { if (!isLoggedIn() || !hasRole($role)) { redirect('index.php'); } }
function getSystemSetting($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: $default;
    } catch (Exception $e) { return $default; }
}
function getSystemLogoUrl($pdo) {
    $logo = getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg');
    $path = __DIR__ . '/../' . $logo;
    if ($logo !== '' && file_exists($path)) {
        return $logo;
    }
    return 'image/aclc.jpg';
}
function outputThemeColors($pdo) {
    $colors = [
        'theme_sidebar_bg' => '#0f172a',
        'theme_primary_color' => '#1e3a8a',
        'theme_body_bg' => '#f1f5f9',
        'theme_header_bg' => '#ffffff',
        'theme_text_color' => '#1e293b',
        'theme_accent_color' => '#6366f1',
    ];
    foreach ($colors as $key => $default) {
        $colors[$key] = getSystemSetting($pdo, $key, $default);
    }
    extract($colors);
    echo "<style>
        :root {
            --theme-sidebar-bg: {$theme_sidebar_bg};
            --theme-primary-color: {$theme_primary_color};
            --theme-body-bg: {$theme_body_bg};
            --theme-header-bg: {$theme_header_bg};
            --theme-text-color: {$theme_text_color};
            --theme-accent-color: {$theme_accent_color};
        }
        body { background-color: var(--theme-body-bg); color: var(--theme-text-color); }
        .sidebar { background: var(--theme-sidebar-bg); }
        .sidebar .nav-link.active { background: var(--theme-primary-color); border-left-color: var(--theme-accent-color); }
        .btn-primary { background-color: var(--theme-primary-color); border-color: var(--theme-primary-color); }
        .btn-primary:hover { background-color: var(--theme-accent-color); border-color: var(--theme-accent-color); }
        .navbar-light { background-color: var(--theme-header-bg) !important; }
        .card-header { background-color: var(--theme-header-bg); }
        .text-primary { color: var(--theme-primary-color) !important; }
        .border-primary { border-color: var(--theme-primary-color) !important; }
        .bg-primary { background-color: var(--theme-primary-color) !important; }
    </style>";
}
function logActivity($pdo, $user_id, $role, $action, $description = null) {
    $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, role, action, description, timestamp) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$user_id, $role, $action, $description]);
}

/**
 * Logs an administrative action to the audit_log table.
 *
 * @param PDO $pdo The database connection object.
 * @param int $actor_id The ID of the user performing the action.
 * @param string $action A short string identifying the action (e.g., 'user_create').
 * @param int|null $target_user_id The ID of the user being affected.
 * @param string|null $details A text description of the action, e.g., what was changed.
 */
function logAudit($pdo, $actor_id, $action, $target_user_id = null, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_user_id, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$actor_id, $action, $target_user_id, $details]);
    } catch (PDOException $e) {
        // Log the error to a file but don't stop the user's action.
        error_log("Audit Log Error: " . $e->getMessage());
    }
}

function getGradeWeights($pdo, $teacher_id = null) {
    $keys = ['weight_lec', 'weight_lab', 'weight_lec_quiz', 'weight_lec_online', 'weight_lec_exam', 'weight_lab_att', 'weight_lab_cs', 'weight_lab_exam'];
    $defaults = ['weight_lec' => 40, 'weight_lab' => 60, 'weight_lec_quiz' => 40, 'weight_lec_online' => 10, 'weight_lec_exam' => 50, 'weight_lab_att' => 10, 'weight_lab_cs' => 40, 'weight_lab_exam' => 50];
    $weights = [];
    foreach ($keys as $key) {
        $val = null;
        if ($teacher_id) {
            $stmt = $pdo->prepare("SELECT setting_value FROM teacher_settings WHERE teacher_id = ? AND setting_key = ?");
            $stmt->execute([$teacher_id, $key]);
            $val = $stmt->fetchColumn();
        }
        if ($val === null || $val === false || $val === '') {
            $val = getSystemSetting($pdo, $key, $defaults[$key] ?? 0);
        }
        $weights[str_replace('weight_', '', $key)] = (float)$val / 100;
    }
    return $weights;
}

/**
 * Returns period/term weights as fractions summing to 1.0.
 * Keys: prelim, midterm, prefinal, final. Configurable via settings.
 */
function getPeriodWeights($pdo) {
    $keys = ['period_weight_prelim', 'period_weight_midterm', 'period_weight_prefinal', 'period_weight_final'];
    $defaults = [20, 20, 20, 40];
    $vals = [];
    $sum = 0;
    foreach ($keys as $i => $k) {
        $v = (float)getSystemSetting($pdo, $k, $defaults[$i]);
        if ($v < 0) $v = 0;
        $vals[] = $v;
        $sum += $v;
    }
    $out = [];
    $names = ['prelim', 'midterm', 'prefinal', 'final'];
    if ($sum <= 0) {
        foreach ($names as $i => $n) $out[$n] = $defaults[$i] / 100;
    } else {
        foreach ($names as $i => $n) $out[$n] = $vals[$i] / $sum;
    }
    return $out;
}

function calculateOverallGrade($prelim, $midterm, $prefinal, $final, $periodWeights = null) {
    if ($periodWeights === null) $periodWeights = ['prelim' => 0.2, 'midterm' => 0.2, 'prefinal' => 0.2, 'final' => 0.4];
    return ((float)$prelim * ($periodWeights['prelim'] ?? 0.2))
         + ((float)$midterm * ($periodWeights['midterm'] ?? 0.2))
         + ((float)$prefinal * ($periodWeights['prefinal'] ?? 0.2))
         + ((float)$final * ($periodWeights['final'] ?? 0.4));
}

function calculatePeriodTotal($quiz_avg, $cs, $att, $lab, $exam, $weights) {
    $online = $cs;
    $lecGrade = ((float)$quiz_avg * ($weights['lec_quiz'] ?? 0)) + ((float)$cs * ($weights['lec_online'] ?? 0)) + ((float)$exam * ($weights['lec_exam'] ?? 0));
    $labGrade = ((float)$lab * ($weights['lab_cs'] ?? 0)) + ((float)$att * ($weights['lab_att'] ?? 0)) + ((float)$exam * ($weights['lab_exam'] ?? 0));
    return ($lecGrade * ($weights['lec'] ?? 0.4)) + ($labGrade * ($weights['lab'] ?? 0.6));
}

function getDashboardUrl() {
    if (!isLoggedIn()) return 'index.php';
    switch ($_SESSION['role']) {
        case 'admin': return 'admin.php';
        case 'teacher': return 'teacher.php';
        case 'student': return 'students.php';
        default: return 'index.php';
    }
}

/** * DATA RETRIEVAL */

function getStudentInfo($pdo, $student_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->execute([$student_id]);
    return $stmt->fetch();
}

function getTotalStudentsCount($pdo) {
    return $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
}

/** * GRADE UTILITIES */
function calculateGradePoint($grade) {
    $grade = (float)$grade;
    if ($grade >= 96) return '1.00';
    if ($grade >= 91) return '1.25';
    if ($grade >= 86) return '1.50';
    if ($grade >= 81) return '1.75';
    if ($grade >= 75) return '2.00';
    if ($grade >= 69) return '2.25';
    if ($grade >= 63) return '2.50';
    if ($grade >= 57) return '2.75';
    if ($grade >= 50) return '3.00';
    if ($grade > 0 && $grade < 50) return '5.00';
    
    return 'IP'; // In Progress or no grade
}

function getGradeColor($grade) {
    $grade = (float)$grade;
    if ($grade >= 90) return 'success';
    if ($grade >= 80) return 'primary';
    if ($grade >= 75) return 'info';
    if ($grade > 0) return 'danger';
    return 'secondary';
}

    function getGradePointColorClass($letter) {
    switch ($letter) {
        case '1.00': case '1.25': return 'bg-success';
        case '1.50': case '1.75': return 'bg-primary';
        case '2.00': case '2.25': return 'bg-info text-dark';
        case '2.50': case '2.75': return 'bg-warning text-dark';
        case '3.00': return 'bg-danger';
        case '5.00': return 'bg-dark';
        case 'IP': return 'bg-secondary';
        default: return 'bg-light';
    }
}

/**
 * Finds and highlights common keywords and placeholders in notification messages.
 *
 * @param string $message The raw notification message.
 * @return string The HTML-formatted message with highlighted keywords.
 */
function highlightNotificationKeywords($message) {
    // First, escape the entire message to prevent XSS.
    $escaped_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    // Define placeholders, keywords, and common date/time formats to highlight.
    $patterns = [
        // Placeholders
        '\[Date\]', '\[Time\]', '\[Holiday\]', '\[Topic\]', '\[Day\]', '\[Time Start\]', '\[Time End\]',
        // Keywords
        '\bdate\b', '\btime\b', '\bholiday\b', '\bcanceled\b', '\breminder\b', '\bexam\b', '\bimportant\b', '\burgent\b', '\bmaintenance\b', '\bannouncement\b',
        // Common Date/Time Formats (from flatpickr defaults)
        '\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b', // F j, Y
        '\b\d{1,2}:\d{2}\s(?:AM|PM)\b' // h:i K
    ];
    
    $regex = '/'.implode('|', $patterns).'/i';
    return preg_replace($regex, '<strong class="text-primary">$0</strong>', $escaped_message);
}

/** * DELETE & MANAGEMENT FUNCTIONS */

function deleteUser($pdo, $id) {
    try {
        $pdo->beginTransaction();
        // Handle manual cleanup for safety before deleting the user
        $pdo->prepare("DELETE FROM grades WHERE teacher_id = ?")->execute([$id]);
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $pdo->commit();

        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error deleting user: " . $e->getMessage();
    }
}

function archiveUser($pdo, $id) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET status = 'archived' WHERE id = ?");
        $stmt->execute([$id]);
        return true;
    } catch (Exception $e) {
        return "Error archiving user: " . $e->getMessage();
    }
}

function restoreUser($pdo, $id) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
        return true;
    } catch (Exception $e) {
        return "Error restoring user: " . $e->getMessage();
    }
}

function deleteSubject($pdo, $subject_id) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM grades WHERE subject_id = ?")->execute([$subject_id]);
        $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$subject_id]);
        $pdo->commit();

        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return "Error deleting subject: " . $e->getMessage();
    }
}

/**
 * Renders the set of action buttons for a user row in the admin user management table.
 *
 * @param array $user The user data array.
 * @param string $search_user The current search query for context.
 * @return string The HTML for the action buttons.
 */
function renderAdminUserActionButtons($user, $search_user) {
    $role = htmlspecialchars($user['role']);
    $user_id = htmlspecialchars($user['id']);
    $username = htmlspecialchars($user['username']);
    $csrf = $_SESSION['csrf_token'];
    $search_query = urlencode($search_user);

    // --- Start: Common buttons for all roles ---
    $all_data = htmlspecialchars(json_encode($user), ENT_QUOTES, "UTF-8");
    $buttons = <<<HTML
<button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewUserProfileModal" data-all='{$all_data}' title="View Full Profile"><i class="fas fa-eye"></i></button>
HTML;
    // --- End: Common buttons ---

    // Build data attributes for the common "Edit" button
    $edit_data_attrs = [
        'data-bs-toggle="modal"',
        'data-bs-target="#editUserModal"',
        'data-id="' . $user_id . '"',
        'data-username="' . $username . '"',
        'data-firstname="' . htmlspecialchars($user['first_name']) . '"',
        'data-lastname="' . htmlspecialchars($user['last_name']) . '"',
        'data-email="' . htmlspecialchars($user['email']) . '"',
        'data-role="' . $role . '"',
        'data-phone-number="' . htmlspecialchars($user['phone_number'] ?? '') . '"',
        'data-profile-picture="' . htmlspecialchars($user['profile_picture'] ?? '') . '"',
    ];

    // Add student-specific buttons and data attributes
    if ($role === 'student') {
        $full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
        $buttons .= <<<HTML
<button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#monitorPerformanceModal" data-id="{$user_id}" data-name="{$full_name}" title="Monitor Performance"><i class="fas fa-chart-line"></i></button>
HTML;
        $edit_data_attrs[] = 'data-course="' . htmlspecialchars($user['course'] ?? '') . '"';
        $edit_data_attrs[] = 'data-year-level="' . htmlspecialchars($user['year_level'] ?? '') . '"';
        $edit_data_attrs[] = 'data-home-address="' . htmlspecialchars($user['home_address'] ?? '') . '"';
        $edit_data_attrs[] = 'data-city="' . htmlspecialchars($user['city'] ?? '') . '"';
        $edit_data_attrs[] = 'data-province="' . htmlspecialchars($user['province'] ?? '') . '"';
        $edit_data_attrs[] = 'data-guardian-name="' . htmlspecialchars($user['guardian_name'] ?? '') . '"';
        $edit_data_attrs[] = 'data-guardian-status="' . htmlspecialchars($user['guardian_status'] ?? '') . '"';
        $edit_data_attrs[] = 'data-guardian-phone="' . htmlspecialchars($user['guardian_phone'] ?? '') . '"';
        $edit_data_attrs[] = 'data-guardian-occupation="' . htmlspecialchars($user['guardian_occupation'] ?? '') . '"';
        $edit_data_attrs[] = 'data-guardian-address="' . htmlspecialchars($user['guardian_address'] ?? '') . '"';
    }

    // Add the common buttons for all roles
    $buttons .= '<button class="btn btn-sm btn-outline-primary" ' . implode(' ', $edit_data_attrs) . ' title="Edit User"><i class="fas fa-edit"></i></button>';
    
    $buttons .= <<<HTML
<button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#changePasswordModal" data-id="{$user_id}" data-username="{$username}" title="Change Password"><i class="fas fa-key"></i></button>
HTML;

    $archive_confirm_text = $role === 'student' ? 'Archive this student record?' : "Archive this {$role}?";
    $buttons .= <<<HTML
<a href="?action=archive_user&id={$user_id}&search_user={$search_query}&csrf_token={$csrf}" class="btn btn-sm btn-outline-secondary" onclick="return confirm('{$archive_confirm_text}')" title="Archive User"><i class="fas fa-archive"></i></a>
HTML;

    return '<div class="user-actions">' . $buttons . '</div>';
}

/**
 * Handles the processing and saving of an uploaded profile picture.
 *
 * @param PDO $pdo The database connection object.
 * @param array|null $file_data The specific file array from $_FILES (e.g., $_FILES['profile_picture']).
 * @param int $user_id The ID of the user to associate the picture with.
 * @param array &$errors A reference to an array where error messages will be stored.
 * @return string|null The path to the newly saved image on success, or null on failure.
 */
function handleProfilePictureUpload($pdo, $file_data, $user_id, &$errors) {
    // --- NEW: Prioritize cropped image data from a hidden input field ---
    if (isset($_POST['cropped_image_data']) && !empty($_POST['cropped_image_data'])) {
        $base64_image = $_POST['cropped_image_data'];
        
        // Expected format: data:image/jpeg;base64,.....
        if (preg_match('/^data:image\/(\w+);base64,/', $base64_image, $type)) {
            $data = substr($base64_image, strpos($base64_image, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
                $errors[] = 'Invalid image type from cropper.';
                return null;
            }
            $data = base64_decode($data);
            if ($data === false) {
                $errors[] = 'Base64 decode failed.';
                return null;
            }

            $old_pic_stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
            $old_pic_stmt->execute([$user_id]);
            $old_pic_path = $old_pic_stmt->fetchColumn();

            $target_dir = "uploads/avatars/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            
            $new_picture_path = $target_dir . "user_" . $user_id . "_" . time() . "." . $type;

            if (file_put_contents($new_picture_path, $data)) {
                if ($old_pic_path && file_exists($old_pic_path) && strpos($old_pic_path, 'default') === false) @unlink($old_pic_path);
                return $new_picture_path;
            }
            $errors[] = "Failed to save the cropped profile picture.";
            return null;
        }
    }

    // --- Original file upload logic (as a fallback) ---
    if (!$file_data || $file_data['error'] !== UPLOAD_ERR_OK) {
        // No file uploaded or a non-critical upload error occurred. This is not a failure of this function.
        return null;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if (!in_array($file_data['type'], $allowed_types) || $file_data['size'] > $max_size) {
        $errors[] = "Invalid file type or size for profile picture (max 2MB for JPG, PNG, GIF).";
        return null;
    }

    $old_pic_stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $old_pic_stmt->execute([$user_id]);
    $old_pic_path = $old_pic_stmt->fetchColumn();

    $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
    $target_dir = "uploads/avatars/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    
    $new_picture_path = $target_dir . "user_" . $user_id . "_" . time() . "." . $file_ext;

    if (move_uploaded_file($file_data['tmp_name'], $new_picture_path)) {
        if ($old_pic_path && file_exists($old_pic_path) && strpos($old_pic_path, 'default') === false) @unlink($old_pic_path);
        return $new_picture_path;
    }
    $errors[] = "Failed to save the new profile picture.";
    return null;
}

/** * SOURCE PROTECTION & MINIFICATION */
// Test Case 20: Anti-Inspection & Source Protection (Point 3)
function minifyHTML($buffer) {
    // 1. Remove HTML comments
    $buffer = preg_replace('/<!--(.|\s)*?-->/', '', $buffer);

    // Aggressively remove single-line comments and multi-line comments
    $buffer = preg_replace('/(?<!:)\/\/.*$/m', '', preg_replace('!/\*.*?\*/!s', '', $buffer));

    /* Aggressive Minification into a single line (Requirement 3) */
    $search = array(
        '/\>[^\S ]+/s',     /* strip whitespaces after tags, except space */
        '/[^\S ]+\</s',     /* strip whitespaces before tags, except space */
        '/(\s)+/s',         /* shorten multiple whitespace sequences */
        '/^\s+|\s+$/m',     /* trim each line */
        '/\n/',             /* remove newlines */
        '/\r/',             /* remove carriage returns */
        '/\t/'              /* remove tabs */
    );
    $replace = array('>', '<', '\\1', '', '', '', '');
    $buffer = preg_replace($search, $replace, $buffer);

    return trim($buffer);
}
/**
 * Read tabular rows from CSV/TSV/TXT/XLSX files for format-agnostic imports.
 * Returns array of associative rows using normalized header keys, or ['error' => string].
 */
function readImportRows($filePath, $originalName = '') {
    $ext = strtolower(pathinfo($originalName ?: $filePath, PATHINFO_EXTENSION));
    $xlsxLike = ['xlsx', 'xlsm', 'xltx', 'xltm'];
    if (in_array($ext, $xlsxLike, true)) {
        return readXlsxImportRows($filePath, $ext);
    }
    if ($ext === 'xls') {
        // Try as OOXML first in case it's misnamed, then as an HTML table.
        $res = readXlsxImportRows($filePath, $ext);
        if (is_array($res) && !isset($res['error'])) return $res;
        $html = readHtmlTableRows($filePath);
        if (is_array($html) && !isset($html['error'])) return $html;
        return ['error' => 'Legacy .xls (BIFF) files cannot be read directly in this environment. Please save/export the file as .xlsx or CSV and try again.'];
    }
    if (in_array($ext, ['ods', 'numbers'], true)) {
        return ['error' => strtoupper($ext) . ' files are not supported directly. Please export/save the file as .xlsx or CSV and import again.'];
    }
    // Text-like: some systems export grade tables as HTML (even with .xls/.txt names).
    $html = readHtmlTableRows($filePath);
    if (is_array($html) && !isset($html['error'])) return $html;

    // For unknown or text-like extensions, try delimited parsing; if it fails and file looks like zip/xlsx, try xlsx reader
    $delimited = readDelimitedImportRows($filePath, $ext);
    if (is_array($delimited) && !isset($delimited['error'])) return $delimited;
    $fh = @fopen($filePath, 'rb');
    $sig = $fh ? fread($fh, 4) : '';
    if ($fh) fclose($fh);
    if ($sig === "PK\x03\x04") {
        $xlsxTry = readXlsxImportRows($filePath, $ext ?: 'xlsx');
        if (is_array($xlsxTry) && !isset($xlsxTry['error'])) return $xlsxTry;
    }
    if (is_array($delimited) && isset($delimited['error'])) {
        // Improve generic message for likely unsupported binary formats
        $binExts = ['pdf','doc','docx','rtf','jpg','jpeg','png','gif','bmp','zip','rar','7z'];
        if (in_array($ext, $binExts, true)) {
            return ['error' => 'This file type cannot be imported as a grade table. Please export/save the data as .xlsx or CSV (with a header row) and import again.'];
        }
        return $delimited;
    }
    return ['error' => 'Unsupported or unreadable file. Please upload an Excel/CSV file with a header row (or export your template as .xlsx or CSV).'];
}

function readHtmlTableRows($filePath) {
    $html = @file_get_contents($filePath);
    if ($html === false || stripos($html, '<table') === false) return null;
    $rows = [];
    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trs)) {
        foreach ($trs[1] as $tr) {
            $cells = [];
            if (preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $tds)) {
                foreach ($tds[1] as $td) {
                    $cells[] = trim(html_entity_decode(strip_tags($td), ENT_QUOTES, 'UTF-8'));
                }
            }
            if (!empty($cells)) $rows[] = $cells;
        }
    }
    return empty($rows) ? null : tabularRowsToAssoc($rows);
}

function readDelimitedImportRows($filePath, $ext = '') {
    $handle = fopen($filePath, 'r');
    if ($handle === false) return ['error' => 'Unable to read uploaded file.'];

    $sample = fread($handle, 4096);
    rewind($handle);
    $sample = $sample === false ? '' : $sample;
    $firstLine = strtok(str_replace("\r", "\n", $sample), "\n") ?: '';
    $delimiters = [',', "\t", ';', '|'];
    $delimiter = ',';
    $bestCount = -1;
    foreach ($delimiters as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $bestCount) { $bestCount = $count; $delimiter = $d; }
    }
    if ($bestCount <= 0 && $ext === 'tsv') $delimiter = "\t";

    $rows = [];
    $lineNo = 0;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNo++;
        if ($lineNo === 1 && isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
        }
        $rows[] = array_map(function($v){ return is_string($v) ? trim($v) : $v; }, $row);
    }
    fclose($handle);
    return tabularRowsToAssoc($rows);
}

function readXlsxImportRows($filePath, $ext = 'xlsx') {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'XLSX/XLS import requires PHP ZipArchive support. Please upload CSV/TSV or enable ZipArchive.'];
    }
    $zip = new ZipArchive();
    $openRes = $zip->open($filePath);
    if ($openRes !== true) {
        $hint = ($ext === 'xls')
            ? 'Legacy .xls (BIFF) files are not supported without conversion. Please save/export the file as .xlsx or CSV and try again.'
            : 'Unable to open spreadsheet file. If this is an .xls file, save it as .xlsx or CSV and try again.';
        return ['error' => $hint];
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $run) { $text .= (string)$run->t; }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // Resolve all worksheet paths in workbook order
    $sheetPaths = [];
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $relMap = [];
    if ($relsXml !== false) {
        $rels = @simplexml_load_string($relsXml);
        if ($rels) {
            foreach ($rels->Relationship as $rel) {
                $attrs = $rel->attributes();
                $rid = (string)($attrs['Id'] ?? '');
                $target = (string)($attrs['Target'] ?? '');
                if ($rid !== '' && $target !== '') {
                    // Relationship targets may be absolute (/xl/...), already
                    // package-relative (xl/...), or relative to xl/ (worksheets/...).
                    if ($target[0] === '/') {
                        $relMap[$rid] = ltrim($target, '/');
                    } elseif (strpos($target, 'xl/') === 0) {
                        $relMap[$rid] = $target;
                    } else {
                        $relMap[$rid] = 'xl/' . $target;
                    }
                }
            }
        }
    }
    if ($wbXml !== false) {
        $wb = @simplexml_load_string($wbXml);
        if ($wb && isset($wb->sheets->sheet)) {
            foreach ($wb->sheets->sheet as $sheetNode) {
                $rid = (string)$sheetNode->attributes('r', true)->id;
                if ($rid !== '' && isset($relMap[$rid])) $sheetPaths[] = $relMap[$rid];
            }
        }
    }
    if (empty($sheetPaths)) {
        // Fallback: include any worksheet xmls found
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) $sheetPaths[] = $name;
        }
        sort($sheetPaths, SORT_NATURAL);
    }
    if (empty($sheetPaths)) $sheetPaths[] = 'xl/worksheets/sheet1.xml';

    $best = null; $bestScore = -1; $lastError = null;
    foreach ($sheetPaths as $sheetPath) {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) continue;
        $sheet = @simplexml_load_string($sheetXml);
        if (!$sheet || !isset($sheet->sheetData)) continue;

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            $maxCol = -1;
            $autoCol = 0;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                if ($ref !== '') {
                    $colLetters = preg_replace('/\d+/', '', $ref);
                    $colIndex = xlsxColumnIndex($colLetters);
                } else {
                    // Some writers omit the cell reference; fall back to order.
                    $colIndex = $autoCol;
                }
                $autoCol = $colIndex + 1;
                $type = (string)$c['t'];
                $value = '';
                if ($type === 's') {
                    $idx = isset($c->v) ? (int)$c->v : -1;
                    $value = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
                } elseif ($type === 'inlineStr') {
                    if (isset($c->is->t)) {
                        $value = (string)$c->is->t;
                    } elseif (isset($c->is->r)) {
                        $value = '';
                        foreach ($c->is->r as $run) { $value .= (string)$run->t; }
                    } else {
                        $value = '';
                    }
                } elseif (isset($c->v)) {
                    $value = (string)$c->v;
                } elseif (isset($c->is->t)) {
                    $value = (string)$c->is->t;
                }
                $cells[$colIndex] = is_string($value) ? trim($value) : $value;
                if ($colIndex > $maxCol) $maxCol = $colIndex;
            }
            if ($maxCol < 0) { $rows[] = []; continue; }
            $full = [];
            for ($i = 0; $i <= $maxCol; $i++) { $full[$i] = array_key_exists($i, $cells) ? $cells[$i] : ''; }
            $rows[] = $full;
        }

        $parsed = tabularRowsToAssoc($rows);
        if (is_array($parsed) && isset($parsed['error'])) { $lastError = $parsed['error']; continue; }
        $score = is_array($parsed) ? count($parsed) : 0;
        // Prefer sheets that look like a grade table: a student identifier
        // column plus one or more grade/score columns.
        if (!empty($parsed) && is_array($parsed[0])) {
            $keys = array_keys($parsed[0]);
            $idHints = ['student_id','name','username','email','first_name','last_name'];
            foreach ($keys as $k) { if (in_array($k, $idHints, true)) { $score += 100; break; } }
            $score += min(count($keys), 50);
            $gradeHits = 0;
            foreach ($keys as $k) { if (isGradeLikeImportKey($k)) $gradeHits++; }
            $score += min($gradeHits * 15, 90);
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $parsed; }
    }
    $zip->close();
    if ($best !== null) return $best;
    if ($ext === 'xls') {
        return ['error' => 'Legacy .xls (BIFF) files cannot be read directly in this environment. Please save/export as .xlsx or CSV and import again.'];
    }
    return ['error' => $lastError ?: 'Could not read worksheet data from spreadsheet.'];
}

function xlsxColumnIndex($letters) {
    $letters = strtoupper($letters);
    $num = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return $num > 0 ? $num - 1 : 0;
}

function tabularRowsToAssoc($rows) {
    if (empty($rows)) return ['error' => 'Empty file. No data rows found.'];

    // Detect header row (handles templates with title/metadata rows above the real header)
    $headerIndex = 0; $bestScore = -1;
    $idHints = ['student_id','name','username','email','first_name','last_name','id','student','lrn','student_no','student_number'];
    $scanLimit = min(count($rows), 30);
    for ($r = 0; $r < $scanLimit; $r++) {
        $row = $rows[$r];
        if (!is_array($row)) continue;
        $nonEmpty = 0; $normKeys = [];
        foreach ($row as $cell) {
            $s = trim((string)$cell);
            if ($s === '') continue;
            $nonEmpty++;
            $k = normalizeImportHeader($s);
            if ($k !== '') $normKeys[] = $k;
        }
        if ($nonEmpty < 1) continue;
        $score = $nonEmpty;
        foreach ($normKeys as $k) { if (in_array($k, $idHints, true)) $score += 20; }
        // Prefer rows that look like headers (textual, multiple columns) over single-title rows
        if ($nonEmpty >= 2) $score += 5;
        if ($score > $bestScore) { $bestScore = $score; $headerIndex = $r; }
    }

    $header = $rows[$headerIndex];
    $dataStart = $headerIndex + 1;

    // Merge a secondary header row (sub-headers sitting under merged group headers).
    // Example: "Prelim Term" spans several columns (row above) and the row below
    // labels each sub-column (Attend, Quiz 1, ..., Prelim Grade). The period grade
    // lives in the last sub-column, not the first column of the merged group, so we
    // must combine both rows instead of treating the merged header as one column.
    if (isset($rows[$headerIndex + 1]) && is_array($rows[$headerIndex + 1])) {
        $subRow = $rows[$headerIndex + 1];
        $idKeys = ['student_id', 'name', 'username', 'email', 'first_name', 'last_name'];
        $idIdx = [];
        $fills = 0;
        $maxc = max(count($header), count($subRow));
        for ($i = 0; $i < $maxc; $i++) {
            $h = trim((string)($header[$i] ?? ''));
            $s = trim((string)($subRow[$i] ?? ''));
            if (in_array(normalizeImportHeader($h), $idKeys, true)) { $idIdx[] = $i; }
            if ($h === '' && $s !== '') { $fills++; }
        }
        // It's a sub-header only if it fills columns left blank by the merged header
        // AND it does not carry a student identifier (which would make it a data row).
        $subHasId = false;
        foreach ($idIdx as $i) {
            if (trim((string)($subRow[$i] ?? '')) !== '') { $subHasId = true; break; }
        }
        if ($fills >= 1 && !$subHasId && !empty($idIdx)) {
            $combined = [];
            for ($i = 0; $i < $maxc; $i++) {
                $h = trim((string)($header[$i] ?? ''));
                $s = trim((string)($subRow[$i] ?? ''));
                // Prefer the specific sub-column label; fall back to the group header.
                $combined[$i] = ($s !== '') ? $s : $h;
            }
            $header = $combined;
            $dataStart = $headerIndex + 2;
        }
    }

    $normalized = [];
    foreach ($header as $h) {
        $key = normalizeImportHeader((string)$h);
        $normalized[] = $key;
    }
    // Ensure unique keys for duplicate/blank headers to avoid collisions
    $seen = [];
    foreach ($normalized as $i => $key) {
        if ($key === '') continue;
        if (isset($seen[$key])) { $seen[$key]++; $normalized[$i] = $key . '_' . $seen[$key]; }
        else { $seen[$key] = 1; }
    }
    $hasHeader = false;
    foreach ($normalized as $k) { if ($k !== '') { $hasHeader = true; break; } }
    if (!$hasHeader) return ['error' => 'Could not detect column headers in the file. Make sure the file has a header row with column names (e.g. Student Name, ID, Quiz, Exam).'];

    $assoc = [];
    $total = count($rows);
    for ($r = $dataStart; $r < $total; $r++) {
        $row = $rows[$r];
        if (!is_array($row)) continue;
        $item = [];
        $empty = true;
        foreach ($normalized as $i => $key) {
            $val = array_key_exists($i, $row) ? $row[$i] : '';
            if ($key !== '') $item[$key] = is_string($val) ? trim($val) : $val;
            if (trim((string)$val) !== '') $empty = false;
        }
        if (!$empty) $assoc[] = $item;
    }
    if (empty($assoc)) return ['error' => 'No data rows found below the detected header row.'];
    return $assoc;
}

function normalizeImportHeader($header) {
    $h = strtolower(trim((string)$header));
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = str_replace(['%', '#'], '', $h);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    $h = trim($h, '_');
    $aliases = [
        'id' => 'student_id', 'studentid' => 'student_id', 'student_no' => 'student_id', 'student_number' => 'student_id',
        'stud_id' => 'student_id', 'id_number' => 'student_id', 'id_no' => 'student_id', 'lrn' => 'student_id',
        'user_name' => 'username', 'user' => 'username',
        'email_address' => 'email', 'e_mail' => 'email',
        'student_name' => 'name', 'fullname' => 'name', 'full_name' => 'name',
        'first_name' => 'first_name', 'firstname' => 'first_name', 'last_name' => 'last_name', 'lastname' => 'last_name',
        'final_grade' => 'grade', 'overall' => 'grade', 'overall_grade' => 'grade', 'gwa' => 'grade',
        'average' => 'grade', 'semestral_grade' => 'grade', 'final_rating' => 'grade', 'fg' => 'grade',
        'weighted_average' => 'grade', 'weighted_avg' => 'grade', 'weighted_grade' => 'grade',
        'overall_average' => 'grade', 'overall_avg' => 'grade', 'final_average_grade' => 'grade',
        'remark' => 'remarks', 'comment' => 'remarks', 'notes' => 'remarks',
        'pre_lim' => 'prelim', 'mid_term' => 'midterm', 'pre_final' => 'prefinal',
        'classstanding' => 'class_standing', 'class_standing' => 'cs', 'class_stand' => 'cs',
        // Alternate period / semester spellings.
        'prelim_term' => 'prelim', 'prelim_period' => 'prelim', 'prelim_score' => 'prelim', 'prelim_avg' => 'prelim', 'prelim_average' => 'prelim',
        'midterm_term' => 'midterm', 'midterm_period' => 'midterm', 'midterm_score' => 'midterm', 'midterm_avg' => 'midterm', 'midterm_average' => 'midterm',
        'prefinal_term' => 'prefinal', 'prefinal_period' => 'prefinal', 'prefinal_score' => 'prefinal', 'prefinal_avg' => 'prefinal', 'prefinal_average' => 'prefinal',
        'final_term' => 'final', 'final_period' => 'final', 'final_score' => 'final', 'final_avg' => 'final', 'final_average' => 'final', 'finals' => 'final',
        'final_sem' => 'grade', 'final_semester' => 'grade', 'semestral' => 'grade', 'sem_grade' => 'grade', 'semester_grade' => 'grade', 'sem' => 'grade',
        // Remarks / status.
        'result' => 'remarks', 'standing' => 'remarks', 'pass_fail' => 'remarks', 'passed' => 'remarks',
    ];
    // Normalize period component variants e.g. prelim_quiz, quiz_prelim, prelim quiz,
    // and period grade columns like "Prelim Grade" / "Final Term Grade" / "PrelimRating".
    $h = preg_replace('/^(prelim|midterm|prefinal|final)(_term)?_?grade$/', '$1', $h);
    $h = preg_replace('/^(prelim|midterm|prefinal|final)(_term)?_?rating$/', '$1', $h);
    $h = preg_replace('/^(prelim|midterm|prefinal|final)(_term)?_?total$/', '$1', $h);
    return isset($aliases[$h]) ? $aliases[$h] : $h;
}

function getImportValue($row, $keys) {
    foreach ((array)$keys as $k) {
        $k = normalizeImportHeader((string)$k);
        if ($k !== '' && array_key_exists($k, $row)) {
            $v = $row[$k];
            if ($v !== null && trim((string)$v) !== '') return trim((string)$v);
        }
    }
    return null;
}

/**
 * Resolve a student (role = student) from an imported row by student id, username,
 * email, or full name. Returns the users row (id, first_name, last_name) or null.
 */
function resolveImportStudent($pdo, $row) {
    $base = "SELECT id, first_name, last_name FROM users WHERE role = 'student'";

    $idRaw = getImportValue($row, ['student_id', 'lrn', 'id_number', 'id_no']);
    if ($idRaw !== null && ctype_digit($idRaw)) {
        $st = $pdo->prepare($base . " AND id = ? LIMIT 1");
        $st->execute([(int)$idRaw]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    $uname = getImportValue($row, ['username']);
    if ($uname !== null && $uname !== '') {
        $st = $pdo->prepare($base . " AND username = ? LIMIT 1");
        $st->execute([$uname]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    $email = getImportValue($row, ['email']);
    if ($email !== null && $email !== '') {
        $st = $pdo->prepare($base . " AND LOWER(email) = LOWER(?) LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    $nameRaw = getImportValue($row, ['name']);
    $first = getImportValue($row, ['first_name']);
    $last = getImportValue($row, ['last_name']);

    if ($nameRaw !== null && $nameRaw !== '') {
        $nameRaw = trim($nameRaw);
        $st = $pdo->prepare($base . " AND (LOWER(CONCAT(first_name,' ',last_name)) = LOWER(?) OR LOWER(CONCAT(last_name,', ',first_name)) = LOWER(?) OR LOWER(CONCAT(last_name,' ',first_name)) = LOWER(?)) LIMIT 1");
        $st->execute([$nameRaw, $nameRaw, $nameRaw]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    if ($first !== null && $first !== '' && $last !== null && $last !== '') {
        $st = $pdo->prepare($base . " AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) LIMIT 1");
        $st->execute([trim($first), trim($last)]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return $u;
    }

    return null;
}

/**
 * Heuristic: does a (normalized) column name look like a grade/score column?
 * Used to support arbitrary grading layouts (e.g. "prelim_term", "Quiz",
 * "Class Standing", "Final Grade", "Attendance %").
 */
function isGradeLikeImportKey($nk) {
    $nk = normalizeImportHeader($nk);
    if ($nk === '') return false;

    $exclude = [
        'serial', 'serial_number', 'student_id', 'username', 'email', 'name', 'first_name', 'last_name',
        'age', 'year_level', 'course', 'section', 'phone', 'contact', 'address', 'guardian', 'photo',
        'image', 'picture', 'date', 'time', 'remarks', 'status', 'letter_grade', 'grade_point', 'gp',
        'gpa', 'lrn', 'no', 'id', 'description', 'weight', 'weight_in_term', 'component',
        'total_attended', 'total_classes', 'attended', 'classes',
    ];
    if (in_array($nk, $exclude, true)) return false;
    if (preg_match('/(serial|student|user|email|first_name|last_name|name|age|year|course|section|phone|contact|address|guardian|photo|image|picture|date|time|weight|component|description|attended|classes|session)/', $nk)) {
        return false;
    }
    if (in_array($nk, ['prelim', 'midterm', 'prefinal', 'final'], true)) return true;
    if (preg_match('/(grade|score|rating|average|avg|percent|pct|mark|point|term|sem|semester|period|prelim|midterm|prefinal|final|quiz|exam|attendance|laboratory|lab|online|assignment|activity|project|seatwork|recitation)/', $nk)) {
        return true;
    }
    return false;
}

/**
 * Compute grades from an imported Excel/CSV file and store them into the `grades`
 * table so students can see their final average and Passed/Failed status.
 *
 * The source file is left untouched so it can still be edited in Excel. Only the
 * columns that actually exist in the sheet are written, so manual data in other
 * columns is preserved.
 *
 * @return array{updated:int, skipped:int, unmatched:array<int,string>, error:?string}
 */
function importComputeGrades($pdo, $filePath, $originalName, $subjectId, $teacherId) {
    $result = ['updated' => 0, 'skipped' => 0, 'unmatched' => [], 'error' => null];

    $subjectId = (int)$subjectId;
    $teacherId = (int)$teacherId;

    if ($subjectId <= 0) {
        $result['error'] = 'No subject selected. Select a subject and import again to compute grades.';
        return $result;
    }
    if (!is_file($filePath)) {
        $result['error'] = 'Imported file not found on disk. Re-import the file and try again.';
        return $result;
    }

    $rows = readImportRows($filePath, $originalName ?: $filePath);
    if (isset($rows['error'])) {
        $result['error'] = (string)$rows['error'];
        return $result;
    }
    if (empty($rows) || !is_array($rows)) {
        $result['error'] = 'No data rows were found in the file.';
        return $result;
    }

    $periods    = ['prelim', 'midterm', 'prefinal', 'final'];
    $components = ['quiz', 'cs', 'attendance', 'laboratory', 'online', 'exam'];
    $scoreCols  = [];
    foreach ($periods as $p) {
        $scoreCols[] = $p;
        foreach ($components as $c) { $scoreCols[] = $p . '_' . $c; }
    }

    // Classify the sheet's columns so the fallback average can use whatever
    // grading layout the teacher's file happens to use.
    $allKeys    = is_array($rows[0]) ? array_keys($rows[0]) : [];
    $periodKeys = [];
    $gradeKeys  = [];
    foreach ($allKeys as $k) {
        $nk = normalizeImportHeader($k);
        if (in_array($nk, $periods, true)) {
            $periodKeys[] = $k;
        } elseif (isGradeLikeImportKey($nk)) {
            $gradeKeys[] = $k;
        }
    }

    // Only write columns that exist in the sheet (avoids wiping manually entered data).
    $present = [];
    if (is_array($rows[0])) {
        foreach ($scoreCols as $c) {
            if (array_key_exists($c, $rows[0])) { $present[] = $c; }
        }
    }

    $insertCols = ['student_id', 'subject_id', 'teacher_id'];
    $updateCols = [];
    foreach ($present as $c) { $insertCols[] = $c; $updateCols[] = $c; }
    $insertCols[] = 'grade';   $updateCols[] = 'grade';
    $insertCols[] = 'remarks'; $updateCols[] = 'remarks';

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $colList = implode(', ', $insertCols);
    $setList = [];
    foreach ($updateCols as $c) { $setList[] = $c . ' = VALUES(' . $c . ')'; }
    $setList[] = 'graded_at = CURRENT_TIMESTAMP';

    $sql = 'INSERT INTO grades (' . $colList . ') VALUES (' . $placeholders . ')'
         . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $setList);

    try {
        $upsert = $pdo->prepare($sql);
    } catch (Throwable $e) {
        $result['error'] = 'Database error preparing grade save: ' . $e->getMessage();
        return $result;
    }

    foreach ($rows as $idx => $row) {
        $student = resolveImportStudent($pdo, $row);
        if (!$student) {
            $result['skipped']++;
            $label = getImportValue($row, ['student_id', 'username', 'email', 'name']);
            if ($label === null) {
                $f = getImportValue($row, ['first_name']);
                $l = getImportValue($row, ['last_name']);
                $label = trim(($f ?? '') . ' ' . ($l ?? ''));
            }
            $result['unmatched'][] = ($label !== null && $label !== '')
                ? $label
                : ('Row ' . ($idx + 2));
            continue;
        }

        // 1) Explicit overall grade column (aliases: overall, gwa, average, final_sem, ...).
        $finalGrade = null;
        $overallRaw = getImportValue($row, ['grade']);
        if ($overallRaw !== null && is_numeric($overallRaw)) {
            $finalGrade = (float)$overallRaw;
        }

        // 1b) Explicit overall/weighted column that is not caught by the 'grade'
        // aliases (e.g. "Weighted Average", "Weighted Avg", "Overall Rating").
        // Prefer it over averaging the period totals so the stored grade matches
        // the overall value shown in the teacher's sheet.
        if ($finalGrade === null) {
            foreach ($gradeKeys as $k) {
                $nk = normalizeImportHeader($k);
                if (in_array($nk, $periods, true)) { continue; }
                if (preg_match('/(overall|average|avg|gwa|weighted|rating|final|sem)/', $nk)) {
                    $v = $row[$k] ?? null;
                    if ($v !== null && is_numeric(trim((string)$v))) {
                        $finalGrade = (float)$v;
                        break;
                    }
                }
            }
        }

        // 2) Otherwise average the available period totals (prelim/midterm/prefinal/final).
        if ($finalGrade === null && !empty($periodKeys)) {
            $vals = [];
            foreach ($periodKeys as $k) {
                if (array_key_exists($k, $row) && is_numeric(trim((string)$row[$k]))) { $vals[] = (float)$row[$k]; }
            }
            if (!empty($vals)) { $finalGrade = array_sum($vals) / count($vals); }
        }

        // 3) Otherwise average any other grade-like numeric columns.
        if ($finalGrade === null && !empty($gradeKeys)) {
            $vals = [];
            foreach ($gradeKeys as $k) {
                if (array_key_exists($k, $row) && is_numeric(trim((string)$row[$k]))) { $vals[] = (float)$row[$k]; }
            }
            if (!empty($vals)) { $finalGrade = array_sum($vals) / count($vals); }
        }

        // 4) Otherwise convert a letter grade / grade point column via transmutation.
        if ($finalGrade === null) {
            $lg = getImportValue($row, ['letter_grade', 'grade_point', 'gp', 'rating']);
            if ($lg !== null && is_numeric($lg)) {
                $pointMap = ['1.00' => 96, '1.25' => 91, '1.50' => 86, '1.75' => 81, '2.00' => 75, '2.25' => 69, '2.50' => 63, '2.75' => 57, '3.00' => 50, '5.00' => 40];
                $norm = number_format((float)$lg, 2, '.', '');
                if (isset($pointMap[$norm])) { $finalGrade = (float)$pointMap[$norm]; }
            }
        }

        if ($finalGrade === null) {
            $result['skipped']++;
            continue;
        }

        $finalGrade = round(max(0, min(100, $finalGrade)), 2);
        $remarksRaw = getImportValue($row, ['remarks', 'status', 'result', 'standing']);
        $remarks = ($remarksRaw !== null && trim($remarksRaw) !== '')
            ? trim($remarksRaw)
            : ($finalGrade >= 75 ? 'Passed' : ($finalGrade > 0 ? 'Failed' : 'IP'));

        $bind = [$student['id'], $subjectId, $teacherId];
        foreach ($present as $c) {
            $raw = $row[$c] ?? '';
            $bind[] = is_numeric(trim((string)$raw)) ? (float)$raw : null;
        }
        $bind[] = $finalGrade;
        $bind[] = $remarks;

        try {
            $upsert->execute($bind);
            $result['updated']++;
        } catch (Throwable $e) {
            $result['skipped']++;
        }
    }

    return $result;
}
