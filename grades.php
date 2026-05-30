<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('teacher');

$teacher_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle Subject CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subject'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];

        // Check if subject name already exists for this specific teacher or globally
        $check_name = $pdo->prepare("SELECT id FROM subjects WHERE name = ? AND (teacher_id = ? OR teacher_id IS NULL)");
        $check_name->execute([$name, $teacher_id]);

        if ($check_name->rowCount() > 0) {
            $message = "Error: A subject with the name '{$name}' already exists.";
            $message_type = "danger";
        } else {
            // Auto-generate Subject Code: UGRD- followed by 7 random digits
            do {
                $code = 'UGRD-' . str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);
                $check = $pdo->prepare("SELECT id FROM subjects WHERE code = ?");
                $check->execute([$code]);
            } while ($check->rowCount() > 0);

            $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, teacher_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $code, $description, $teacher_id]);
            $_SESSION['new_subject_id'] = $pdo->lastInsertId();
            // Store message in session for redirect
            $_SESSION['message'] = "Subject Added: '{$name}' has been created with code {$code}.";
            $_SESSION['message_type'] = "success";
            $message = "Subject Added: '{$name}' has been created with code {$code}.";
            $message_type = "success";
        }
    }

    if (isset($_POST['update_subject'])) {
        $id = $_POST['subject_id'];
        $name = $_POST['name'];
        $code = $_POST['code'];
        $description = $_POST['description'];

        $check = $pdo->prepare("SELECT id FROM subjects WHERE (code = ? OR name = ?) AND id != ?");
        $check->execute([$code, $name, $id]);
        if ($check->rowCount() > 0) {
            $message = "Error: Subject name or code already exists!";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $code, $description, $id]);
            $message = "Success: Subject '{$name}' has been updated.";
            $message_type = "success";
        }
    }

    // Handle Student Enrollment from Teacher Ledger
    if (isset($_POST['action']) && $_POST['action'] === 'enroll_student') {
        $student_id = $_POST['student_id'];
        $subject_id = $_POST['subject_id'];

        // Check if already enrolled
        $check = $pdo->prepare("SELECT id FROM grades WHERE student_id = ? AND subject_id = ?");
        $check->execute([$student_id, $subject_id]);

        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, teacher_id) VALUES (?, ?, ?)");
            $stmt->execute([$student_id, $subject_id, $teacher_id]);
            $_SESSION['message'] = "Enrollment successful! The student is now in your ledger.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Notice: This student is already enrolled in this subject.";
            $_SESSION['message_type'] = "warning";
        }
        header("Location: teacher.php?tab=manage-grades&filter_subject=" . urlencode($subject_id));
        exit();
    }
}

// Handle subject deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_subject' && isset($_GET['id'])) {
    $result = deleteSubject($pdo, $_GET['id']);
    if ($result === true) {
        $message = "Removed: The subject has been successfully deleted.";
        $message_type = "success";
    } else {
        $message = $result;
        $message_type = "danger";
    }
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header("Location: grades.php?manage_subjects=true");
    exit();
}

