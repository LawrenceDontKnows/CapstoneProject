<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/mailer.php';
requireRole('admin');
validateSession(); // Add this line for session security checks

// CSRF Protection: Generate a token if one doesn't exist for the forms on this page.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle AJAX: fetch current user's notifications (real-time popup poller)
if (isset($_GET['ajax_notifications'])) {
    header('Content-Type: application/json');
    try {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT n.id, n.message, n.is_read, n.created_at,
                   u.first_name AS sender_first, u.last_name AS sender_last
            FROM notifications n
            JOIN users u ON n.sender_id = u.id
            WHERE n.recipient_id = ?
            ORDER BY n.id DESC
            LIMIT 10
        ");
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
        $unread->execute([$uid]);
        echo json_encode(['unread_count' => (int)$unread->fetchColumn(), 'notifications' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['unread_count' => 0, 'notifications' => []]);
    }
    exit;
}

// Handle AJAX Search Suggestions
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT first_name, last_name, username, role FROM users WHERE status = 'active' AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ?) LIMIT 5");
    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Handle AJAX to get students for a subject
if (isset($_GET['ajax_get_students_by_subject'])) {
    header('Content-Type: application/json');
    try {
        $subject_id = $_GET['ajax_get_students_by_subject'];
        
        // Fetch subject details (including new fields)
        $subject_stmt = $pdo->prepare("SELECT name, target_course, target_year_level FROM subjects WHERE id = ?");
        $subject_stmt->execute([$subject_id]);
        $subject_details = $subject_stmt->fetch(PDO::FETCH_ASSOC);

        // Query to get students enrolled in the specific subject
        $students_stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.course, u.year_level 
            FROM users u
            JOIN grades g ON u.id = g.student_id
            WHERE g.subject_id = ? AND u.role = 'student' AND u.status = 'active'
            ORDER BY u.last_name, u.first_name
        ");
        $students_stmt->execute([$subject_id]);
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['details' => $subject_details, 'students' => $students]);
    } catch (PDOException $e) {
        http_response_code(500);
        // Log the real error for the developer
        error_log("Admin AJAX get_students_by_subject failed: " . $e->getMessage());
        // Send a generic, safe, JSON-formatted error to the client
        echo json_encode(['error' => 'A database error occurred. This might be due to missing `target_course` or `target_year_level` columns in the `subjects` table.']);
    }
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
    // Initialize distribution with the correct grade point labels used across the system.
    $grade_point_labels = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'IP'];
    $distribution = array_fill_keys($grade_point_labels, 0);
    $avg_sum = 0;
    foreach ($grades as $g) {
        $avg_sum += $g['grade'];
        foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p) {
            if (isset($g[$p]) && $g[$p] > 0) {
                $period_sums[$p] += $g[$p];
                $period_counts[$p]++;
            }
        }
        // Correctly increment the distribution array.
        $point = calculateGradePoint($g['grade']);
        if (isset($distribution[$point])) $distribution[$point]++;
        $hue = abs(crc32($g['subject_name'])) % 360;
        $datasets[] = [
            'label' => htmlspecialchars($g['subject_name']),
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
    // Central CSRF Token Validation for all POST requests
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission (CSRF token mismatch). Please try again.";
        $_SESSION['message_type'] = "danger";
        redirect("admin.php?tab=" . ($active_tab ?? 'dashboard'));
    }

    if (isset($_POST['update_admin_profile'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        $admin_id = $_SESSION['user_id'];
        
        $errors = [];
        // Handle file upload
        $profile_picture_path = handleProfilePictureUpload($pdo, $_FILES['profile_picture'] ?? null, $admin_id, $errors);

        $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_email->execute([$email, $admin_id]);
        if ($check_email->rowCount() > 0) $errors[] = "Email is already in use by another account.";

        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) $errors[] = "Passwords do not match.";
            if (strlen($new_password) < 8) $errors[] = "New password must be at least 8 characters long.";
        }

        if (empty($errors)) {
            $sql_parts = ["first_name = ?", "last_name = ?", "email = ?"];
            $params = [$first_name, $last_name, $email];

            if ($profile_picture_path) {
                $sql_parts[] = "profile_picture = ?";
                $params[] = $profile_picture_path;
            }

            if (!empty($new_password)) {
                $sql_parts[] = "password = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            
            $params[] = $admin_id;
            $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);
            
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            $_SESSION['message'] = "Your profile has been updated successfully.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = implode(" ", $errors);
            $_SESSION['message_type'] = "danger";
        }
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['add_broadcast_template'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO broadcast_templates (title, message, role_access) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['title'], $_POST['message'], $_POST['role_access'] ?? 'admin']);
            $_SESSION['message'] = "Broadcast template added successfully.";
            $_SESSION['message_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Database Error: Could not add template. The 'broadcast_templates' table may be missing.";
            $_SESSION['message_type'] = "danger";
            error_log("Add Broadcast Template Error: " . $e->getMessage());
        }
        redirect("admin.php?tab=settings&sub_tab=broadcast");
    }

    if (isset($_POST['update_broadcast_template'])) {
        try {
            $stmt = $pdo->prepare("UPDATE broadcast_templates SET title = ?, message = ?, role_access = ? WHERE id = ?");
            $stmt->execute([$_POST['title'], $_POST['message'], $_POST['role_access'] ?? 'admin', $_POST['template_id']]);
            $_SESSION['message'] = "Broadcast template updated successfully.";
            $_SESSION['message_type'] = "success";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Database Error: Could not update template. The 'broadcast_templates' table may be missing.";
            $_SESSION['message_type'] = "danger";
            error_log("Update Broadcast Template Error: " . $e->getMessage());
        }
        redirect("admin.php?tab=settings&sub_tab=broadcast");
    }

    if (isset($_POST['delete_broadcast_template'])) {
        // The central CSRF check at the top of the POST block handles this.
        try {
            $stmt = $pdo->prepare("DELETE FROM broadcast_templates WHERE id = ?");
            $stmt->execute([$_POST['template_id']]);
            $_SESSION['message'] = "Broadcast template deleted.";
            $_SESSION['message_type'] = "warning";
        } catch (PDOException $e) {
            $_SESSION['message'] = "Database Error: Could not delete template. The 'broadcast_templates' table may be missing.";
            $_SESSION['message_type'] = "danger";
            error_log("Delete Broadcast Template Error: " . $e->getMessage());
        }
        redirect("admin.php?tab=settings&sub_tab=broadcast");
    }

    if (isset($_POST['add_subject'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $teacher_id = !empty($_POST['teacher_id']) ? $_POST['teacher_id'] : NULL;
        $target_course = trim($_POST['target_course']);
        $target_year_level = trim($_POST['target_year_level']);

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

            $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, teacher_id, target_course, target_year_level) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $code, $description, $teacher_id, $target_course, $target_year_level]);
            $_SESSION['new_subject_id'] = $pdo->lastInsertId();
            $message = "Success: The subject '{$name}' has been added. System assigned code: {$code}.";
            if ($teacher_id) {
                $teacher_name_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $teacher_name_stmt->execute([$teacher_id]);
                $teacher = $teacher_name_stmt->fetch();
                $message .= " Assigned to " . htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) . ".";
            }
            $message_type = "success";
        }
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $message_type;
        redirect("admin.php?tab=subjects");
    }

    if (isset($_POST['broadcast_notification'])) {
        $message = trim($_POST['message']);
        $target_groups = $_POST['target_groups'] ?? [];
        $sender_id = $_SESSION['user_id'];

        if (empty($message) || empty($target_groups)) {
            $_SESSION['message'] = "Message and at least one target audience are required.";
            $_SESSION['message_type'] = "warning";
        } else {
            // Whitelist roles to prevent unexpected values
            $allowed_roles = ['admin', 'teacher', 'student'];
            $roles_to_query = array_intersect($target_groups, $allowed_roles);

            if (empty($roles_to_query)) {
                $_SESSION['message'] = "Invalid target audience selected.";
                $_SESSION['message_type'] = "danger";
            } else {
                // Create placeholders for the IN clause
                $placeholders = implode(',', array_fill(0, count($roles_to_query), '?'));
                $query = "SELECT id, email, first_name FROM users WHERE role IN ($placeholders) AND status = 'active'";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($roles_to_query);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($recipients)) {
                    $sendEmail = !empty($_POST['send_email']);
                    $emailReady = $sendEmail && isEmailConfigured($pdo);
                    $emailed = 0;
                    $sent = 0;
                    $dbError = '';
                    $sql = "INSERT INTO notifications (sender_id, recipient_id, message) VALUES (?, ?, ?)";
                    $ins = $pdo->prepare($sql);
                    foreach ($recipients as $r) {
                        try {
                            $ins->execute([$sender_id, $r['id'], $message]);
                            $sent++;
                        } catch (Exception $e) {
                            $dbError = $e->getMessage();
                            error_log("Broadcast insert failed for recipient {$r['id']}: " . $dbError);
                        }
                    }

                    if ($sent === 0) {
                        $_SESSION['message'] = "An error occurred while sending the broadcast."
                            . ($dbError !== '' ? " Details: " . $dbError : "");
                        $_SESSION['message_type'] = "danger";
                        redirect("admin.php?tab=dashboard");
                    }

                    if ($emailReady) {
                        $subject = 'GradeView Broadcast';
                        foreach ($recipients as $r) {
                            if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
                            try {
                                $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937">'
                                    . '<p>Hello ' . htmlspecialchars($r['first_name'] ?: 'User') . ',</p>'
                                    . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
                                    . '<p style="color:#6b7280;font-size:12px;margin-top:16px">This is a broadcast from your GradeView administrator.</p>'
                                    . '</div>';
                                if (sendMail($r['email'], $subject, $body)) $emailed++;
                            } catch (Throwable $te) {
                                error_log("Broadcast email failed for {$r['email']}: " . $te->getMessage());
                            }
                        }
                    }

                    $_SESSION['message'] = "Broadcast sent to " . $sent . " recipients."
                        . ($sendEmail ? ($emailReady ? " Email copies sent: {$emailed}." : " (Email not configured, so no email copies were sent.)") : "");
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "No active recipients found for the selected group(s).";
                    $_SESSION['message_type'] = "info";
                }
            }
        }
        redirect("admin.php?tab=dashboard");
    }

    if (isset($_POST['update_logo'])) {
        if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
            $max_size = 2 * 1024 * 1024; // 2 MB

            $file_type = mime_content_type($_FILES['logo_file']['tmp_name']);
            $file_ext = strtolower(pathinfo($_FILES["logo_file"]["name"], PATHINFO_EXTENSION));

            if (!in_array($file_type, $allowed_types) || !in_array($file_ext, $allowed_exts)) {
                $_SESSION['message'] = "Error: Invalid file type. Only JPG, PNG, and GIF are allowed.";
                $_SESSION['message_type'] = "danger";
            } elseif ($_FILES['logo_file']['size'] > $max_size) {
                $_SESSION['message'] = "Error: File size exceeds the 2MB limit.";
                $_SESSION['message_type'] = "danger";
            } else {
            $target_dir = "image/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $target_file = $target_dir . "system_logo_" . time() . "." . $file_ext;
            
            if (move_uploaded_file($_FILES["logo_file"]["tmp_name"], $target_file)) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$target_file, $target_file]);
                $_SESSION['message'] = "Logo updated successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error: Could not move uploaded file.";
                $_SESSION['message_type'] = "danger";
            }
            }
        }
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['update_slideshow'])) {
        $settings = [
            'login_slideshow_enabled' => $_POST['login_slideshow_enabled'] ?? '1',
            'login_slideshow_interval' => (int)($_POST['login_slideshow_interval'] ?? 6),
            'login_slideshow_opacity' => (int)($_POST['login_slideshow_opacity'] ?? 30),
            'login_form_glass' => $_POST['login_form_glass'] ?? '1',
        ];
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        // Handle slideshow image uploads
        $target_dir = "image/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        for ($i = 1; $i <= 3; $i++) {
            $file_key = "slideshow_image_$i";
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $max_size = 5 * 1024 * 1024;

                $file_type = mime_content_type($_FILES[$file_key]['tmp_name']);
                $file_ext = strtolower(pathinfo($_FILES[$file_key]["name"], PATHINFO_EXTENSION));

                if (!in_array($file_type, $allowed_types) || !in_array($file_ext, $allowed_exts)) {
                    $_SESSION['message'] = "Error: Slideshow image $i - Invalid file type. Only JPG, PNG, GIF, WebP allowed.";
                    $_SESSION['message_type'] = "danger";
                    redirect("admin.php?tab=settings");
                } elseif ($_FILES[$file_key]['size'] > $max_size) {
                    $_SESSION['message'] = "Error: Slideshow image $i - File size exceeds 5MB limit.";
                    $_SESSION['message_type'] = "danger";
                    redirect("admin.php?tab=settings");
                } else {
                    $target_file = $target_dir . "login_slideshow_" . $i . "." . $file_ext;
                    if (move_uploaded_file($_FILES[$file_key]["tmp_name"], $target_file)) {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                        $stmt->execute(["login_slideshow_image_$i", $target_file, $target_file]);
                    } else {
                        $_SESSION['message'] = "Error: Could not move uploaded slideshow image $i.";
                        $_SESSION['message_type'] = "danger";
                        redirect("admin.php?tab=settings");
                    }
                }
            }
        }
        $_SESSION['message'] = "Slideshow settings updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['update_register_bg'])) {
        $settings = [
            'register_bg_type' => $_POST['register_bg_type'] ?? 'color',
            'register_bg_color' => $_POST['register_bg_color'] ?? '#f1f5f9',
            'register_bg_opacity' => (int)($_POST['register_bg_opacity'] ?? 100),
        ];
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        // Handle registration background image upload
        if (isset($_FILES['register_bg_image']) && $_FILES['register_bg_image']['error'] === UPLOAD_ERR_OK) {
            $target_dir = "image/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $max_size = 5 * 1024 * 1024;

            $file_type = mime_content_type($_FILES['register_bg_image']['tmp_name']);
            $file_ext = strtolower(pathinfo($_FILES["register_bg_image"]["name"], PATHINFO_EXTENSION));

            if (!in_array($file_type, $allowed_types) || !in_array($file_ext, $allowed_exts)) {
                $_SESSION['message'] = "Error: Invalid file type for registration background. Only JPG, PNG, GIF, WebP allowed.";
                $_SESSION['message_type'] = "danger";
                redirect("admin.php?tab=settings");
            } elseif ($_FILES['register_bg_image']['size'] > $max_size) {
                $_SESSION['message'] = "Error: Registration background image exceeds 5MB limit.";
                $_SESSION['message_type'] = "danger";
                redirect("admin.php?tab=settings");
            } else {
                $target_file = $target_dir . "register_bg." . $file_ext;
                if (move_uploaded_file($_FILES["register_bg_image"]["tmp_name"], $target_file)) {
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute(['register_bg_image', $target_file, $target_file]);
                } else {
                    $_SESSION['message'] = "Error: Could not move uploaded registration background image.";
                    $_SESSION['message_type'] = "danger";
                    redirect("admin.php?tab=settings");
                }
            }
        }
        $_SESSION['message'] = "Registration background settings updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['update_weights'])) {
        $keys = ['weight_lec', 'weight_lab', 'weight_lec_quiz', 'weight_lec_online', 'weight_lec_exam', 'weight_lab_att', 'weight_lab_cs', 'weight_lab_exam', 'period_weight_prelim', 'period_weight_midterm', 'period_weight_prefinal', 'period_weight_final'];
        foreach($keys as $key) {
            $val = isset($_POST[$key]) ? (float)$_POST[$key] : 0;
            if ($val < 0) $val = 0;
            if ($val > 100) $val = 100;
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $val, $val]);
        }
        $_SESSION['message'] = "Global grading policy updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings");
    }

    if (isset($_POST['enroll_students_import'])) {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if (!$subject_id || !isset($_FILES['enroll_file']) || $_FILES['enroll_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['message'] = "Error: Select a subject and a valid enrollment file.";
            $_SESSION['message_type'] = "danger";
            redirect("admin.php?tab=subjects");
        }
        $sub_stmt = $pdo->prepare("SELECT id, teacher_id FROM subjects WHERE id = ?");
        $sub_stmt->execute([$subject_id]);
        $sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            $_SESSION['message'] = "Error: Subject not found.";
            $_SESSION['message_type'] = "danger";
            redirect("admin.php?tab=subjects");
        }
        $assign_teacher = $sub['teacher_id'] ? (int)$sub['teacher_id'] : null;
        $rows = readImportRows($_FILES['enroll_file']['tmp_name'], $_FILES['enroll_file']['name'] ?? '');
        if (isset($rows['error'])) {
            $_SESSION['message'] = "Error: " . $rows['error'];
            $_SESSION['message_type'] = "danger";
            redirect("admin.php?tab=subjects");
        }
        $enrolled = 0; $skipped = 0; $notfound = 0;
        $ins = $pdo->prepare("INSERT IGNORE INTO grades (student_id, subject_id, teacher_id) VALUES (?, ?, ?)");
        foreach ($rows as $row) {
            $sid_raw = getImportValue($row, ['student_id','id','student_no','student_number','lrn']);
            $uname = getImportValue($row, ['username','user','user_name']);
            $email = getImportValue($row, ['email','email_address','e_mail']);
            $student = null;
            if ($sid_raw !== null && ctype_digit((string)$sid_raw)) {
                $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
                $st->execute([$sid_raw]); $student = $st->fetch(PDO::FETCH_ASSOC);
            }
            if (!$student && $uname !== null && $uname !== '') {
                $st = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'student' AND status = 'active'");
                $st->execute([$uname]); $student = $st->fetch(PDO::FETCH_ASSOC);
            }
            if (!$student && $email !== null && $email !== '') {
                $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' AND status = 'active'");
                $st->execute([$email]); $student = $st->fetch(PDO::FETCH_ASSOC);
            }
            if (!$student) { $notfound++; continue; }
            $ins->execute([$student['id'], $subject_id, $assign_teacher]);
            if ($ins->rowCount() > 0) $enrolled++; else $skipped++;
        }
        $_SESSION['message'] = "Enrollment import: {$enrolled} enrolled, {$skipped} already enrolled/skipped, {$notfound} not found.";
        $_SESSION['message_type'] = $enrolled > 0 ? "success" : "warning";
        redirect("admin.php?tab=subjects");
    }

    if (isset($_POST['reset_theme_colors'])) {
        $theme_keys = ['theme_sidebar_bg', 'theme_primary_color', 'theme_body_bg', 'theme_header_bg', 'theme_text_color', 'theme_accent_color'];
        $stmt = $pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
        foreach ($theme_keys as $key) {
            $stmt->execute([$key]);
        }
        $_SESSION['message'] = "Theme colors reset to default.";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings&sub_tab=theme");
    }

    if (isset($_POST['update_theme_colors'])) {
        $theme_keys = ['theme_sidebar_bg', 'theme_primary_color', 'theme_body_bg', 'theme_header_bg', 'theme_text_color', 'theme_accent_color'];
        foreach ($theme_keys as $key) {
            $value = $_POST[$key] ?? '';
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $value, $value]);
            }
        }
        $_SESSION['message'] = "Theme colors updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings&sub_tab=theme");
    }

    if (isset($_POST['update_smtp_settings'])) {
        $smtp_keys = [
            'smtp_from_email',
            'smtp_from_name',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption'
        ];
        foreach ($smtp_keys as $key) {
            $value = trim($_POST[$key] ?? '');
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        $_SESSION['message'] = "SMTP settings updated successfully!";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=settings&sub_tab=smtp");
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
        $home_address = $_POST['home_address'] ?? '';
        $city = $_POST['city'] ?? '';
        $province = $_POST['province'] ?? '';
        $guardian_name = $_POST['guardian_name'] ?? '';
        $guardian_status = $_POST['guardian_status'] ?? '';
        $guardian_phone = $_POST['guardian_phone'] ?? '';
        $guardian_occupation = $_POST['guardian_occupation'] ?? '';
        $guardian_address = $_POST['guardian_address'] ?? '';

        // Fetch old data for audit log
        $old_data_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $old_data_stmt->execute([$user_id]);
        $old_data = $old_data_stmt->fetch(PDO::FETCH_ASSOC);

        $errors = [];
        $profile_picture_path = handleProfilePictureUpload($pdo, $_FILES['profile_picture'] ?? null, $user_id, $errors);

        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check_stmt->execute([$username, $email, $user_id]);
        if ($check_stmt->rowCount() > 0) $errors[] = "Username or Email already exists!";

        if (empty($errors)) {
            $sql_parts = ["username = ?", "first_name = ?", "last_name = ?", "email = ?", "role = ?", "course = ?", "year_level = ?", "phone_number = ?", "home_address = ?", "city = ?", "province = ?", "guardian_name = ?", "guardian_status = ?", "guardian_phone = ?", "guardian_occupation = ?", "guardian_address = ?"];
            $params = [$username, $first_name, $last_name, $email, $role, $course, $year_level, $phone, $home_address, $city, $province, $guardian_name, $guardian_status, $guardian_phone, $guardian_occupation, $guardian_address];
            if ($profile_picture_path) { $sql_parts[] = "profile_picture = ?"; $params[] = $profile_picture_path; }
            $params[] = $user_id;
            $sql = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);

            // Log the audit trail
            $new_data_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $new_data_stmt->execute([$user_id]);
            $new_data = $new_data_stmt->fetch(PDO::FETCH_ASSOC);
            $changes = [];
            $fields_to_check = ['username', 'first_name', 'last_name', 'email', 'role', 'course', 'year_level', 'phone_number', 'home_address', 'city', 'province', 'guardian_name', 'guardian_status', 'guardian_phone', 'guardian_occupation', 'guardian_address'];
            foreach ($fields_to_check as $field) {
                if (($old_data[$field] ?? null) != ($new_data[$field] ?? null)) {
                    $changes[] = "Changed {$field} from '{$old_data[$field]}' to '{$new_data[$field]}'.";
                }
            }
            if ($profile_picture_path) $changes[] = "Profile picture was updated.";
            if (!empty($changes)) logAudit($pdo, $_SESSION['user_id'], 'user_update', $user_id, implode("\n", $changes));

            $_SESSION['message'] = "Updated: Profile for user '{$username}' has been successfully modified.";
            $_SESSION['message_type'] = "success";
            $_SESSION['updated_user_id'] = $user_id;
        } else {
            $_SESSION['message'] = implode(" ", $errors);
            $_SESSION['message_type'] = "danger";
        }
        redirect("admin.php?tab=users&search_user=" . urlencode($_POST['search_context'] ?? ''));
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

            logAudit($pdo, $_SESSION['user_id'], 'user_password_reset', $user_id, "Admin reset password for user '{$current_username}'.");

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
            // Security: Log detailed error, show generic message to user.
            error_log("Admin Password Update Error: " . $e->getMessage());
            $_SESSION['message'] = "An unexpected error occurred while updating the password.";
            redirect("admin.php?tab=users");
        }
    }

    if (isset($_POST['update_subject'])) {
        $subject_id = $_POST['subject_id'];
        $name = $_POST['name'];
        $code = $_POST['code'];
        $description = $_POST['description'];
        $teacher_id = !empty($_POST['teacher_id']) ? $_POST['teacher_id'] : NULL;
        $target_course = trim($_POST['target_course']);
        $target_year_level = trim($_POST['target_year_level']);

        // Check if subject code or name already exists (excluding current subject)
        $check_stmt = $pdo->prepare("SELECT id FROM subjects WHERE (code = ? OR name = ?) AND id != ?");
        $check_stmt->execute([$code, $name, $subject_id]);

        if ($check_stmt->rowCount() > 0) {
            $message = "Error: Subject name or code already exists!";
            $message_type = "danger";
        } else {
            $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, description = ?, teacher_id = ?, target_course = ?, target_year_level = ? WHERE id = ?");
            $stmt->execute([$name, $code, $description, $teacher_id, $target_course, $target_year_level, $subject_id]);
            $message = "Subject Modified: Details for '{$name}' ({$code}) have been saved.";
            $message_type = "success";
            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=subjects");
        }
    }

    if (isset($_POST['finalize_subject'])) {
        $subject_id = $_POST['subject_id'];
        $stmt = $pdo->prepare("UPDATE subjects SET status = 'finalized' WHERE id = ?");
        $stmt->execute([$subject_id]);

        // Log audit
        $sub_stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
        $sub_stmt->execute([$subject_id]);
        $subject_name = $sub_stmt->fetchColumn();
        logAudit($pdo, $_SESSION['user_id'], 'subject_finalize', $subject_id, "Finalized subject '{$subject_name}'. Grades are now locked.");

        $_SESSION['message'] = "Subject has been finalized and grades are now locked.";
        $_SESSION['message_type'] = "success";
        redirect("admin.php?tab=subjects");
    }

    if (isset($_POST['reopen_subject'])) {
        $subject_id = $_POST['subject_id'];
        $stmt = $pdo->prepare("UPDATE subjects SET status = 'open' WHERE id = ?");
        $stmt->execute([$subject_id]);

        // Log audit
        $sub_stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
        $sub_stmt->execute([$subject_id]);
        $subject_name = $sub_stmt->fetchColumn();
        logAudit($pdo, $_SESSION['user_id'], 'subject_reopen', $subject_id, "Re-opened subject '{$subject_name}'. Grades are now editable.");

        $_SESSION['message'] = "Subject has been re-opened for grade entry.";
        $_SESSION['message_type'] = "info";
        redirect("admin.php?tab=subjects");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_subject') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $subject_id = $_POST['subject_id_admin'];
        // Admin has authority, no ownership check needed, but we still use the function
        $result = deleteSubject($pdo, $subject_id);
        if ($result === true) {
            $_SESSION['message'] = "Subject and all related data have been permanently deleted.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = $result;
            $_SESSION['message_type'] = "danger";
        }
    }
    redirect("admin.php?tab=subjects");
}

