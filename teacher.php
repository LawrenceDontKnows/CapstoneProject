<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('teacher');

// Handle AJAX Student Search Suggestions
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE role = 'student' AND status = 'active' AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?) LIMIT 5");
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

// Handle AJAX Grade Update (Auto-save every changes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_grade'])) {
    header('Content-Type: application/json');
    $t_id = $_SESSION['user_id'];
    $s_id = $_POST['student_id'];
    $sub_id = $_POST['subject_id'];
    $field = $_POST['field']; 
    $val = $_POST['value'] === '' ? 0 : (float)$_POST['value'];

    try {
        $check = $pdo->prepare("SELECT id FROM grades WHERE student_id = ? AND subject_id = ?");
        $check->execute([$s_id, $sub_id]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE grades SET $field = ?, teacher_id = ? WHERE id = ?")
                ->execute([$val, $t_id, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, $field) VALUES (?, ?, ?, ?)")
                ->execute([$s_id, $sub_id, $t_id, $val]);
        }

        // Re-calculate period totals and semestral grade for DB consistency
        $stmt = $pdo->prepare("SELECT * FROM grades WHERE student_id = ? AND subject_id = ?");
        $stmt->execute([$s_id, $sub_id]);
        $g = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $periods = ['prelim', 'midterm', 'prefinal', 'final'];
        $p_weights = ['prelim' => 0.2, 'midterm' => 0.2, 'prefinal' => 0.2, 'final' => 0.4];
        $w = getGradeWeights($pdo, $t_id);

        $sem_grade = 0; $p_results = [];
        foreach ($periods as $p) {
            $lec = (($g[$p.'_quiz']??0) * $w['lec_quiz']) + (($g[$p.'_online']??0) * $w['lec_online']) + (($g[$p.'_exam']??0) * $w['lec_exam']);
            $lab = (($g[$p.'_attendance']??0) * $w['lab_att']) + (100 * $w['lab_cs']) + (($g[$p.'_laboratory']??0) * $w['lab_exam']);
            $total = ($lec * $w['lec']) + ($lab * $w['lab']);
            $p_results[$p] = $total;
            if ($total > 0) $sem_grade += ($total * $p_weights[$p]);
        }
        $upd = $pdo->prepare("UPDATE grades SET prelim=?, midterm=?, prefinal=?, final=?, grade=? WHERE student_id=? AND subject_id=?");
        $upd->execute([$p_results['prelim'], $p_results['midterm'], $p_results['prefinal'], $p_results['final'], $sem_grade, $s_id, $sub_id]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle Teacher Weight Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher_weights'])) {
    $keys = ['weight_lec', 'weight_lab', 'weight_lec_quiz', 'weight_lec_online', 'weight_lec_exam', 'weight_lab_att', 'weight_lab_cs', 'weight_lab_exam'];
    foreach($keys as $key) {
        $stmt = $pdo->prepare("INSERT INTO teacher_settings (teacher_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$teacher_id, $key, $_POST[$key], $_POST[$key]]);
    }
    $_SESSION['message'] = "Your personalized grading weights have been saved.";
    $_SESSION['message_type'] = "success";
    header("Location: teacher.php?tab=settings");
    exit;
}

// FIX: Define $teacher_id from the session so the queries below can use it
$teacher_id = $_SESSION['user_id'];

$active_tab = $_GET['tab'] ?? 'dashboard';

// 2. Live Count: Total Registered Students
// We count all users with the role 'student' so the dashboard reflects the total population
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();

// 3. Count: Grades Assigned
$grades_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE teacher_id = ?");
$grades_count_stmt->execute([$teacher_id]);
$total_grades_assigned = $grades_count_stmt->fetchColumn();

// Master list of subjects - Show teacher's private subjects and global subjects
$all_subjects_stmt = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ? OR teacher_id IS NULL ORDER BY name ASC");
$all_subjects_stmt->execute([$teacher_id]);
$all_subjects = $all_subjects_stmt->fetchAll();

// Fetch Dynamic Grade Weights
$weights = getGradeWeights($pdo, $teacher_id);

// Filtered list: Only subjects this teacher is currently handling
$my_subjects_stmt = $pdo->prepare("SELECT DISTINCT s.* FROM subjects s JOIN grades g ON s.id = g.subject_id WHERE g.teacher_id = ? ORDER BY s.name");
$my_subjects_stmt->execute([$teacher_id]);
$my_subjects = $my_subjects_stmt->fetchAll();

// 5. Fetch Student List for the table view
$filter_subject = $_GET['filter_subject'] ?? '';
$search = $_GET['search'] ?? '';

// Build dynamic query to handle search context and subject filtering correctly
$select_fields = "s.id, s.first_name, s.last_name, s.username, s.course, s.year_level, sub_list.all_subs as enrolled_subjects";
$join_clause = "";
$params = [];

if ($filter_subject) {
    // Subject specific view: get detailed period grades for this teacher's entries
    $select_fields .= ", g.grade as average,
           g.prelim, g.prelim_quiz, g.prelim_cs AS prelim_class_standing, g.prelim_attendance, g.prelim_laboratory, g.prelim_online, g.prelim_exam,
           g.midterm, g.midterm_quiz, g.midterm_cs AS midterm_class_standing, g.midterm_attendance, g.midterm_laboratory, g.midterm_online, g.midterm_exam,
           g.prefinal, g.prefinal_quiz, g.prefinal_cs AS prefinal_class_standing, g.prefinal_attendance, g.prefinal_laboratory, g.prefinal_online, g.prefinal_exam,
           g.final, g.final_quiz, g.final_cs AS final_class_standing, g.final_attendance, g.final_laboratory, g.final_online, g.final_exam";
    
    $join_clause = "LEFT JOIN grades g ON s.id = g.student_id AND g.subject_id = ? AND g.teacher_id = ?
                    LEFT JOIN (SELECT student_id, GROUP_CONCAT(subjects.name SEPARATOR ', ') as all_subs FROM grades JOIN subjects ON grades.subject_id = subjects.id WHERE grades.teacher_id = ? GROUP BY student_id) sub_list ON s.id = sub_list.student_id";
    $params[] = $filter_subject;
    $params[] = $teacher_id;
    $params[] = $teacher_id;
} else {
    // Overall view: get cumulative average of students managed by this teacher
    $select_fields .= ", AVG(g.grade) as average, 
                       NULL as prelim, NULL as midterm, NULL as prefinal, NULL as final";
    $join_clause = "LEFT JOIN grades g ON s.id = g.student_id AND g.teacher_id = ?
                    LEFT JOIN (SELECT student_id, GROUP_CONCAT(subjects.name SEPARATOR ', ') as all_subs FROM grades JOIN subjects ON grades.subject_id = subjects.id WHERE grades.teacher_id = ? GROUP BY student_id) sub_list ON s.id = sub_list.student_id";
    $params[] = $teacher_id;
    $params[] = $teacher_id;
}

$student_sql = "SELECT $select_fields FROM users s $join_clause WHERE s.role = 'student' AND s.status = 'active' ";

if ($search) {
    // GLOBAL SEARCH: Allow finding any student in the system to add them to a subject
    $student_sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.username LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?) ";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
} else {
    // SCOPED VIEW: When not searching, only show students who are actually enrolled/graded
    if ($filter_subject) {
        // Show only students who have an existing record for this specific subject and teacher
        $student_sql .= " AND EXISTS (SELECT 1 FROM grades WHERE student_id = s.id AND subject_id = ? AND teacher_id = ?) ";
        $params[] = $filter_subject;
        $params[] = $teacher_id;
    } else if ($active_tab === 'dashboard' || $active_tab === 'manage-grades') {
        // Scoped view for Dashboard and Ledger: Show only students this teacher has records for
        $student_sql .= " AND EXISTS (SELECT 1 FROM grades WHERE student_id = s.id AND teacher_id = ?) ";
        $params[] = $teacher_id;
    }
    // If $active_tab is 'students', all registered students are visible by default to allow the teacher to find them.
}

if (!$filter_subject) $student_sql .= " GROUP BY s.id ";
$student_sql .= " ORDER BY s.year_level DESC, s.course ASC, s.last_name ASC";

$students_stmt = $pdo->prepare($student_sql);
$students_stmt->execute($params);
$students = $students_stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-bg: #0f172a;
            --primary-blue: #1e3a8a;
            --accent-color: #6366f1;
        }
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
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
            opacity: 1;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            transform: translateX(5px);
            color: white;
        }
        .sidebar .nav-link.active {
            opacity: 1;
            background: var(--accent-color);
            border-left: 4px solid #fff;
            font-weight: bold;
        }
        .sidebar .dropdown-menu {
            background-color: #1e293b;
            border: none;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2);
            margin: 0 10px;
        }
        .sidebar .dropdown-item {
            color: white;
            padding: 0.6rem 1.5rem;
        }
        .sidebar .dropdown-item:hover {
            background-color: rgba(255,255,255,0.1);
            color: white;
        }
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
        
        /* Compact Stat Strip for Tabs */
        .stat-strip-compact {
            display: flex;
            gap: 15px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .stat-pill {
            background: white;
            padding: 8px 18px;
            border-radius: 50px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .stat-pill b { color: #4f46e5; }
        .stat-pill i { font-size: 0.8rem; }
        
        /* Excel-like Table Styling */
        .table-excel {
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #cbd5e1;
            font-size: 0.875rem;
            min-width: 1200px; /* Prevents squishing on mobile */
        }
        .table-excel thead th {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 6px 12px !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
        }
        .sticky-col {
            position: sticky;
            left: 0;
            z-index: 5;
        }
        thead th.sticky-col {
            z-index: 6;
        }
        .table-excel tbody td.sticky-col {
            background-color: white !important;
        }

        /* Multi-level Sticky Header Setup */
        .table-ledger thead tr:nth-child(1) th {
            position: sticky;
            top: 0;
            background-color: #1e3a8a !important; /* Deep Blue */
            color: white !important;
            z-index: 10;
        }

        .table-ledger thead tr:nth-child(2) th {
            position: sticky;
            top: 29px;
            background-color: #3b82f6 !important; /* Primary Blue */
            color: white !important;
            z-index: 9;
        }

        .table-ledger thead tr:nth-child(3) th {
            position: sticky;
            top: 54px;
            background-color: #eff6ff !important; /* Soft Blue Tint */
            color: #1e40af;
            font-size: 9px;
            z-index: 8;
        }

        .final-grade-col {
            background-color: #f0f9ff !important;
            font-weight: bold;
            color: #1e3a8a;
            border-left: 2px solid #3b82f6 !important;
            border-right: 2px solid #3b82f6 !important;
        }
        .table-excel tbody td {
            border: 1px solid #e2e8f0;
            padding: 4px 12px !important;
            vertical-align: middle;
        }
        .table-excel tr:hover td {
            background-color: #f1f5f9 !important;
        }

        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-width)); }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; width: 100%; }
            .sidebar-overlay.active { display: block; }
        }

        /* Compact Stat Cards */
        .stat-box {
            padding: 1.5rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            min-height: 110px;
            transition: all 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
        }
        .stat-box h4 { margin: 0; font-size: 2rem; font-weight: 800; line-height: 1; }
        .stat-box p { margin: 0; font-size: 0.85rem; text-transform: uppercase; opacity: 0.9; letter-spacing: 0.05em; font-weight: 600; }
        .stat-box i { font-size: 2.5rem; opacity: 0.4; }

        .grad-blue { background: #4f46e5; }
        .grad-red { background: #e11d48; }
        .grad-purple { background: #7c3aed; }
        .grad-cyan { background: #0891b2; }
        .toast-container { z-index: 1060; }

        /* Live Search Styles */
        #searchResultsDropdown {
            top: 100%; left: 0; right: 0; z-index: 1050; max-height: 250px; overflow-y: auto;
            transition: opacity 0.2s ease;
        }
        #searchResultsDropdown .list-group-item {
            border-radius: 0; transition: background 0.2s;
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
            .stat-box { padding: 1rem; min-height: 80px; }
            .stat-box h4 { font-size: 1.25rem; }
            .stat-box i { font-size: 1.5rem; }
            .px-4 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .stat-pill { font-size: 0.7rem; padding: 5px 12px; }
        }
        @media (max-width: 360px) {
            .navbar-brand { font-size: 0.85rem !important; }
            .table-excel { font-size: 0.7rem; }
        }

        /* Highlight the targeted student row when viewing from dashboard */
        .student-row:target {
            background-color: #fef08a !important; /* light yellow highlight */
            outline: 2px solid #eab308;
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

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="container-fluid p-0">
        <div class="sidebar" id="sidebar">
            <div class="d-flex flex-column p-3">
                <div class="text-center mb-4">
                    <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 80px; height: 80px; margin-bottom: 10px; border-radius: 50%; object-fit: cover;">
                    <h5>Teacher Portal</h5>
                    <small><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></small>
                </div>
                
                <ul class="nav nav-pills flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" href="teacher.php?tab=dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard 
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'students' ? 'active' : ''; ?>" href="teacher.php?tab=students">
                            <i class="fas fa-user-graduate me-2"></i>My Students
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab === 'manage-grades' && !isset($_GET['manage_subjects'])) ? 'active' : ''; ?>" href="teacher.php?tab=manage-grades">
                            <i class="fas fa-edit me-2"></i>Manage Grades
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo isset($_GET['manage_subjects']) ? 'active' : ''; ?>" href="grades.php?manage_subjects=true">
                            <i class="fas fa-book me-2"></i>Manage Subjects
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="logoutDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-2"></i>Account
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="logoutDropdown">
                            <li><a class="dropdown-item text-warning" href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>

        <div class="main-content" id="main-content">
            <nav class="navbar navbar-light bg-light px-4 border-bottom">
                <i class="fas fa-bars toggle-btn" id="sidebarToggle"></i>
                <span class="navbar-brand mb-0 h1">GradeView Dashboard</span>
            </nav>

            <div class="px-4 py-4">
                <div class="tab-content">
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0 fw-bold">Dashboard Overview</h4>
                            <span>Welcome, <?php echo $_SESSION['first_name']; ?>!</span>
                        </div>

                        <div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-box grad-blue">
            <div>
                                        <h4><?php echo count($my_subjects); ?></h4>
                                        <p>My Subjects</p>
            </div>
            <i class="fas fa-book"></i>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-lg-4">
        <div class="stat-box grad-purple">
            <div>
                <h4><?php echo $total_students; ?></h4>
                <p>Total Students</p>
            </div>
            <i class="fas fa-user-graduate"></i>
        </div>
    </div>

    <div class="col-12 col-sm-12 col-lg-4">
        <div class="stat-box grad-cyan">
            <div>
                <h4><?php echo $total_grades_assigned; ?></h4>
                <p>Total Enrolled</p>
            </div>
            <i class="fas fa-user-check"></i>
        </div>
    </div>
</div>

                        <!-- Student Enrollment List for Dashboard Overview -->
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0 fw-bold text-secondary"><i class="fas fa-list-ul me-2"></i>Recent Enrollment</h6>
                            </div>
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-excel table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Full Name</th>
                                                    <th>Username</th>
                                                    <th>Enrolled Subjects</th>
                                                    <th>Course/Year</th>
                                                    <th class="text-center">Standing</th>
                                                    <th>Quick Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 1; foreach($students as $student): ?>
                                                <tr>
                                                    <td><?php echo $i++; ?></td>
                                                    <td class="fw-semibold"><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></td>
                                                    <td><span class="badge bg-light text-dark border">@<?php echo $student['username']; ?></span></td>
                                                    <td><small class="text-muted"><?php echo $student['enrolled_subjects'] ?: 'None'; ?></small></td>
                                                    <td><small class="fw-bold"><?php echo $student['course'] . ' - ' . $student['year_level']; ?></small></td>
                                                    <td class="text-center">
                                                        <div class="d-flex flex-column align-items-center">
                                                            <?php $avg = (float)$student['average']; ?>
                                                            <?php if ($avg <= 0): ?>
                                                                <span class="badge bg-secondary">No Grades</span>
                                                            <?php else: ?>
                                                                <span class="fw-bold text-dark"><?php echo number_format($avg, 2); ?></span>
                                                                <?php if ($avg < 75): ?>
                                                                    <small class="text-danger fw-bold" style="font-size: 0.7rem;"><i class="fas fa-exclamation-triangle me-1"></i>Needs Improvement</small>
                                                                <?php else: ?>
                                                                    <small class="text-success fw-bold" style="font-size: 0.7rem;"><i class="fas fa-check-circle me-1"></i>Good Standing</small>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td> 
                                                        <a href="teacher.php?tab=manage-grades<?php echo ($filter_subject ? '&filter_subject='.$filter_subject : '') . ($search ? '&search='.urlencode($search) : ''); ?>#student-row-<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye me-1"></i> View Grades
                                                        </a>
                                                        <a href="teacher.php?tab=manage-grades<?php echo ($filter_subject ? '&filter_subject='.$filter_subject : '') . ($search ? '&search='.urlencode($search) : ''); ?>#student-row-<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-success">
                                                            <i class="fas fa-plus-circle me-1"></i> Add Grade
                                                        </a>
                                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#monitorPerformanceModal"
                                                            data-id="<?php echo $student['id']; ?>" data-name="<?php echo $student['first_name'] . ' ' . $student['last_name']; ?>">
                                                            <i class="fas fa-chart-line me-1"></i> Performance
                                                        </button>
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

                    <div class="tab-pane fade <?php echo $active_tab === 'students' ? 'show active' : ''; ?>" id="students">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="d-flex flex-column gap-1">
                                <h4 class="mb-0 fw-bold">My Students List</h4>
                                <?php if($filter_subject): ?>
                                    <div class="text-primary small fw-bold">
                                        <i class="fas fa-book me-1"></i>Viewing: <?php 
                                            foreach($all_subjects as $s) { 
                                                if($s['id'] == $filter_subject) { echo htmlspecialchars($s['name']); break; } 
                                            } 
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <form class="d-flex gap-2 ms-4 position-relative" action="teacher.php" method="GET" id="studentFilterForm">
                                    <input type="hidden" name="tab" value="students">
                                    <div class="position-relative" style="width: 200px;">
                                        <input type="text" name="search" id="liveSearchInput" class="form-control form-control-sm" placeholder="Search name..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                                        <div id="searchResultsDropdown" class="list-group position-absolute shadow d-none"></div>
                                    </div>
                                    <select name="filter_subject" class="form-select form-select-sm" style="width: 200px;" onchange="this.form.submit()">
                                        <option value="">All Subjects</option>
                                        <?php foreach($all_subjects as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $filter_subject == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo $s['name']; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>                                    
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                                    <?php if($filter_subject || $search): ?>
                                    <a href="teacher.php?tab=students" class="btn btn-sm btn-outline-secondary">Clear</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <a href="print_grade.php?all_students=true" target="_blank" class="btn btn-primary"><i class="fas fa-print me-2"></i> Print All Students</a>
                        </div>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-excel mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Username</th>
                                                <th>Enrolled Subjects</th>
                                                <th>Course & Year</th>
                                                <th class="text-center">Standing</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach($students as $student): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo $student['first_name'] . ' ' . $student['last_name']; ?></td>
                                                <td><?php echo $student['username']; ?></td>
                                                <td><small class="text-muted"><?php echo $student['enrolled_subjects'] ?: 'None'; ?></small></td>
                                                <td><span class="badge bg-light text-primary border"><?php echo $student['course'] . ' - ' . $student['year_level']; ?></span></td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <?php $avg = (float)$student['average']; ?>
                                                        <?php if ($avg <= 0): ?>
                                                            <span class="badge bg-secondary">Pending</span>
                                                        <?php else: ?>
                                                            <span class="fw-bold text-dark"><?php echo number_format($avg, 2); ?></span>
                                                            <?php if ($avg < 75): ?>
                                                                <small class="text-danger fw-bold" style="font-size: 0.7rem;"><i class="fas fa-exclamation-triangle me-1"></i>Needs Improvement</small>
                                                            <?php else: ?>
                                                                <small class="text-success fw-bold" style="font-size: 0.7rem;"><i class="fas fa-check-circle me-1"></i>Good Standing</small>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="teacher.php?tab=manage-grades<?php echo ($filter_subject ? '&filter_subject='.$filter_subject : '') . ($search ? '&search='.urlencode($search) : ''); ?>#student-row-<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Grades">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </a>
                                                    <a href="teacher.php?tab=manage-grades<?php echo ($filter_subject ? '&filter_subject='.$filter_subject : '') . ($search ? '&search='.urlencode($search) : ''); ?>#student-row-<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-success" title="Add Grade">
                                                        <i class="fas fa-plus-circle me-1"></i> Add Grade
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#monitorPerformanceModal"
                                                            data-id="<?php echo $student['id']; ?>" data-name="<?php echo $student['first_name'] . ' ' . $student['last_name']; ?>" title="Monitor Performance">
                                                        <i class="fas fa-chart-line me-1"></i> Performance
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#enrollStudentModal" 
                                                            data-id="<?php echo $student['id']; ?>" data-name="<?php echo $student['first_name'] . ' ' . $student['last_name']; ?>" title="Enroll in Subject">
                                                        <i class="fas fa-plus"></i> Enroll
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Excel style Manage Grades Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'manage-grades' ? 'show active' : ''; ?>" id="manage-grades">
                        <div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-3">
                            <div>
                                <h4 class="mb-1 fw-bold"><i class="fas fa-table me-2"></i>Grade Ledger</h4>
                                <div class="small text-muted mb-2">
                                    <b>Legend:</b> Q: Quiz(<?php echo $weights['quiz']*100; ?>%) | CS: Class Standing(<?php echo $weights['cs']*100; ?>%) | Att: Attendance(<?php echo $weights['att']*100; ?>%) | Lab: Laboratory(<?php echo $weights['lab']*100; ?>%) | Onl: Online(<?php echo $weights['onl']*100; ?>%) | E: Exam(<?php echo $weights['exam']*100; ?>%)
                                </div>
                            </div>
                            
                            <?php if ($filter_subject): 
                                $sub_avg = 0; $graded = 0;
                                foreach($students as $st) { if($st['average'] > 0) { $sub_avg += $st['average']; $graded++; } }
                                $sub_avg = $graded > 0 ? $sub_avg / $graded : 0;
                            ?>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="small">Avg: <b class="text-primary"><?php echo number_format($sub_avg, 2); ?></b></div>
                                <div class="small">Graded: <b><?php echo $graded; ?>/<?php echo count($students); ?></b></div>
                            <?php endif; ?>

                            <form class="d-flex gap-2" method="GET">
                                <input type="hidden" name="tab" value="manage-grades">
                                <div class="position-relative" style="width: 200px;">
                                    <input type="text" name="search" id="ledgerSearchInput" class="form-control form-control-sm" placeholder="Find student..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                                    <div id="ledgerSearchResults" class="list-group position-absolute shadow d-none" style="z-index: 1060; width: 100%;"></div>
                                </div>
                                <select name="filter_subject" class="form-select form-select-sm shadow-sm" style="width: 220px;" onchange="this.form.submit()">
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach($all_subjects as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo $filter_subject == $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo $s['name']; ?> (<?php echo $s['code']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php if ($filter_subject): ?></div><?php endif; ?>
                        </div>

                        <?php if ($filter_subject): ?>
                        <div class="bg-primary text-white p-3 rounded-top d-flex justify-content-between align-items-center shadow-sm">
                            <h5 class="mb-0 fw-bold text-uppercase">
                                <i class="fas fa-graduation-cap me-2"></i>
                                <?php 
                                    foreach($all_subjects as $s) { 
                                        if($s['id'] == $filter_subject) { 
                                            echo htmlspecialchars($s['name']) . " (" . htmlspecialchars($s['code']) . ")"; 
                                            break; 
                                        } 
                                    } 
                                ?>
                            </h5>
                            <span class="badge bg-white text-primary px-3">SUBJECT SELECTED</span>
                        </div>
                        <form action="grades.php" method="POST">
                            <input type="hidden" name="subject_id" value="<?php echo $filter_subject; ?>">
                            <div class="table-excel-container shadow-sm bg-white" style="border-radius: 0 0 10px 10px; overflow-x: auto;">
                                <table class="table table-ledger table-bordered table-hover mb-0" style="font-size: 11px; min-width: 2500px; text-align: center;">
                                    <thead>
                                        <tr>
                                            <th colspan="3" rowspan="2" class="align-middle border-end sticky-col" style="left:0; z-index:11; background-color: #0f172a !important;">STUDENT INFO</th>
                                            <th colspan="6" class="border-end">PRELIM (20%)</th>
                                            <th colspan="6" class="border-end">MIDTERM (20%)</th>
                                            <th colspan="6" class="border-end">PREFINAL (20%)</th>
                                            <th colspan="6" class="border-end">FINAL (40%)</th>
                                            <th rowspan="3" class="align-middle bg-dark text-white" style="width: 100px;">FINAL AVG</th>
                                        </tr>
                                        <tr>
                                            <?php foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p): ?>
                                                <th colspan="5">COMPONENTS</th>
                                                <th rowspan="2" class="align-middle border-end" style="background-color: #eff6ff;">GRADE</th>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr style="font-size: 9px; text-transform: uppercase;">
                                            <th class="sticky-col" style="left:0; z-index:11;">#</th>
                                            <th>USN</th>
                                            <th class="text-start">Name</th>
                                            <?php foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p): ?>
                                                <th title="Perfect Attendance">
                                                    <div class="d-flex flex-column align-items-center">
                                                        <small>ATT</small>
                                                        <input type="checkbox" class="master-attendance form-check-input" style="width: 12px; height: 12px;">
                                                    </div>
                                                </th>
                                                <th title="Quizzes">Quiz</th>
                                                <th title="Major Exam">Exam</th>
                                                <th title="Laboratory">Lab</th>
                                                <th title="Online Class Standing">Onl</th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $count = 1; foreach($students as $student): ?>
                                        <tr class="student-row" id="student-row-<?php echo $student['id']; ?>">
                                            <td class="sticky-col bg-white border-end" style="left: 0;"><?php echo $count++; ?></td>
                                            <td class="small text-muted"><?php echo $student['username']; ?></td>
                                            <td class="text-start fw-bold sticky-col bg-white border-end" style="left: 35px;"><?php echo strtoupper($student['last_name']) . ', ' . $student['first_name']; ?></td>
                                            <?php foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p): ?>
                                                <td>
                                                    <div class="d-flex flex-column align-items-center">
                                                        <input type="checkbox" class="ta-checkbox form-check-input mb-1" style="width: 10px; height: 10px;" data-period="<?php echo $p; ?>" <?php echo ($student[$p.'_attendance'] >= 100) ? 'checked' : ''; ?>>
                                                        <input type="number" step="0.01" name="grades[<?php echo $student['id']; ?>][<?php echo $p; ?>_attendance]" class="grade-input border-0 text-center" style="width: 45px;" data-period="<?php echo $p; ?>" value="<?php echo $student[$p.'_attendance'] > 0 ? $student[$p.'_attendance'] : ''; ?>">
                                                    </div>
                                                </td>
                                                <td><input type="number" step="0.01" name="grades[<?php echo $student['id']; ?>][<?php echo $p; ?>_quiz]" class="grade-input border-0 text-center" style="width: 45px;" data-period="<?php echo $p; ?>" value="<?php echo $student[$p.'_quiz'] > 0 ? $student[$p.'_quiz'] : ''; ?>"></td>
                                                <td><input type="number" step="0.01" name="grades[<?php echo $student['id']; ?>][<?php echo $p; ?>_exam]" class="grade-input border-0 text-center" style="width: 45px;" data-period="<?php echo $p; ?>" value="<?php echo $student[$p.'_exam'] > 0 ? $student[$p.'_exam'] : ''; ?>"></td>
                                                <td><input type="number" step="0.01" name="grades[<?php echo $student['id']; ?>][<?php echo $p; ?>_laboratory]" class="grade-input border-0 text-center" style="width: 45px;" data-period="<?php echo $p; ?>" value="<?php echo $student[$p.'_laboratory'] > 0 ? $student[$p.'_laboratory'] : ''; ?>"></td>
                                                <td><input type="number" step="0.01" name="grades[<?php echo $student['id']; ?>][<?php echo $p; ?>_online]" class="grade-input border-0 text-center" style="width: 45px;" data-period="<?php echo $p; ?>" value="<?php echo $student[$p.'_online'] > 0 ? $student[$p.'_online'] : ''; ?>"></td>
                                                <td class="bg-light fw-bold" id="subtotal-<?php echo $student['id']; ?>-<?php echo $p; ?>"><?php echo $student[$p] > 0 ? number_format($student[$p], 2) : '0.00'; ?></td>
                                            <?php endforeach; ?>
                                            <td class="final-grade-col text-center" id="avg-<?php echo $student['id']; ?>"><?php echo $student['average'] > 0 ? number_format($student['average'], 2) : '0.00'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="submit" name="save_bulk_grades" class="btn btn-success fw-bold shadow-sm">
                                    <i class="fas fa-save me-2"></i>SYNCHRONIZE ALL GRADES
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center py-5 border rounded bg-white shadow-sm">
                            <i class="fas fa-book-open fa-3x text-light mb-3"></i>
                            <p class="text-muted">Please select a subject above to view the grade ledger and begin encoding.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enroll Student Modal -->
    <div class="modal fade" id="enrollStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Enroll Student in Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="grades.php" method="POST" id="confirmEnrollForm">
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="student_id" id="enroll_student_id">
                    <div class="modal-body">
                        <p>Select which subject to enroll <strong id="enroll_student_name"></strong> in. This will add them to your grade ledger.</p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Subject</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">-- Choose Subject --</option>
                                <?php foreach($all_subjects as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo $s['name']; ?> (<?php echo $s['code']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Confirm Enrollment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Monitor Student Performance Modal -->
    <div class="modal fade" id="monitorPerformanceModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-chart-area me-2"></i>Academic Performance Analysis: <span id="perf_student_name"></span></h5>
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
                                <h6 class="text-muted small text-uppercase fw-bold">Total Subjects</h6>
                                <h2 class="display-5 fw-bold text-success mb-0" id="perf_total">0</h2>
                                <small class="text-muted mt-2">Enrolled & Graded</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Academic Status</h6>
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
                                <div class="card-header bg-white fw-bold">Grade Trend (Periodic)</div>
                                <div class="card-body">
                                    <div style="height: 350px;"><canvas id="teacherGradeChart"></canvas></div>
                                    <div id="teacherGradeChartLegend" class="d-flex flex-wrap gap-3 justify-content-center mt-3"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 mb-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white fw-bold">Grade Points Distribution</div>
                                <div class="card-body">
                                    <div style="height: 350px;"><canvas id="teacherDistChart"></canvas></div>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Unified Live Search Logic
            function setupSearch(inputId, dropdownId) {
                const input = document.getElementById(inputId);
                const dropdown = document.getElementById(dropdownId);
                if (!input || !dropdown) return;

                input.addEventListener('input', function() {
                    const query = this.value.trim();
                    if (query.length < 2) {
                        dropdown.classList.add('d-none');
                        return;
                    }
                    fetch(`teacher.php?ajax_search=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            dropdown.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(s => {
                                    const a = document.createElement('a');
                                    a.href = '#';
                                    a.className = 'list-group-item list-group-item-action py-2';
                                    a.innerHTML = `<div class="small fw-bold">${s.first_name} ${s.last_name}</div><small class="text-muted">@${s.username}</small>`;
                                    a.addEventListener('click', (e) => {
                                        e.preventDefault();
                                        input.value = s.first_name + ' ' + s.last_name;
                                        dropdown.classList.add('d-none');
                                        input.form.submit();
                                    });
                                    dropdown.appendChild(a);
                                });
                                dropdown.classList.remove('d-none');
                            } else { dropdown.classList.add('d-none'); }
                        });
                });
                document.addEventListener('click', (e) => {
                    if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.add('d-none');
                });
            }

            setupSearch('liveSearchInput', 'searchResultsDropdown');
            setupSearch('ledgerSearchInput', 'ledgerSearchResults');

            // Enroll Student Modal Logic
            const enrollModal = document.getElementById('enrollStudentModal');
            if (enrollModal) {
                enrollModal.addEventListener('show.bs.modal', function (event) {
                    const btn = event.relatedTarget;
                    const id = btn.getAttribute('data-id');
                    const name = btn.getAttribute('data-name');
                    this.querySelector('#enroll_student_id').value = id;
                    this.querySelector('#enroll_student_name').innerText = name;
                });
            }

            // Monitor Performance Modal Logic
            let teacherGradeChart = null;
            let teacherDistChart = null;
            const perfModal = document.getElementById('monitorPerformanceModal');
            if (perfModal) {
                perfModal.addEventListener('show.bs.modal', function(event) {
                    const btn = event.relatedTarget;
                    const studentId = btn.getAttribute('data-id');
                    const studentName = btn.getAttribute('data-name');
                    document.getElementById('perf_student_name').innerText = studentName;

                    fetch(`teacher.php?ajax_performance=${studentId}`)
                        .then(res => res.json())
                        .then(data => {
                            const updateModalPeriodicStats = () => {
                                let sums = [0, 0, 0, 0];
                                let counts = [0, 0, 0, 0];
                                teacherGradeChart.data.datasets.forEach((ds, i) => {
                                    if (teacherGradeChart.isDatasetVisible(i)) {
                                        ds.data.forEach((val, idx) => {
                                            if (val > 0) {
                                                sums[idx] += val;
                                                counts[idx]++;
                                            }
                                        });
                                    }
                                });
                                const ids = ['perf_prelim', 'perf_midterm', 'perf_prefinal', 'perf_final'];
                                ids.forEach((id, idx) => {
                                    const avg = counts[idx] > 0 ? (sums[idx] / counts[idx]) : 0;
                                    const display = avg > 0 ? avg.toFixed(1) + ' (' + calculateGradePointJS(avg) + ')' : '0.0';
                                    document.getElementById(id).innerText = display;
                                });
                            };

                            document.getElementById('perf_total').innerText = data.total;

                            // Cleanup existing charts
                            if (teacherGradeChart) teacherGradeChart.destroy();
                            if (teacherDistChart) teacherDistChart.destroy();

                            // Line Chart
                            const ctxLine = document.getElementById('teacherGradeChart').getContext('2d');
                            teacherGradeChart = new Chart(ctxLine, {
                                type: 'line',
                                data: {
                                    labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'],
                                    datasets: data.datasets
                                },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
                            });

                            // Checkbox Legend Logic
                            const legendContainer = document.getElementById('teacherGradeChartLegend');
                            if (legendContainer) {
                                legendContainer.innerHTML = '';
                                teacherGradeChart.data.datasets.forEach((dataset, index) => {
                                    const div = document.createElement('div');
                                    div.className = 'form-check form-check-inline';
                                    div.innerHTML = `
                                    <input class="form-check-input" type="checkbox" id="t_ds_check_${index}" checked style="cursor: pointer; transition: all 0.3s ease;">
                                    <label class="form-check-label small fw-bold d-flex align-items-center" for="t_ds_check_${index}" style="cursor: pointer;">
                                        <span class="me-2" style="width: 12px; height: 12px; background-color: ${dataset.borderColor}; display: inline-block; border-radius: 2px;"></span>
                                        ${dataset.label}
                                    </label>
                                    `;
                                    div.querySelector('input').addEventListener('change', (e) => {
                                        const label = div.querySelector('label');
                                        label.style.opacity = e.target.checked ? '1' : '0.4';
                                        teacherGradeChart.setDatasetVisibility(index, e.target.checked);
                                        teacherGradeChart.update();
                                        updateModalPeriodicStats();
                                    });
                                    legendContainer.appendChild(div);
                                });
                            }

                            // Pie Chart
                            const ctxPie = document.getElementById('teacherDistChart').getContext('2d');
                            teacherDistChart = new Chart(ctxPie, {
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

                            // Initial population of stats based on visible subjects
                            updateModalPeriodicStats();
                        });
                });
            }

            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const toggle = document.getElementById('sidebarToggle');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                sidebar.classList.toggle('active'); // For mobile
                if(overlay) overlay.classList.toggle('active');
                if(mainContent) mainContent.classList.toggle('expanded');
                window.dispatchEvent(new Event('resize'));
            }

            // Auto-close sidebar on mobile when a link is clicked
            const navLinks = sidebar.querySelectorAll('.nav-link:not(.dropdown-toggle)');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                        toggleSidebar();
                    }
                });
            });

            // Toast Initialization
            const toastEl = document.getElementById('msgToast');
            const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
            
            <?php 
            $display_msg = $_SESSION['message'] ?? '';
            $display_type = $_SESSION['message_type'] ?? 'info';
            if ($display_msg): ?>
                document.getElementById('toastMsg').innerText = "<?php echo addslashes($display_msg); ?>";
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
                toastEl.classList.add('bg-<?php echo $display_type === "danger" ? "danger" : ($display_type === "success" ? "success" : "info"); ?>');
                toast.show();
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            toggle.addEventListener('click', toggleSidebar);
            if(overlay) overlay.addEventListener('click', toggleSidebar);

            // Live Grade Ledger Calculation
            const ledgerTable = document.querySelector('.table-excel');
            if (ledgerTable) {
                // Automatic calculation as you type
                ledgerTable.addEventListener('input', function(e) {
                    if (e.target.classList.contains('grade-input')) {
                        const row = e.target.closest('.student-row');
                        calculateRow(row);
                    }
                });

                // Automatic save on change (blur)
                ledgerTable.addEventListener('change', function(e) {
                    if (e.target.classList.contains('grade-input')) {
                        performAutoSave(e.target);
                    }
                });
            }

            function performAutoSave(input) {
                const row = input.closest('.student-row');
                const studentId = row.id.split('-').pop();
                const match = input.name.match(/\[([^\]]+)\]$/);
                if (!match) return;

                const formData = new FormData();
                formData.append('ajax_save_grade', '1');
                formData.append('student_id', studentId);
                formData.append('subject_id', '<?php echo $filter_subject; ?>');
                formData.append('field', match[1]);
                formData.append('value', input.value);

                fetch('teacher.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => { if (!data.success) console.error("Auto-save failed:", data.error); });
            }

            // Master Attendance Checkbox Logic
            document.querySelectorAll('.master-attendance').forEach((checkbox, index) => {
                checkbox.addEventListener('change', function() {
                    const period = ['prelim', 'midterm', 'prefinal', 'final'][index];
                    const inputs = document.querySelectorAll(`input[name*="_attendance"][data-period="${period}"]`);
                    inputs.forEach(input => {
                        input.value = this.checked ? 100 : '';
                        const row = input.closest('.student-row');
                        const ta = row.querySelector(`.ta-checkbox[data-period="${period}"]`);
                        if (ta) ta.checked = this.checked;
                        calculateRow(row);
                        performAutoSave(input);
                    });
                });
            });

            function calculateGradePointJS(grade) {
                if (grade <= 0) return "N/A";
                if (grade >= 90) return "1.0";
                if (grade >= 80) return "2.0";
                if (grade >= 70) return "3.0";
                if (grade >= 60) return "4.0";
                return "INC";
            }

            function calculateRow(row) {
                const w = <?php echo json_encode($weights); ?>;
                const periods = ['prelim', 'midterm', 'prefinal', 'final'];
                let semestralGrade = 0;
                let hasAnyData = false;
                const periodWeights = [0.2, 0.2, 0.2, 0.4];

                periods.forEach((p, index) => {
                    // LEC Components
                    const q_lec = parseFloat(row.querySelector(`input[name*="${p}_quiz"]`)?.value) || 0;
                    const cs_lec = parseFloat(row.querySelector(`input[name*="${p}_online"]`)?.value) || 0;
                    const e_lec = parseFloat(row.querySelector(`input[name*="${p}_exam"]`)?.value) || 0;
                    const lec_period = (q_lec * w.lec_quiz) + (cs_lec * w.lec_online) + (e_lec * w.lec_exam);

                    // LAB Components
                    const act_lab = parseFloat(row.querySelector(`input[name*="${p}_attendance"]`)?.value) || 0;
                    const cs_lab = 100; 
                    const e_lab = parseFloat(row.querySelector(`input[name*="${p}_laboratory"]`)?.value) || 0;
                    const lab_period = (act_lab * w.lab_att) + (cs_lab * w.lab_cs) + (e_lab * w.lab_exam);

                    // Period Total
                    const periodTotal = (lec_period * w.lec) + (lab_period * w.lab);
                    
                    const inputs = row.querySelectorAll(`input[data-period="${p}"]`);
                    let hasValue = Array.from(inputs).some(i => i.value !== "");

                    const studentId = row.querySelector('input[name^="grades"]').name.match(/\[(\d+)\]/)[1];
                    const subTotalEl = document.getElementById(`subtotal-${studentId}-${p}`);
                    if (subTotalEl) {
                        subTotalEl.innerText = periodTotal > 0 ? periodTotal.toFixed(2) + ' (' + calculateGradePointJS(periodTotal) + ')' : '-';
                    }

                    if (hasValue) {
                        semestralGrade += (periodTotal * periodWeights[index]);
                        hasAnyData = true;
                    }
                });

                const studentId = row.querySelector('input[name^="grades"]').name.match(/\[(\d+)\]/)[1];
                const avgEl = document.getElementById(`avg-${studentId}`);
                if (avgEl) {
                    avgEl.innerText = hasAnyData ? semestralGrade.toFixed(2) + ' (' + calculateGradePointJS(semestralGrade) + ')' : '-';
                    avgEl.className = 'text-center fw-bold bg-light final-avg-cell ' + 
                                     (semestralGrade > 0 && semestralGrade < 75 ? 'text-danger' : (semestralGrade >= 75 ? 'text-primary' : ''));
                }
            }

            // Listener for TA checkboxes
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('ta-checkbox')) {
                    const input = e.target.closest('td').querySelector('input[type="number"]');
                    input.value = e.target.checked ? 100 : '';
                    const row = e.target.closest('.student-row');
                    calculateRow(row);
                    performAutoSave(input);
                }
            });
        });
    </script>
</body>
</html>