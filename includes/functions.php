<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Start Output Buffering immediately to ensure security scripts are injected
// REMOVED: ob_start("minifyHTML"); 
// Minification is causing JavaScript syntax errors by collapsing lines with // comments.

/** * DATABASE MAINTENANCE */
function reorderIDs($pdo, $table) {
    // Resets the sequence of IDs to be gapless (1, 2, 3...)
    // This relies on ON UPDATE CASCADE being set in the database
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
    // Completely clear all output buffers to ensure the minifyHTML callback 
    // does not append whitespace padding to the redirect response.
    while (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: $url"); 
    exit(); 
}
function hasRole($role) { return isset($_SESSION['role']) && $_SESSION['role'] === $role; }
function requireRole($role) { if (!isLoggedIn() || !hasRole($role)) { redirect('index.php'); } }
function getSystemSetting($pdo, $key, $default = '') {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: $default;
    } catch (Exception $e) { return $default; }
}
function logActivity($pdo, $user_id, $role, $action, $description = null) {
    $stmt = $pdo->prepare("INSERT INTO login_logs (user_id, role, action, description, timestamp) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$user_id, $role, $action, $description]);
}

function getGradeWeights($pdo, $teacher_id = null) {
    $keys = ['weight_lec', 'weight_lab', 'weight_lec_quiz', 'weight_lec_online', 'weight_lec_exam', 'weight_lab_att', 'weight_lab_cs', 'weight_lab_exam'];
    $weights = [];
    foreach ($keys as $key) {
        $val = null;
        if ($teacher_id) {
            $stmt = $pdo->prepare("SELECT setting_value FROM teacher_settings WHERE teacher_id = ? AND setting_key = ?");
            $stmt->execute([$teacher_id, $key]);
            $val = $stmt->fetchColumn();
        }
        if ($val === null || $val === false) {
            $val = getSystemSetting($pdo, $key, 0);
        }
        $weights[str_replace('weight_', '', $key)] = (float)$val / 100;
    }
    return $weights;
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
    if ($grade >= 90) return '1.0';
    if ($grade >= 80) return '2.0';
    if ($grade >= 70) return '3.0';
    if ($grade >= 60) return '4.0';
    return 'INC';
}

function getGradeColor($grade) {
    if ($grade >= 90) return 'success';
    if ($grade >= 80) return 'info';
    if ($grade >= 70) return 'warning';
    if ($grade < 60) return 'dark';
    return 'danger';
}

/** * DELETE & MANAGEMENT FUNCTIONS */

function deleteStudent($pdo, $student_id) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET status = 'archived' WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        return true;
    } catch (Exception $e) {
        return "Error deleting student: " . $e->getMessage();
    }
}

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