// Handle session messages after redirect
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Handle delete actions
if (isset($_GET['action'])) {
    // Central CSRF Check for all GET-based actions
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $_SESSION['message'] = "Invalid action link (CSRF token mismatch). Please try again.";
        $_SESSION['message_type'] = "danger";
        redirect("admin.php?tab=" . ($active_tab ?? 'dashboard'));
    }

    $action = $_GET['action'];
    $id = $_GET['id'] ?? null;
    $log_id = $_GET['log_id'] ?? null;
    $search_user = $_GET['search_user'] ?? '';

    switch ($action) {
        case 'archive_user':
            if (!$id) { $_SESSION['message'] = "Invalid request."; $_SESSION['message_type'] = "danger"; redirect("admin.php?tab=users"); }
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
                // Log audit
                $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $u_stmt->execute([$id]);
                $username_archived = $u_stmt->fetchColumn();
                logAudit($pdo, $_SESSION['user_id'], 'user_archive', $id, "Archived user '{$username_archived}'.");
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
            if (!$id) { $_SESSION['message'] = "Invalid request."; $_SESSION['message_type'] = "danger"; redirect("admin.php?tab=archive"); }
            $result = restoreUser($pdo, $id);
            if ($result === true) {
                // Log audit
                $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $u_stmt->execute([$id]);
                $username_restored = $u_stmt->fetchColumn();
                logAudit($pdo, $_SESSION['user_id'], 'user_restore', $id, "Restored user '{$username_restored}' from archive.");
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
            if (!$id) { $_SESSION['message'] = "Invalid request."; $_SESSION['message_type'] = "danger"; redirect("admin.php?tab=archive"); }
            // Get username before deleting for the log
            $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $u_stmt->execute([$id]);
            $username_deleted = $u_stmt->fetchColumn();

            $result = deleteUser($pdo, $id);
            if ($result === true) {
                logAudit($pdo, $_SESSION['user_id'], 'user_purge', $id, "Permanently deleted (purged) user '{$username_deleted}' (ID: {$id}).");
                $message = "User Purged: Account and data have been permanently removed.";
                $message_type = "success";
            } else {
                $message = $result;
                $message_type = "danger";
            }
            $_SESSION['message'] = $message; $_SESSION['message_type'] = $message_type;
            redirect("admin.php?tab=archive");
            break;

        case 'revert_update':
            if (!$log_id) {
                $_SESSION['message'] = "Invalid request: Missing log ID.";
                $_SESSION['message_type'] = "danger";
                redirect("admin.php?tab=audit");
            }

            $log_stmt = $pdo->prepare("SELECT * FROM audit_log WHERE id = ? AND action = 'user_update'");
            $log_stmt->execute([$log_id]);
            $log = $log_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log || !$log['target_user_id']) {
                $_SESSION['message'] = "Cannot revert: The log is invalid or the target user no longer exists.";
                $_SESSION['message_type'] = "warning";
                redirect("admin.php?tab=audit");
            }

            $target_user_id = $log['target_user_id'];
            $changes = explode("\n", $log['details']);
            $revert_sql_parts = [];
            $revert_params = [];
            $reverted_changes_details = [];

            foreach ($changes as $change_line) {
                if (preg_match("/^Changed (\w+) from '(.*?)' to '(.*?)'./", trim($change_line), $matches)) {
                    $field = $matches[1];
                    $old_value = $matches[2];
                    $allowed_fields = ['username', 'first_name', 'last_name', 'email', 'role', 'course', 'year_level', 'phone_number'];
                    if (in_array($field, $allowed_fields)) {
                        $revert_sql_parts[] = "`$field` = ?";
                        $revert_params[] = $old_value;
                        $reverted_changes_details[] = "Reverted `$field` to '{$old_value}'.";
                    }
                }
            }

            if (empty($revert_sql_parts)) {
                $_SESSION['message'] = "No revertible changes found in this log entry (e.g., only profile picture was updated).";
                $_SESSION['message_type'] = "info";
            } else {
                try {
                    $pdo->beginTransaction();
                    $revert_params[] = $target_user_id;
                    $sql = "UPDATE users SET " . implode(", ", $revert_sql_parts) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($revert_params);

                    $revert_details_string = "Reverted changes from log ID #{$log_id}:\n" . implode("\n", $reverted_changes_details);
                    logAudit($pdo, $_SESSION['user_id'], 'user_update_revert', $target_user_id, $revert_details_string);

                    $pdo->commit();
                    $_SESSION['message'] = "Successfully reverted user update.";
                    $_SESSION['message_type'] = "success";
                } catch (Exception $e) {
                    $pdo->rollBack(); $_SESSION['message'] = "An error occurred during the revert operation."; $_SESSION['message_type'] = "danger"; error_log("Revert Error: " . $e->getMessage());
                }
            }
            redirect("admin.php?tab=audit");
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

// Fetch Registrars & Teachers
$staff_users_stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? AND role IN ('admin', 'teacher') $search_sql ORDER BY role, first_name ASC");
$staff_users_stmt->execute($users_params);
$staff_users = $staff_users_stmt->fetchAll();

// NEW: Split staff into roles to avoid redundant loops in the view
$admins = [];
$teachers = [];
foreach ($staff_users as $user) {
    if ($user['role'] === 'admin') {
        $admins[] = $user;
    } else if ($user['role'] === 'teacher') {
        $teachers[] = $user;
    }
}
// Fetch Students (Filtered by Search)
$students_stmt = $pdo->prepare("
    SELECT u.*, (
        SELECT GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ')
        FROM grades g
        JOIN subjects s ON g.subject_id = s.id
        WHERE g.student_id = u.id
    ) as enrolled_subjects
    FROM users u
    WHERE u.status = ? AND u.role = 'student' $search_sql
    ORDER BY u.year_level DESC, u.course ASC, u.last_name ASC");
$students_stmt->execute($users_params);
$all_students = $students_stmt->fetchAll();

// Fetch Archived Users
$archive_params = ['archived'];
if ($search_user) array_push($archive_params, "%$search_user%", "%$search_user%", "%$search_user%", "%$search_user%");
$archived_users_stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? $search_sql ORDER BY role, last_name ASC");
$archived_users_stmt->execute($archive_params);
$archived_users = $archived_users_stmt->fetchAll();

// Fetch current registrar's full info for profile settings
$admin_info_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$admin_info_stmt->execute([$_SESSION['user_id']]);
$admin_info = $admin_info_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch broadcast templates for the editor and modal
$broadcast_templates = [];
try {
    $broadcast_templates_stmt = $pdo->query("SELECT * FROM broadcast_templates ORDER BY FIELD(role_access, 'admin', 'all', 'teacher'), title");
    $broadcast_templates = $broadcast_templates_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Broadcast templates table may not exist: " . $e->getMessage());
}

// --- One-time seeding of default broadcast templates ---
try {
    $default_templates = [
        // Admin templates
        ['Grade Release', 'All grades for the current period have been released. Please check your portal.', 'admin'],
        ['Enrollment Open', 'The enrollment period for the upcoming semester is now open. Please complete your enrollment by [Date].', 'admin'],
        ['Holiday', 'Please be advised that there will be no classes on [Date] in observance of [Holiday].', 'admin'],
        ['Maintenance', 'The portal will be down for scheduled maintenance on [Date] at [Time]. We apologize for any inconvenience.', 'admin'],
        // Teacher templates
        ['Class Canceled', 'Please be advised that our class for today, [Date], has been canceled. An asynchronous activity will be provided.', 'teacher'],
        ['Assignment Reminder', 'This is a reminder that your assignment on [Topic] is due on [Date]. Please submit it on time.', 'teacher'],
        ['Exam Schedule', 'Heads up! Our upcoming [Period] exam is scheduled for [Date] at [Time].', 'teacher'],
        ['Consultation Hours', 'My consultation hours for this week are on [Day] from [Time Start] to [Time End]. Feel free to approach me with any concerns.', 'teacher'],
        // All Users templates
        ['General Announcement', 'Important: Please check the school website for recent announcements regarding [Topic].', 'all'],
        ['System Update', 'The system will undergo a minor update on [Date] at [Time]. Expect brief interruptions.', 'all']
    ];

    $insert_stmt = $pdo->prepare("INSERT INTO broadcast_templates (title, message, role_access) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM broadcast_templates WHERE title = ?)");
    
    foreach ($default_templates as $template) {
        $insert_stmt->execute([$template[0], $template[1], $template[2], $template[0]]);
    }
} catch (PDOException $e) { error_log("Default broadcast template seeding failed: " . $e->getMessage()); }

// Fetch data for Audit Log filters
$all_admins_for_filter = [];
$distinct_actions = [];
try {
    $all_admins_stmt = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'admin' AND status = 'active' ORDER BY last_name, first_name");
    $all_admins_for_filter = $all_admins_stmt->fetchAll(PDO::FETCH_ASSOC);

    $distinct_actions_stmt = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");
    $distinct_actions = $distinct_actions_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Silently fail if audit_log table doesn't exist
    error_log("Audit Log Filter Data Fetch Error: " . $e->getMessage());
}

// Get filter parameters for Audit Log
$audit_search = $_GET['audit_search'] ?? '';
$audit_action = $_GET['audit_action'] ?? '';
$audit_admin_id = $_GET['audit_admin_id'] ?? '';
$audit_start_date = $_GET['audit_start_date'] ?? '';
$audit_end_date = $_GET['audit_end_date'] ?? '';
 
// Pagination for Audit Log
$audit_page = isset($_GET['audit_page']) ? (int)$_GET['audit_page'] : 1;
if ($audit_page < 1) $audit_page = 1;
$audit_per_page = 25; // Records per page
$audit_offset = ($audit_page - 1) * $audit_per_page;
 
// Build WHERE clause and params for both count and data queries
$audit_where_clauses = [];
$audit_params = [];
 
if ($audit_search) {
    // Using MATCH...AGAINST for the 'details' column requires a FULLTEXT index.
    // This is significantly faster than LIKE '%...%' on large text fields.
    $audit_where_clauses[] = "(MATCH(al.details) AGAINST(? IN BOOLEAN MODE) OR target.username LIKE ?)";
    // Add wildcards for boolean mode to mimic LIKE '%...%' behavior.
    $audit_params[] = "*" . $audit_search . "*";
    $audit_params[] = "%$audit_search%";
}
if ($audit_action) { $audit_where_clauses[] = "al.action = ?"; $audit_params[] = $audit_action; }
if ($audit_admin_id) { $audit_where_clauses[] = "al.actor_id = ?"; $audit_params[] = $audit_admin_id; }
if ($audit_start_date) { $audit_where_clauses[] = "al.timestamp >= ?"; $audit_params[] = $audit_start_date . ' 00:00:00'; }
if ($audit_end_date) { $audit_where_clauses[] = "al.timestamp <= ?"; $audit_params[] = $audit_end_date . ' 23:59:59'; }
 
$where_sql_part = "";
if (!empty($audit_where_clauses)) {
    $where_sql_part = " WHERE " . implode(" AND ", $audit_where_clauses);
}
 
// Fetch Audit Logs (with error handling and filtering)
$audit_logs = [];
$total_audit_logs = 0;
$total_audit_pages = 0;
 
try {
    // Count total records for pagination
    $count_sql = "SELECT COUNT(al.id) FROM audit_log al LEFT JOIN users target ON al.target_user_id = target.id" . $where_sql_part;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($audit_params);
    $total_audit_logs = $count_stmt->fetchColumn();
    $total_audit_pages = ceil($total_audit_logs / $audit_per_page);
 
    // Fetch the records for the current page
    $audit_base_sql = "
        SELECT al.*, actor.first_name as admin_first, actor.last_name as admin_last,
               target.first_name as target_first, target.last_name as target_last, target.username as target_username
        FROM audit_log al
        JOIN users actor ON al.actor_id = actor.id
        LEFT JOIN users target ON al.target_user_id = target.id
    " . $where_sql_part . " ORDER BY al.timestamp DESC LIMIT ? OFFSET ?";
 
    $audit_logs_stmt = $pdo->prepare($audit_base_sql);
 
    // Bind parameters one by one to set types
    $param_index = 1;
    foreach ($audit_params as $param) { $audit_logs_stmt->bindValue($param_index++, $param); }
    $audit_logs_stmt->bindValue($param_index++, $audit_per_page, PDO::PARAM_INT);
    $audit_logs_stmt->bindValue($param_index++, $audit_offset, PDO::PARAM_INT);
 
    $audit_logs_stmt->execute();
    $audit_logs = $audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("Audit Log Fetch Error (admin.php): " . $e->getMessage()); }

// Fetch recent audit logs for dashboard widget
$recent_audit_logs = [];
try {
    $recent_audit_logs_stmt = $pdo->query("
        SELECT 
            al.*, 
            actor.first_name as admin_first, actor.last_name as admin_last,
            target.username as target_username
        FROM audit_log al
        JOIN users actor ON al.actor_id = actor.id
        LEFT JOIN users target ON al.target_user_id = target.id
        ORDER BY al.timestamp DESC
        LIMIT 5
    ");
    $recent_audit_logs = $recent_audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail if table doesn't exist, the widget will just be empty.
    error_log("Dashboard Audit Widget Error: " . $e->getMessage());
}

// --- Data for Broadcast History Tab ---
$broadcast_history = [];
$total_broadcasts = 0;
$broadcast_page = 1;
$broadcast_per_page = 15;
$total_broadcast_pages = 0;

if ($active_tab === 'broadcast-history') {
    $broadcast_page = isset($_GET['broadcast_page']) ? (int)$_GET['broadcast_page'] : 1;
    if ($broadcast_page < 1) $broadcast_page = 1;
    $broadcast_offset = ($broadcast_page - 1) * $broadcast_per_page;

    // Count total unique broadcasts sent by admins
    // A "broadcast" is approximated by a unique message sent by an admin at a specific minute.
    // This is a heuristic because the current schema doesn't have a dedicated 'broadcasts' table.
    $total_broadcast_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT CONCAT(n.sender_id, n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')))
        FROM notifications n
        JOIN users u ON n.sender_id = u.id
        WHERE u.role = 'admin'
    ");
    $total_broadcast_stmt->execute();
    $total_broadcasts = $total_broadcast_stmt->fetchColumn();
    $total_broadcast_pages = ceil($total_broadcasts / $broadcast_per_page);

    // Fetch paginated broadcast history
    // Group by sender, message, and a minute-level timestamp to represent a single broadcast event.
    $broadcast_stmt = $pdo->prepare("
        SELECT 
            n.sender_id,
            u.first_name as sender_first,
            u.last_name as sender_last,
            n.message,
            MIN(n.created_at) as created_at,
            COUNT(n.id) as recipient_count,
            GROUP_CONCAT(DISTINCT r.role ORDER BY r.role SEPARATOR ', ') as target_roles
        FROM notifications n
        JOIN users u ON n.sender_id = u.id
        JOIN users r ON n.recipient_id = r.id
        WHERE u.role = 'admin'
        GROUP BY n.sender_id, n.message, DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i')
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $broadcast_stmt->bindValue(1, $broadcast_per_page, PDO::PARAM_INT);
    $broadcast_stmt->bindValue(2, $broadcast_offset, PDO::PARAM_INT);
    $broadcast_stmt->execute();
    $broadcast_history = $broadcast_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Subjects with enrolled students for the 'subjects' tab accordion
$subjects_with_enrollments = [];
$enrollments_stmt = $pdo->prepare("
    SELECT 
        s.id as subject_id, s.name as subject_name, s.code as subject_code, s.description, s.teacher_id,
        s.target_course, s.target_year_level, s.status,
        t.first_name as teacher_first, t.last_name as teacher_last,
        u.id as student_id, u.first_name, u.last_name, u.username, u.course, u.year_level
    FROM subjects s
    LEFT JOIN grades g ON s.id = g.subject_id
    LEFT JOIN users u ON g.student_id = u.id AND u.role = 'student' AND u.status = 'active'
    LEFT JOIN users t ON s.teacher_id = t.id
    ORDER BY s.name, u.last_name, u.first_name
");
$enrollments_stmt->execute();
$all_enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_enrollments as $enrollment) {
    $subject_id = $enrollment['subject_id'];
    if (!isset($subjects_with_enrollments[$subject_id])) {
        // On first encounter, store all subject details
        $subjects_with_enrollments[$subject_id] = $enrollment;
        $subjects_with_enrollments[$subject_id]['students'] = [];
    }
    if ($enrollment['student_id']) {
        $subjects_with_enrollments[$subject_id]['students'][] = $enrollment;
    }
}

// Fetch Grade Weights for labels
$weights = getGradeWeights($pdo);

// Fetch Recent Users for Dashboard
$recent_admins = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();
$recent_teachers = $pdo->query("SELECT * FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();
$recent_students = $pdo->query("SELECT * FROM users WHERE role = 'student' AND status = 'active' ORDER BY id DESC LIMIT 5")->fetchAll();

// Assign variables for use in templates
$users = $staff_users;
$subjects = $subjects_with_enrollments;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="css/portal.css" rel="stylesheet">
    <?php outputThemeColors($pdo); ?>
</head>
 
<body class="d-flex flex-column min-vh-100">
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
                        <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="Logo" class="sidebar-logo mb-2">
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
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?php echo in_array($active_tab, ['archive', 'broadcast-history', 'audit']) ? 'active' : ''; ?>" href="#" id="moreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-h me-2"></i>More
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="moreDropdown">
                                <li><a class="dropdown-item <?php echo $active_tab === 'archive' ? 'active' : ''; ?>" href="admin.php?tab=archive"><i class="fas fa-archive me-2"></i>Archive <span class="badge bg-light text-dark ms-2"><?php echo $total_archived; ?></span></a></li>
                                <li><a class="dropdown-item <?php echo $active_tab === 'broadcast-history' ? 'active' : ''; ?>" href="admin.php?tab=broadcast-history"><i class="fas fa-mail-bulk me-2"></i>Broadcast History</a></li>
                                <li><a class="dropdown-item <?php echo $active_tab === 'audit' ? 'active' : ''; ?>" href="admin.php?tab=audit"><i class="fas fa-history me-2"></i>Audit Log</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" href="admin.php?tab=settings">
                                <i class="fas fa-cog me-2"></i>Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <form action="logout.php" method="post" id="logoutForm" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <a class="nav-link" href="#" onclick="if(confirm('Are you sure you want to logout?')) document.getElementById('logoutForm').submit(); return false;">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="main-content" id="main-content">
                <nav class="navbar navbar-light bg-light px-4 border-bottom d-lg-none app-header">
                    <button type="button" class="btn toggle-btn" id="sidebarToggle" aria-label="Toggle navigation"><i class="fas fa-bars"></i></button>
                    <span class="navbar-brand mb-0 h1 app-header-title">Admin Panel</span>
                </nav>
                <div class="px-4 py-4">
                <div class="tab-content">
                    <!-- Dashboard Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="dash-header mb-4">
                            <div class="dash-header-text">
                                <h2>Admin Dashboard</h2>
                                <span>Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?>!</span>
                            </div>
                            <button class="btn btn-warning dash-header-action" data-bs-toggle="modal" data-bs-target="#broadcastNotificationModal"><i class="fas fa-bullhorn me-2"></i>Broadcast Notification</button>
                        </div>

                        <!-- Statistics Cards -->
                        <div class="row mb-4 stat-grid">
                            <div class="col-lg-3 col-md-6 mb-3">
                                <div class="stat-box grad-blue">
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
                            <div class="col-lg-3 col-md-6 mb-3">
                                <div class="stat-box grad-teal">
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
                            <div class="col-lg-3 col-md-6 mb-3">
                                <div class="stat-box grad-orange">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <h4><?php echo $total_admins; ?></h4>
                                                <p>Total Admins</p>
                                            </div>
                                            <i class="fas fa-user-shield fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-md-6 mb-3">
                                <div class="stat-box grad-red">
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
                                                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                                                <td><span class="badge bg-danger">Admin</span></td>
                                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
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
                                                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                                                <td><span class="badge bg-success">Teacher</span></td>
                                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
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
                                                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                                                <td><span class="badge bg-primary">Student</span></td>
                                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
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

                        <!-- Recent Audit Log Widget -->
                        <div class="card border-0 shadow-sm mt-4">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-secondary"></i>Recent System Activity</h5>
                                <a href="admin.php?tab=audit" class="btn btn-sm btn-outline-secondary">View Full Log <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush">
                                    <?php if (empty($recent_audit_logs)): ?>
                                        <li class="list-group-item text-center text-muted p-4">No recent activity recorded.</li>
                                    <?php else: ?>
                                        <?php foreach ($recent_audit_logs as $log): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center p-3">
                                                <div> 
                                                    <span class="fw-bold"><?php echo htmlspecialchars($log['admin_first'] . ' ' . $log['admin_last']); ?></span>
                                                    <span class="text-muted">performed action</span>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))); ?></span>
                                                    <?php 
                                                    $target_display = '';
                                                    if (!empty($log['target_username'])) {
                                                        // User exists, show their current username.
                                                        $target_display = '@' . htmlspecialchars($log['target_username']);
                                                    } elseif (!empty($log['target_user_id'])) {
                                                        // User was purged, parse username from details.
                                                        if (preg_match("/user '([^']*)'/", $log['details'], $matches)) {
                                                            $target_display = '@' . htmlspecialchars($matches[1]) . ' <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Purged</span>';
                                                        }
                                                    }
                                                    if ($target_display):
                                                    ?>
                                                        <span class="text-muted">on</span>
                                                        <span class="fw-bold"><?php echo $target_display; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted flex-shrink-0 ms-3"><?php echo date('M j, g:i A', strtotime($log['timestamp'])); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'users' ? 'show active' : ''; ?>" id="users">
                        <h2 class="mb-4">Manage Users</h2>
                        <div class="d-flex justify-content-end align-items-center mb-4">
                            <a href="add_user.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New User</a>
                        </div>

                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" action="admin.php" class="d-flex gap-2 position-relative">
                                    <input type="hidden" name="tab" value="users">
                                    <div class="flex-grow-1 position-relative">
                                        <input type="text" name="search_user" id="liveSearchInput" class="form-control" placeholder="Search by name, username, or email..." value="<?php echo htmlspecialchars($search_user); ?>" autocomplete="off">
                                        <div id="searchResultsDropdown" class="list-group position-absolute w-100 shadow-lg d-none"></div>
                                    </div>
                                    <button type="submit" id="adminUserSearchClearBtn" class="btn btn-primary" title="Search"><i class="fas fa-search"></i></button>
                                </form>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header bg-danger text-white">
                                <h5 class="mb-0"><i class="fas fa-user-shield me-2"></i>Admins</h5>
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
                                            <?php $i = 1; foreach ($admins as $user): ?>
                                                    <tr id="user-row-<?php echo $user['id']; ?>">
                                                        <td><?php echo htmlspecialchars($i++); ?></td>
                                                        <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                        <td>
                                                            <?php echo renderAdminUserActionButtons($user, $search_user); ?>
                                                        </td>
                                                    </tr>
                                            <?php endforeach; ?>
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
                                            <?php $i = 1; foreach ($teachers as $user): ?>
                                                    <tr id="user-row-<?php echo $user['id']; ?>">
                                                        <td><?php echo htmlspecialchars($i++); ?></td>
                                                        <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                        <td>
                                                            <?php echo renderAdminUserActionButtons($user, $search_user); ?>
                                                        </td>
                                                    </tr>
                                            <?php endforeach; ?>
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
                                                    <td class="align-middle"><?php echo $i++; ?></td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                    </td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($student['username']); ?></td>
                                                    <td class="align-middle">
                                                        <?php if ($student['enrolled_subjects']): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Enrolled</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="align-middle"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($student['course'] . ' | ' . $student['year_level']); ?></span></td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($student['email']); ?></td>
                                                    <td class="align-middle">
                                                        <?php echo renderAdminUserActionButtons($student, $search_user); ?>
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
                                    <input type="text" name="search_user" id="archiveSearchInput" class="form-control" placeholder="Search archive..." value="<?php echo htmlspecialchars($search_user); ?>">
                                    <button type="submit" id="adminArchiveSearchClearBtn" class="btn btn-primary" title="Search"><i class="fas fa-search"></i></button>
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
                                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucfirst($au['role'])); ?></span></td>
                                                        <td><strong><?php echo htmlspecialchars($au['first_name'] . ' ' . $au['last_name']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($au['username']); ?></td>
                                                        <td><?php echo htmlspecialchars($au['email']); ?></td>
                                                        <td>
                                                            <a href="?action=restore_user&id=<?php echo htmlspecialchars($au['id']); ?>&search_user=<?php echo urlencode($search_user); ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-sm btn-outline-success">
                                                                <i class="fas fa-undo me-1"></i> Restore
                                                            </a>
                                                            <a href="?action=delete_user&id=<?php echo htmlspecialchars($au['id']); ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('PERMANENTLY DELETE this user and all associated data? This cannot be undone.')">
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

                    <!-- Audit Log Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'audit' ? 'show active' : ''; ?>" id="audit">
                        <h2 class="mb-4">System Audit Log</h2>

                        <!-- Filter Form -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <form method="GET" action="admin.php" class="row g-3 align-items-end">
                                    <input type="hidden" name="tab" value="audit">
                                    <div class="col-md-3">
                                        <label for="audit_search" class="form-label small">Search Details/User</label>
                                        <input type="text" class="form-control form-control-sm" id="audit_search" name="audit_search" value="<?php echo htmlspecialchars($audit_search); ?>" placeholder="e.g. user_create, @username">
                                    </div>
                                    <div class="col-md-2">
                                        <label for="audit_action" class="form-label small">Action Type</label>
                                        <select class="form-select form-select-sm" id="audit_action" name="audit_action">
                                            <option value="">All Actions</option>
                                            <?php foreach ($distinct_actions as $action_item): ?>
                                                <option value="<?php echo htmlspecialchars($action_item); ?>" <?php echo ($audit_action === $action_item) ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $action_item))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="audit_admin_id" class="form-label small">Performed By</label>
                                        <select class="form-select form-select-sm" id="audit_admin_id" name="audit_admin_id">
                                            <option value="">All Admins</option>
                                            <?php foreach ($all_admins_for_filter as $admin_user): ?> 
                                                <option value="<?php echo $admin_user['id']; ?>" <?php echo ($audit_admin_id == $admin_user['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($admin_user['first_name'] . ' ' . $admin_user['last_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2"><label for="audit_start_date" class="form-label small">Start Date</label><input type="text" class="form-control form-control-sm flatpickr-date" id="audit_start_date" name="audit_start_date" value="<?php echo htmlspecialchars($audit_start_date); ?>" placeholder="Select start date..." readonly></div>
                                    <div class="col-md-2"><label for="audit_end_date" class="form-label small">End Date</label><input type="text" class="form-control form-control-sm flatpickr-date" id="audit_end_date" name="audit_end_date" value="<?php echo htmlspecialchars($audit_end_date); ?>" placeholder="Select end date..." readonly></div>
                                    <div class="col-md-1 d-flex"><button type="submit" class="btn btn-sm btn-primary w-100 me-2" title="Apply Filters"><i class="fas fa-filter"></i></button><a href="admin.php?tab=audit" class="btn btn-sm btn-secondary w-100" title="Clear Filters"><i class="fas fa-times"></i></a></div>
                                </form>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Administrative Actions (<?php echo number_format($total_audit_logs); ?> records found)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-3">Timestamp</th> 
                                                <th>Admin</th>
                                                <th>Action</th>
                                                <th>Target User</th>
                                                <th style="width: 35%;">Details</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($audit_logs)): ?>
                                                <tr><td colspan="6" class="text-center py-5 text-muted">No audit records found. This may be a new feature.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($audit_logs as $log): ?>
                                                    <tr>
                                                        <td class="ps-3"><small><?php echo date('M j, Y g:i:s A', strtotime($log['timestamp'])); ?></small></td>
                                                        <td><?php echo htmlspecialchars($log['admin_first'] . ' ' . $log['admin_last']); ?></td>
                                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></span></td>
                                                        <td>
                                                            <?php if (!empty($log['target_user_id'])): ?>
                                                                <?php 
                                                                if (!empty($log['target_username'])) {
                                                                    // User exists, show their current name and username.
                                                                    echo htmlspecialchars(($log['target_first'] ?? '') . ' ' . ($log['target_last'] ?? ''));
                                                                    echo '<small class="text-muted d-block">@' . htmlspecialchars($log['target_username']) . '</small>';
                                                                } else {
                                                                    // User was purged, parse username from details.
                                                                    if (preg_match("/user '([^']*)'/", $log['details'], $matches)) {
                                                                        echo '<span class="text-danger">' . htmlspecialchars($matches[1]) . ' (Purged)</span>';
                                                                    } else {
                                                                        echo '<span class="text-danger">Purged User</span>';
                                                                    }
                                                                    echo '<small class="text-muted d-block">User ID: ' . htmlspecialchars($log['target_user_id']) . '</small>';
                                                                } ?>
                                                            <?php else: ?><span class="text-muted">N/A</span><?php endif; ?>
                                                        </td>
                                                        <td><pre class="mb-0" style="font-size: 0.8em; white-space: pre-wrap;"><?php echo htmlspecialchars($log['details']); ?></pre></td>
                                                        <td>
                                                            <?php if ($log['action'] === 'user_update' && $log['target_user_id']): ?>
                                                                <a href="admin.php?tab=audit&action=revert_update&log_id=<?php echo $log['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                                                                   class="btn btn-sm btn-outline-warning" title="Revert this change" 
                                                                   onclick="return confirm('Are you sure you want to revert this user update? This will restore the previous values.');"><i class="fas fa-history"></i> Revert</a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <?php
                        $pagination_params = array_filter([
                            'tab' => 'audit', 'audit_search' => $audit_search, 'audit_action' => $audit_action,
                            'audit_admin_id' => $audit_admin_id, 'audit_start_date' => $audit_start_date, 'audit_end_date' => $audit_end_date,
                        ]);
                        $query_string = http_build_query($pagination_params);
                        ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <span class="text-muted small">
                                Page <?php echo $audit_page; ?> of <?php echo $total_audit_pages > 0 ? $total_audit_pages : 1; ?>
                            </span>
                            <?php if ($total_audit_pages > 1): ?>
                            <nav>
                                <ul class="pagination mb-0">
                                    <li class="page-item <?php echo ($audit_page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo $query_string . '&audit_page=' . ($audit_page - 1); ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_audit_pages; $i++): ?>
                                        <?php if ($i == $audit_page || $i <= 2 || $i >= $total_audit_pages - 1 || abs($i - $audit_page) <= 1): ?>
                                            <li class="page-item <?php echo ($audit_page == $i) ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo $query_string . '&audit_page=' . $i; ?>"><?php echo $i; ?></a></li>
                                        <?php elseif ($i == $audit_page - 2 || $i == $audit_page + 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo ($audit_page >= $total_audit_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo $query_string . '&audit_page=' . ($audit_page + 1); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Broadcast History Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'broadcast-history' ? 'show active' : ''; ?>" id="broadcast-history">
                        <h2 class="mb-4">Broadcast History</h2>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Sent Broadcasts (<?php echo $total_broadcasts; ?> total)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">Sender</th>
                                                <th style="width: 40%;">Message</th>
                                                <th>Target Audience</th>
                                                <th class="text-center">Recipients</th>
                                                <th>Date Sent</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($broadcast_history)): ?>
                                                <tr><td colspan="5" class="text-center text-muted p-5">No broadcast history found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($broadcast_history as $broadcast): ?>
                                                    <tr class="align-middle">
                                                        <td class="ps-4 fw-bold"><?php echo htmlspecialchars($broadcast['sender_first'] . ' ' . $broadcast['sender_last']); ?></td>
                                                        <td><small><?php echo highlightNotificationKeywords($broadcast['message']); ?></small></td>
                                                        <td><?php $roles = explode(', ', $broadcast['target_roles']); foreach ($roles as $role) { echo '<span class="badge bg-secondary me-1">' . htmlspecialchars(ucfirst($role)) . '</span>'; } ?></td>
                                                        <td class="text-center"><span class="badge rounded-pill bg-primary"><?php echo $broadcast['recipient_count']; ?></span></td>
                                                        <td><small><?php echo date('M j, Y, g:i a', strtotime($broadcast['created_at'])); ?></small></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination for Broadcast History -->
                        <?php if ($total_broadcast_pages > 1): ?>
                        <nav class="mt-4"><ul class="pagination justify-content-center">
                            <li class="page-item <?php echo ($broadcast_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?tab=broadcast-history&broadcast_page=<?php echo $broadcast_page - 1; ?>">Previous</a></li>
                            <?php for ($i = 1; $i <= $total_broadcast_pages; $i++): ?><?php if ($i == $broadcast_page || $i <= 2 || $i >= $total_broadcast_pages - 1 || abs($i - $broadcast_page) <= 1): ?><li class="page-item <?php echo ($broadcast_page == $i) ? 'active' : ''; ?>"><a class="page-link" href="?tab=broadcast-history&broadcast_page=<?php echo $i; ?>"><?php echo $i; ?></a></li><?php elseif ($i == $broadcast_page - 2 || $i == $broadcast_page + 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?><?php endfor; ?>
                            <li class="page-item <?php echo ($broadcast_page >= $total_broadcast_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?tab=broadcast-history&broadcast_page=<?php echo $broadcast_page + 1; ?>">Next</a></li>
                        </ul></nav>
                        <?php endif; ?>
                    </div>

                    <!-- Settings Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                        <h2 class="mb-4">System Settings</h2>
                        <?php $active_sub_tab = $_GET['sub_tab'] ?? 'profile'; ?>
                        <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $active_sub_tab === 'profile' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pills-profile">My Profile</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $active_sub_tab === 'branding' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pills-branding">Branding & Policy</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $active_sub_tab === 'smtp' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pills-smtp">Email / SMTP</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $active_sub_tab === 'theme' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pills-theme">Theme Colors</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link <?php echo $active_sub_tab === 'broadcast' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pills-broadcast">Broadcast Editor</button></li>
                        </ul>
                        <div class="tab-content" id="pills-tabContent">
                            <div class="tab-pane fade <?php echo $active_sub_tab === 'profile' ? 'show active' : ''; ?>" id="pills-profile">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">My Admin Profile</div>
                                    <div class="card-body">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3"><label class="form-label">First Name</label><input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($admin_info['first_name']); ?>" required></div>
                                                        <div class="col-md-6 mb-3"><label class="form-label">Last Name</label><input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($admin_info['last_name']); ?>" required></div>
                                                        <div class="col-12 mb-3"><label class="form-label">Email Address</label><input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin_info['email']); ?>" required></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <img src="<?php echo htmlspecialchars($admin_info['profile_picture'] ?: 'image/admin_logo.jpeg'); ?>" id="adminProfilePicturePreview" class="rounded-circle mb-2 cropper-preview" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #eee;" onerror="this.onerror=null;this.src='image/admin_logo.jpeg';">
                                                    <input type="file" class="form-control form-control-sm cropper-file-input" name="profile_picture" accept="image/*">
                                                    <input type="hidden" name="cropped_image_data" class="cropper-data">
                                                    <div class="form-text">Update Picture</div>
                                                </div>
                                            </div>
                                                <hr class="my-3">
                                            <div class="row">
                                                <div class="col-md-6 mb-3"><label class="form-label">New Password</label><input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep current"></div>
                                                <div class="col-md-6 mb-3"><label class="form-label">Confirm New Password</label><input type="password" class="form-control" name="confirm_password"></div>
                                            </div>
                                            <div class="text-end"><button type="submit" name="update_admin_profile" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update My Profile</button></div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade <?php echo $active_sub_tab === 'branding' ? 'show active' : ''; ?>" id="pills-branding">
                                <div class="card mb-4 border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Configuration & Branding</div>
                                    <div class="card-body">
                                        <form method="POST" enctype="multipart/form-data" class="row align-items-end g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="col-md-3 text-center">
                                                <img src="<?php echo getSystemLogoUrl($pdo); ?>" class="rounded border p-1" style="width: 100px; height: 100px; object-fit: cover;">
                                                <div class="small text-muted mt-2">Current Logo</div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Upload New System Logo</label>
                                                <input type="file" name="logo_file" class="form-control" accept="image/*" required>
                                                <div class="form-text">This logo will be displayed across all portal dashboards and login pages.</div>
                                            </div>
                                            <div class="col-md-3"><button type="submit" name="update_logo" class="btn btn-dark w-100"><i class="fas fa-upload me-2"></i>Update Branding</button></div>
                                        </form>
                                    </div>
                                </div>
                                <div class="card mb-4 border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Login Page Slideshow</div>
                                    <div class="card-body">
                                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Enable Slideshow</label>
                                                <select name="login_slideshow_enabled" class="form-select">
                                                    <option value="1" <?php echo getSystemSetting($pdo, 'login_slideshow_enabled', '1') === '1' ? 'selected' : ''; ?>>Enabled</option>
                                                    <option value="0" <?php echo getSystemSetting($pdo, 'login_slideshow_enabled', '1') === '0' ? 'selected' : ''; ?>>Disabled</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Slide Transition (seconds)</label>
                                                <input type="number" name="login_slideshow_interval" class="form-control" min="3" max="30" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'login_slideshow_interval', '6')); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Overlay Opacity</label>
                                                <input type="range" name="login_slideshow_opacity" class="form-control" min="0" max="100" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'login_slideshow_opacity', '30')); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Form Glassmorphism</label>
                                                <select name="login_form_glass" class="form-select">
                                                    <option value="1" <?php echo getSystemSetting($pdo, 'login_form_glass', '1') === '1' ? 'selected' : ''; ?>>Enabled</option>
                                                    <option value="0" <?php echo getSystemSetting($pdo, 'login_form_glass', '1') === '0' ? 'selected' : ''; ?>>Disabled</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Slideshow Image 1</label>
                                                <input type="file" name="slideshow_image_1" class="form-control" accept="image/*">
                                                <div class="form-text">Upload slideshow image 1 (1920x1080px recommended)</div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Slideshow Image 2</label>
                                                <input type="file" name="slideshow_image_2" class="form-control" accept="image/*">
                                                <div class="form-text">Upload slideshow image 2 (1920x1080px recommended)</div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Slideshow Image 3</label>
                                                <input type="file" name="slideshow_image_3" class="form-control" accept="image/*">
                                                <div class="form-text">Upload slideshow image 3 (1920x1080px recommended)</div>
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" name="update_slideshow" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Slideshow Settings</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="card mb-4 border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Registration Page Background</div>
                                    <div class="card-body">
                                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Background Type</label>
                                                <select name="register_bg_type" class="form-select">
                                                    <option value="color" <?php echo getSystemSetting($pdo, 'register_bg_type', 'color') === 'color' ? 'selected' : ''; ?>>Solid Color</option>
                                                    <option value="image" <?php echo getSystemSetting($pdo, 'register_bg_type', 'color') === 'image' ? 'selected' : ''; ?>>Single Image</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Background Color</label>
                                                <input type="color" name="register_bg_color" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'register_bg_color', '#f1f5f9')); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Background Image</label>
                                                <input type="file" name="register_bg_image" class="form-control" accept="image/*">
                                                <div class="form-text">Upload background image (1920x1080px recommended)</div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">Image Opacity</label>
                                                <input type="range" name="register_bg_opacity" class="form-control" min="0" max="100" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'register_bg_opacity', '100')); ?>">
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" name="update_register_bg" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Register Background</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="card mb-4 border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Institutional Grading Policy</div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="row g-4 mb-4">
                                                <div class="col-md-6 border-end">
                                                    <h6 class="fw-bold text-primary mb-3">LECTURE SETTINGS (Overall: <input type="number" name="weight_lec" class="d-inline form-control form-control-sm w-25 text-center" value="<?php echo getSystemSetting($pdo, 'weight_lec', 40); ?>">% )</h6>
                                                    <div class="row g-2">
                                                        <div class="col-4"><label class="small fw-bold">Quiz (%)</label><input type="number" name="weight_lec_quiz" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lec_quiz', 40); ?>"></div>
                                                        <div class="col-4"><label class="small fw-bold">Online (%)</label><input type="number" name="weight_lec_online" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lec_online', 10); ?>"></div>
                                                        <div class="col-4"><label class="small fw-bold">Exam (%)</label><input type="number" name="weight_lec_exam" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lec_exam', 50); ?>"></div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="fw-bold text-success mb-3">LABORATORY SETTINGS (Overall: <input type="number" name="weight_lab" class="d-inline form-control form-control-sm w-25 text-center" value="<?php echo getSystemSetting($pdo, 'weight_lab', 60); ?>">% )</h6>
                                                    <div class="row g-2">
                                                        <div class="col-4"><label class="small fw-bold">Activity (%)</label><input type="number" name="weight_lab_cs" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lab_cs', 40); ?>"></div>
                                                        <div class="col-4"><label class="small fw-bold">Att (%)</label><input type="number" name="weight_lab_att" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lab_att', 10); ?>"></div>
                                                        <div class="col-4"><label class="small fw-bold">Exam (%)</label><input type="number" name="weight_lab_exam" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'weight_lab_exam', 50); ?>"></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <hr>
                                            <h6 class="fw-bold text-dark mb-3">SEMENTRAL / PERIOD DISTRIBUTION (Overall Grade %)</h6>
                                            <div class="row g-2 mb-3">
                                                <div class="col-3"><label class="small fw-bold">Prelim (%)</label><input type="number" name="period_weight_prelim" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'period_weight_prelim', 20); ?>"></div>
                                                <div class="col-3"><label class="small fw-bold">Midterm (%)</label><input type="number" name="period_weight_midterm" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'period_weight_midterm', 20); ?>"></div>
                                                <div class="col-3"><label class="small fw-bold">Prefinal (%)</label><input type="number" name="period_weight_prefinal" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'period_weight_prefinal', 20); ?>"></div>
                                                <div class="col-3"><label class="small fw-bold">Final (%)</label><input type="number" name="period_weight_final" class="form-control form-control-sm text-center" value="<?php echo getSystemSetting($pdo, 'period_weight_final', 40); ?>"></div>
                                            </div>
                                            <div class="text-end"><button type="submit" name="update_weights" class="btn btn-dark btn-sm"><i class="fas fa-save me-2"></i>Apply Global Policy</button></div>
                                        </form>
                                        <div class="alert alert-light border small text-center mt-3"><strong>Semestral Distribution:</strong> Prelim (<?php echo getSystemSetting($pdo,'period_weight_prelim',20); ?>%) + Midterm (<?php echo getSystemSetting($pdo,'period_weight_midterm',20); ?>%) + Prefinal (<?php echo getSystemSetting($pdo,'period_weight_prefinal',20); ?>%) + Final (<?php echo getSystemSetting($pdo,'period_weight_final',40); ?>%). Configure to match your school/teacher grading policy; imports and calculations use these weights.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade <?php echo $active_sub_tab === 'theme' ? 'show active' : ''; ?>" id="pills-theme">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Theme Colors</div>
                                    <div class="card-body">
                                        <form method="POST" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Sidebar Background</label>
                                                <input type="color" name="theme_sidebar_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_sidebar_bg', '#0f172a')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Primary / Accent Color</label>
                                                <input type="color" name="theme_primary_color" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_primary_color', '#1e3a8a')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Body Background</label>
                                                <input type="color" name="theme_body_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_body_bg', '#f1f5f9')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Header / Navbar Background</label>
                                                <input type="color" name="theme_header_bg" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_header_bg', '#ffffff')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Main Text Color</label>
                                                <input type="color" name="theme_text_color" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_text_color', '#1e293b')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Accent / Highlight Color</label>
                                                <input type="color" name="theme_accent_color" class="form-control form-control-color" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'theme_accent_color', '#6366f1')); ?>">
                                            </div>
                                            <div class="col-12 text-end">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Reset all theme colors to default?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <button type="submit" name="reset_theme_colors" class="btn btn-outline-secondary"><i class="fas fa-undo me-2"></i>Reset to Default</button>
                                                </form>
                                                <button type="submit" name="update_theme_colors" class="btn btn-primary ms-2"><i class="fas fa-palette me-2"></i>Save Theme Colors</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade <?php echo $active_sub_tab === 'smtp' ? 'show active' : ''; ?>" id="pills-smtp">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-white fw-bold">Email / SMTP Configuration</div>
                                    <div class="card-body">
                                        <form method="POST" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">From Email</label>
                                                <input type="email" name="smtp_from_email" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_from_email', '')); ?>" placeholder="your-email@gmail.com">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">From Name</label>
                                                <input type="text" name="smtp_from_name" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_from_name', 'GradeView')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">SMTP Host</label>
                                                <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_host', 'smtp.gmail.com')); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Port</label>
                                                <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_port', '587')); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small fw-bold">Encryption</label>
                                                <select name="smtp_encryption" class="form-select">
                                                    <option value="tls" <?php echo getSystemSetting($pdo, 'smtp_encryption', 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                    <option value="ssl" <?php echo getSystemSetting($pdo, 'smtp_encryption', 'tls') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                    <option value="none" <?php echo getSystemSetting($pdo, 'smtp_encryption', 'tls') === 'none' ? 'selected' : ''; ?>>None</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">SMTP Username</label>
                                                <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_username', '')); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">SMTP Password / App Password</label>
                                                <div class="input-group">
                                                    <input type="password" name="smtp_password" id="smtp_password" class="form-control" value="<?php echo htmlspecialchars(getSystemSetting($pdo, 'smtp_password', '')); ?>">
                                                    <span class="input-group-text" id="smtp_eye_wrap">
                                                        <i class="fas fa-eye" id="smtp_eye"></i>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" name="update_smtp_settings" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save SMTP Settings</button>
                                            </div>
                                        </form>
                                        <div class="alert alert-info border-0 shadow-sm mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Gmail users:</strong> Use an <a href="https://support.google.com/accounts/answer/185833" target="_blank">App Password</a> instead of your regular password. Also enable 2-Step Verification in your Google Account.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade <?php echo $active_sub_tab === 'broadcast' ? 'show active' : ''; ?>" id="pills-broadcast">
                                <?php
                                $role_access_map = [
                                    'admin' => 'Registrar/Admins', 'teacher' => 'Teachers',
                                    'student' => 'Students', 'all' => 'All Senders'
                                ];
                                ?>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white fw-bold">Add New Template</div>
                                            <div class="card-body">
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required placeholder="e.g., Exam Reminder"></div>
                                                    <div class="mb-3"><label class="form-label">Message</label><textarea name="message" class="form-control" rows="4" required placeholder="Enter template message..."></textarea></div>
                                                    <div class="mb-3">
                                                        <label class="form-label">Visible To</label>
                                                        <select name="role_access" class="form-select" required>
                                                            <option value="admin">Registrar/Admins Only</option>
                                                            <option value="teacher">Teachers Only</option>
                                                            <option value="student">Students Only</option>
                                                            <option value="all">All Senders (Admins & Teachers)</option>
                                                        </select>
                                                    </div>
                                                    <button type="submit" name="add_broadcast_template" class="btn btn-primary w-100">Add Template</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="card border-0 shadow-sm">
                                            <div class="card-header bg-white fw-bold">Manage Broadcast Templates</div>
                                            <div class="card-body p-0">
                                                <div class="table-responsive">
                                                    <table class="table table-hover mb-0">
                                                        <thead class="table-light"><tr><th>Title</th><th>Visible To</th><th>Actions</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($broadcast_templates as $template): ?>
                                                            <tr>
                                                                <td class="fw-bold"><?php echo htmlspecialchars($template['title']); ?></td> 
                                                                <td><span class="badge bg-secondary"><?php echo $role_access_map[$template['role_access']] ?? ucfirst($template['role_access']); ?></span></td>
                                                                <td>
                                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTemplateModal" data-id="<?php echo $template['id']; ?>" data-title="<?php echo htmlspecialchars($template['title']); ?>" data-message="<?php echo htmlspecialchars($template['message']); ?>" data-access="<?php echo $template['role_access']; ?>"><i class="fas fa-edit"></i></button>
                                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this template?');">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                        <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                                                        <button type="submit" name="delete_broadcast_template" class="btn btn-sm btn-outline-danger" title="Delete Template"><i class="fas fa-trash"></i></button>
                                                                    </form>
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
                                <form method="POST" id="gradingPolicyForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <div class="row">
                                        <div class="col-md-2">
                                            <input type="text" class="form-control mb-3" name="name"
                                                placeholder="Subject Name" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control mb-3" name="description"
                                                placeholder="Description">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control mb-3" name="target_course" placeholder="Courses (e.g. BSCS,BSIT)">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control mb-3" name="target_year_level" placeholder="Years (e.g. 1st,2nd)">
                                        </div>
                                        <div class="col-md-4">
                                            <select class="form-select mb-3" name="teacher_id">
                                                <option value="">Assign to Teacher (Optional)</option>
                                                <?php foreach ($staff_users as $user):
                                                    if ($user['role'] == 'teacher'): ?>
                                                        <option value="<?php echo $user['id']; ?>">
                                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                        </option>
                                                    <?php endif;
                                                endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" name="add_subject" class="btn btn-primary">Add
                                        Subject</button>
                                </form>
                            </div>
                        </div>

                        <h5 class="mb-3 fw-bold text-secondary"><i class="fas fa-list-ul me-2"></i>Subject Enrollment Lists</h5>
                        <div class="accordion" id="adminSubjectsAccordion">
                            <?php if (empty($subjects)): ?>
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body text-center text-muted p-5">
                                        <p class="mb-0">No subjects found. Add one using the form above.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php $idx = 0; foreach ($subjects as $subject_id => $subject_data): $idx++; ?>
                                    <div class="accordion-item" id="subject-row-<?php echo $subject_id; ?>">
                                        <h2 class="accordion-header" id="admin-heading-<?php echo $subject_id; ?>">
                                            <button class="accordion-button <?php echo $idx > 1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#admin-collapse-<?php echo $subject_id; ?>" aria-expanded="<?php echo $idx === 1 ? 'true' : 'false'; ?>" aria-controls="admin-collapse-<?php echo $subject_id; ?>">
                                                <div class="d-flex w-100 justify-content-between align-items-center pe-4 subject-accordion-head">
                                                    <span class="fw-bold"><?php echo htmlspecialchars($subject_data['subject_name']); ?> (<?php echo htmlspecialchars($subject_data['subject_code'] ?? 'N/A'); ?>)</span>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($subject_data['teacher_first'] ? $subject_data['teacher_first'] . ' ' . $subject_data['teacher_last'] : 'Unassigned'); ?></span>
                                                    <?php if ($subject_data['status'] === 'finalized'): ?>
                                                        <span class="badge bg-danger"><i class="fas fa-lock me-1"></i> Finalized</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success"><i class="fas fa-lock-open me-1"></i> Open</span>
                                                    <?php endif; ?>

                                                    <span class="badge bg-primary rounded-pill"><?php echo count($subject_data['students']); ?> Students</span>
                                                </div>
                                            </button>
                                        </h2>
                                        <div id="admin-collapse-<?php echo $subject_id; ?>" class="accordion-collapse collapse <?php echo $idx === 1 ? 'show' : ''; ?>" aria-labelledby="admin-heading-<?php echo $subject_id; ?>" data-bs-parent="#adminSubjectsAccordion">
                                            <div class="accordion-body">
                                                <div class="d-flex justify-content-between align-items-center p-2 bg-light border-bottom mb-2 subject-actions-bar">
                                                    <div><small><strong>Restrictions:</strong> Course: <?php echo htmlspecialchars($subject_data['target_course'] ?? 'Any'); ?>, Year: <?php echo htmlspecialchars($subject_data['target_year_level'] ?? 'Any'); ?></small></div>
                                                    <div>
                                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#enrollStudentsModal" data-subject-id="<?php echo htmlspecialchars($subject_id); ?>" data-subject-name="<?php echo htmlspecialchars($subject_data['subject_name'] ?? ''); ?>" title="Import enrollees from file"><i class="fas fa-file-import"></i> Import Enrollees</button>
                                                        <a href="print_grade.php?subject_id=<?php echo htmlspecialchars($subject_id); ?>" target="_blank" class="btn btn-sm btn-outline-info"><i class="fas fa-print"></i> Report</a>
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                            data-id="<?php echo htmlspecialchars($subject_id); ?>"
                                                            data-name="<?php echo htmlspecialchars($subject_data['subject_name'] ?? ''); ?>"
                                                            data-code="<?php echo htmlspecialchars($subject_data['subject_code'] ?? ''); ?>"
                                                            data-description="<?php echo htmlspecialchars($subject_data['description'] ?? ''); ?>"
                                                           
                                                            data-target-course="<?php echo htmlspecialchars($subject_data['target_course'] ?? ''); ?>"
                                                            data-target-year="<?php echo htmlspecialchars($subject_data['target_year_level'] ?? ''); ?>"><i class="fas fa-edit"></i> Edit</button>
                                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSubjectModal" data-id="<?php echo htmlspecialchars($subject_id); ?>" data-name="<?php echo htmlspecialchars($subject_data['subject_name'] ?? ''); ?>"><i class="fas fa-trash"></i> Delete</button>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="subject_id" value="<?php echo htmlspecialchars($subject_id); ?>">
                                                            <?php if ($subject_data['status'] !== 'finalized'): ?>
                                                                <button type="submit" name="finalize_subject" class="btn btn-sm btn-outline-warning" onclick="return confirm('Finalizing this subject will lock all associated grade entries for teachers. Are you sure?')"><i class="fas fa-lock"></i> Finalize</button>
                                                            <?php else: ?>
                                                                <button type="submit" name="reopen_subject" class="btn btn-sm btn-outline-secondary"><i class="fas fa-lock-open"></i> Re-open</button>
                                                            <?php endif; ?>
                                                        </form>
                                                    </div>
                                                </div>
                                                <?php if (empty($subject_data['students'])): ?>
                                                    <div class="p-3 text-center text-muted">No students are enrolled in this subject.</div>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-hover table-sm mb-0">
                                                            <thead class="table-light"><tr><th class="ps-3">#</th><th>Student Name</th><th>Username</th><th>Course & Year</th></tr></thead>
                                                            <tbody>
                                                                <?php $i = 1; foreach ($subject_data['students'] as $student): ?>
                                                                    <tr>
                                                                        <td class="ps-3"><?php echo $i++; ?></td>
                                                                        <td class="fw-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                                                        <td><span class="badge bg-light text-dark border">@<?php echo htmlspecialchars($student['username']); ?></span></td>
                                                                        <td><small><?php echo htmlspecialchars($student['course'] . ' - ' . $student['year_level']); ?></small></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="search_context" value="<?php echo htmlspecialchars($search_user); ?>">
                        <input type="hidden" name="user_id" id="edit_user_id">

                        <div class="text-center mb-3">
                            <img src="image/default.png" id="edit_profile_picture_preview" class="rounded-circle cropper-preview" style="width: 100px; height: 100px; object-fit: cover; border: 2px solid #eee;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Update Profile Picture</label>
                                <input type="file" class="form-control form-control-sm cropper-file-input" name="profile_picture" accept="image/jpeg,image/png,image/gif">
                            <input type="hidden" name="cropped_image_data" class="cropper-data">
                            <div class="form-text">Optional. Max 2MB. JPG, PNG, GIF.</div>
                        </div>
                        <hr>

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
                            <select class="form-select" name="role" id="edit_role" required onchange="toggleEditStudentFields(this.value)">
                                <option value="admin">Admin</option>
                                <option value="teacher">Teacher</option>
                                <option value="student">Student</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" name="phone_number" id="edit_phone_number">
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
                            <div class="col-12 mb-3">
                                <label class="form-label">Home Address</label>
                                <input type="text" class="form-control" name="home_address" id="edit_home_address" placeholder="e.g., 123 Main St., Brgy. Sample">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">City/Municipality</label>
                                <input type="text" class="form-control" name="city" id="edit_city">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Province</label>
                                <input type="text" class="form-control" name="province" id="edit_province">
                            </div>
                            <div class="col-12 mt-3"><h6 class="text-primary border-top pt-3">Guardian Information</h6></div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Guardian Name</label>
                                <input type="text" class="form-control" name="guardian_name" id="edit_guardian_name">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Guardian Status</label>
                                <select class="form-select" name="guardian_status" id="edit_guardian_status">
                                    <option value="">-- Select --</option>
                                    <option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Guardian Phone</label>
                                <input type="text" class="form-control" name="guardian_phone" id="edit_guardian_phone">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Guardian Occupation</label>
                                <input type="text" class="form-control" name="guardian_occupation" id="edit_guardian_occupation">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Guardian Address</label>
                                <input type="text" class="form-control" name="guardian_address" id="edit_guardian_address">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" name="update_user" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="changePasswordForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="search_context" value="<?php echo htmlspecialchars($search_user); ?>">
                        <input type="hidden" name="user_id" id="password_user_id">
                        <div class="mb-4">
                            <label class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-user"></i></span>
                            <input type="text" class="form-control fw-bold" name="username" id="password_username" readonly>
                            </div>
                            <div class="form-text">You are resetting the security credentials for this account.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password (Min 8 characters)</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="new_password" id="change_password_field" required>
                                <span class="input-group-text password-toggle">
                                    <i class="fas fa-eye"></i>
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
                                <input type="password" class="form-control" name="confirm_password" id="confirm_password_field" required>
                                <span class="input-group-text password-toggle">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <div class="invalid-feedback" id="change_cpass_feedback" style="display: none; color: #dc3545; font-size: 0.8rem; margin-top: 5px;">Passwords do not match</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" name="update_password" class="btn btn-primary"><i class="fas fa-key me-2"></i>Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Broadcast Notification Modal -->
    <div class="modal fade" id="broadcastNotificationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Broadcast Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="broadcastForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="broadcast_notification" value="1">
                        <div class="mb-3">
                            <label class="form-label">Target Audience</label>
                            <div class="p-3 border rounded">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="" id="targetAll">
                                    <label class="form-check-label fw-bold" for="targetAll">All Users</label>
                                </div>
                                <hr class="my-2">
                                <div class="form-check">
                                    <input class="form-check-input target-group-checkbox" type="checkbox" name="target_groups[]" value="admin" id="targetAdmins">
                                    <label class="form-check-label" for="targetAdmins">Admins</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input target-group-checkbox" type="checkbox" name="target_groups[]" value="teacher" id="targetTeachers">
                                    <label class="form-check-label" for="targetTeachers">Teachers</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input target-group-checkbox" type="checkbox" name="target_groups[]" value="student" id="targetStudents">
                                    <label class="form-check-label" for="targetStudents">Students</label>
                                </div>
                            </div>
                            <div class="form-text">Select one or more groups to receive the broadcast.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="send_email" value="1" id="broadcastSendEmail" checked>
                            <label class="form-check-label" for="broadcastSendEmail">Also send a copy via email to each recipient</label>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Quick Messages</label>
                            <div>
                                <?php foreach ($broadcast_templates as $template):
                                    if ($template['role_access'] === 'admin' || $template['role_access'] === 'all'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-1 admin-quick-message-btn" data-message="<?php echo htmlspecialchars($template['message'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($template['title']); ?></button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div> 
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small">Set Date</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm" id="broadcast_date_picker" placeholder="Select a date..." readonly>
                                    <button class="btn btn-outline-primary" type="button" id="insert_date_btn" title="Insert Date"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Set Time</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm" id="broadcast_time_picker" placeholder="Select a time..." readonly>
                                    <button class="btn btn-outline-info" type="button" id="insert_time_btn" title="Insert Time"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" id="broadcastMessage" name="message" rows="5" required placeholder="Enter your broadcast message here..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="broadcast_notification" class="btn btn-warning">Send Broadcast</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Broadcast Template Modal -->
    <div class="modal fade" id="editTemplateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title">Edit Broadcast Template</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="template_id" id="edit_template_id">
                        <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" id="edit_template_title" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Message</label><textarea name="message" id="edit_template_message" class="form-control" rows="4" required></textarea></div>
                        <div class="mb-3">
                            <label class="form-label">Visible To</label>
                            <select name="role_access" id="edit_template_access" class="form-select" required>
                                <option value="admin">Registrar/Admins Only</option>
                                <option value="teacher">Teachers Only</option>
                                <option value="student">Students Only</option>
                                <option value="all">All Senders (Admins & Teachers)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="update_broadcast_template" class="btn btn-primary">Save Changes</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title">Edit Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                        <div class="mb-3">
                            <label class="form-label">Target Course(s)</label>
                            <input type="text" class="form-control" name="target_course" id="edit_subject_target_course" placeholder="e.g. BSCS, BSIT">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Target Year Level(s)</label>
                            <input type="text" class="form-control" name="target_year_level" id="edit_subject_target_year" placeholder="e.g. 1st Year, 2nd Year">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign Teacher</label>
                            <select class="form-select" name="teacher_id" id="edit_subject_teacher">
                                <option value="">Unassigned</option>
                                <?php foreach ($staff_users as $user):
                                    if ($user['role'] == 'teacher'): ?>
                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></option>
                                    <?php endif;
                                endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" name="update_subject" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Subject Confirmation Modal (for Admin) -->
    <div class="modal fade" id="deleteSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="admin.php?tab=subjects" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_subject"> 
                        <input type="hidden" name="subject_id" id="delete_subject_id_admin">
                        <p>This action is irreversible. You will permanently delete the subject <strong id="delete_subject_name_text_admin"></strong> and all associated grade records.</p>
                        <p>To confirm, please type the subject's name below:</p>
                        <div class="mb-3">
                            <input type="text" class="form-control" id="delete_confirm_input_admin" autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" id="confirmDeleteBtnAdmin" class="btn btn-danger" disabled>
                            <i class="fas fa-trash me-2"></i>I understand, delete this subject
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View User Full Profile Modal -->
    <div class="modal fade" id="viewUserProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>User Profile Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-lg-4 text-center border-end pe-lg-4 mb-4 mb-lg-0 d-flex flex-column align-items-center">
                            <img src="image/admin_logo.jpeg" id="view_profile_picture" class="rounded-circle mb-3 avatar-lg" onerror="this.onerror=null;this.src='image/admin_logo.jpeg';">
                            <h4 class="mb-0 fw-bold" id="view_fullname_header"></h4>
                            <p class="text-muted" id="view_username_header"></p>
                            <div id="view_student_subheader" style="display: none;">
                                <p class="text-info fw-bold"><span id="view_course"></span> - <span id="view_year"></span></p>
                            </div>
                            <hr class="w-100">
                            <h6 class="text-primary align-self-start">Account Credentials</h6>
                            <p class="text-start mb-1 w-100"><strong>Email:</strong> <span id="view_email"></span></p>
                            <p class="text-start mb-1 w-100"><strong>Role:</strong> <span class="badge bg-secondary" id="view_role"></span></p>
                        </div>
                        <div class="col-lg-8 ps-lg-4">
                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4"><p class="mb-1 small text-muted">Suffix</p><strong id="view_suffix">N/A</strong></div>
                                <div class="col-md-4"><p class="mb-1 small text-muted">Birth Date</p><strong id="view_bdate">N/A</strong></div>
                                <div class="col-md-4"><p class="mb-1 small text-muted">Age</p><strong id="view_age">N/A</strong></div>
                                <div class="col-md-4"><p class="mb-1 small text-muted">Sex</p><strong id="view_sex">N/A</strong></div>
                                <div class="col-md-8"><p class="mb-1 small text-muted">Phone Number</p><strong id="view_phone">N/A</strong></div>
                                <div class="col-md-12"><p class="mb-1 small text-muted">Full Address</p><strong id="view_address">N/A</strong></div>
                            </div>

                            <div id="view_guardian_info" style="display: none;">
                                <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-users me-2"></i>Guardian Information</h5>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Full Name</p><strong id="view_gname">N/A</strong></div>
                                    <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Phone</p><strong id="view_gphone">N/A</strong></div>
                                    <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Occupation</p><strong id="view_gocc">N/A</strong></div>
                                    <div class="col-md-6"><p class="mb-1 small text-muted">Guardian's Status</p><strong id="view_gstatus">N/A</strong></div>
                                    <div class="col-12 mt-2"><p class="mb-1 small text-muted">Guardian's Address</p><strong id="view_gaddress">N/A</strong></div>
                                </div>
                            </div>
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


    <!-- Enroll Students Modal -->
    <div class="modal fade" id="enrollStudentsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Import Enrollees</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="subject_id" id="enroll_csv_subject_id">
                    <div class="modal-body">
                        <p class="small text-muted mb-2">Subject: <strong id="enroll_csv_subject_name"></strong></p>
                        <div class="alert alert-info small">Upload a CSV, TSV, TXT, or Excel (.xlsx) file containing the enrollees for this subject. Students are matched by <code>student_id</code>, <code>username</code>, or <code>email</code> (headers auto-detected). Matching students are enrolled; existing enrollments are skipped.</div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Enrollees File (CSV/TSV/Excel)</label>
                            <input type="file" class="form-control" name="enroll_file" accept=".csv,.tsv,.txt,.xlsx,.xlsm,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="enroll_students_import" class="btn btn-success"><i class="fas fa-file-import me-1"></i>Import Enrollees</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        window.pageConfig = {
            toastMessage: "<?php echo addslashes($message); ?>",
            toastType: "<?php echo $message_type ?: 'info'; ?>",
            newSubjectId: "<?php echo $_SESSION['new_subject_id'] ?? ''; ?>",
            updatedUserId: "<?php echo $_SESSION['updated_user_id'] ?? ''; ?>",
            activeTab: "<?php echo $active_tab; ?>",
            subTab: "<?php echo $_GET['sub_tab'] ?? ''; ?>"
        };
        <?php 
            // Clear session variables after passing them to JS
            unset($_SESSION['new_subject_id'], $_SESSION['updated_user_id']);
        ?>
    </script>
    <script src="admin.js"></script>
    <script>
        /* Toggle Visibility for SMTP Password */
        window.toggleVisibility = function(inputId, eyeId) {
            var input = document.getElementById(inputId);
            var eye = document.getElementById(eyeId);
            if (input && eye) {
                if (input.type === "password") {
                    input.type = "text";
                    eye.classList.remove('fa-eye');
                    eye.classList.add('fa-eye-slash');
                } else {
                    input.type = "password";
                    eye.classList.remove('fa-eye-slash');
                    eye.classList.add('fa-eye');
                }
            }
        };
        document.addEventListener('DOMContentLoaded', function() {
            var smtpWrap = document.getElementById('smtp_eye_wrap');
            if (smtpWrap) {
                smtpWrap.style.cursor = 'pointer';
                smtpWrap.addEventListener('click', function() {
                    window.toggleVisibility('smtp_password', 'smtp_eye');
                });
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            var enrollModal=document.getElementById("enrollStudentsModal");
            if(enrollModal){enrollModal.addEventListener("show.bs.modal",function(e){var b=e.relatedTarget;if(!b)return;var i=document.getElementById("enroll_csv_subject_id");var n=document.getElementById("enroll_csv_subject_name");if(i)i.value=b.dataset.subjectId||"";if(n)n.textContent=b.dataset.subjectName||"";});}
            var importModal=document.getElementById("importGradesModal");
            if(importModal){importModal.addEventListener("show.bs.modal",function(e){var b=e.relatedTarget;if(!b)return;var i=document.getElementById("import_grades_subject_id");var n=document.getElementById("import_grades_subject_name");if(i)i.value=b.dataset.subjectId||"";if(n)n.textContent=b.dataset.subjectName||"";});}
        });
    </script>
    <script src="includes/notifications.js"></script>
</body>
</html>
