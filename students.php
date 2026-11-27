<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('student');
validateSession(); // Add this line for session security checks

// Handle AJAX to mark notification as read
if (isset($_POST['ajax_mark_read'])) {
    header('Content-Type: application/json');
    try {
        $notification_id = $_POST['notification_id'];
        $student_id = $_SESSION['user_id'];

        // Security check: make sure student owns the notification
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ? AND is_read = 0");
        $stmt->execute([$notification_id, $student_id]);

        // Recalculate unread count
        $unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
        $unread_count_stmt->execute([$student_id]);
        $new_count = $unread_count_stmt->fetchColumn();
        echo json_encode(['status' => 'success', 'new_count' => $new_count]);

    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') { // Base table or view not found
            echo json_encode(['status' => 'error', 'message' => 'Notifications table not found.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
        error_log("Mark as read error: " . $e->getMessage());
    }
    exit;
}

// Handle AJAX to mark all notifications as read
if (isset($_POST['ajax_mark_all_read'])) {
    header('Content-Type: application/json');
    try {
        $user_id = $_SESSION['user_id'];

        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);

        echo json_encode(['status' => 'success']);

    } catch (PDOException $e) {
        // Log error and respond
        error_log("Mark all as read error (student): " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
}

// Handle AJAX Search Suggestions (Subjects/Classrooms)
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT DISTINCT s.name as subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ? AND s.name LIKE ? LIMIT 5");
    $stmt->execute([$_SESSION['user_id'], "%$query%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Handle AJAX to fetch latest grades (for live updates without a page refresh)
if (isset($_GET['ajax_grades'])) {
    header('Content-Type: application/json');
    $student_id = (int)$_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("
            SELECT g.subject_id, s.name AS subject_name, g.prelim, g.midterm, g.prefinal, g.final, g.grade, g.remarks, g.graded_at,
                   u.first_name AS teacher_first, u.last_name AS teacher_last, u.profile_picture AS teacher_picture
            FROM grades g
            JOIN subjects s ON g.subject_id = s.id
            LEFT JOIN users u ON g.teacher_id = u.id
            WHERE g.student_id = ?
            ORDER BY g.graded_at DESC
        ");
        $stmt->execute([$student_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'subject_id'      => (int)$r['subject_id'],
                'subject_name'    => $r['subject_name'],
                'prelim'          => (float)$r['prelim'],
                'midterm'         => (float)$r['midterm'],
                'prefinal'        => (float)$r['prefinal'],
                'final'           => (float)$r['final'],
                'grade'           => (float)$r['grade'],
                'remarks'         => $r['remarks'],
                'teacher_first'   => $r['teacher_first'] ?? '',
                'teacher_last'    => $r['teacher_last'] ?? '',
                'teacher_picture' => $r['teacher_picture'] ?? '',
                'graded_at'       => $r['graded_at'] ?? '',
            ];
        }
        echo json_encode($out);
    } catch (Throwable $e) {
        echo json_encode([]);
    }
    exit;
}

$student_id = $_SESSION['user_id'];
$student_info = getStudentInfo($pdo, $student_id);

// Calculate current school year for display
$current_year = date('Y');
$current_month = date('n'); // Month as a number (1-12)

// A common assumption is that a new school year starts in August (month 8).
if ($current_month >= 8) {
    $start_year = $current_year;
    $end_year = $current_year + 1;
} else {
    $start_year = $current_year - 1;
    $end_year = $current_year;
}
$school_year_display = $start_year . ' - ' . $end_year;

// Fetch notifications
$notifications = [];
$unread_count = 0;
try {
    $notif_stmt = $pdo->prepare("
        SELECT n.*, u.first_name as sender_first, u.last_name as sender_last, u.profile_picture as sender_picture
        FROM notifications n
        JOIN users u ON n.sender_id = u.id
        WHERE n.recipient_id = ? 
        ORDER BY n.created_at DESC 
        LIMIT 10
    ");
    $notif_stmt->execute([$student_id]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
    $unread_count_stmt->execute([$student_id]);
    $unread_count = $unread_count_stmt->fetchColumn();
} catch (PDOException $e) {
    // Gracefully handle if notifications table doesn't exist yet
    if ($e->getCode() !== '42S02') error_log("Notification fetch error: " . $e->getMessage());
}

// Determine current active tab from query string or default to dashboard
$active_tab = $_GET['tab'] ?? 'dashboard';

// Handle student profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student_profile'])) {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: students.php?tab=profile");
        exit;
    }
    $student_id = $_SESSION['user_id'];
    // Store POST data in session to repopulate form on error
    $_SESSION['form_data'] = $_POST;
    // Sanitize and prepare data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $suffix = trim($_POST['suffix'] ?? '');
    $birth_date = trim($_POST['birth_date']);
    $age = (int)$_POST['age'];
    $sex = trim($_POST['sex']);
    $phone_number = trim($_POST['phone_number']);
    $course = trim($_POST['course']);
    $year_level = trim($_POST['year_level']);
    $home_address = trim($_POST['home_address']);
    $city = trim($_POST['city']);
    $province = trim($_POST['province']);
    $guardian_name = trim($_POST['guardian_name']);
    $guardian_occupation = trim($_POST['guardian_occupation']);
    $guardian_phone = trim($_POST['guardian_phone']);
    $guardian_address = trim($_POST['guardian_address']);
    $errors = [];

    // Handle file upload using the new reusable function
    $profile_picture_path = handleProfilePictureUpload($pdo, $_FILES['profile_picture'] ?? null, $student_id, $errors);
    
    // Validation (similar to registration)
    if (!preg_match('/^09[0-9]{9}$/', $phone_number)) $errors[] = "Your phone number must be 11 digits and start with 09.";
    if (!preg_match('/^09[0-9]{9}$/', $guardian_phone)) $errors[] = "Guardian's phone number must be 11 digits and start with 09.";

    if (empty($errors)) {
        try {
            $sql_parts = [
                "first_name = ?", "last_name = ?", "suffix = ?", "birth_date = ?", "age = ?", "sex = ?", "course = ?", "year_level = ?", "phone_number = ?", 
                "home_address = ?", "city = ?", "province = ?", 
                "guardian_name = ?", "guardian_occupation = ?", "guardian_phone = ?", "guardian_address = ?"
            ];
            $params = [
                $first_name, $last_name, $suffix, $birth_date, $age, $sex, $course, $year_level, $phone_number,
                $home_address, $city, $province,
                $guardian_name, $guardian_occupation, $guardian_phone, $guardian_address
            ];

            if ($profile_picture_path) {
                $sql_parts[] = "profile_picture = ?";
                $params[] = $profile_picture_path;
            }

            $params[] = $student_id;

            $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ? AND role = 'student'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $_SESSION['message'] = "Your profile has been updated successfully.";
            $_SESSION['message_type'] = "success";
            unset($_SESSION['form_data']); // Clear form data on success
        } catch (Exception $e) {
            // Security: Log detailed error, show generic message to user.
            error_log("Student Profile Update Error: " . $e->getMessage());
            $_SESSION['message'] = "An unexpected error occurred while updating your profile. Please try again.";
            $_SESSION['message_type'] = "danger";
        }
    } else {
        // If there are validation errors, store them in the session
        $_SESSION['errors'] = $errors;
    }

    header("Location: students.php?tab=profile");
    exit;
}

// CSRF Protection: Generate a token if one doesn't exist for the forms on this page.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to get value for profile form fields
function get_value($field_name, $db_data, $session_data) {
    // Prioritize session data (on validation error), then database data, then empty.
    return htmlspecialchars($session_data[$field_name] ?? $db_data[$field_name] ?? '');
}

// Retrieve form data and errors from session for repopulation on validation failure
$form_data = $_SESSION['form_data'] ?? [];
$form_errors = $_SESSION['errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['errors']);

// Determine if the edit form should be shown on page load (i.e., if there were validation errors)
$show_edit_form_on_load = !empty($form_errors);

// Get student's grades
$search = $_GET['search'] ?? '';
$sql = "
    SELECT g.*, s.name as subject_name,
           u.first_name as teacher_first, u.last_name as teacher_last, u.profile_picture as teacher_picture
    FROM grades g 
    JOIN subjects s ON g.subject_id = s.id 
    JOIN users u ON g.teacher_id = u.id 
    WHERE g.student_id = ?
";
$params = [$student_id];

if ($search) {
    $sql .= " AND s.name LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY g.graded_at DESC";

$grades_stmt = $pdo->prepare($sql);
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

// Get grade distribution for each period
$periods = ['prelim', 'midterm', 'prefinal', 'final'];
$period_distributions = [];
$grade_point_labels = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'INC'];

foreach ($periods as $p) {
    $period_distributions[$p] = array_fill_keys($grade_point_labels, 0);
}

// NEW: Initialize overall grade distribution for the dashboard
$grade_distribution = array_fill_keys($grade_point_labels, 0);

foreach ($grades as $grade) {
    // For overall distribution
    $point_overall = calculateGradePoint($grade['grade']);
    if (isset($grade_distribution[$point_overall])) {
        $grade_distribution[$point_overall]++;
    }

    // For periodic distributions
    foreach ($periods as $p) {
        // We check if the key exists to handle cases where a period might not be in the data yet
        if (array_key_exists($p, $grade)) {
            $point = calculateGradePoint($grade[$p]);
            if (isset($period_distributions[$p][$point])) {
                $period_distributions[$p][$point]++;
            }
        }
    }
}

// Prepare dynamic chart data: Each subject is a line over 4 quarters
$chart_datasets = [];
foreach ($grades as $g) {
    // FIX: Prevent duplicate subjects in the line chart.
    // If a subject is already in the datasets, skip it.
    $subject_exists = false;
    foreach ($chart_datasets as $dataset) {
        if ($dataset['label'] === htmlspecialchars($g['subject_name'])) {
            $subject_exists = true; break;
        }
    }
    if ($subject_exists) continue;
    // Generate deterministic colors based on subject name for consistency
    $hue = abs(crc32($g['subject_name'])) % 360;
    $chart_datasets[] = [
        'label' => htmlspecialchars($g['subject_name']),
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

// Unique subjects for the subject selector (Grades + Performance tabs).
$subject_options = [];
foreach ($grades as $g) {
    $sid = (int)$g['subject_id'];
    if ($sid <= 0 || isset($subject_options[$sid])) continue;
    $subject_options[$sid] = $g['subject_name'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="css/portal.css" rel="stylesheet">
    <?php outputThemeColors($pdo); ?>
<style>
.typing .dot{display:inline-block;width:8px;height:8px;margin:0 2px;background:#6c757d;border-radius:50%;animation:typingWave 1.4s infinite ease-in-out both;}
.typing .dot:nth-child(1){animation-delay:-0.32s;}
.typing .dot:nth-child(2){animation-delay:-0.16s;}
@keyframes typingWave{0%,80%,100%{transform:scale(0);}40%{transform:scale(1);}}
</style>
</head>
 
<body class="d-flex flex-column min-vh-100">
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
                    <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="Logo" class="sidebar-logo mb-2">
                    <h5>Student Portal</h5>
                    <div class="fw-bold"><?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?></div>
                    <small class="text-info"><?php echo htmlspecialchars(($student_info['course'] ?? 'N/A') . ' - ' . ($student_info['year_level'] ?? 'N/A')); ?></small>
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
                            <a class="nav-link dropdown-toggle <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" href="#" id="accountDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-2"></i>Account
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="accountDropdown">
                                <li><a class="dropdown-item <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" href="students.php?tab=profile"><i class="fas fa-user-edit me-2"></i>My Profile</a></li>
                            </ul>
                        </li>
                        <li class="nav-item mt-auto">
                            <a class="nav-link text-warning" href="logout.php" onclick="return confirm('Are you sure you want to logout?')">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

        <!-- Main Content -->
        <div class="main-content" id="main-content">
            <nav class="navbar navbar-light bg-light px-4 border-bottom">
                <div class="d-flex align-items-center">
                    <i class="fas fa-bars toggle-btn me-3" id="sidebarToggle"></i>
                    <span class="navbar-brand mb-0 h1">GradeView Portal</span>
                    <span class="badge bg-danger text-white ms-3" style="font-size:.85rem">BUILD D · subject: <span id="buildSubject">all</span></span>
                </div>

                <div class="dropdown">
                    <a href="#" class="text-secondary position-relative" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell fa-lg"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo $unread_count > 0 ? '' : 'd-none'; ?>" id="notif-count-badge">
                            <?php echo $unread_count; ?>
                            <span class="visually-hidden">unread messages</span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 notification-dropdown" aria-labelledby="notificationDropdown">
                        <li class="d-flex justify-content-between align-items-center px-3 py-2">
                            <span class="fw-bold">Notifications</span>
                            <?php if ($unread_count > 0): ?>
                            <a href="#" class="small text-decoration-none" id="markAllAsReadBtn">Mark all as read</a>
                            <?php endif; ?>
                        </li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <div id="notification-list">
                            <?php if (empty($notifications)): ?>
                                <li class="px-3 py-4 text-muted text-center small">You have no notifications.</li>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <li>
                                        <a class="dropdown-item text-wrap py-2 notification-item <?php echo $notif['is_read'] ? '' : 'bg-light'; ?>" href="#" data-id="<?php echo $notif['id']; ?>" onclick="markAsRead(event, this)">
                                            <p class="mb-1 small <?php echo $notif['is_read'] ? 'text-muted' : 'fw-bold'; ?>"><?php echo highlightNotificationKeywords($notif['message']); ?></p>
                                            <small class="text-xs text-muted">From: <?php echo htmlspecialchars($notif['sender_first'] . ' ' . $notif['sender_last']); ?> &bull; <?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></small>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li><a class="dropdown-item text-center small text-primary py-2 view-all-notifications" href="#">View All</a></li>
                    </ul>
                </div>
            </nav>

            <div class="px-4 py-4">
                <div class="tab-content">
                    <!-- Dashboard Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="row mb-2 mb-md-4">
                            <div class="col-md-4 mb-3">
                                <div class="stat-box grad-blue text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4 id="statTotalSubjects"><?php echo $total_grades; ?></h4>
                                                <p>Total Subjects</p>
                                            </div>
                                            <i class="fas fa-list-alt fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="stat-box grad-teal text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4 id="statOverallAverage"><?php echo number_format($average_grade, 2); ?></h4>
                                                <p>Overall Average</p>
                                            </div>
                                            <i class="fas fa-calculator fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="stat-box grad-red text-white">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4 id="statExcellent"><?php echo $grade_distribution['1.00']; ?></h4>
                                                <p>Excellent Grades (1.00)</p>
                                            </div>
                                            <i class="fas fa-star fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-start align-items-center mb-3">
                            <div class="p-2 bg-primary-subtle text-primary-emphasis rounded border border-primary-subtle">
                                <i class="fas fa-calendar-alt me-2"></i>
                                <span class="fw-bold">Current Academic Year: <?php echo $school_year_display; ?></span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-start align-items-center gap-2 small mb-4 p-2 bg-light rounded border">
                            <strong class="me-2">Grade Legend:</strong>
                            <span class="badge bg-success">Excellent (90+)</span>
                            <span class="badge bg-primary">Very Good (80-89)</span>
                            <span class="badge bg-info text-dark">Good (75-79)</span>
                            <span class="badge bg-danger">Failing (&lt; 75)</span>
                            <span class="badge bg-secondary">IP (In Progress)</span>
                        </div>

                        <!-- Academic Record and Recent Grades -->
                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>My Academic Record</h5>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive" style="max-height: 450px;">
                                            <table class="table table-hover table-sm mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="ps-3">Subject & Instructor</th>
                                                        <th class="text-center">Latest Period Grade</th>
                                                        <th class="text-center">Equivalent</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="academicRecordBody">
                                                    <?php
                                                    $unique_subjects = [];
                                                    foreach ($grades as $grade) {
                                                        $unique_subjects[$grade['subject_id']] = $grade;
                                                    }
                                                    if (empty($unique_subjects)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted py-4">No academic records found.</td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($unique_subjects as $grade):
                                                            // Determine the latest period with a grade to display
                                                            $latest_period_name = 'Final Average';
                                                            $latest_period_grade = $grade['grade'];

                                                            if (!empty($grade['final']) && $grade['final'] > 0) {
                                                                $latest_period_name = 'Final';
                                                                $latest_period_grade = $grade['final'];
                                                            } elseif (!empty($grade['prefinal']) && $grade['prefinal'] > 0) {
                                                                $latest_period_name = 'Prefinal';
                                                                $latest_period_grade = $grade['prefinal'];
                                                            } elseif (!empty($grade['midterm']) && $grade['midterm'] > 0) {
                                                                $latest_period_name = 'Midterm';
                                                                $latest_period_grade = $grade['midterm'];
                                                            } elseif (!empty($grade['prelim']) && $grade['prelim'] > 0) {
                                                                $latest_period_name = 'Prelim';
                                                                $latest_period_grade = $grade['prelim'];
                                                            }
                                                            
                                                            $grade_color = getGradeColor($latest_period_grade);
                                                            $letter_grade = calculateGradePoint($latest_period_grade);
                                                        ?>
                                                            <tr>
                                                                <td class="ps-3 align-middle">
                                                                    <div class="fw-bold"><?php echo htmlspecialchars($grade['subject_name']); ?></div>
                                                                    <div class="d-flex align-items-center text-muted small mt-1">
                                                                        <img src="<?php echo htmlspecialchars($grade['teacher_picture'] ?? 'image/default.png'); ?>" class="rounded-circle me-2 avatar-xs" onerror="this.onerror=null;this.src='image/default.png';">
                                                                        <span><?php echo htmlspecialchars($grade['teacher_first'] . ' ' . $grade['teacher_last']); ?></span>
                                                                    </div>
                                                                </td>
                                                                <td class="text-center align-middle">
                                                                    <span class="badge bg-<?php echo $grade_color; ?>"><?php echo htmlspecialchars(number_format($latest_period_grade, 2)); ?></span>
                                                                    <div class="small text-primary fw-bold mt-1"><?php echo $latest_period_name; ?></div>
                                                                </td>
                                                                <td class="text-center align-middle fw-bold"><?php echo $letter_grade; ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>Recent Grades</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach (array_slice($grades, 0, 5) as $grade): 
                                            // Determine the latest period with a grade
                                            $latest_period_name = 'Final Average';
                                            $latest_period_grade = $grade['grade'];

                                            if (!empty($grade['final']) && $grade['final'] > 0) {
                                                $latest_period_name = 'Final';
                                                $latest_period_grade = $grade['final'];
                                            } elseif (!empty($grade['prefinal']) && $grade['prefinal'] > 0) {
                                                $latest_period_name = 'Prefinal';
                                                $latest_period_grade = $grade['prefinal'];
                                            } elseif (!empty($grade['midterm']) && $grade['midterm'] > 0) {
                                                $latest_period_name = 'Midterm';
                                                $latest_period_grade = $grade['midterm'];
                                            } elseif (!empty($grade['prelim']) && $grade['prelim'] > 0) {
                                                $latest_period_name = 'Prelim';
                                                $latest_period_grade = $grade['prelim'];
                                            }
                                            
                                            $grade_color = getGradeColor($latest_period_grade);
                                            ?>
                                            <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded">
                                                <div><h6 class="mb-0"><?php echo htmlspecialchars($grade['subject_name']); ?></h6><small class="text-primary fw-bold"><?php echo $latest_period_name; ?> Grade</small></div>
                                                <div class="text-end"><span class="badge bg-<?php echo $grade_color; ?>"><?php echo htmlspecialchars(number_format($latest_period_grade, 2)); ?></span><div><small class="text-muted"><?php echo date('M j', strtotime($grade['graded_at'])); ?></small></div></div>
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

                        <div class="d-flex justify-content-start align-items-center gap-2 small mb-3 p-2 bg-light rounded border">
                            <strong class="me-2">Grade Legend:</strong>
                            <span class="badge bg-success">Excellent (90+)</span>
                            <span class="badge bg-primary">Very Good (80-89)</span>
                            <span class="badge bg-info text-dark">Good (75-79)</span>
                            <span class="badge bg-danger">Failing (&lt; 75)</span>
                            <span class="badge bg-secondary">IP (In Progress)</span>
                        </div>

                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <form method="GET" class="d-flex gap-2" id="studentSubjectSearchForm">
                                    <input type="hidden" name="tab" value="grades">
                                    <div class="flex-grow-1 position-relative">
                                        <input type="text" name="search" id="liveSearchInput" class="form-control" placeholder="Search for a subject..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                                        <div id="searchResultsDropdown" class="list-group position-absolute w-100 shadow-lg d-none"></div>
                                    </div>
                                    <button type="submit" id="studentSearchClearBtn" class="btn btn-primary" title="Search"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
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
                                                <th class="text-center">Final Period</th>
                                                <th>Final Average</th>
                                                <th class="text-center">Status</th>
                                                <th>Teacher</th>
                                                <th>Remarks</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody id="allGradesBody">
                                            <?php $i = 1; foreach ($grades as $grade):
                                                $letter_grade = calculateGradePoint($grade['grade']);
                                                $grade_color = getGradeColor($grade['grade']); 
                                                
                                                // Determine Passed/Failed status
                                                $final_grade_val = (float)($grade['grade'] ?? 0);
                                                $status_text = 'IP (In Progress)';
                                                $status_color = 'secondary';
                                                if ($final_grade_val >= 75) {
                                                    $status_text = 'Passed';
                                                    $status_color = 'success';
                                                } elseif ($final_grade_val > 0 && $final_grade_val < 75) {
                                                    $status_text = 'Failed';
                                                    $status_color = 'danger';
                                                }
                                                ?>
                                                <tr>
                                                    <td><?php echo $i++; ?></td>
                                                    <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                                    <td class="text-center"><?php $p = $grade['prelim'] ?? 0; echo number_format($p, 2) . ($p > 0 ? ' <small class="text-muted">('.calculateGradePoint($p).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $m = $grade['midterm'] ?? 0; echo number_format($m, 2) . ($m > 0 ? ' <small class="text-muted">('.calculateGradePoint($m).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $pf = $grade['prefinal'] ?? 0; echo number_format($pf, 2) . ($pf > 0 ? ' <small class="text-muted">('.calculateGradePoint($pf).')</small>' : ''); ?></td>
                                                    <td class="text-center"><?php $f = $grade['final'] ?? 0; echo number_format($f, 2) . ($f > 0 ? ' <small class="text-muted">('.calculateGradePoint($f).')</small>' : ''); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $grade_color; ?>">
                                                            <?php echo htmlspecialchars(number_format($grade['grade'], 2)); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-<?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                                    </td>
                                                <td class="d-flex align-items-center">
                                                    <img src="<?php echo htmlspecialchars($grade['teacher_picture'] ?? 'image/default.png'); ?>" class="rounded-circle me-2 avatar-sm" onerror="this.onerror=null;this.src='image/default.png';">
                                                    <?php echo htmlspecialchars($grade['teacher_first'] . ' ' . $grade['teacher_last']); ?>
                                                </td>
                                                    <td><?php echo htmlspecialchars($grade['remarks'] ?? ''); ?></td>
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
                        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                            <h2 class="mb-0">Performance Analysis</h2>
                            <span class="text-muted small" id="subjectFilterHint">Showing all subjects.</span>
                        </div>
 
                        <div class="row g-4">
                            <div class="col-12">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5>Grade Trend by Subject</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($total_grades > 0): ?>
                                            <div style="position: relative; min-height: 350px;">
                                                <canvas id="gradeChart"></canvas>
                                            </div>
                                            <div id="gradeChartLegend" class="d-flex flex-wrap gap-3 justify-content-center mt-3"></div>
                                        <?php else: ?>
                                            <div class="text-center py-5 text-muted">No grade data available for analysis.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php 
                            $period_titles = ['prelim' => 'Prelim', 'midterm' => 'Midterm', 'prefinal' => 'Prefinal', 'final' => 'Final'];
                            foreach ($periods as $p): 
                                $avg = $period_counts[$p] > 0 ? $period_stats[$p] / $period_counts[$p] : 0;
                            ?>
                            <div class="col-xl-3 col-md-6">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><?php echo $period_titles[$p]; ?> Summary</h5>
                                    </div>
                                    <div class="card-body d-flex flex-column">
                                        <div class="text-center mb-3">
                                            <h1 class="display-5 text-<?php echo getGradeColor($avg); ?>" id="periodAvg-<?php echo $p; ?>"><?php echo number_format($avg, 1); ?></h1>
                                            <p class="lead mb-0">Average Grade</p>
                                            <h3 id="periodPoint-<?php echo $p; ?>"><?php echo calculateGradePoint($avg); ?></h3>
                                        </div>
                                        <div class="mt-auto" style="position: relative; height: 250px;">
                                            <canvas id="<?php echo $p; ?>DistributionChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- My Profile Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'profile' ? 'show active' : ''; ?>" id="profile">
                        <!-- Profile View Container -->
                        <div id="profile-view-container" class="<?php echo $show_edit_form_on_load ? 'd-none' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h2 class="mb-0">My Profile</h2>
                                <button id="edit-profile-btn" class="btn btn-primary"><i class="fas fa-user-edit me-2"></i>Edit Profile</button>
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="row">
                                        <div class="col-lg-4 text-center border-end pe-lg-4 mb-4 mb-lg-0 d-flex flex-column align-items-center justify-content-center">
                                            <img src="<?php echo htmlspecialchars($student_info['profile_picture'] ?? 'image/default.png'); ?>" class="rounded-circle mb-3 avatar-lg" onerror="this.onerror=null;this.src='image/default.png';">
                                            <h4 class="mb-0 fw-bold"><?php echo htmlspecialchars(($student_info['first_name'] ?? '') . ' ' . ($student_info['last_name'] ?? '')); ?></h4>
                                            <p class="text-muted"><?php echo htmlspecialchars(($student_info['course'] ?? 'N/A') . ' - ' . ($student_info['year_level'] ?? 'N/A')); ?></p>
                                        </div>
                                        <div class="col-lg-8 ps-lg-4">
                                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-4"><p class="mb-1 small text-muted">Suffix</p><strong><?php echo htmlspecialchars($student_info['suffix'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-4"><p class="mb-1 small text-muted">Birth Date</p><strong><?php echo !empty($student_info['birth_date']) ? htmlspecialchars(date('F j, Y', strtotime($student_info['birth_date']))) : 'N/A'; ?></strong></div>
                                                <div class="col-md-4"><p class="mb-1 small text-muted">Age</p><strong><?php echo htmlspecialchars($student_info['age'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-4"><p class="mb-1 small text-muted">Sex</p><strong><?php echo htmlspecialchars($student_info['sex'] ?? 'N/A'); ?></strong></div>
                                            </div>

                                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-map-marker-alt me-2"></i>Contact & Address</h5>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6"><p class="mb-1 small text-muted">Email Address</p><strong><?php echo htmlspecialchars($student_info['email'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-6"><p class="mb-1 small text-muted">Phone Number</p><strong><?php echo htmlspecialchars($student_info['phone_number'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-12"><p class="mb-1 small text-muted">Full Address</p><strong><?php echo htmlspecialchars(implode(', ', array_filter([$student_info['home_address'] ?? null, $student_info['city'] ?? null, $student_info['province'] ?? null])) ?: 'N/A'); ?></strong></div>
                                            </div>

                                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-users me-2"></i>Guardian Information</h5>
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Full Name</p><strong><?php echo htmlspecialchars($student_info['guardian_name'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Phone</p><strong><?php echo htmlspecialchars($student_info['guardian_phone'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-md-12"><p class="mb-1 small text-muted">Guardian's Occupation</p><strong><?php echo htmlspecialchars($student_info['guardian_occupation'] ?? 'N/A'); ?></strong></div>
                                                <div class="col-12"><p class="mb-1 small text-muted">Guardian's Address</p><strong><?php echo htmlspecialchars($student_info['guardian_address'] ?? 'N/A'); ?></strong></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Profile Edit Container -->
                        <div id="profile-edit-container" class="<?php echo !$show_edit_form_on_load ? 'd-none' : ''; ?>">
                            <h2 class="mb-4">Edit My Profile</h2>
                            <div class="card border-0 shadow-sm">
                                <?php if (!empty($form_errors)): ?>
                                    <div class="alert alert-danger m-3">
                                        <h5 class="alert-heading">Please correct the following errors:</h5>
                                        <ul class="mb-0">
                                            <?php foreach ($form_errors as $error): ?>
                                                <li><?php echo htmlspecialchars($error); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body p-4">
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="update_student_profile" value="1">
                                        
                                        <div class="row">
                                            <div class="col-lg-8">
                                                <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-circle me-2"></i>Personal & Academic Information</h5>
                                                <div class="row g-3 mb-4">
                                                    <div class="col-md-4">
                                                        <label class="form-label small">First Name</label>
                                                        <input type="text" class="form-control" name="first_name" value="<?php echo get_value('first_name', $student_info, $form_data); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Last Name</label>
                                                        <input type="text" class="form-control" name="last_name" value="<?php echo get_value('last_name', $student_info, $form_data); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Suffix</label>
                                                        <input type="text" class="form-control" name="suffix" value="<?php echo get_value('suffix', $student_info, $form_data); ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Birth Date</label>
                                                        <input type="date" class="form-control" name="birth_date" id="val_bdate" value="<?php echo get_value('birth_date', $student_info, $form_data); ?>"
                                                            min="1900-01-01" max="<?php echo date('Y-12-31'); ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Age</label>
                                                        <input type="text" class="form-control bg-light" name="age" id="val_age" value="<?php echo get_value('age', $student_info, $form_data); ?>" placeholder="Auto-Filled" required readonly>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Sex</label>
                                                        <select class="form-select" name="sex" required>
                                                            <option value="">-- Select --</option>
                                                            <?php $sexes = ['Male', 'Female']; ?>
                                                            <?php foreach ($sexes as $s): ?>
                                                                <option value="<?php echo $s; ?>" <?php echo (get_value('sex', $student_info, $form_data) == $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small">Course</label>
                                                        <select class="form-select" name="course" required>
                                                            <option value="">-- Choose Course --</option>
                                                            <?php $courses = ['BSCS', 'BSIT', 'BSHM', 'BSBA', 'BSA']; ?>
                                                            <?php foreach ($courses as $c): ?>
                                                                <option value="<?php echo $c; ?>" <?php echo (get_value('course', $student_info, $form_data) == $c) ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small">Year Level</label>
                                                        <select class="form-select" name="year_level" required>
                                                            <option value="">-- Choose Year --</option>
                                                            <?php $years = ['1st Year', '2nd Year', '3rd Year', '4th Year']; ?>
                                                            <?php foreach ($years as $y): ?>
                                                                <option value="<?php echo $y; ?>" <?php echo (get_value('year_level', $student_info, $form_data) == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-4">
                                                <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-image me-2"></i>Profile Picture</h5>
                                                <div class="text-center">
                                                    <img src="<?php echo htmlspecialchars($student_info['profile_picture'] ?? 'image/default.png'); ?>" id="profilePicturePreview" class="rounded-circle mb-2 cropper-preview avatar-xl" onerror="this.onerror=null;this.src='image/default.png';">
                                                    <input type="file" class="form-control form-control-sm cropper-file-input" name="profile_picture" id="profilePictureInput" accept="image/*">
                                                    <input type="hidden" name="cropped_image_data" class="cropper-data">
                                                    <div class="form-text">Optional. Max 2MB. JPG, PNG, GIF.</div>
                                                </div>
                                            </div>
                                        </div>

                                        <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-map-marker-alt me-2"></i>Contact & Address</h5>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label small">Email Address</label>
                                                <input type="email" class="form-control bg-light" value="<?php echo htmlspecialchars($student_info['email']); ?>" readonly>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small">Phone Number</label>
                                                <input type="text" class="form-control" name="phone_number" value="<?php echo get_value('phone_number', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small">City</label>
                                                <input type="text" class="form-control" name="city" value="<?php echo get_value('city', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small">Province</label>
                                                <input type="text" class="form-control" name="province" value="<?php echo get_value('province', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small">Home Address (Brgy, Street)</label>
                                                <input type="text" class="form-control" name="home_address" value="<?php echo get_value('home_address', $student_info, $form_data); ?>" required>
                                            </div>
                                        </div>

                                        <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-users me-2"></i>Guardian Information</h5>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label small">Guardian's Full Name</label>
                                                <input type="text" class="form-control" name="guardian_name" value="<?php echo get_value('guardian_name', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small">Guardian's Phone</label>
                                                <input type="text" class="form-control" name="guardian_phone" value="<?php echo get_value('guardian_phone', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small">Guardian's Occupation</label>
                                                <input type="text" class="form-control" name="guardian_occupation" value="<?php echo get_value('guardian_occupation', $student_info, $form_data); ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small">Guardian's Address</label>
                                                <input type="text" class="form-control" name="guardian_address" value="<?php echo get_value('guardian_address', $student_info, $form_data); ?>" required>
                                            </div>
                                        </div>

                                        <div class="text-end">
                                            <button type="button" id="cancel-edit-btn" class="btn btn-secondary px-4"><i class="fas fa-times me-2"></i>Cancel</button>
                                            <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        // Mark notification as read via AJAX and open the "All Notifications" modal
        function markAsRead(event, element) {
            event.preventDefault();
            const notifId = element.dataset.id;

            const openNotificationsModal = () => {
                const viewAllLink = document.querySelector('.view-all-notifications');
                if (viewAllLink) {
                    viewAllLink.click();
                }
            };

            if (element.classList.contains('bg-light')) {
                fetch('students.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `ajax_mark_read=1&notification_id=${notifId}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        element.classList.remove('bg-light');
                        element.querySelector('p').classList.replace('fw-bold', 'text-muted');
                        const badge = document.getElementById('notif-count-badge');
                        badge.textContent = data.new_count;
                        if (data.new_count <= 0) badge.classList.add('d-none');
                    }
                    openNotificationsModal();
                }).catch(() => openNotificationsModal());
            } else {
                openNotificationsModal();
            }
        }

        // Mark all notifications as read
        const markAllReadBtn = document.getElementById('markAllAsReadBtn');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function(e) {
                e.preventDefault();
                fetch('students.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: 'ajax_mark_all_read=1'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelectorAll('.notification-item.bg-light').forEach(item => {
                            item.classList.remove('bg-light');
                            item.querySelector('p').classList.replace('fw-bold', 'text-muted');
                        });
                        document.getElementById('notif-count-badge').classList.add('d-none');
                        this.remove(); // Remove the "mark all" button
                    }
                });
            });
        }
    </script>
    <script>
        window.onerror = function (msg, src, line, col, err) {
            var el = document.getElementById('buildSubject');
            if (el) { el.textContent = 'ERR: ' + msg + ' @' + line + ':' + col; }
        };
        document.addEventListener('DOMContentLoaded', function () {
            let gradeChart = null;
            let periodCharts = {};
            // Latest grades, kept in sync by the polling module below so charts built
            // later (e.g. when the Performance tab is first opened) use fresh data.
            let latestGradeRows = <?php
                $seedRows = [];
                foreach ($grades as $g) {
                    $seedRows[] = [
                        'subject_id'   => (int)$g['subject_id'],
                        'subject_name' => $g['subject_name'],
                        'prelim'       => (float)$g['prelim'],
                        'midterm'      => (float)$g['midterm'],
                        'prefinal'     => (float)$g['prefinal'],
                        'final'        => (float)$g['final'],
                        'grade'        => (float)$g['grade'],
                    ];
                }
                echo json_encode($seedRows);
            ?>;

            // --- MODULE: SearchClearButton ---
            const SearchClearButton = {
                init(formId, inputId, buttonId, clearUrl) {
                    const form = document.getElementById(formId);
                    if (!form) return;

                    const input = document.getElementById(inputId);
                    const button = document.getElementById(buttonId);

                    if (!input || !button) return;

                    const updateButton = () => {
                        const hasSearch = input.value.trim() !== '';

                        if (hasSearch) {
                            button.type = 'button';
                            button.className = 'btn btn-secondary';
                            button.title = 'Clear Search';
                            button.innerHTML = '<i class="fas fa-times"></i>';
                            button.onclick = (e) => {
                                e.preventDefault();
                                window.location.href = clearUrl;
                            };
                        } else {
                            button.type = 'submit';
                            button.className = 'btn btn-primary';
                            button.title = 'Search';
                            button.innerHTML = '<i class="fas fa-search"></i>';
                            button.onclick = null;
                        }
                    };
                    input.addEventListener('input', updateButton);
                    updateButton(); // Set initial state
                }
            };
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

            let sidebarPinned = false;
            let sidebarCloseTimer = null;

            function applySidebar(expanded) {
                sidebar.classList.toggle('collapsed', !expanded);
                sidebar.classList.toggle('active', expanded);
                if (overlay) overlay.classList.toggle('active', expanded);
                mainContent.classList.toggle('expanded', !expanded);
                window.dispatchEvent(new Event('resize'));
                if (gradeChart) gradeChart.resize();
            }

            function toggleSidebar() {
                applySidebar(sidebar.classList.contains('collapsed'));
            }

            function cancelSidebarClose() {
                if (sidebarCloseTimer) { clearTimeout(sidebarCloseTimer); sidebarCloseTimer = null; }
            }

            // Hover the menu button to open the sidebar on desktop.
            toggle.addEventListener('mouseenter', () => {
                cancelSidebarClose();
                if (window.innerWidth > 768 && sidebar.classList.contains('collapsed')) {
                    applySidebar(true);
                }
            });

            // Auto-minimize again when the pointer leaves the sidebar (desktop, unless pinned).
            sidebar.addEventListener('mouseenter', cancelSidebarClose);
            sidebar.addEventListener('mouseleave', () => {
                if (window.innerWidth <= 768 || sidebarPinned) return;
                cancelSidebarClose();
                sidebarCloseTimer = setTimeout(() => {
                    if (!sidebarPinned && !sidebar.matches(':hover')) applySidebar(false);
                }, 350);
            });

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

            // Click: pin open / unpin close on desktop, plain toggle on mobile.
            toggle.addEventListener('click', () => {
                cancelSidebarClose();
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                    return;
                }
                sidebarPinned = !sidebarPinned;
                applySidebar(sidebarPinned);
            });
            if (overlay) overlay.addEventListener('click', toggleSidebar);

            // Auto-minimize the sidebar on desktop page load for more viewing area.
            if (window.innerWidth > 768 && !sidebar.classList.contains('collapsed')) {
                toggleSidebar();
            }

            // Chart Configuration
            function calculateGradePointJS(grade) {
                grade = parseFloat(grade);
                if (grade <= 0) return 'IP';
                if (grade >= 96) return '1.00';
                if (grade >= 91) return '1.25';
                if (grade >= 86) return '1.50';
                if (grade >= 81) return '1.75';
                if (grade >= 75) return '2.00';
                if (grade >= 69) return '2.25';
                if (grade >= 63) return '2.50';
                if (grade >= 57) return '2.75';
                if (grade >= 50) return '3.00';
                return '5.00';
            }

            const GRADE_POINT_COLORS = {
                '1.00': '#10b981', '1.25': '#10b981',
                '1.50': '#2563eb', '1.75': '#2563eb',
                '2.00': '#3b82f6', '2.25': '#3b82f6',
                '2.50': '#f59e0b', '2.75': '#f59e0b',
                '3.00': '#ef4444',
                '5.00': '#1e293b',
                'IP': '#64748b'
            };

            // Shared plugin that paints the score + grade point in the middle of a gauge.
            // It reads from the chart instance so it stays correct after in-place updates.
            const gxCenterTextPlugin = {
                id: 'gxCenterText',
                afterDraw: function (chart) {
                    if (!chart.$centerTop) return;
                    const ctx2 = chart.ctx;
                    const meta = chart.getDatasetMeta(0);
                    if (!meta || !meta.data || !meta.data[0]) return;
                    const arc = meta.data[0];
                    const x = arc.x;
                    const y = arc.y;
                    ctx2.save();
                    ctx2.textAlign = 'center';
                    ctx2.textBaseline = 'middle';
                    ctx2.fillStyle = '#212529';
                    ctx2.font = '700 20px Inter, sans-serif';
                    ctx2.fillText(chart.$centerTop, x, y - (chart.$centerBottom ? 8 : 0));
                    if (chart.$centerBottom) {
                        ctx2.font = '600 13px Inter, sans-serif';
                        ctx2.fillStyle = chart.$centerColor || '#212529';
                        ctx2.fillText(chart.$centerBottom, x, y + 14);
                    }
                    ctx2.restore();
                }
            };

            // Create-or-update a period chart on the given canvas. Using getChart() avoids the
            // "Canvas is already in use" error that silently left old charts on screen.
            function renderPeriodChart(canvasId, cfg) {
                const canvas = document.getElementById(canvasId);
                if (!canvas) return null;

                // Clear any stale placeholder message.
                const oldPh = canvas.parentElement.querySelector('.gx-chart-placeholder');
                if (oldPh) oldPh.remove();
                canvas.style.display = '';

                // Empty distribution -> show a placeholder and hide the canvas.
                if (cfg.empty) {
                    const existing = (typeof Chart.getChart === 'function') ? Chart.getChart(canvas) : null;
                    if (existing) { existing.destroy(); }
                    canvas.style.display = 'none';
                    const p = document.createElement('p');
                    p.className = 'text-center text-muted py-5 small gx-chart-placeholder';
                    p.innerText = 'No grades for this period.';
                    canvas.parentElement.appendChild(p);
                    return null;
                }

                const borderWidth = (cfg.borderWidth == null) ? 2 : cfg.borderWidth;

                // Always destroy any existing chart bound to this canvas, then create fresh.
                // Destroying first (looked up by canvas) prevents "Canvas is already in use".
                const existing = (typeof Chart.getChart === 'function') ? Chart.getChart(canvas) : null;
                if (existing) { existing.destroy(); }

                const chart = new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: cfg.labels,
                        datasets: [{
                            data: cfg.data,
                            backgroundColor: cfg.bg,
                            borderColor: cfg.borderColor || '#ffffff',
                            borderWidth: borderWidth
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        cutout: cfg.cutout,
                        rotation: cfg.rotation,
                        circumference: cfg.circumference,
                        plugins: {
                            legend: { display: cfg.legend },
                            tooltip: cfg.tooltip || {}
                        }
                    },
                    plugins: [gxCenterTextPlugin]
                });

                chart.$centerTop = cfg.centerTop || '';
                chart.$centerBottom = cfg.centerBottom || '';
                chart.$centerColor = cfg.centerColor || '#212529';
                canvas.setAttribute('data-period', cfg.periodTitle || '');
                chart.update();
                return chart;
            }

            function gradeColorHex(g) {
                g = parseFloat(g);
                if (isNaN(g) || g <= 0) return '#6c757d';
                if (g >= 90) return '#10b981';
                if (g >= 80) return '#2563eb';
                if (g >= 75) return '#0ea5e9';
                return '#ef4444';
            }

            const chartCanvas = document.getElementById('gradeChart');

            // Currently selected subject ids. `null` = All Subjects; a Set = explicit subset;
            // an empty Set = none selected. Shared by both tabs and persisted in
            // sessionStorage because the sidebar tabs are full-page reloads.
            const ALL_SUBJECTS = <?php
                $allSubj = [];
                foreach ($subject_options as $sid => $sname) { $allSubj[] = ['id' => (string)$sid, 'name' => (string)$sname]; }
                echo json_encode($allSubj);
            ?>;
            const ALL_SUBJECT_IDS = ALL_SUBJECTS.map(function (s) { return s.id; });
            const SUBJECT_NAME_MAP = {};
            ALL_SUBJECTS.forEach(function (s) { SUBJECT_NAME_MAP[s.id] = s.name; });

            let selectedSubjects = null; // null = all; Set = subset; empty Set = none
            try {
                const saved = sessionStorage.getItem('gv_selected_subjects');
                if (saved !== null && saved !== '') {
                    if (saved === 'all') {
                        selectedSubjects = null;
                    } else {
                        const arr = JSON.parse(saved);
                        if (Array.isArray(arr)) {
                            const uniq = Array.from(new Set(arr.map(String)));
                            if (uniq.length === 0) selectedSubjects = new Set();
                            else if (uniq.length === ALL_SUBJECT_IDS.length) selectedSubjects = null;
                            else selectedSubjects = new Set(uniq);
                        }
                    }
                }
            } catch (e) { selectedSubjects = null; }

            // Filter helper shared by the table, stats, and charts.
            function filterBySelection(rows) {
                if (selectedSubjects === null) return rows;
                if (selectedSubjects.size === 0) return [];
                return rows.filter(function (g) { return selectedSubjects.has(String(g.subject_id)); });
            }

            function getFilteredRows() {
                return filterBySelection(latestGradeRows);
            }

            const initPerformanceChart = () => {
                if (!chartCanvas) return;

                const rows = getFilteredRows();

                // Deterministic color per subject name (shared by the line datasets and the legend).
                const subjectColor = (name) => {
                    var str = String(name || '');
                    var h = 0;
                    for (var i = 0; i < str.length; i++) { h = (h * 31 + str.charCodeAt(i)) & 0xffffffff; }
                    return 'hsl(' + (Math.abs(h) % 360) + ', 70%, 45%)';
                };

                const buildDatasets = (rows) => {
                    const out = [];
                    const seen = {};
                    rows.forEach((g) => {
                        const label = g.subject_name || ('Subject ' + (g.subject_id || ''));
                        if (seen[label]) return;
                        seen[label] = true;
                        out.push({
                            label: label,
                            data: [g.prelim, g.midterm, g.prefinal, g.final].map(x => parseFloat(x) || 0),
                            borderColor: subjectColor(g.subject_name),
                            backgroundColor: 'transparent',
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            fill: false
                        });
                    });
                    return out;
                };

                // Line chart: create once, then update in place.
                const datasets = buildDatasets(rows);
                if (gradeChart) {
                    gradeChart.data.datasets = datasets;
                    gradeChart.update();
                } else {
                    const ctx = chartCanvas.getContext('2d');
                    gradeChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'],
                            datasets: datasets
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
                }

                // Generate custom checkbox legend from every subject so each stays toggleable.
                // Checking/unchecking drives the shared subject filter (table, stats, all charts).
                const legendContainer = document.getElementById('gradeChartLegend');
                if (legendContainer) {
                    legendContainer.innerHTML = '';
                    ALL_SUBJECTS.forEach((subj) => {
                        const sid = String(subj.id);
                        const isSel = (selectedSubjects === null) || selectedSubjects.has(sid);
                        const cbId = 'ds_check_' + sid;
                        const color = subjectColor(subj.name);
                        const div = document.createElement('div');
                        div.className = 'form-check form-check-inline';
                        div.innerHTML = `
                        <input class="form-check-input" type="checkbox" id="${cbId}" data-subject-id="${sid}" ${isSel ? 'checked' : ''} style="cursor: pointer;">
                        <label class="form-check-label small fw-bold d-flex align-items-center" for="${cbId}" style="cursor: pointer; transition: all 0.3s ease; opacity: ${isSel ? '1' : '0.4'};">
                            <span class="me-2" style="width: 12px; height: 12px; background-color: ${color}; display: inline-block; border-radius: 2px;"></span>
                            ${escapeHtmlSafe(subj.name)}
                        </label>
                        `;
                        div.querySelector('input').addEventListener('change', function () {
                            const checked = [];
                            legendContainer.querySelectorAll('input[type="checkbox"]').forEach(function (el) {
                                if (el.checked) checked.push(String(el.dataset.subjectId));
                            });
                            if (checked.length === 0) selectedSubjects = new Set();
                            else if (checked.length === ALL_SUBJECT_IDS.length) selectedSubjects = null;
                            else selectedSubjects = new Set(checked);
                            persistSelection();
                            syncMultiselectUI();
                            initPerformanceChart();
                        });
                        legendContainer.appendChild(div);
                    });
                }

                const gradePointLabels = <?php echo json_encode($grade_point_labels); ?>;
                const periods = ['prelim', 'midterm', 'prefinal', 'final'];
                const periodTitles = { prelim: 'Prelim', midterm: 'Midterm', prefinal: 'Prefinal', final: 'Final' };

                // Compute per-period grade-point distributions + sums from the filtered rows.
                const perDist = { prelim: {}, midterm: {}, prefinal: {}, final: {} };
                periods.forEach(p => gradePointLabels.forEach(l => perDist[p][l] = 0));
                const periodSums = { prelim: 0, midterm: 0, prefinal: 0, final: 0 };
                const periodCounts = { prelim: 0, midterm: 0, prefinal: 0, final: 0 };
                rows.forEach((g) => {
                    periods.forEach(p => {
                        const v = parseFloat(g[p]);
                        if (!isNaN(v) && v > 0) {
                            periodSums[p] += v;
                            periodCounts[p]++;
                            const pt = computeGradePointJS(v);
                            if (perDist[p].hasOwnProperty(pt)) perDist[p][pt]++;
                        }
                    });
                });

                // Update the period summary numbers (average + grade point) for the scope.
                periods.forEach(p => {
                    const avg = periodCounts[p] > 0 ? periodSums[p] / periodCounts[p] : 0;
                    const avgEl = document.getElementById('periodAvg-' + p);
                    const ptEl = document.getElementById('periodPoint-' + p);
                    if (avgEl) {
                        avgEl.textContent = avg.toFixed(1);
                        avgEl.className = 'display-5 text-' + gradeColorClass(avg);
                    }
                    if (ptEl) ptEl.textContent = computeGradePointJS(avg);
                });

                // Single selected subject -> gauge each period's actual teacher grade.
                // All Subjects -> keep the grade-point distribution pies.
                const singleSubject = (selectedSubjects !== null && selectedSubjects.size === 1 && rows.length === 1);
                const g0 = singleSubject ? rows[0] : null;

                periods.forEach(p => {
                    const canvasId = p + 'DistributionChart';
                    if (singleSubject) {
                        const val = parseFloat(g0[p]);
                        const has = !isNaN(val) && val > 0;
                        const pct = has ? Math.max(0, Math.min(100, val)) : 0;
                        const color = gradeColorHex(val);
                        periodCharts[p] = renderPeriodChart(canvasId, {
                            labels: ['Score', 'Remaining'],
                            data: [pct, Math.max(0, 100 - pct)],
                            bg: [color, '#e9ecef'],
                            borderColor: [color, '#e9ecef'],
                            borderWidth: 0,
                            cutout: '70%',
                            rotation: -90,
                            circumference: 360,
                            legend: false,
                            periodTitle: periodTitles[p],
                            centerTop: has ? val.toFixed(2) : 'N/A',
                            centerBottom: has ? computeGradePointJS(val) : '',
                            centerColor: color,
                            tooltip: {
                                callbacks: {
                                    title: function () { return periodTitles[p]; },
                                    label: function (ctx) {
                                        if (ctx.dataIndex === 0) return 'Grade: ' + (has ? val.toFixed(2) : 'N/A');
                                        return null;
                                    }
                                }
                            }
                        });
                    } else {
                        const data = gradePointLabels.map(l => perDist[p][l]);
                        const total = data.reduce((a, b) => a + b, 0);
                        const bg = gradePointLabels.map(l => GRADE_POINT_COLORS[l] || '#cccccc');
                        periodCharts[p] = renderPeriodChart(canvasId, {
                            labels: gradePointLabels,
                            data: data,
                            bg: bg,
                            borderColor: gradePointLabels.map(() => '#ffffff'),
                            borderWidth: 2,
                            cutout: '50%',
                            rotation: 0,
                            circumference: 360,
                            legend: true,
                            periodTitle: periodTitles[p],
                            empty: total === 0
                        });
                    }
                });
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
                            Object.values(periodCharts).forEach(chart => {
                                if(chart) { chart.resize(); chart.update(); }
                            });
                            // Trigger a global resize event to help other responsive elements
                            window.dispatchEvent(new Event('resize')); 
                        }, 100); // Small delay to allow tab transition to complete
                    }
                });
            });

            // Fix: Initialize performance chart if tab is active on page load
            const performancePane = document.getElementById('performance');
            if (performancePane && performancePane.classList.contains('active')) {
                initPerformanceChart();
                // Also ensure resize/update if it was already active
                setTimeout(() => {
                    if (gradeChart) { gradeChart.resize(); gradeChart.update(); }
                    Object.values(periodCharts).forEach(chart => {
                        if(chart) { chart.resize(); chart.update(); }
                    });
                    window.dispatchEvent(new Event('resize'));
                }, 150); // A bit longer delay for initial resize

            }

            // Profile View/Edit Toggle Logic
            const profileView = document.getElementById('profile-view-container');
            const profileEdit = document.getElementById('profile-edit-container');
            const editBtn = document.getElementById('edit-profile-btn');
            const cancelBtn = document.getElementById('cancel-edit-btn');

            if (editBtn && cancelBtn && profileView && profileEdit) {
                editBtn.addEventListener('click', () => {
                    profileView.classList.add('d-none');
                    profileEdit.classList.remove('d-none');
                });

                cancelBtn.addEventListener('click', () => {
                    profileEdit.classList.add('d-none');
                    profileView.classList.remove('d-none');
                    const errorAlert = profileEdit.querySelector('.alert-danger');
                    if (errorAlert) errorAlert.style.display = 'none';
                });

            }

            // Initialize search/clear button if on the grades tab
            const gradesPane = document.getElementById('grades');
            if (gradesPane && gradesPane.classList.contains('active')) {
                SearchClearButton.init('studentSubjectSearchForm', 'liveSearchInput', 'studentSearchClearBtn', 'students.php?tab=grades');
            }

            // Auto-calculate Age based on Birth Date on profile page
            const bdateInput = document.getElementById('val_bdate');
            if (bdateInput) {
                bdateInput.addEventListener('change', function() {
                    const birthDate = new Date(this.value);
                    const today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const m = today.getMonth() - birthDate.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
                    document.getElementById('val_age').value = age;
                }); 
            }
            // The global cropper script in footer.php now handles profile picture selection and preview.

            // --- Live grade polling: refresh tables, stats and charts without a manual reload ---
            // Re-fetches grades from ajax_grades and rebuilds the DOM in place so a teacher's
            // recompute/import is reflected here automatically.
            const GRADE_POLL_MS = 15000;
            const gradePointLabels = <?php echo json_encode($grade_point_labels); ?>;
            let lastGradesSignature = '';

            function computeGradePointJS(grade) {
                grade = parseFloat(grade);
                if (grade <= 0 || isNaN(grade)) return 'IP';
                return calculateGradePointJS(grade);
            }

            function gradeColorClass(g) {
                g = parseFloat(g);
                if (g <= 0) return 'secondary';
                if (g >= 90) return 'success';
                if (g >= 80) return 'primary';
                if (g >= 75) return 'info';
                return 'danger';
            }

            function periodGradeCell(val) {
                val = parseFloat(val);
                if (isNaN(val) || val <= 0) return '—';
                return val.toFixed(2) + ' <small class="text-muted">(' + computeGradePointJS(val) + ')</small>';
            }

            function latestPeriod(g) {
                if (g.final > 0) return { name: 'Final', grade: g.final };
                if (g.prefinal > 0) return { name: 'Prefinal', grade: g.prefinal };
                if (g.midterm > 0) return { name: 'Midterm', grade: g.midterm };
                if (g.prelim > 0) return { name: 'Prelim', grade: g.prelim };
                return { name: 'Final Average', grade: g.grade };
            }

            function rebuildAllGradesTable(rows) {
                const tbody = document.getElementById('allGradesBody');
                if (!tbody) return;
                const visible = rows;
                if (!visible.length) {
                    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">No grades yet.</td></tr>';
                    return;
                }
                let html = '';
                visible.forEach((g, idx) => {
                    const gp = parseFloat(g.grade) || 0;
                    const status = gp >= 75 ? ['Passed', 'success'] : (gp > 0 ? ['Failed', 'danger'] : ['IP (In Progress)', 'secondary']);
                    html += '<tr>'
                        + '<td>' + (idx + 1) + '</td>'
                        + '<td>' + escapeHtmlSafe(g.subject_name) + '</td>'
                        + '<td class="text-center">' + periodGradeCell(g.prelim) + '</td>'
                        + '<td class="text-center">' + periodGradeCell(g.midterm) + '</td>'
                        + '<td class="text-center">' + periodGradeCell(g.prefinal) + '</td>'
                        + '<td class="text-center">' + periodGradeCell(g.final) + '</td>'
                        + '<td><span class="badge bg-' + gradeColorClass(gp) + '">' + gp.toFixed(2) + '</span></td>'
                        + '<td class="text-center"><span class="badge bg-' + status[1] + '">' + status[0] + '</span></td>'
                        + '<td><img src="' + escapeHtmlSafe(g.teacher_picture || 'image/default.png') + '" class="rounded-circle me-2 avatar-sm" onerror="this.onerror=null;this.src=\'image/default.png\';">'
                        + escapeHtmlSafe(((g.teacher_first || '') + ' ' + (g.teacher_last || '')).trim()) + '</td>'
                        + '<td>' + escapeHtmlSafe(g.remarks || '') + '</td>'
                        + '<td>' + formatGradeDate(g.graded_at) + '</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;
            }

            function rebuildAcademicRecord(rows) {
                const tbody = document.getElementById('academicRecordBody');
                if (!tbody) return;
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">No academic records found.</td></tr>';
                    return;
                }
                const seen = {};
                let html = '';
                rows.forEach((g) => {
                    if (seen[g.subject_id]) return;
                    seen[g.subject_id] = true;
                    const lp = latestPeriod(g);
                    const cls = gradeColorClass(lp.grade);
                    html += '<tr>'
                        + '<td class="ps-3 align-middle"><div class="fw-bold">' + escapeHtmlSafe(g.subject_name) + '</div></td>'
                        + '<td class="text-center align-middle"><span class="badge bg-' + cls + '">' + (parseFloat(lp.grade) || 0).toFixed(2) + '</span>'
                        + '<div class="small text-primary fw-bold mt-1">' + lp.name + '</div></td>'
                        + '<td class="text-center align-middle fw-bold">' + computeGradePointJS(lp.grade) + '</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;
            }

            function escapeHtmlSafe(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function formatGradeDate(s) {
                if (!s) return '—';
                var d = new Date(String(s).replace(' ', 'T'));
                if (isNaN(d.getTime())) return '—';
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
            }

            function rebuildStats(rows) {
                const visible = rows;
                const total = visible.length;
                let sum = 0, excellent = 0;
                visible.forEach((g) => {
                    const gp = parseFloat(g.grade) || 0;
                    sum += gp;
                    if (computeGradePointJS(gp) === '1.00') excellent++;
                });
                const avg = total > 0 ? sum / total : 0;

                const totalEl = document.getElementById('statTotalSubjects');
                const avgEl = document.getElementById('statOverallAverage');
                const excEl = document.getElementById('statExcellent');
                if (totalEl) totalEl.textContent = total;
                if (avgEl) avgEl.textContent = avg.toFixed(2);
                if (excEl) excEl.textContent = excellent;
            }

            // Persist the current selection across full-page tab reloads.
            function persistSelection() {
                try {
                    if (selectedSubjects === null) sessionStorage.setItem('gv_selected_subjects', 'all');
                    else sessionStorage.setItem('gv_selected_subjects', JSON.stringify(Array.from(selectedSubjects)));
                } catch (e) { /* ignore */ }
            }

            // Reflect selectedSubjects into the hint text and build marker.
            function syncMultiselectUI() {
                const isAll = selectedSubjects === null;
                const n = isAll ? ALL_SUBJECT_IDS.length : selectedSubjects.size;
                let label = 'All Subjects';
                if (!isAll) {
                    if (n === 0) label = 'None';
                    else if (n === 1) { const id = Array.from(selectedSubjects)[0]; label = SUBJECT_NAME_MAP[id] || '1 subject'; }
                    else label = n + ' subjects';
                }
                let hint = 'Showing all subjects.';
                if (!isAll) {
                    if (n === 0) hint = 'No subjects selected.';
                    else if (n === 1) hint = 'Showing: ' + label;
                    else hint = 'Showing ' + n + ' subjects.';
                }
                const hintEl = document.getElementById('subjectFilterHint');
                if (hintEl) hintEl.textContent = hint;
                const bs = document.getElementById('buildSubject');
                if (bs) bs.textContent = isAll ? 'all' : (n + ' subject' + (n === 1 ? '' : 's'));
            }

            async function pollGrades() {
                try {
                    const resp = await fetch('students.php?ajax_grades=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!resp.ok) return;
                    const rows = await resp.json();
                    if (!Array.isArray(rows)) return;
                    const sig = JSON.stringify(rows);
                    if (sig === lastGradesSignature) return;
                    lastGradesSignature = sig;
                    latestGradeRows = rows;
                    rebuildAllGradesTable(rows);
                    rebuildAcademicRecord(rows);
                    rebuildStats(rows);
                    // Rebuild charts from fresh data (no-op if the Performance tab was never opened).
                    initPerformanceChart();
                } catch (e) { /* silent: keep last good view */ }
            }

            // Seed the signature from the server-rendered data so the first poll only fires on change.
            lastGradesSignature = JSON.stringify(<?php
                $seed = [];
                foreach ($grades as $g) {
                    $seed[] = [
                        'subject_id'      => (int)$g['subject_id'],
                        'subject_name'    => $g['subject_name'],
                        'prelim'          => (float)$g['prelim'],
                        'midterm'         => (float)$g['midterm'],
                        'prefinal'        => (float)$g['prefinal'],
                        'final'           => (float)$g['final'],
                        'grade'           => (float)$g['grade'],
                        'remarks'         => $g['remarks'],
                        'teacher_first'   => $g['teacher_first'] ?? '',
                        'teacher_last'    => $g['teacher_last'] ?? '',
                        'teacher_picture' => $g['teacher_picture'] ?? '',
                        'graded_at'       => $g['graded_at'] ?? '',
                    ];
                }
                echo json_encode($seed);
            ?>);

            // Reflect any persisted subject filter on load (hint + build marker).
            syncMultiselectUI();

            setInterval(pollGrades, GRADE_POLL_MS);
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
            typingDiv.innerHTML = '<span class="dot"></span><span class="dot"></span><span class="dot"></span>';
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