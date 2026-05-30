<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('admin');

// Handle AJAX Search Suggestions
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT first_name, last_name, username, role FROM users WHERE status = 'active' AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ?) LIMIT 5");
    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Handle AJAX Performance Monitoring
if (isset($_GET['ajax_performance'])) {
    header('Content-Type: application/json');
    $student_id = $_GET['ajax_performance'];
    $stmt = $pdo->prepare("SELECT g.*, s.name as subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ?");
    $stmt->execute([$student_id]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $period_sums = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
    $period_counts = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
    $datasets = [];
    $distribution = ['1.0' => 0, '2.0' => 0, '3.0' => 0, '4.0' => 0, 'INC' => 0];
    $avg_sum = 0;
    foreach ($grades as $g) {
        $avg_sum += $g['grade'];
        foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p) {
            if (isset($g[$p]) && $g[$p] > 0) {
                $period_sums[$p] += $g[$p];
                $period_counts[$p]++;
            }
        }
        $letter = calculateGradePoint($g['grade']);
        if(isset($distribution[$letter])) $distribution[$letter]++;
        $hue = abs(crc32($g['subject_name'])) % 360;
        $datasets[] = [
            'label' => $g['subject_name'],
            'data' => [(float)($g['prelim']??0), (float)($g['midterm']??0), (float)($g['prefinal']??0), (float)($g['final']??0)],
            'borderColor' => "hsl($hue, 70%, 45%)",
            'tension' => 0.4
        ];
    }
    $overall_avg = count($grades) > 0 ? $avg_sum / count($grades) : 0;
    $periodic_avgs = [];
    foreach($period_sums as $p => $sum) {
        if ($period_counts[$p] > 0) {
            $avg = $sum / $period_counts[$p];
            $periodic_avgs[$p] = number_format($avg, 1) . ' (' . calculateGradePoint($avg) . ')';
        } else { $periodic_avgs[$p] = '0.0'; }
    }

    echo json_encode([
        'average' => number_format($overall_avg, 1),
        'letter' => calculateGradePoint($overall_avg),
        'total' => count($grades),
        'distribution' => $distribution,
        'datasets' => $datasets,
        'periodic' => $periodic_avgs
    ]);
    exit;
}

$message = '';
$message_type = '';

// Determine current active tab from query string or default to dashboard
$active_tab = $_GET['tab'] ?? 'dashboard';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $plain_password = $_POST['password']; // Get plain password for validation
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];

        if (strlen($plain_password) < 8) { // Add password length validation
            $message = "Password must be at least 8 characters long!";
            $message_type = "danger";
        } else {
            $password = password_hash($plain_password, PASSWORD_DEFAULT); // Hash only after validation

            // Check if username or email already exists
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check_stmt->execute([$username, $email]);

            if ($check_stmt->rowCount() > 0) {
                $message = "Username or Email already exists!";
                $message_type = "danger";
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $password, $email, $role, $first_name, $last_name]);
                $new_user_id = $pdo->lastInsertId();
                $message = "Success: User '{$username}' has been successfully created with the role of " . ucfirst($role) . ".";
                $message_type = "success";
                $_SESSION['message'] = $message;
                $_SESSION['message_type'] = $message_type;
                $_SESSION['updated_user_id'] = $new_user_id;
                redirect("admin.php?tab=users");
            }
        }
    }

    if (isset($_POST['add_subject'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];

        // Check if subject name already exists
        $check_name = $pdo->prepare("SELECT id FROM subjects WHERE name = ?");
        $check_name->execute([$name]);

        if ($check_name->rowCount() > 0) {
            $message = "Error: Subject name already exists!";
            $message_type = "danger";
        } else {
            // Auto-generate Subject Code: UGRD- followed by 7 random digits
            do {
                $code = 'UGRD-' . str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);
                $check_stmt = $pdo->prepare("SELECT id FROM subjects WHERE code = ?");
                $check_stmt->execute([$code]);
            } while ($check_stmt->rowCount() > 0);

            $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, teacher_id) VALUES (?, ?, ?, NULL)");
            $stmt->execute([$name, $code, $description]);
            $_SESSION['new_subject_id'] = $pdo->lastInsertId();
            $message = "Success: The subject '{$name}' has been added. System assigned code: {$code}.";
            $message_type = "success";
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $message_type;
        redirect("admin.php?tab=subjects");
    }

    if (isset($_POST['update_logo'])) {
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] == 0) {
            $target_dir = "image/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $file_ext = pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION);
            $target_file = $target_dir . "system_logo_" . time() . "." . $file_ext;
            
            if (move_uploaded_file($_FILES["logo_file"]["tmp_name"], $target_file)) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$target_file, $target_file]);
                $_SESSION['message'] = "Logo updated successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error uploading logo.";
                $_SESSION['message_type'] = "danger";
            }
        }
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['update_weights'])) {
        $keys = ['weight_lec', 'weight_lab', 'weight_lec_quiz', 'weight_lec_online', 'weight_lec_exam', 'weight_lab_att', 'weight_lab_cs', 'weight_lab_exam'];
        foreach($keys as $key) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $_POST[$key], $_POST[$key]]);
        }
        $_SESSION['message'] = "Global grading policy updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['update_user'])) {
        $user_id = $_POST['user_id'];
        $username = trim($_POST['username']);
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $course = $_POST['course'] ?? '';
        $year_level = $_POST['year_level'] ?? '';
        $phone = $_POST['phone_number'] ?? '';

        // Check if username already exists (excluding current user)
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check_stmt->execute([$username, $email, $user_id]);

        if ($check_stmt->rowCount() > 0) {
            $_SESSION['message'] = "Error: Username or Email already exists!";
            $_SESSION['message_type'] = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, email = ?, role = ?, course = ?, year_level = ?, phone_number = ? WHERE id = ?");
            $stmt->execute([$username, $first_name, $last_name, $email, $role, $course, $year_level, $phone, $user_id]);
            
            $_SESSION['message'] = "Updated: Profile for user '{$username}' has been successfully modified.";
            $_SESSION['message_type'] = "success";
            $_SESSION['updated_user_id'] = $user_id;
        }

        // Maintain search context after modification
        $search_query = $_POST['search_context'] ?? ($_GET['search_user'] ?? '');
        $redirect_url = "admin.php?tab=users";
        if ($search_query) {
            $redirect_url .= "&search_user=" . urlencode($search_query);
        }
        redirect($redirect_url);
    }

    if (isset($_POST['update_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        try {
            if ($new_password !== $confirm_password) throw new Exception("Passwords do not match!");
            if (strlen($new_password) < 8) throw new Exception("Password must be at least 8 characters long!");
            
            // Verify user existence before updating to ensure integrity
            $check_user = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $check_user->execute([$user_id]);
            if (!$check_user->fetch()) throw new Exception("Error: User record not found.");

            // Only update the password to prevent accidental username corruption
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if (!$stmt->execute([$hashed_password, $user_id])) {
                throw new Exception("Database Error: Failed to synchronize new credentials.");
            }

            // Fetch username for the success message
            $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $u_stmt->execute([$user_id]);
            $current_username = $u_stmt->fetchColumn();

            $_SESSION['message'] = "Success: The password for '{$current_username}' has been successfully changed.";
            $_SESSION['message_type'] = "success";
            $_SESSION['updated_user_id'] = $user_id;

            $search_query = $_POST['search_context'] ?? ($_GET['search_user'] ?? '');
            $redirect_url = "admin.php?tab=users";
            if ($search_query) $redirect_url .= "&search_user=" . urlencode($search_query);
            redirect($redirect_url);
        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = "danger";
            redirect("admin.php?tab=users");
        }
    }

    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $name = $_POST['name'];
        $code = $_POST['code'];
        $description = $_POST['description'];

        // Check if subject code or name already exists (excluding current subject)
        $check_stmt = $pdo->prepare("SELECT id FROM subjects WHERE (code = ? OR name = ?) AND id != ?");
        $check_stmt->execute([$code, $name, $subject_id]);

        if ($check_stmt->rowCount() > 0) {
            $message = "Error: Subject name or code already exists!";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $code, $description, $subject_id]);
            $message = "Subject Modified: Details for '{$name}' ({$code}) have been saved.";
            $message_type = "success";
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=subjects");
        }
    }
}

