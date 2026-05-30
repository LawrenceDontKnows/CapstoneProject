<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('student');

// Handle AJAX Search Suggestions (Subjects/Classrooms)
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT DISTINCT s.name as subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ? AND s.name LIKE ? LIMIT 5");
    $stmt->execute([$_SESSION['user_id'], "%$query%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$student_id = $_SESSION['user_id'];
$student_info = getStudentInfo($pdo, $student_id);

// Determine current active tab from query string or default to dashboard
$active_tab = $_GET['tab'] ?? 'dashboard';

// Get student's grades
$search = $_GET['search'] ?? '';
$grades_stmt = $pdo->prepare("
    SELECT g.*, s.name as subject_name, 
           u.first_name as teacher_first, u.last_name as teacher_last 
    FROM grades g 
    JOIN subjects s ON g.subject_id = s.id 
    JOIN users u ON g.teacher_id = u.id 
    WHERE g.student_id = ? " . ($search ? "AND s.name LIKE ?" : "") . "
    ORDER BY g.graded_at DESC
");

$params = [$student_id];
if ($search) {
    $params[] = "%$search%";
}
$grades_stmt->execute($params);
$grades = $grades_stmt->fetchAll();

// Calculate statistics
$total_grades = count($grades);
$average_grade = 0;
if ($total_grades > 0) {
    $sum = 0;
    foreach ($grades as $grade) {
        $sum += $grade['grade'];
    }
    $average_grade = $sum / $total_grades;
}

// Calculate periodic averages
$period_stats = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
$period_counts = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
foreach ($grades as $grade) {
    foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p) {
        if (isset($grade[$p]) && $grade[$p] > 0) {
            $period_stats[$p] += $grade[$p];
            $period_counts[$p]++;
        }
    }
}

// Get grade distribution
$grade_distribution = ['1.0' => 0, '2.0' => 0, '3.0' => 0, '4.0' => 0, 'INC' => 0, 'F' => 0];
foreach ($grades as $grade) {
    $letter_grade = calculateGradePoint($grade['grade']);
    $grade_distribution[$letter_grade]++;
}
// Prepare dynamic chart data: Each subject is a line over 4 quarters
$chart_datasets = [];
foreach ($grades as $g) {
    // Generate deterministic colors based on subject name for consistency
    $hue = abs(crc32($g['subject_name'])) % 360;
    $chart_datasets[] = [
        'label' => $g['subject_name'],
        'data' => [
            (float)($g['prelim'] ?? 0),
            (float)($g['midterm'] ?? 0),
            (float)($g['prefinal'] ?? 0),
            (float)($g['final'] ?? 0)
        ],
        'borderColor' => "hsl($hue, 70%, 45%)",
        'backgroundColor' => "transparent",
        'tension' => 0.4,
        'pointRadius' => 5,
        'pointHoverRadius' => 7,
        'fill' => false
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --sidebar-bg: #0f172a;
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
            opacity: 1;
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
            color: white;
        }
        .sidebar .nav-link.active {
            opacity: 1;
            background: #6366f1;
            border-left-color: #fff;
            font-weight: bold;
        }
        .sidebar .dropdown-menu {
            background-color: #1e293b;
            border: none;
            margin: 0 10px;
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
        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-width)); }
            .sidebar.active { left: 0; }
            .main-content { margin-left: 0; width: 100%; }
            .sidebar-overlay.active { display: block; }
        }

        .grade-progress {
            height: 10px;
        }

        .stat-card {
            transition: transform 0.3s ease;
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-card:hover { transform: translateY(-5px); }
        .grad-1 { background: linear-gradient(45deg, #1e3a8a, #3b82f6); }
        .grad-2 { background: linear-gradient(45deg, #dc2626, #f87171); }
        .grad-3 { background: linear-gradient(45deg, #7c3aed, #a78bfa); }
        .grad-4 { background: linear-gradient(45deg, #0d9488, #2dd4bf); }
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
            font-size: 1.25rem !important;
        }

        /* AI Chatbot Widget Styles */
        .ai-chat-widget {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1050;
        }
        #aiChatContainer {
            width: 350px;
            height: 450px;
            max-width: calc(100vw - 30px);
            max-height: calc(100vh - 120px);
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .ai-chat-header {
            background: var(--sidebar-bg);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        #aiChatMessages {
            flex-grow: 1;
            padding: 15px;
            overflow-y: auto;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .chat-bubble {
            padding: 10px 14px;
            border-radius: 15px;
            max-width: 85%;
            font-size: 0.9rem;
            word-wrap: break-word;
        }
        .chat-bubble.ai { background: #e0e7ff; color: #1e1b4b; align-self: flex-start; }
        .chat-bubble.user { background: #6366f1; color: white; align-self: flex-end; }

        .ai-suggestion-btn {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid #6366f1;
            color: #6366f1;
            background: transparent;
            transition: all 0.2s;
            cursor: pointer;
        }
        .ai-suggestion-btn:hover {
            background: #6366f1;
            color: white;
        }

        @media (max-width: 576px) {
            .ai-chat-widget { right: 10px; bottom: 10px; }
            #aiChatContainer { width: calc(100vw - 20px); height: 400px; right: -10px; position: relative; }
            .navbar-brand { font-size: 0.95rem !important; }
            .stat-card h4 { font-size: 1.25rem; }
            .stat-card p { font-size: 0.75rem; }
        }
        @media (max-width: 360px) {
            .navbar-brand { font-size: 0.85rem !important; }
            .chat-bubble { font-size: 0.8rem; }
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
            <!-- Sidebar -->
            <div class="d-flex flex-column p-3">
                <div class="text-center mb-4">
                        <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 80px; height: 80px; margin-bottom: 10px; border-radius: 50%; object-fit: cover;">
                        <h5>Student Portal</h5>
                        <div class="fw-bold"><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></div>
                        <small class="text-info"><?php echo $student_info['course'] . ' - ' . $student_info['year_level']; ?></small>
                    </div>

                    <ul class="nav nav-pills flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" href="students.php?tab=dashboard">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'grades' ? 'active' : ''; ?>" href="students.php?tab=grades">
                                <i class="fas fa-chart-bar me-2"></i>My Grades
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'performance' ? 'active' : ''; ?>" href="students.php?tab=performance">
                                <i class="fas fa-trending-up me-2"></i>Performance
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

        <!-- Main Content -->
        <div class="main-content" id="main-content">
            <nav class="navbar navbar-light bg-light px-4 border-bottom">
                <i class="fas fa-bars toggle-btn" id="sidebarToggle"></i>
                <span class="navbar-brand mb-0 h1">GradeView Portal</span>
            </nav>

            <div class="px-4 py-4">
                <div class="tab-content">
                    <!-- Dashboard Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="row mb-2 mb-md-4">
                            <div class="col-sm-6 col-lg-3 mb-3">
                                <div class="card stat-card grad-1 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $total_grades; ?></h4>
                                                <p>Total Grades</p>
                                            </div>
                                            <i class="fas fa-list-alt fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3 mb-3">
                                <div class="card stat-card grad-4 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo number_format($average_grade, 2); ?></h4>
                                                <p>Average Grade</p>
                                            </div>
                                            <i class="fas fa-calculator fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3 mb-3">
                                <div class="card stat-card grad-3 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo calculateGradePoint($average_grade); ?></h4>
                                                <p>Overall Grade</p>
                                            </div>
                                            <i class="fas fa-award fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3 mb-3">
                                <div class="card stat-card grad-2 text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $grade_distribution['1.0']; ?></h4>
                                                <p>1.0 Grades</p>
                                            </div>
                                            <i class="fas fa-star fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Grade Distribution -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Grade Distribution</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($grade_distribution as $letter => $count):
                                            $percentage = $total_grades > 0 ? ($count / $total_grades) * 100 : 0;
                                            $color_class = '';
                                            switch ($letter) {
                                                case '1.0':
                                                    $color_class = 'bg-success';
                                                    break;
                                                case '2.0':
                                                    $color_class = 'bg-info';
                                                    break;
                                                case '3.0':
                                                    $color_class = 'bg-warning';
                                                    break;
                                                case '4.0':
                                                    $color_class = 'bg-danger';
                                                    break;
                                                case 'INC':
                                                    $color_class = 'bg-dark';
                                                    break;
                                            }
                                            ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span>Grade <?php echo $letter; ?></span>
                                                    <span><?php echo $count; ?>
                                                        (<?php echo number_format($percentage, 1); ?>%)</span>
                                                </div>
                                                <div class="progress grade-progress">
                                                    <div class="progress-bar <?php echo $color_class; ?>"
                                                        style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5>My Enrolled Subjects</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($grades as $grade): ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo $grade['subject_name']; ?></h6>
                                                        <small class="text-muted">Instructor: <?php echo $grade['teacher_first'] . ' ' . $grade['teacher_last']; ?></small>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (empty($grades)): ?>
                                                <div class="text-center text-muted py-3">Not enrolled in any subjects yet.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="card">
                                    <div class="card-header">
                                        <h5>Recent Grades</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach (array_slice($grades, 0, 5) as $grade):
                                            $grade_color = getGradeColor($grade['grade']);
                                            ?>
                                            <div
                                                class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded">
                                                <div>
                                                    <h6 class="mb-0"><?php echo $grade['subject_name']; ?></h6>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-<?php echo $grade_color; ?>">
                                                        <?php echo $grade['grade']; ?>
                                                    </span>
                                                    <div>
                                                        <small
                                                            class="text-muted"><?php echo date('M j', strtotime($grade['graded_at'])); ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grades Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'grades' ? 'show active' : ''; ?>" id="grades">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2 class="mb-0">My Grades</h2>
                            <a href="print_grade.php" target="_blank" class="btn btn-primary"><i class="fas fa-print me-2"></i>Print Grade Slip</a>
                        </div>

                        <div class="mb-4">
                            <form method="GET" class="d-flex gap-2 position-relative">
                                <input type="hidden" name="tab" value="grades">
                                <div class="flex-grow-1 position-relative">
                                    <input type="text" name="search" id="liveSearchInput" class="form-control" placeholder="Search subject..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                                    <div id="searchResultsDropdown" class="list-group position-absolute shadow-lg d-none"></div>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                <?php if($search): ?><a href="students.php?tab=grades" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
                            </form>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h5>All Grades</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Subject</th>
                                                <th class="text-center">Prelim</th>
                                                <th class="text-center">Midterm</th>
                                                <th class="text-center">Prefinal</th>
                                                <th class="text-center">Finals</th>
                                                <th>Average</th>
                                                <th>Letter</th>
                                                <th>Teacher</th>
                                                <th>Remarks</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach ($grades as $grade):
                                                $letter_grade = calculateGradePoint($grade['grade']);
                                                $grade_color = getGradeColor($grade['grade']);
                                                ?>
                                                <tr>
                                                    <td><?php echo $i++; ?></td>
                                                    <td><?php echo $grade['subject_name']; ?></td>
                                                    <td class="text-center"><?php $p = $grade['prelim'] ?? 0; echo number_format($p, 1) . ($p > 0 ? ' <small class="text-muted">('.calculateGradePoint($p).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $m = $grade['midterm'] ?? 0; echo number_format($m, 1) . ($m > 0 ? ' <small class="text-muted">('.calculateGradePoint($m).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $pf = $grade['prefinal'] ?? 0; echo number_format($pf, 1) . ($pf > 0 ? ' <small class="text-muted">('.calculateGradePoint($pf).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $f = $grade['final'] ?? 0; echo number_format($f, 1) . ($f > 0 ? ' <small class="text-muted">('.calculateGradePoint($f).')</small>' : ''); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $grade_color; ?>">
                                                            <?php echo number_format($grade['grade'], 2); ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo $letter_grade; ?></strong></td>
                                                    <td><?php echo $grade['teacher_first'] . ' ' . $grade['teacher_last']; ?>
                                                    </td>
                                                    <td><?php echo $grade['remarks']; ?></td>
                                                    <td><?php echo date('M j, Y', strtotime($grade['graded_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'performance' ? 'show active' : ''; ?>" id="performance">
                        <h2 class="mb-4">Performance Analysis</h2>

                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="card text-center p-3 shadow-sm border-0"><small class="text-muted fw-bold">PRELIM AVG</small><h4 class="mb-0 text-primary" id="p_avg_card"><?php 
                                    $p_avg = $period_counts['prelim'] > 0 ? $period_stats['prelim'] / $period_counts['prelim'] : 0;
                                    echo number_format($p_avg, 1) . ($p_avg > 0 ? ' <small class="fs-6">('.calculateGradePoint($p_avg).')</small>' : ''); 
                                ?></h4></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-center p-3 shadow-sm border-0"><small class="text-muted fw-bold">MIDTERM AVG</small><h4 class="mb-0 text-primary" id="m_avg_card"><?php 
                                    $m_avg = $period_counts['midterm'] > 0 ? $period_stats['midterm'] / $period_counts['midterm'] : 0;
                                    echo number_format($m_avg, 1) . ($m_avg > 0 ? ' <small class="fs-6">('.calculateGradePoint($m_avg).')</small>' : ''); 
                                ?></h4></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-center p-3 shadow-sm border-0"><small class="text-muted fw-bold">PREFINAL AVG</small><h4 class="mb-0 text-primary" id="pf_avg_card"><?php 
                                    $pf_avg = $period_counts['prefinal'] > 0 ? $period_stats['prefinal'] / $period_counts['prefinal'] : 0;
                                    echo number_format($pf_avg, 1) . ($pf_avg > 0 ? ' <small class="fs-6">('.calculateGradePoint($pf_avg).')</small>' : ''); 
                                ?></h4></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="card text-center p-3 shadow-sm border-0"><small class="text-muted fw-bold">FINAL AVG</small><h4 class="mb-0 text-primary" id="f_avg_card"><?php 
                                    $f_avg = $period_counts['final'] > 0 ? $period_stats['final'] / $period_counts['final'] : 0;
                                    echo number_format($f_avg, 1) . ($f_avg > 0 ? ' <small class="fs-6">('.calculateGradePoint($f_avg).')</small>' : ''); 
                                ?></h4></div>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-xl-6 col-lg-12">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>Grade Trend by Subject</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($total_grades > 0): ?>
                                            <div style="position: relative; min-height: 400px;">
                                                <canvas id="gradeChart"></canvas>
                                            </div>
                                            <div id="gradeChartLegend" class="d-flex flex-wrap gap-3 justify-content-center mt-3"></div>
                                        <?php else: ?>
                                            <div class="text-center py-5 text-muted">No grade data available for analysis.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>Grade Distribution</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($total_grades > 0): ?>
                                            <div style="position: relative; height: 300px;">
                                                <canvas id="distributionChart"></canvas>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-5 text-muted small">Insufficient data.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>Grade Summary</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="text-center">
                                            <h1 class="display-4 text-<?php
                                            echo getGradeColor($average_grade);
                                            ?>"><?php echo number_format($average_grade, 1); ?></h1>
                                            <p class="lead">Overall Average</p>
                                            <h3><?php echo calculateGradePoint($average_grade); ?></h3>
                                        </div>
                                        <hr>
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <h5><?php echo $total_grades; ?></h5>
                                                <small>Total Records</small>
                                            </div>
                                            <div class="col-6">
                                                <h5><?php echo count(array_unique(array_column($grades, 'subject_id'))); ?>
                                                </h5>
                                                <small>Subjects</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Chatbot Floating Widget -->
    <div class="ai-chat-widget no-print">
        <div id="aiChatContainer">
            <div class="ai-chat-header">
                <span class="fw-bold"><i class="fas fa-robot me-2"></i> Syntax AI</span>
                <div class="d-flex gap-3">
                    <button class="btn btn-sm text-white p-1 border-0" onclick="toggleAIChat()" title="Minimize"><i class="fas fa-minus"></i></button>
                    <button class="btn btn-sm text-white p-1 border-0" onclick="toggleAIChat()" title="Close"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div id="aiChatMessages">
                <div class="chat-bubble ai shadow-sm">Greetings, <?php echo $_SESSION['first_name']; ?>! I am Syntax, your personal AI academic advisor. I've already reviewed your grades. How may I assist you today?</div>
            </div>
            <div class="px-3 pt-2 d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted fw-bold" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Suggestions</small>
                <button class="btn btn-link btn-sm p-0 text-decoration-none" onclick="toggleSuggestions()" title="Toggle Suggestions" style="color: #6366f1;">
                    <i class="fas fa-minus" id="suggestToggleIcon" style="font-size: 0.8rem;"></i>
                </button>
            </div>
            <div id="aiQuickActions" class="px-3 pb-2 d-flex flex-wrap gap-2">
                <button class="ai-suggestion-btn" onclick="sendAIMessage('Give me a performance recommendation')">Recommendations</button>
                <button class="ai-suggestion-btn" onclick="sendAIMessage('What is my overall status?')">Overall Status</button>
                <button class="ai-suggestion-btn" onclick="sendAIMessage('How can I improve?')">Improvement Tips</button>
                <button class="ai-suggestion-btn" onclick="sendAIMessage('Analyze my grade trends')">Grade Trends</button>
                <button class="ai-suggestion-btn" onclick="sendAIMessage('How should I approach my teachers for consultation?')">Consultation Advice</button>
            </div>
            <div class="p-3 border-top bg-white">
                <div class="input-group">
                    <input type="text" id="aiInput" class="form-control" placeholder="Ask about your performance..." onkeypress="if(event.key === 'Enter') sendAIMessage()">
                    <button class="btn btn-primary" onclick="sendAIMessage()"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>
        <button class="btn btn-primary rounded-circle shadow-lg" id="aiToggleBtn" onclick="toggleAIChat()" style="width: 60px; height: 60px;">
            <i class="fas fa-comment-dots fa-lg"></i>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function() {
            // Clean URL immediately after page load to ensure Ctrl+R goes back to Dashboard
            if (window.location.search || window.location.hash) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        })();

        document.addEventListener('DOMContentLoaded', function () {
            let gradeChart = null;
            let distChart = null;
            // Sidebar Toggle Logic

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

                    fetch(`students.php?ajax_search=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResultsDropdown.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(item => {
                                    const listItem = document.createElement('a');
                                    listItem.href = '#';
                                    listItem.className = 'list-group-item list-group-item-action py-2 border-bottom';
                                    listItem.innerHTML = `
                                        <div class="small fw-bold">${item.subject_name}</div>
                                    `;
                                    listItem.addEventListener('click', (e) => {
                                        e.preventDefault();
                                        liveSearchInput.value = item.subject_name;
                                        searchResultsDropdown.classList.add('d-none');
                                        liveSearchInput.form.submit();
                                    });
                                    searchResultsDropdown.appendChild(listItem);
                                });
                                searchResultsDropdown.classList.remove('d-none');
                            } else {
                                searchResultsDropdown.classList.add('d-none');
                            }
                        });
                });
                document.addEventListener('click', (e) => {
                    if (!liveSearchInput.contains(e.target) && !searchResultsDropdown.contains(e.target)) {
                        searchResultsDropdown.classList.add('d-none');
                    }
                });
            }

            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const toggle = document.getElementById('sidebarToggle');
            const overlay = document.getElementById('sidebarOverlay');

            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                sidebar.classList.toggle('active');
                if(overlay) overlay.classList.toggle('active');
                mainContent.classList.toggle('expanded');
                window.dispatchEvent(new Event('resize'));
                if (gradeChart) gradeChart.resize();
                if (distChart) distChart.resize();
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
            
            <?php if (isset($_SESSION['message'])): ?>
                document.getElementById('toastMsg').innerText = "<?php echo $_SESSION['message']; ?>";
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
                toastEl.classList.add('bg-<?php echo $_SESSION['message_type'] === "danger" ? "danger" : ($_SESSION['message_type'] === "success" ? "success" : "info"); ?>');
                toast.show();
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            <?php endif; ?>

            toggle.addEventListener('click', toggleSidebar);
            if(overlay) overlay.addEventListener('click', toggleSidebar);

            // Chart Configuration
            function calculateGradePointJS(grade) {
                if (grade <= 0) return "N/A";
                if (grade >= 90) return "1.0";
                if (grade >= 80) return "2.0";
                if (grade >= 70) return "3.0";
                if (grade >= 60) return "4.0";
                return "INC";
            }

            function updatePeriodicCards() {
                let sums = [0, 0, 0, 0];
                let counts = [0, 0, 0, 0];
                
                gradeChart.data.datasets.forEach((ds, i) => {
                    if (gradeChart.isDatasetVisible(i)) {
                        ds.data.forEach((val, idx) => {
                            if (val > 0) {
                                sums[idx] += val;
                                counts[idx]++;
                            }
                        });
                    }
                });

                const ids = ['p_avg_card', 'm_avg_card', 'pf_avg_card', 'f_avg_card'];
                ids.forEach((id, idx) => {
                    const avg = counts[idx] > 0 ? (sums[idx] / counts[idx]) : 0;
                    const el = document.getElementById(id);
                    if (el) {
                        if (avg > 0) {
                            el.innerHTML = avg.toFixed(1) + ' <small class="fs-6">(' + calculateGradePointJS(avg) + ')</small>';
                        } else {
                            el.innerHTML = '0.0';
                        }
                    }
                });
            }

            const chartCanvas = document.getElementById('gradeChart');
            const distCanvas = document.getElementById('distributionChart');

            const initPerformanceChart = () => {
                if (!chartCanvas || gradeChart) return;

                const ctx = chartCanvas.getContext('2d');
                gradeChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'],
                        datasets: <?php echo json_encode($chart_datasets); ?>
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 1200,
                            easing: 'easeInOutQuart',
                            delay: (context) => {
                                if (context.type === 'data' && context.mode === 'default') {
                                    return context.dataIndex * 300 + context.datasetIndex * 100;
                                }
                                return 0;
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                min: 0,
                                max: 100,
                                title: { display: true, text: 'Grade Score' }
                            },
                            x: {
                                title: { display: true, text: 'Academic Quarters' }
                            }
                        }
                    }
                });

                // Generate custom checkbox legend
                const legendContainer = document.getElementById('gradeChartLegend');
                if (legendContainer) {
                    legendContainer.innerHTML = '';
                    gradeChart.data.datasets.forEach((dataset, index) => {
                        const div = document.createElement('div');
                        div.className = 'form-check form-check-inline';
                        div.innerHTML = `
                        <input class="form-check-input" type="checkbox" id="ds_check_${index}" checked style="cursor: pointer;">
                        <label class="form-check-label small fw-bold d-flex align-items-center" for="ds_check_${index}" style="cursor: pointer; transition: all 0.3s ease;">
                            <span class="me-2" style="width: 12px; height: 12px; background-color: ${dataset.borderColor}; display: inline-block; border-radius: 2px;"></span>
                            ${dataset.label}
                        </label>
                        `;
                        div.querySelector('input').addEventListener('change', (e) => {
                        const label = div.querySelector('label');
                        label.style.opacity = e.target.checked ? '1' : '0.4';
                            gradeChart.setDatasetVisibility(index, e.target.checked);
                            gradeChart.update();
                            updatePeriodicCards();
                        });
                        legendContainer.appendChild(div);
                    });
                }
                gradeChart.resize(); // Initial resize after creation
                gradeChart.update();


                if (distCanvas) {
                    const distCtx = distCanvas.getContext('2d');
                    distChart = new Chart(distCtx, {
                        type: 'pie',
                        data: {
                            labels: <?php echo json_encode(array_keys($grade_distribution)); ?>,
                            datasets: [{
                                data: <?php echo json_encode(array_values($grade_distribution)); ?>,
                                backgroundColor: [
                                    '#10b981', // 1.0
                                    '#3b82f6', // 2.0
                                    '#f59e0b', // 3.0
                                    '#ef4444', // 4.0
                                    '#64748b', // INC
                                    '#1e293b'  // F
                                ],
                                borderWidth: 2,
                                borderColor: '#ffffff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15 } } }
                        }
                    });
                    distChart.resize(); // Initial resize after creation
                    distChart.update();
                }
            };

            // Initialize Bootstrap Tabs & Handle Chart Visibility
            const triggerTabList = document.querySelectorAll('a[data-bs-toggle="tab"]');
            triggerTabList.forEach(triggerEl => {
                triggerEl.addEventListener('shown.bs.tab', function (event) {
                    const target = event.target.getAttribute('href');
                    if (target === '#performance') {
                        initPerformanceChart(); // This will create charts if they are null
                        // Always resize and update after tab is shown, with a slight delay
                        setTimeout(() => {
                            if (gradeChart) {
                                gradeChart.resize();
                                gradeChart.update();
                            }
                            if (distChart) {
                                distChart.resize();
                                distChart.update();
                            }
                            // Trigger a global resize event to help other responsive elements
                            window.dispatchEvent(new Event('resize')); 
                        }, 100); // Small delay to allow tab transition to complete
                    }
                });
            });

            // Fix: Initialize performance chart if tab is active on page load
            const performancePane = document.getElementById('performance');
            if (performancePane && performancePane.classList.contains('active')) {
                setTimeout(initPerformanceChart, 200);
                // Also ensure resize/update if it was already active
                setTimeout(() => {
                    if (gradeChart) { gradeChart.resize(); gradeChart.update(); }
                    if (distChart) { distChart.resize(); distChart.update(); }
                    window.dispatchEvent(new Event('resize'));
                }, 300); // A bit longer delay for initial resize

            }
        });

        // AI Chatbot Logic
        function toggleAIChat() {
            const container = document.getElementById('aiChatContainer');
            const btn = document.getElementById('aiToggleBtn');
            if (container.style.display === 'none' || container.style.display === '') {
                container.style.display = 'flex';
                btn.style.display = 'none';
            } else {
                container.style.display = 'none';
                btn.style.display = 'block';
            }
        }

        function toggleSuggestions() {
            const container = document.getElementById('aiQuickActions');
            const icon = document.getElementById('suggestToggleIcon');
            container.classList.toggle('d-none');
            icon.classList.toggle('fa-minus');
            icon.classList.toggle('fa-plus');
        }

        function sendAIMessage(forcedQuery = null) {
            const input = document.getElementById('aiInput');
            const msgContainer = document.getElementById('aiChatMessages');
            const query = forcedQuery ? forcedQuery : input.value.trim();
            if (!query) return;

        // Auto-minimize suggestions when starting a conversation
        const quickActions = document.getElementById('aiQuickActions');
        const suggestIcon = document.getElementById('suggestToggleIcon');
        if (quickActions && !quickActions.classList.contains('d-none')) {
            quickActions.classList.add('d-none');
            if (suggestIcon) {
                suggestIcon.classList.replace('fa-minus', 'fa-plus');
            }
        }

            // Add User Bubble
            const userDiv = document.createElement('div');
            userDiv.className = 'chat-bubble user shadow-sm';
            userDiv.textContent = query;
            msgContainer.appendChild(userDiv);
            input.value = '';
            msgContainer.scrollTop = msgContainer.scrollHeight;

            // Simulate "Typing"
            const typingDiv = document.createElement('div');
            typingDiv.className = 'chat-bubble ai shadow-sm typing';
            typingDiv.textContent = '...';
            msgContainer.appendChild(typingDiv);

            fetch('assistant/syntax_ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: 'query=' + encodeURIComponent(query)
            })
            .then(res => res.json())
            .then(data => {
                msgContainer.removeChild(typingDiv);
                const aiDiv = document.createElement('div');
                aiDiv.className = 'chat-bubble ai shadow-sm';
                aiDiv.innerHTML = data.recommendation;
                msgContainer.appendChild(aiDiv);
                msgContainer.scrollTop = msgContainer.scrollHeight;
            })
            .catch(err => {
                console.error('AI Error:', err);
                msgContainer.removeChild(typingDiv);
                const errDiv = document.createElement('div');
                errDiv.className = 'chat-bubble ai shadow-sm text-danger';
                errDiv.textContent = 'I apologize, but I encountered a connection error. Please try again.';
                msgContainer.appendChild(errDiv);
            });
        }
    </script>
</body>
</html>