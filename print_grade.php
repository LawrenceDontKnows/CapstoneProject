<?php
include 'includes/conn.php';
include 'includes/functions.php';

if (!isLoggedIn()) redirect('index.php');

$where_clauses = ["u_s.role = 'student'"];
$params = [];

if (hasRole('student')) {
    $where_clauses[] = "u_s.id = ?";
    $params[] = $_SESSION['user_id'];
} elseif (hasRole('teacher')) {
    if (!isset($_GET['all_students'])) {
        $where_clauses[] = "g.teacher_id = ?";
        $params[] = $_SESSION['user_id'];
    }
    // If all_students is set, we don't add the teacher filter, 
    // allowing the teacher to see the master list (enrolled/unenrolled)
} elseif (hasRole('admin')) {
    if (isset($_GET['subject_id'])) {
        $where_clauses[] = "g.subject_id = ?";
        $params[] = $_GET['subject_id'];
    }
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

$query = "SELECT u_s.id as student_id, u_s.first_name as student_first, u_s.last_name as student_last,
                 u_s.course, u_s.year_level, g.prelim, g.midterm, g.prefinal, g.final, g.grade,
                 s.name as subject_name, s.code as subject_code, 
                 u_t.first_name as teacher_first, u_t.last_name as teacher_last,
                 g.teacher_id, g.subject_id
          FROM users u_s
          INNER JOIN grades g ON u_s.id = g.student_id
          LEFT JOIN subjects s ON g.subject_id = s.id 
          LEFT JOIN users u_t ON g.teacher_id = u_t.id
          $where_sql 
          ORDER BY u_t.last_name ASC, s.name ASC, u_s.year_level DESC, u_s.course ASC, u_s.last_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_grades = $stmt->fetchAll();

if (empty($all_grades)) die("No records found to print.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Academic Report - GradeView</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; padding: 40px 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .report-container { background: white; max-width: 1140px; margin: 0 auto; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .table thead th { background-color: #f8f9fa !important; border-bottom: 2px solid #dee2e6; color: #333; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; vertical-align: middle; }
        .table td { vertical-align: middle; font-size: 0.9rem; }
        .slip-header h3 { letter-spacing: 1px; font-weight: 800; color: #1e3a8a; }
        @media print { 
            body { background: white; padding: 0; } 
            .no-print { display: none; } 
            .report-container { box-shadow: none; border: none !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            .subject-section { page-break-before: always; }
            .table thead th { -webkit-print-color-adjust: exact; background-color: #f8f9fa !important; }
        }
    </style>
</head>
<body>
    <div class="text-center mb-5 no-print">
        <button onclick="window.print()" class="btn btn-primary px-5 me-2"><i class="fas fa-print me-2"></i> Print Report</button>
        <button onclick="window.close()" class="btn btn-secondary px-5"><i class="fas fa-arrow-left me-2"></i> Back</button>
    </div>

    <div class="container border p-5 report-container">
        <div class="slip-header text-center">
            <img src="<?php echo getSystemSetting($pdo, 'system_logo', 'image/aclc.jpg'); ?>" alt="Logo" style="width: 100px; height: 100px; margin-bottom: 15px; border-radius: 50%; object-fit: cover;">
            <h3>GRADEVIEW ACADEMIC PORTAL</h3>
            <p class="mb-0 text-muted small fw-bold">Official Grade Report Summary</p>
            <hr>
        </div>

        <div class="row mb-4 fw-bold">
            <div class="col-6">
                Date Generated: <?php echo date('F j, Y'); ?>
            </div>
            <div class="col-6 text-end">
                Status: Official Record
            </div>
        </div>

        <?php 
        $current_teacher_id = null;
        $current_subject_id = null;

        foreach ($all_grades as $g): 
            // Start a new Teacher Section
            $teacher_id = $g['teacher_id'] ?? 0;
            $subject_id = $g['subject_id'] ?? 0;

            if ($current_teacher_id !== $teacher_id):
                if ($current_teacher_id !== null) echo '</tbody></table></div>';
                echo '<div class="teacher-group mb-5">';
                $t_name = ($g['teacher_first'] || $g['teacher_last']) ? $g['teacher_first'] . ' ' . $g['teacher_last'] : 'System / Unassigned';
                echo '<h4 class="bg-dark text-white p-2 rounded-1 mb-0"><i class="fas fa-chalkboard-teacher me-2"></i>Teacher: ' . htmlspecialchars($t_name) . '</h4>';
                $current_teacher_id = $teacher_id;
                $current_subject_id = null; // Reset subject tracking for new teacher
            endif;

            // Start a new Subject Section
            if ($current_subject_id !== $subject_id):
                if ($current_subject_id !== null) echo '</tbody></table>';
                $page_break_class = ($current_subject_id !== null) ? 'subject-section' : '';
                $s_name = $g['subject_name'] ?? 'No Subject Enrolled';
                $s_code = $g['subject_code'] ? ' (' . $g['subject_code'] . ')' : '';
                echo '<div class="'.$page_break_class.'"><h5 class="mt-3 text-primary"><i class="fas fa-book me-2"></i>Subject: ' . htmlspecialchars($s_name . $s_code) . '</h5>';
                ?>
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 250px;">Student Name</th>
                            <th class="text-center">Course & Year</th>
                            <th class="text-center">Prelim</th>
                            <th class="text-center">Midterm</th>
                            <th class="text-center">Prefinal</th>
                            <th class="text-center">Finals</th>
                            <th class="text-center">Average</th>
                            <th class="text-center">Equivalent</th>
                        </tr>
                    </thead>
                    <tbody>
                <?php 
                $current_subject_id = $subject_id;
            endif; ?>

            <tr>
                <td><?php echo htmlspecialchars($g['student_first'] . ' ' . $g['student_last']); ?></td>
                <td class="text-center small"><?php echo htmlspecialchars($g['course'] . ' - ' . $g['year_level']); ?></td>
                <td class="text-center"><?php echo number_format($g['prelim'] ?? 0, 1); ?></td>
                <td class="text-center"><?php echo number_format($g['midterm'] ?? 0, 1); ?></td>
                <td class="text-center"><?php echo number_format($g['prefinal'] ?? 0, 1); ?></td>
                <td class="text-center"><?php echo number_format($g['final'] ?? 0, 1); ?></td>
                <td class="text-center fw-bold text-primary"><?php echo number_format($g['grade'] ?? 0, 2); ?></td>
                <td class="text-center"><?php echo calculateGradePoint($g['grade'] ?? 0); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

        <div class="mt-5 pt-5 row text-center">
            <div class="col-6"><div class="pt-2 border-top w-75 mx-auto fw-bold">Dean signature</div></div>
            <div class="col-6"><div class="pt-2 border-top w-75 mx-auto fw-bold">Registrar / Teacher</div></div>
        </div>
    </div>
</body>
</html>