// Handle session messages after redirect
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Handle delete actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    $search_user = $_GET['search_user'] ?? '';

    switch ($action) {
        case 'archive_user':
            // Security Check: Prevent archiving the last remaining active administrator
            $check_role = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $check_role->execute([$id]);
            $target_role = $check_role->fetchColumn();

            if ($target_role === 'admin') {
                $active_admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
                if ($active_admin_count <= 1) {
                    $_SESSION['message'] = "Security Restriction: You cannot archive the last remaining active administrator account.";
                    $_SESSION['message_type'] = "danger";
                    redirect("admin.php?tab=users");
                }
            }

            $result = archiveUser($pdo, $id);
            if ($result === true) {
                $message = "User Account Archived: The user has been moved to the backup archive.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;

            $search_query = $_GET['search_user'] ?? '';
            $redirect_url = "admin.php?tab=users";
            if ($search_query) $redirect_url .= "&search_user=" . urlencode($search_query);
            redirect($redirect_url);
            break;

        case 'restore_user':
            $result = restoreUser($pdo, $id);
            if ($result === true) {
                $message = "Account Restored: The user is now active again.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=archive");
            break;

        case 'delete_user':
            $result = deleteUser($pdo, $id);
            if ($result === true) {
                $message = "User Purged: Account and data have been permanently removed.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=archive");
            break;

        case 'delete_student':
            // This now archives via the updated function in functions.php
            $result = deleteStudent($pdo, $id);
            if ($result === true) {
                $message = "Student Archived: Grade records are preserved in the backup archive.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;

            $search_query = $_GET['search_user'] ?? '';
            $redirect_url = "admin.php?tab=users";
            if ($search_query) $redirect_url .= "&search_user=" . urlencode($search_query);
            redirect($redirect_url);
            break;

        case 'delete_subject':
            $result = deleteSubject($pdo, $id);
            if ($result === true) {
                $message = "Subject Removed: The subject and related grades have been deleted.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=subjects");
            break;
    }
}

// --- 1. STATISTICS ---
// Count Staff (Admins and Teachers)
$total_teachers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active'")->fetchColumn();
$total_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
// Count Students from the separate table
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();
$total_subjects = $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$total_archived = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'archived'")->fetchColumn();

// --- 2. DATA LISTS FOR TABLES AND SIDEBARS ---
// Fetch Staff with Search
$search_user = $_GET['search_user'] ?? '';
$users_params = ['active'];
$search_sql = "";
if ($search_user) {
    $search_sql = "AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ?) ";
    array_push($users_params, "%$search_user%", "%$search_user%", "%$search_user%", "%$search_user%");
}

// Fetch Admins & Teachers
$staff_users_stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? AND role IN ('admin', 'teacher') $search_sql ORDER BY role, first_name ASC");
$staff_users_stmt->execute($users_params);
$staff_users = $staff_users_stmt->fetchAll();
// Fetch Students (Filtered by Search)
$students_stmt = $pdo->prepare("SELECT u.*, GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as enrolled_subjects 
    FROM users u 
    LEFT JOIN grades g ON u.id = g.student_id 
    LEFT JOIN subjects s ON g.subject_id = s.id 
    WHERE u.status = ? AND u.role = 'student' $search_sql 
    GROUP BY u.id
    ORDER BY u.year_level DESC, u.course ASC, u.last_name ASC");
$students_stmt->execute($users_params);
$all_students = $students_stmt->fetchAll();

// Fetch Archived Users
$archive_params = ['archived'];
if ($search_user) array_push($archive_params, "%$search_user%", "%$search_user%", "%$search_user%", "%$search_user%");
$archived_users_stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? $search_sql ORDER BY role, last_name ASC");
$archived_users_stmt->execute($archive_params);
$archived_users = $archived_users_stmt->fetchAll();

// Fetch Subjects (This is what your sidebar needs)
$all_subjects = $pdo->query("SELECT * FROM subjects ORDER BY name ASC")->fetchAll();

// Fetch Grade Weights for labels
$weights = getGradeWeights($pdo);

// Fetch Recent Users for Dashboard
$recent_admins = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();
$recent_teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();
$recent_students = $pdo->query("SELECT * FROM users WHERE role = 'student' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();

// Assign variables for use in templates
$users = $staff_users;
$subjects = $all_subjects;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-bg: #0f172a;
            --accent-color: #6366f1;
        }
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }

        .sidebar {
            background: var(--sidebar-bg);
            color: white;
            min-height: 100vh;
            padding: 0;
            width: var(--sidebar-width);
            position: fixed;
            left: 0;
            top: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
        }
        .sidebar.collapsed {
            left: calc(-1 * var(--sidebar-width));
        }
        .main-content {
            margin-left: var(--sidebar-width);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: calc(100% - var(--sidebar-width));
        }
        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }
        .sidebar .nav-link {
            color: white;
            padding: 1rem 1.5rem;
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
            opacity: 0.85;
            margin: 4px 12px;
            border-radius: 8px;
        }

        .sidebar .nav-link:hover:not(.active) {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
            opacity: 1;
        }

        .sidebar .nav-link.active {
            background: var(--accent-color);
            border-left-color: #fff;
            font-weight: bold;
            opacity: 1;
        }

        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .grad-adm-1 { background: #4f46e5; }
        .grad-adm-2 { background: #e11d48; }
        .grad-adm-3 { background: #f59e0b; }
        .grad-adm-4 { background: #0891b2; }

        .toggle-btn {
            cursor: pointer;
            font-size: 1.5rem;
            margin-right: 15px;
        }
        .sidebar-overlay {
            cursor: pointer;
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            transition: opacity 0.4s ease;
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        @media (max-width: 992px) {
            .sidebar { left: calc(-1 * var(--sidebar-width)); }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; width: 100%; }
            .sidebar-overlay.active { display: block; }
        }
        .password-toggle {
            cursor: pointer;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: #667eea !important;
        }

        .toast-container { z-index: 1060; }
        /* Live Search Styles */
        #searchResultsDropdown {
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1050;
            max-height: 300px;
            overflow-y: auto;
            transition: opacity 0.3s ease, transform 0.3s ease;
            opacity: 1;
        }
        #searchResultsDropdown .list-group-item {
            border-radius: 0;
            transition: background 0.2s;
        }

        /* Enhanced Table Responsiveness */
        .table thead th, .table tbody td { white-space: nowrap; }

        /* Navbar fixes for text compression */
        .navbar { flex-wrap: nowrap; min-height: 64px; }
        .navbar-brand { 
            white-space: nowrap; 
            font-weight: 700;
            letter-spacing: -0.025em;
            font-size: 1.1rem !important;
        }

        @media (max-width: 576px) {
            .navbar-brand { font-size: 1rem !important; }
            .stat-card h4 { font-size: 1.25rem; }
            .px-4 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .card-body { padding: 1rem; }
        }

        @media (max-width: 360px) {
            .navbar-brand { font-size: 0.85rem !important; }
            .sidebar-overlay.active { display: block; }
            .btn-sm { padding: 0.25rem 0.4rem; font-size: 0.75rem; }
            .table { font-size: 0.8rem; }
        }

        /* Glow Highlight for newly added rows */
        .row-glow-highlight {
            animation: tableRowGlow 4s ease-out;
        }
        @keyframes tableRowGlow {
            0% { background-color: rgba(99, 102, 241, 0.3); }
            100% { background-color: transparent; }
        }
    </style>
</head>
 
<body>
    <!-- Toast Notification Container -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="msgToast" class="toast fade align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="d-flex flex-column p-3">
                    <div class="text-center mb-4">
                        <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 80px; height: 80px; margin-bottom: 10px; border-radius: 50%; object-fit: cover;">
                        <h5>Grade Management</h5>
                        <small>Administrator Panel</small>
                    </div>

                    <ul class="nav nav-pills flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" href="admin.php?tab=dashboard">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'users' ? 'active' : ''; ?>" href="admin.php?tab=users">
                                <i class="fas fa-users me-2"></i>Manage Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'subjects' ? 'active' : ''; ?>" href="admin.php?tab=subjects">
                                <i class="fas fa-book me-2"></i>Subjects
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'archive' ? 'active' : ''; ?>" href="admin.php?tab=archive">
                                <i class="fas fa-archive me-2"></i>Archive <span class="badge bg-light text-dark ms-2"><?php echo $total_archived; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" href="admin.php?tab=settings">
                                <i class="fas fa-cog me-2"></i>Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="main-content" id="main-content">
                <nav class="navbar navbar-light bg-light px-4 border-bottom d-lg-none">
                    <i class="fas fa-bars toggle-btn" id="sidebarToggle"></i>
                    <span class="navbar-brand mb-0 h1">Admin Panel</span>
                </nav>
                <div class="px-4 py-4">
                <div class="tab-content">
                    <!-- Dashboard Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2>Admin Dashboard</h2>
                            <span>Welcome, <?php echo $_SESSION['first_name']; ?>!</span>
                        </div>

                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3">
                                <div class="card stat-card grad-adm-1 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $total_students; ?></h4>
                                                <p>Total Students</p>
                                            </div>
                                            <i class="fas fa-user-graduate fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4 mb-3">
                                <div class="card stat-card grad-adm-4 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $total_teachers; ?></h4>
                                                <p>Total Teachers</p>
                                            </div>
                                            <i class="fas fa-chalkboard-teacher fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-4 mb-3">
                                <div class="card stat-card grad-adm-2 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $total_subjects; ?></h4>
                                                <p>Subjects</p>
                                            </div>
                                            <i class="fas fa-book fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Recent Admins</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Role</th>
                                                        <th>Username</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($recent_admins)): ?>
                                                        <tr><td colspan="2" class="text-muted">No recent admins.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($recent_admins as $user): ?>
                                                            <tr>
                                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                                <td><span class="badge bg-danger">Admin</span></td>
                                                                <td><?php echo $user['username']; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Recent Teachers</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Role</th>
                                                        <th>Username</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($recent_teachers)): ?>
                                                        <tr><td colspan="2" class="text-muted">No recent teachers.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($recent_teachers as $user): ?>
                                                            <tr>
                                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                                <td><span class="badge bg-success">Teacher</span></td>
                                                                <td><?php echo $user['username']; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Recent Students</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Role</th>
                                                        <th>Username</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($recent_students)): ?>
                                                        <tr><td colspan="2" class="text-muted">No recent students.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($recent_students as $user): ?>
                                                            <tr>
                                                                <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                                <td><span class="badge bg-primary">Student</span></td>
                                                                <td><?php echo $user['username']; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'users' ? 'show active' : ''; ?>" id="users">
                        <h2 class="mb-4">Manage Users</h2>

                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" action="admin.php" class="d-flex gap-2 position-relative">
                                    <input type="hidden" name="tab" value="users">
                                    <div class="flex-grow-1 position-relative">
                                        <input type="text" name="search_user" id="liveSearchInput" class="form-control" placeholder="Search by name, username, or email..." value="<?php echo htmlspecialchars($search_user); ?>" autocomplete="off">
                                        <div id="searchResultsDropdown" class="list-group position-absolute shadow-lg d-none"></div>
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                    <?php if($search_user): ?><a href="admin.php?tab=users" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Add New User</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="addUserForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">First Name</label>
                                            <input type="text" class="form-control mb-1" name="first_name" id="val_fname"
                                                placeholder="First Name" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                                            <div class="invalid-feedback mb-3">Please enter a valid first name</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Last Name</label>
                                            <input type="text" class="form-control mb-1" name="last_name" id="val_lname"
                                                placeholder="Last Name" required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                                            <div class="invalid-feedback mb-3">Please enter a valid last name</div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Username</label>
                                            <input type="text" class="form-control mb-1" name="username" id="val_user"
                                                placeholder="Username" required>
                                            <div class="invalid-feedback mb-3">Username is required</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Email</label>
                                            <input type="email" class="form-control mb-1" name="email" id="val_email"
                                                placeholder="Email" required>
                                            <div class="invalid-feedback mb-3">A valid email address is required</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Role</label>
                                            <select class="form-select mb-1" name="role" id="val_role">
                                                <option value="">Select Role</option>
                                                <option value="admin">Admin</option>
                                                <option value="teacher">Teacher</option>
                                                <option value="student">Student</option>
                                            </select>
                                            <div class="invalid-feedback mb-3">Please select a role for this user</div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold">Password</label>
                                            <div class="input-group mb-3">
                                                <input type="password" class="form-control" name="password"
                                                    id="new_password" placeholder="Password" required minlength="8">
                                                <span class="input-group-text password-toggle"
                                                    onclick="togglePassword('new_password', 'new_password_icon')">
                                                    <i class="fas fa-eye" id="new_password_icon"></i>
                                                </span>
                                            </div>
                                            <!-- Real-time Strength Indicator -->
                                            <div class="progress mt-1" style="height: 5px;">
                                                <div id="add-pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                            </div>
                                            <small id="add-pw-strength-text" class="text-muted" style="font-size: 0.7rem;"></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label d-none d-md-block">&nbsp;</label>
                                            <button type="submit" name="add_user" class="btn btn-primary w-100">Add User</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-user-shield me-2"></i>Administrators</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($staff_users as $user):
                                                if ($user['role'] == 'admin'): ?>
                                                    <tr id="user-row-<?php echo $user['id']; ?>">
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                        <td><?php echo $user['username']; ?></td>
                                                        <td><?php echo $user['email']; ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary"
                                                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>"
                                                                data-firstname="<?php echo $user['first_name']; ?>"
                                                                data-lastname="<?php echo $user['last_name']; ?>"
                                                                data-email="<?php echo $user['email']; ?>"
                                                                data-role="<?php echo $user['role']; ?>"><i
                                                                    class="fas fa-edit"></i></button>
                                                            <button class="btn btn-sm btn-outline-warning" 
                                                                data-bs-toggle="modal" data-bs-target="#changePasswordModal"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>">
                                                                <i class="fas fa-key"></i>
                                                            </button>
                                                            <a href="?action=archive_user&id=<?php echo $user['id']; ?>&search_user=<?php echo urlencode($search_user); ?>"
                                                                class="btn btn-sm btn-outline-secondary"
                                                                onclick="return confirm('Archive this admin?')"><i
                                                                    class="fas fa-archive"></i></a>
                                                        </td>
                                                    </tr>
                                                <?php endif; endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Teachers</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Full Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($staff_users as $user):
                                                if ($user['role'] == 'teacher'): ?>
                                                    <tr id="user-row-<?php echo $user['id']; ?>">
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></td>
                                                        <td><?php echo $user['username']; ?></td>
                                                        <td><?php echo $user['email']; ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary"
                                                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>"
                                                                data-firstname="<?php echo $user['first_name']; ?>"
                                                                data-lastname="<?php echo $user['last_name']; ?>"
                                                                data-email="<?php echo $user['email']; ?>"
                                                                data-role="<?php echo $user['role']; ?>"><i
                                                                    class="fas fa-edit"></i></button>
                                                            <button class="btn btn-sm btn-outline-warning" 
                                                                data-bs-toggle="modal" data-bs-target="#changePasswordModal"
                                                                data-id="<?php echo $user['id']; ?>"
                                                                data-username="<?php echo $user['username']; ?>">
                                                                <i class="fas fa-key"></i>
                                                            </button>
                                                            <a href="?action=archive_user&id=<?php echo $user['id']; ?>&search_user=<?php echo urlencode($search_user); ?>"
                                                                class="btn btn-sm btn-outline-secondary"
                                                                onclick="return confirm('Archive this teacher?')"><i
                                                                    class="fas fa-archive"></i></a>
                                                        </td>
                                                    </tr>
                                                <?php endif; endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Students</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Full Name</th>
                                                <th>Username</th>
                                                <th>Enrollment Status</th>
                                                <th>Course & Year</th>
                                                <th>Email</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($all_students as $student): ?>
                                                <tr id="user-row-<?php echo $student['id']; ?>">
                                                    <td><?php echo $i++; ?></td>
                                                    <td><?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                                    </td>
                                                    <td><?php echo $student['username']; ?></td>
                                                    <td>
                                                        <?php if ($student['enrolled_subjects']): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Enrolled</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge bg-light text-dark border"><?php echo $student['course'] . ' | ' . $student['year_level']; ?></span></td>
                                                    <td><?php echo $student['email']; ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-info" 
                                                            data-bs-toggle="modal" data-bs-target="#viewUserProfileModal"
                                                            data-all='<?php echo htmlspecialchars(json_encode($student), ENT_QUOTES, "UTF-8"); ?>'>
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-success" 
                                                            data-bs-toggle="modal" data-bs-target="#monitorPerformanceModal"
                                                            data-id="<?php echo $student['id']; ?>"
                                                            data-name="<?php echo $student['first_name'] . ' ' . $student['last_name']; ?>">
                                                            <i class="fas fa-chart-line"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                            data-bs-toggle="modal" data-bs-target="#editUserModal" 
                                                            data-id="<?php echo $student['id']; ?>" 
                                                            data-username="<?php echo $student['username']; ?>" 
                                                            data-firstname="<?php echo $student['first_name']; ?>" 
                                                            data-lastname="<?php echo $student['last_name']; ?>" 
                                                            data-email="<?php echo $student['email']; ?>" 
                                                            data-role="student">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                            data-bs-toggle="modal" data-bs-target="#changePasswordModal"
                                                            data-id="<?php echo $student['id']; ?>"
                                                            data-username="<?php echo $student['username']; ?>">
                                                            <i class="fas fa-key"></i>
                                                        </button>
                                                        <a href="?action=delete_student&id=<?php echo $student['id']; ?>&search_user=<?php echo urlencode($search_user); ?>"
                                                            class="btn btn-sm btn-outline-secondary"
                                                            onclick="return confirm('Archive this student record?')"><i
                                                                class="fas fa-archive"></i></a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Archive Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'archive' ? 'show active' : ''; ?>" id="archive">
                        <h2 class="mb-4">Backup & Archived Accounts</h2>
                        <div class="card mb-4 border-0 shadow-sm">
                            <div class="card-body">
                                <form method="GET" action="admin.php" class="d-flex gap-2">
                                    <input type="hidden" name="tab" value="archive">
                                    <input type="text" name="search_user" class="form-control" placeholder="Search archive..." value="<?php echo htmlspecialchars($search_user); ?>">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0">Inactive Records (Backup Storage)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Role</th>
                                                <th>Full Name</th>
                                                <th>Username</th>
                                                <th>Email</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($archived_users)): ?>
                                                <tr><td colspan="5" class="text-center py-5 text-muted">The archive is currently empty.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($archived_users as $au): ?>
                                                    <tr>
                                                        <td><span class="badge bg-light text-dark border"><?php echo ucfirst($au['role']); ?></span></td>
                                                        <td><strong><?php echo $au['first_name'] . ' ' . $au['last_name']; ?></strong></td>
                                                        <td><?php echo $au['username']; ?></td>
                                                        <td><?php echo $au['email']; ?></td>
                                                        <td>
                                                            <a href="?action=restore_user&id=<?php echo $au['id']; ?>&search_user=<?php echo urlencode($search_user); ?>" class="btn btn-sm btn-outline-success">
                                                                <i class="fas fa-undo me-1"></i> Restore
                                                            </a>
                                                            <a href="?action=delete_user&id=<?php echo $au['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('PERMANENTLY DELETE this user and all associated data? This cannot be undone.')">
                                                                <i class="fas fa-trash-alt me-1"></i> Purge
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                        <h2 class="mb-4">System Settings</h2>
                        <div class="card mb-4 border-0 shadow-sm">
                            <div class="card-header bg-white fw-bold">Configuration & Branding</div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" class="row align-items-end g-3">
                                    <div class="col-md-3 text-center">
                                        <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" class="rounded border p-1" style="width: 100px; height: 100px; object-fit: cover;">
                                        <div class="small text-muted mt-2">Current Logo</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Upload New System Logo</label>
                                        <input type="file" name="logo_file" class="form-control" accept="image/*" required>
                                        <div class="form-text">This logo will be displayed across all portal dashboards and login pages.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" name="update_logo" class="btn btn-dark w-100">
                                            <i class="fas fa-upload me-2"></i>Update Branding
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card mb-4 border-0 shadow-sm">
                            <div class="card-header bg-white fw-bold">Institutional Grading Policy</div>
                            <div class="card-body">
                                <div class="row text-center mb-4">
                                    <div class="col-md-6 border-end">
                                        <h6 class="fw-bold text-primary">LECTURE (40%)</h6>
                                        <p class="small mb-1">Quizzes: 40% | CS: 10% | Major Exam: 50%</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold text-success">LABORATORY (60%)</h6>
                                        <p class="small mb-1">Activities: 40% | CS: 10% | Actual Exam: 50%</p>
                                    </div>
                                </div>
                                <div class="alert alert-light border small text-center">
                                    <strong>Semestral Distribution:</strong> 
                                    Prelim (20%) + Midterm (20%) + Prefinal (20%) + Final (40%)
                                </div>
                                <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i> Grading weights are now fixed to institutional standards.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Subjects Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'subjects' ? 'show active' : ''; ?>" id="subjects">
                        <h2 class="mb-4">Manage Subjects</h2>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <a href="print_grade.php?all_records=true" target="_blank" class="btn btn-primary shadow-sm"><i class="fas fa-print me-2"></i> Print Master Grade Report</a>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>Add New Subject</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <input type="text" class="form-control mb-3" name="name"
                                                placeholder="Subject Name" required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control mb-3" name="description"
                                                placeholder="Description">
                                        </div>
                                    </div>
                                    <button type="submit" name="add_subject" class="btn btn-primary">Add
                                        Subject</button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h5>All Subjects</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>Description</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($subjects as $subject): ?>
                                                <tr id="subject-row-<?php echo $subject['id']; ?>">
                                                    <td><?php echo $i++; ?></td>
                                                    <td><?php echo $subject['name']; ?></td>
                                                    <td><?php echo $subject['code']; ?></td>
                                                    <td><?php echo $subject['description']; ?></td>
                                                    <td>
                                                        <a href="print_grade.php?subject_id=<?php echo $subject['id']; ?>" target="_blank" class="btn btn-sm btn-outline-info me-1">
                                                            <i class="fas fa-print"></i> Report
                                                        </a>
                                                        <button class="btn btn-sm btn-outline-primary"
                                                            data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                            data-id="<?php echo $subject['id']; ?>"
                                                            data-name="<?php echo $subject['name']; ?>"
                                                            data-code="<?php echo $subject['code']; ?>"
                                                            data-description="<?php echo $subject['description']; ?>">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <a href="?action=delete_subject&id=<?php echo $subject['id']; ?>"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Are you sure you want to delete this subject? This will also delete all grades associated with it.')">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="search_context" value="<?php echo htmlspecialchars($search_user); ?>">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="edit_username" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name" id="edit_first_name" 
                                        required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name" id="edit_last_name" 
                                        required oninput="this.value = this.value.replace(/[0-9]/g, '').replace(/\b\w/g, c => c.toUpperCase())">
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role" id="edit_role" required>
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                        <div class="row" id="edit_student_fields">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Course</label>
                                <input type="text" class="form-control" name="course" id="edit_course">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Year Level</label>
                                <input type="text" class="form-control" name="year_level" id="edit_year_level">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone_number" id="edit_phone_number">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="changePasswordForm">
                    <div class="modal-body">
                        <input type="hidden" name="search_context" value="<?php echo htmlspecialchars($search_user); ?>">
                        <input type="hidden" name="user_id" id="password_user_id">
                        <div class="mb-4">
                            <label class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control bg-light fw-bold" name="username" id="password_username" readonly>
                            </div>
                            <div class="form-text">You are resetting the security credentials for this account.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password (Min 8 characters)</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="change_password_field"
                                    required>
                                <span class="input-group-text password-toggle"
                                    onclick="togglePassword('change_password_field', 'change_password_icon')">
                                    <i class="fas fa-eye" id="change_password_icon"></i>
                                </span>
                            </div>
                            <!-- Real-time Strength Indicator -->
                            <div class="progress mt-1" style="height: 5px;">
                                <div id="change-pw-strength-bar" class="progress-bar" role="progressbar" style="width: 0%"></div>
                            </div>
                            <small id="change-pw-strength-text" class="text-muted" style="font-size: 0.7rem;"></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="confirm_password"
                                    id="confirm_password_field" required>
                                <span class="input-group-text password-toggle"
                                    onclick="togglePassword('confirm_password_field', 'confirm_password_icon')">
                                    <i class="fas fa-eye" id="confirm_password_icon"></i>
                                </span>
                            </div>
                            <div class="invalid-feedback" id="change_cpass_feedback" style="display: none; color: #dc3545; font-size: 0.8rem; margin-top: 5px;">Passwords do not match</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_password" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="subject_id" id="edit_subject_id">
                        <div class="mb-3">
                            <label class="form-label">Subject Name</label>
                            <input type="text" class="form-control" name="name" id="edit_subject_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject Code</label>
                            <input type="text" class="form-control" name="code" id="edit_subject_code" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" id="edit_subject_description">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_subject" class="btn btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View User Full Profile Modal -->
    <div class="modal fade" id="viewUserProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Credentials & Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <h6 class="text-primary border-bottom pb-2">Account Credentials</h6>
                            <p><strong>Username:</strong> <span id="view_username"></span></p>
                            <p><strong>Email:</strong> <span id="view_email"></span></p>
                            <p><strong>Role:</strong> <span class="badge bg-secondary" id="view_role"></span></p>
                            
                            <h6 class="text-primary border-bottom pb-2 mt-4">Personal Information</h6>
                            <p><strong>Full Name:</strong> <span id="view_fullname"></span></p>
                            <p><strong>Age / Sex:</strong> <span id="view_agesex"></span></p>
                            <p><strong>Birth Date:</strong> <span id="view_bdate"></span></p>
                            <p><strong>Phone:</strong> <span id="view_phone"></span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary border-bottom pb-2">Academic Info</h6>
                            <p><strong>Course:</strong> <span id="view_course"></span></p>
                            <p><strong>Year Level:</strong> <span id="view_year"></span></p>
                            <p><strong>Address:</strong> <span id="view_address"></span></p>

                            <h6 class="text-primary border-bottom pb-2 mt-4">Guardian Details</h6>
                            <p><strong>Name:</strong> <span id="view_gname"></span></p>
                            <p><strong>Phone:</strong> <span id="view_gphone"></span></p>
                            <p><strong>Occupation:</strong> <span id="view_gocc"></span></p>
                            <p><strong>Address:</strong> <span id="view_gaddress"></span></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Monitor Student Performance Modal -->
    <div class="modal fade" id="monitorPerformanceModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-chart-area me-2"></i>Performance Analysis: <span id="perf_student_name"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Overall Average</h6>
                                <h2 class="display-5 fw-bold text-primary mb-0" id="perf_avg">0.0</h2>
                                <span class="badge bg-primary mt-2 mx-auto w-25" id="perf_letter">N/A</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Subjects Graded</h6>
                                <h2 class="display-5 fw-bold text-success mb-0" id="perf_total">0</h2>
                                <small class="text-muted mt-2">Active records</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Academic Standing</h6>
                                <h2 class="display-5 fw-bold text-dark mb-0" id="perf_standing">-</h2>
                                <small id="perf_status_text" class="mt-2 fw-bold"></small>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-4">
                        <div class="col-6 col-md-3"><div class="card text-center p-2 shadow-sm border-0"><small class="text-muted fw-bold">PRELIM AVG</small><h5 class="mb-0 text-primary" id="perf_prelim">0.0</h5></div></div>
                        <div class="col-6 col-md-3"><div class="card text-center p-2 shadow-sm border-0"><small class="text-muted fw-bold">MIDTERM AVG</small><h5 class="mb-0 text-primary" id="perf_midterm">0.0</h5></div></div>
                        <div class="col-6 col-md-3"><div class="card text-center p-2 shadow-sm border-0"><small class="text-muted fw-bold">PREFINAL AVG</small><h5 class="mb-0 text-primary" id="perf_prefinal">0.0</h5></div></div>
                        <div class="col-6 col-md-3"><div class="card text-center p-2 shadow-sm border-0"><small class="text-muted fw-bold">FINAL AVG</small><h5 class="mb-0 text-primary" id="perf_final">0.0</h5></div></div>
                    </div>
                    <div class="row">
                        <div class="col-lg-8 mb-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white fw-bold">Grade Trend by Subject</div>
                                <div class="card-body">
                                    <div style="height: 350px;"><canvas id="adminGradeChart"></canvas></div>
                                    <div id="adminGradeChartLegend" class="d-flex flex-wrap gap-3 justify-content-center mt-3"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 mb-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white fw-bold">Grade Distribution</div>
                                <div class="card-body">
                                    <div style="height: 350px;"><canvas id="adminDistChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Password toggle functionality defined globally for inline onclick handlers
        function togglePassword(passwordFieldId, iconId) {
            var passwordField = document.getElementById(passwordFieldId);
            var icon = document.getElementById(iconId);

            if (!passwordField || !icon) return;

            if (passwordField.type === "password") {
                passwordField.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
    <script>
        // Activate tab from URL hash
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');

            // Live Search Logic
            const liveSearchInput = document.getElementById('liveSearchInput');
            const searchResultsDropdown = document.getElementById('searchResultsDropdown');

            if (liveSearchInput) {
                liveSearchInput.addEventListener('input', function() {
                    const query = this.value.trim();
                    if (query.length < 2) {
                        searchResultsDropdown.classList.add('d-none');
                        return;
                    }

                    fetch(`admin.php?ajax_search=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResultsDropdown.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(user => {
                                    const item = document.createElement('a');
                                    item.href = '#';
                                    item.className = 'list-group-item list-group-item-action border-bottom';
                                    item.innerHTML = `
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0 small fw-bold">${user.first_name} ${user.last_name}</h6>
                                                <small class="text-muted">@${user.username}</small>
                                            </div>
                                            <span class="badge bg-light text-dark border small" style="font-size: 0.6rem;">${user.role}</span>
                                        </div>
                                    `;
                                    item.addEventListener('click', (e) => {
                                        e.preventDefault();
                                        liveSearchInput.value = user.username;
                                        searchResultsDropdown.classList.add('d-none');
                                        liveSearchInput.form.submit();
                                    });
                                    searchResultsDropdown.appendChild(item);
                                });
                                searchResultsDropdown.classList.remove('d-none');
                            } else {
                                searchResultsDropdown.classList.add('d-none');
                            }
                        });
                });

                document.addEventListener('click', function(e) {
                    if (!liveSearchInput.contains(e.target) && !searchResultsDropdown.contains(e.target)) {
                        searchResultsDropdown.classList.add('d-none');
                    }
                });
            }

            const mainContent = document.getElementById('main-content');
            const toggle = document.getElementById('sidebarToggle');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                sidebar.classList.toggle('active');
                if(overlay) overlay.classList.toggle('active');
                if(mainContent) mainContent.classList.toggle('expanded');
                window.dispatchEvent(new Event('resize'));
            }

            if(toggle) toggle.addEventListener('click', toggleSidebar);
            if(overlay) overlay.addEventListener('click', toggleSidebar);

            // Auto-close sidebar on mobile when a link is clicked
            const navLinks = sidebar.querySelectorAll('.nav-link:not(.dropdown-toggle)');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                        toggleSidebar();
                    }
                });
            });

            // Toast Initialization
            const toastEl = document.getElementById('msgToast');
            const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
            
            <?php 
            $display_msg = $message ?: ($_SESSION['message'] ?? '');
            $display_type = $message_type ?: ($_SESSION['message_type'] ?? 'info');
            if ($display_msg): ?>
                document.getElementById('toastMsg').innerText = "<?php echo addslashes($display_msg); ?>";
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
                toastEl.classList.add('bg-<?php echo $display_type === "danger" ? "danger" : ($display_type === "success" ? "success" : "info"); ?>');
                toast.show();
                <?php unset($_SESSION['message'], $_SESSION['message_type']); // Clear after showing ?>
            <?php endif; ?>

            // Highlight newly added subject
            <?php if (isset($_SESSION['new_subject_id'])): ?>
                const newRow = document.getElementById('subject-row-<?php echo $_SESSION['new_subject_id']; ?>');
                if (newRow) {
                    newRow.classList.add('row-glow-highlight');
                    newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                <?php unset($_SESSION['new_subject_id']); ?>
            <?php endif; ?>

            // Highlight newly added or updated user
            <?php if (isset($_SESSION['updated_user_id'])): ?>
                const userRow = document.getElementById('user-row-<?php echo $_SESSION['updated_user_id']; ?>');
                if (userRow) {
                    userRow.classList.add('row-glow-highlight');
                    userRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                <?php unset($_SESSION['updated_user_id']); ?>
            <?php endif; ?>

            var triggerTabList = [].slice.call(document.querySelectorAll('a[data-bs-toggle="tab"]'))
            triggerTabList.forEach(function (triggerEl) {
                var tabTrigger = new bootstrap.Tab(triggerEl)
                triggerEl.addEventListener('click', function (event) {
                    event.preventDefault()
                    tabTrigger.show()
                    // Force layout recalculation when switching tabs
                    setTimeout(() => window.dispatchEvent(new Event('resize')), 150);
                })
            });
            
            // Handle shown.bs.tab to ensure responsiveness after transition
            document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tab => {
                tab.addEventListener('shown.bs.tab', () => window.dispatchEvent(new Event('resize')));
            });

            // Edit User Modal
            var editUserModal = document.getElementById('editUserModal')
            editUserModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var userId = button.getAttribute('data-id')
                var username = button.getAttribute('data-username')
                var firstName = button.getAttribute('data-firstname')
                var lastName = button.getAttribute('data-lastname')
                var email = button.getAttribute('data-email')
                var role = button.getAttribute('data-role')
                var allData = button.closest('tr').querySelector('.btn-outline-info')?.getAttribute('data-all');
                var extra = allData ? JSON.parse(allData) : {};

                var modal = this
                modal.querySelector('#edit_user_id').value = userId
                modal.querySelector('#edit_username').value = username
                modal.querySelector('#edit_first_name').value = firstName
                modal.querySelector('#edit_last_name').value = lastName
                modal.querySelector('#edit_email').value = email
                modal.querySelector('#edit_role').value = role
                modal.querySelector('#edit_course').value = extra.course || ''
                modal.querySelector('#edit_year_level').value = extra.year_level || ''
                modal.querySelector('#edit_phone_number').value = extra.phone_number || ''
            })

            // Change Password Modal
            var changePasswordModal = document.getElementById('changePasswordModal')
            changePasswordModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var userId = button.getAttribute('data-id')
                var username = button.getAttribute('data-username')

                var modal = this
                modal.querySelector('#password_user_id').value = userId
                modal.querySelector('#password_username').value = username
                
                // Reset modal state on open
                modal.querySelector('#change_password_field').value = '';
                modal.querySelector('#confirm_password_field').value = '';
                modal.querySelector('#change-pw-strength-bar').style.width = '0%';
                modal.querySelector('#change-pw-strength-text').innerText = '';
                modal.querySelector('#change_cpass_feedback').style.display = 'none';
                modal.querySelector('#confirm_password_field').classList.remove('is-invalid');
            })

            // Change Password Real-time Validation (Registration Style)
            const changePassField = document.getElementById('change_password_field');
            const confirmPassField = document.getElementById('confirm_password_field');
            
            if(changePassField) {
                changePassField.addEventListener('input', function() {
                    const val = this.value;
                    const bar = document.getElementById('change-pw-strength-bar');
                    const text = document.getElementById('change-pw-strength-text');
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
                    if(confirmPassField.value !== "") validatePasswordMatch();
                });
            }

            if(confirmPassField) confirmPassField.addEventListener('input', validatePasswordMatch);

            function validatePasswordMatch() {
                if(confirmPassField.value !== changePassField.value) {
                    confirmPassField.classList.add('is-invalid');
                    document.getElementById('change_cpass_feedback').style.display = 'block';
                    return false;
                } else {
                    confirmPassField.classList.remove('is-invalid');
                    document.getElementById('change_cpass_feedback').style.display = 'none';
                    return true;
                }
            }

            const changePasswordForm = document.getElementById('changePasswordForm');
            if(changePasswordForm) {
                changePasswordForm.addEventListener('submit', function(e) {
                    if (changePassField.value.length < 8 || !validatePasswordMatch()) {
                        e.preventDefault();
                    }
                });
            }

            // Edit Subject Modal
            var editSubjectModal = document.getElementById('editSubjectModal')
            editSubjectModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var subjectId = button.getAttribute('data-id')
                var name = button.getAttribute('data-name')
                var code = button.getAttribute('data-code')
                var description = button.getAttribute('data-description')

                var modal = this
                modal.querySelector('#edit_subject_id').value = subjectId
                modal.querySelector('#edit_subject_name').value = name
                modal.querySelector('#edit_subject_code').value = code
                modal.querySelector('#edit_subject_description').value = description
            })

            // View User Profile Modal
            var viewUserModal = document.getElementById('viewUserProfileModal')
            viewUserModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget
                var data = JSON.parse(button.getAttribute('data-all'))
                
                this.querySelector('#view_username').innerText = data.username
                this.querySelector('#view_email').innerText = data.email || 'N/A'
                this.querySelector('#view_role').innerText = data.role.toUpperCase()
                this.querySelector('#view_fullname').innerText = data.first_name + ' ' + (data.suffix || '') + ' ' + data.last_name
                this.querySelector('#view_agesex').innerText = data.age + ' / ' + data.sex
                this.querySelector('#view_bdate').innerText = data.birth_date
                this.querySelector('#view_phone').innerText = data.phone_number
                this.querySelector('#view_course').innerText = data.course || 'N/A'
                this.querySelector('#view_year').innerText = data.year_level || 'N/A'
                this.querySelector('#view_address').innerText = (data.home_address || '') + ', ' + (data.city || '') + ', ' + (data.province || '')
                this.querySelector('#view_gname').innerText = data.guardian_name || 'N/A'
                this.querySelector('#view_gphone').innerText = data.guardian_phone || 'N/A'
                this.querySelector('#view_gocc').innerText = data.guardian_occupation || 'N/A'
                this.querySelector('#view_gaddress').innerText = data.guardian_address || 'N/A'
            })
            
            // Monitor Performance Modal Logic
            let adminGradeChart = null;
            let adminDistChart = null;
            const perfModal = document.getElementById('monitorPerformanceModal');
            if (perfModal) {
                perfModal.addEventListener('show.bs.modal', function(event) {
                    const btn = event.relatedTarget;
                    const studentId = btn.getAttribute('data-id');
                    const studentName = btn.getAttribute('data-name');
                    document.getElementById('perf_student_name').innerText = studentName;

                    const calculateGradePointJS = (grade) => {
                        if (grade <= 0) return "N/A";
                        if (grade >= 90) return "1.0";
                        if (grade >= 80) return "2.0";
                        if (grade >= 70) return "3.0";
                        if (grade >= 60) return "4.0";
                        return "INC";
                    };

                    fetch(`admin.php?ajax_performance=${studentId}`)
                        .then(res => res.json())
                        .then(data => {
                            const updateModalPeriodicStats = () => {
                                if (!adminGradeChart) return;
                                
                                let sums = [0, 0, 0, 0];
                                let counts = [0, 0, 0, 0];
                                let totalSum = 0;
                                let totalCount = 0;

                                adminGradeChart.data.datasets.forEach((ds, i) => {
                                    if (adminGradeChart.isDatasetVisible(i)) {
                                        let subVal = 0, subPeriods = 0;
                                        ds.data.forEach((val, idx) => {
                                            if (val > 0) {
                                                sums[idx] += val;
                                                counts[idx]++;
                                                subVal += val;
                                                subPeriods++;
                                            }
                                        });
                                        if(subPeriods > 0) {
                                            totalSum += (subVal / subPeriods);
                                            totalCount++;
                                        }
                                    }
                                });

                                const overallAvg = totalCount > 0 ? totalSum / totalCount : 0;
                                document.getElementById('perf_avg').innerText = overallAvg.toFixed(1);
                                document.getElementById('perf_letter').innerText = calculateGradePointJS(overallAvg);
                                
                                const standing = overallAvg >= 75 ? 'Passing' : (totalCount > 0 ? 'Failing' : 'No Data');
                                document.getElementById('perf_standing').innerText = standing;
                                document.getElementById('perf_status_text').className = 'mt-2 fw-bold ' + (standing === 'Passing' ? 'text-success' : 'text-danger');
                                document.getElementById('perf_status_text').innerText = standing === 'Passing' ? 'Academic criteria met' : 'Improvement recommended';

                                const ids = ['perf_prelim', 'perf_midterm', 'perf_prefinal', 'perf_final'];
                                ids.forEach((id, idx) => {
                                    const avg = counts[idx] > 0 ? (sums[idx] / counts[idx]) : 0;
                                    const display = avg > 0 ? avg.toFixed(1) + ' (' + calculateGradePointJS(avg) + ')' : '0.0';
                                    document.getElementById(id).innerText = display;
                                });
                            };
                            
                            document.getElementById('perf_total').innerText = data.total;

                            // Cleanup existing charts
                            if (adminGradeChart) adminGradeChart.destroy();
                            if (adminDistChart) adminDistChart.destroy();

                            // Line Chart
                            const ctxLine = document.getElementById('adminGradeChart').getContext('2d');
                            adminGradeChart = new Chart(ctxLine, {
                                type: 'line',
                                data: {
                                    labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'],
                                    datasets: data.datasets
                                },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
                            });

                            // Checkbox Legend Logic
                            const legendContainer = document.getElementById('adminGradeChartLegend');
                            if (legendContainer) {
                                legendContainer.innerHTML = '';
                                adminGradeChart.data.datasets.forEach((dataset, index) => {
                                    const div = document.createElement('div');
                                    div.className = 'form-check form-check-inline';
                                    div.innerHTML = `
                                    <input class="form-check-input" type="checkbox" id="a_ds_check_${index}" checked style="cursor: pointer; transition: all 0.3s ease;">
                                    <label class="form-check-label small fw-bold d-flex align-items-center" for="a_ds_check_${index}" style="cursor: pointer;">
                                        <span class="me-2" style="width: 12px; height: 12px; background-color: ${dataset.borderColor}; display: inline-block; border-radius: 2px;"></span>
                                        ${dataset.label}
                                    </label>
                                    `;
                                    div.querySelector('input').addEventListener('change', (e) => {
                                        const label = div.querySelector('label');
                                        label.style.opacity = e.target.checked ? '1' : '0.4';
                                        adminGradeChart.setDatasetVisibility(index, e.target.checked);
                                        adminGradeChart.update();
                                        updateModalPeriodicStats();
                                    });
                                    legendContainer.appendChild(div);
                                });
                            }

                            // Pie Chart
                            const ctxPie = document.getElementById('adminDistChart').getContext('2d');
                            adminDistChart = new Chart(ctxPie, {
                                type: 'pie',
                                data: {
                                    labels: Object.keys(data.distribution),
                                    datasets: [{
                                        data: Object.values(data.distribution),
                                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#64748b']
                                    }]
                                },
                                options: { responsive: true, maintainAspectRatio: false }
                            });

                            // Now that charts are initialized, update the summary stats
                            updateModalPeriodicStats();
                        });
                });
            }

            // Add User Password Strength Logic
            const addUserPassField = document.getElementById('new_password');
            if(addUserPassField) {
                addUserPassField.addEventListener('input', function() {
                    const val = this.value;
                    const bar = document.getElementById('add-pw-strength-bar');
                    const text = document.getElementById('add-pw-strength-text');
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
                });
            }

            // Fix: Globalize validatePasswordMatch to ensure it's accessible to the form submit listener
            window.validateChangePasswordMatch = function() {
                const pass = document.getElementById('change_password_field');
                const confirm = document.getElementById('confirm_password_field');
                const feedback = document.getElementById('change_cpass_feedback');
                const isMatch = pass.value === confirm.value && pass.value !== "";
                
                if (!isMatch && confirm.value !== "") {
                    confirm.classList.add('is-invalid');
                    feedback.style.display = 'block';
                    return false;
                } else {
                    confirm.classList.remove('is-invalid');
                    feedback.style.display = 'none';
                    return true;
                }
            };

            // Add User Form Validation (Just like register.php but dynamic)
            const addUserForm = document.getElementById('addUserForm');
            if(addUserForm) {
                addUserForm.querySelectorAll('input, select').forEach(input => {
                    input.addEventListener('blur', function() {
                        validateAdminField(this);
                    });
                });

                addUserForm.addEventListener('submit', function(e) {
                    const pass = document.getElementById('new_password').value;
                    if (pass.length < 8) {
                        alert("Password must be at least 8 characters long.");
                        e.preventDefault();
                        return;
                    }
                    
                    let formValid = true;
                    this.querySelectorAll('[required]').forEach(input => {
                        if (!validateAdminField(input)) formValid = false;
                    });
                    if(!formValid) e.preventDefault();
                });
            }

            function validateAdminField(input) {
                let valid = true;
                let feedback = input.nextElementSibling;
                if (input.value.trim() === "" && input.id !== 'val_email') valid = false;
                if (input.id === 'val_email' && input.value.trim() !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) valid = false;
                
                if (!valid) {
                    input.classList.add('is-invalid');
                } else {
                    input.classList.remove('is-invalid');
                }
                return valid;
            }
        });

        // Password strength indicator (optional enhancement)
        function checkPasswordStrength(password) {
            var strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;

            return strength;
        }
    </script>
</body>

</html>