// Handle bulk grade synchronization from Teacher Dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bulk_grades'])) {
    $subject_id = $_POST['subject_id'];
    $bulk_data = $_POST['grades'] ?? [];

    foreach ($bulk_data as $student_id => $p_grades) {
        $periods = ['prelim', 'midterm', 'prefinal', 'final'];
        $period_weights = ['prelim' => 0.2, 'midterm' => 0.2, 'prefinal' => 0.2, 'final' => 0.4];
        $semestral_grade = 0;
        $vals = [];

        foreach ($periods as $p) {
            $q_lec = (float)($p_grades[$p.'_quiz'] ?? 0);
            $cs_lec = (float)($p_grades[$p.'_online'] ?? 0);
            $e_lec = (float)($p_grades[$p.'_exam'] ?? 0);
            $p_lec = ($q_lec * 0.4) + ($cs_lec * 0.1) + ($e_lec * 0.5);

            $act_lab = (float)($p_grades[$p.'_attendance'] ?? 0);
            $e_lab = (float)($p_grades[$p.'_laboratory'] ?? 0);
            $p_lab = ($act_lab * 0.4) + (100 * 0.1) + ($e_lab * 0.5); // Lab CS assumed 100
            
            $period_total = ($p_lec * 0.4) + ($p_lab * 0.6);
            
            $vals[$p] = $period_total;
            $vals[$p.'_quiz'] = $q_lec; 
            $vals[$p.'_cs'] = $cs_lec; 
            $vals[$p.'_attendance'] = $act_lab; 
            $vals[$p.'_laboratory'] = $e_lab; 
            $vals[$p.'_online'] = $cs_lec; 
            $vals[$p.'_exam'] = $e_lec;
            
            if ($period_total > 0) {
                $semestral_grade += ($period_total * $period_weights[$p]);
            }
        }

        $check = $pdo->prepare("SELECT id FROM grades WHERE student_id = ? AND subject_id = ?");
        $check->execute([$student_id, $subject_id]);
        $existing = $check->fetch();

        if ($existing) {
            $upd = $pdo->prepare("UPDATE grades SET 
                prelim_quiz=?, prelim_cs=?, prelim_attendance=?, prelim_laboratory=?, prelim_online=?, prelim_exam=?, prelim=?,
                midterm_quiz=?, midterm_cs=?, midterm_attendance=?, midterm_laboratory=?, midterm_online=?, midterm_exam=?, midterm=?,
                prefinal_quiz=?, prefinal_cs=?, prefinal_attendance=?, prefinal_laboratory=?, prefinal_online=?, prefinal_exam=?, prefinal=?,
                final_quiz=?, final_cs=?, final_attendance=?, final_laboratory=?, final_online=?, final_exam=?, final=?,
                grade = ?, teacher_id = ? WHERE id = ?");
            $upd->execute([$vals['prelim_quiz'],$vals['prelim_cs'],$vals['prelim_attendance'],$vals['prelim_laboratory'],$vals['prelim_online'],$vals['prelim_exam'],$vals['prelim'],$vals['midterm_quiz'],$vals['midterm_cs'],$vals['midterm_attendance'],$vals['midterm_laboratory'],$vals['midterm_online'],$vals['midterm_exam'],$vals['midterm'],$vals['prefinal_quiz'],$vals['prefinal_cs'],$vals['prefinal_attendance'],$vals['prefinal_laboratory'],$vals['prefinal_online'],$vals['prefinal_exam'],$vals['prefinal'],$vals['final_quiz'],$vals['final_cs'],$vals['final_attendance'],$vals['final_laboratory'],$vals['final_online'],$vals['final_exam'],$vals['final'],$semestral_grade,$teacher_id,$existing['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, prelim_quiz, prelim_cs, prelim_attendance, prelim_laboratory, prelim_online, prelim_exam, prelim, midterm_quiz, midterm_cs, midterm_attendance, midterm_laboratory, midterm_online, midterm_exam, midterm, prefinal_quiz, prefinal_cs, prefinal_attendance, prefinal_laboratory, prefinal_online, prefinal_exam, prefinal, final_quiz, final_cs, final_attendance, final_laboratory, final_online, final_exam, final, grade) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([$student_id,$subject_id,$teacher_id,$vals['prelim_quiz'],$vals['prelim_cs'],$vals['prelim_attendance'],$vals['prelim_laboratory'],$vals['prelim_online'],$vals['prelim_exam'],$vals['prelim'],$vals['midterm_quiz'],$vals['midterm_cs'],$vals['midterm_attendance'],$vals['midterm_laboratory'],$vals['midterm_online'],$vals['midterm_exam'],$vals['midterm'],$vals['prefinal_quiz'],$vals['prefinal_cs'],$vals['prefinal_attendance'],$vals['prefinal_laboratory'],$vals['prefinal_online'],$vals['prefinal_exam'],$vals['prefinal'],$vals['final_quiz'],$vals['final_cs'],$vals['final_attendance'],$vals['final_laboratory'],$vals['final_online'],$vals['final_exam'],$vals['final'],$semestral_grade]);
        }
    }
    $_SESSION['message'] = "Bulk Update Success: Grades for the selected subject have been synchronized.";
    $_SESSION['message_type'] = "success";
    header("Location: teacher.php?tab=manage-grades&filter_subject=" . $subject_id);
    exit();
}

// Handle grade deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_grade' && isset($_GET['id'])) {
    $grade_id = $_GET['id'];

    try {
        $stmt = $pdo->prepare("DELETE FROM grades WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$grade_id, $teacher_id]);
        $_SESSION['message'] = "Deleted: The student's grade record has been removed.";
        $_SESSION['message_type'] = "success";
    } catch (Exception $e) {
        $_SESSION['message'] = "Error deleting grade: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }
    header("Location: teacher.php?tab=manage-grades");
    exit();
}

// Fetch message from session if redirect occurred
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Get subjects
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY name")->fetchAll();
// Master list for UI/Management - Show teacher's subjects and global admin subjects
$all_subjects_stmt = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ? OR teacher_id IS NULL ORDER BY name");
$all_subjects_stmt->execute([$teacher_id]);
$all_subjects = $all_subjects_stmt->fetchAll();
// Subjects this teacher handles
$my_subjects_stmt = $pdo->prepare("SELECT DISTINCT s.* FROM subjects s JOIN grades g ON s.id = g.subject_id WHERE g.teacher_id = ? ORDER BY s.name");
$my_subjects_stmt->execute([$teacher_id]);
$my_subjects = $my_subjects_stmt->fetchAll();

// Redirect legacy "Recent Grades" view to the Teacher Ledger
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['manage_subjects']) && !isset($_GET['action'])) {
    header("Location: teacher.php?tab=manage-grades");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Grades - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            opacity: 1;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            transform: translateX(5px);
            color: white;
        }

        .sidebar .nav-link.active {
            opacity: 1;
            background: var(--accent-color);
            border-left-color: #fff;
            font-weight: bold;
        }

        .sidebar .dropdown-menu {
            background-color: #1e293b;
            border: none;
            margin: 0 10px;
        }

        .sidebar .dropdown-item {
            color: white;
            padding: 0.6rem 1.5rem;
        }

        .sidebar .dropdown-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
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
            .sidebar {
                left: calc(-1 * var(--sidebar-width));
            }
            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .sidebar-overlay.active { display: block; }
        }

        .grade-badge {
            font-size: 0.9em;
            font-weight: bold;
        }

        .period-col {
            background-color: rgba(30, 58, 138, 0.03);
        }

        .period-header {
            background-color: rgba(30, 58, 138, 0.08) !important;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
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

        /* Glow Highlight for newly added rows */
        .row-glow-highlight {
            animation: tableRowGlow 4s ease-out;
        }
        @keyframes tableRowGlow {
            0% { background-color: rgba(99, 102, 241, 0.3); }
            100% { background-color: transparent; }
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
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Toast Notification Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="msgToast" class="toast fade align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    <div class="container-fluid p-0">
        <div class="sidebar" id="sidebar">
            <div class="d-flex flex-column p-3">
                <div class="text-center mb-4">
                    <img src="image/aclc.jpg" alt="ACLC Logo" style="width: 80px; height: 80px; margin-bottom: 10px; border-radius: 50%; object-fit: cover;">
                    <h5>Teacher Portal</h5>
                    <small><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></small>
                </div>

                <ul class="nav nav-pills flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="teacher.php?tab=dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="teacher.php?tab=students">
                            <i class="fas fa-user-graduate me-2"></i>My Students
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo !isset($_GET['manage_subjects']) ? 'active' : ''; ?>" href="teacher.php?tab=manage-grades">
                            <i class="fas fa-edit me-2"></i>Manage Grades
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo isset($_GET['manage_subjects']) ? 'active' : ''; ?>"
                            href="grades.php?manage_subjects=true">
                            <i class="fas fa-book me-2"></i>Manage Subjects
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="logoutDropdown" role="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-2"></i>Account
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="logoutDropdown">
                            <li><a class="dropdown-item text-warning" href="logout.php"
                                    onclick="return confirm('Are you sure you want to logout?')">
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
                <span class="navbar-brand mb-0 h1">Manage Grades</span>
            </nav>

            <div class="px-4 py-4">

                <?php if (isset($_GET['manage_subjects'])): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h4><i class="fas fa-book"></i> Subject Management</h4>
                                    <button class="btn btn-primary" data-bs-toggle="modal"
                                        data-bs-target="#addSubjectModal"><i class="fas fa-plus"></i> Add Subject</button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Code</th>
                                                    <th>Name</th>
                                                    <th>Description</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($all_subjects as $s): ?>
                                                    <tr id="subject-row-<?php echo $s['id']; ?>">
                                                        <td><?php echo $s['code']; ?></td>
                                                        <td><?php echo $s['name']; ?></td>
                                                        <td><?php echo $s['description']; ?></td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary"
                                                                data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                                data-id="<?php echo $s['id']; ?>"
                                                                data-name="<?php echo $s['name']; ?>"
                                                                data-code="<?php echo $s['code']; ?>"
                                                                data-desc="<?php echo $s['description']; ?>">Edit</button>
                                                            <a href="?manage_subjects=true&action=delete_subject&id=<?php echo $s['id']; ?>"
                                                                class="btn btn-sm btn-outline-danger"
                                                                onclick="return confirm('Delete this subject?')">Delete</a>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Subject</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Subject Name</label><input type="text"
                                class="form-control" name="name" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control"
                                name="description" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_subject" class="btn btn-primary">Save Subject</button>
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
                    <h5 class="modal-title">Edit Subject</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="subject_id" id="edit_sub_id">
                        <div class="mb-3"><label class="form-label">Subject Name</label><input type="text"
                                class="form-control" name="name" id="edit_sub_name" required></div>
                        <div class="mb-3"><label class="form-label">Subject Code</label><input type="text"
                                class="form-control" name="code" id="edit_sub_code" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control"
                                name="description" id="edit_sub_desc" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_subject" class="btn btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const toggle = document.getElementById('sidebarToggle');

            toggle.addEventListener('click', function () {
                sidebar.classList.toggle('collapsed');
                sidebar.classList.toggle('active');
                mainContent.classList.toggle('expanded');
            });
            
            // Toast Initialization
            const toastEl = document.getElementById('msgToast');
            if (toastEl) {
                const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
                <?php 
                $display_msg = $_SESSION['message'] ?? $message ?? '';
                $display_type = $_SESSION['message_type'] ?? $message_type ?? 'info';
                if ($display_msg): ?>
                    document.getElementById('toastMsg').innerText = "<?php echo addslashes($display_msg); ?>";
                    toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
                    toastEl.classList.add('bg-<?php echo $display_type === "danger" ? "danger" : ($display_type === "success" ? "success" : "info"); ?>');
                    toast.show();
                    <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
                <?php endif; ?>
            }

            const editSubjectModal = document.getElementById('editSubjectModal');
            if (editSubjectModal) {
                editSubjectModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('edit_sub_id').value = button.getAttribute('data-id');
                    document.getElementById('edit_sub_name').value = button.getAttribute('data-name');
                    document.getElementById('edit_sub_code').value = button.getAttribute('data-code');
                    document.getElementById('edit_sub_desc').value = button.getAttribute('data-desc');
                });
            }

            // Highlight newly added subject
            <?php if (isset($_SESSION['new_subject_id'])): ?>
                const newRow = document.getElementById('subject-row-<?php echo $_SESSION['new_subject_id']; ?>');
                if (newRow) {
                    newRow.classList.add('row-glow-highlight');
                    newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                <?php unset($_SESSION['new_subject_id']); ?>
            <?php endif; ?>

        });

    </script>
</body>

</html>