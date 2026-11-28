<?php
include 'includes/conn.php';
include 'includes/functions.php';
include 'includes/mailer.php';
requireRole('teacher');
validateSession(); 

$teacher_id = $_SESSION['user_id'];
$teacher_info_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$teacher_info_stmt->execute([$teacher_id]);
$teacher_info = $teacher_info_stmt->fetch(PDO::FETCH_ASSOC);
if (!$teacher_info) {
    // Failsafe in case user is deleted but session persists
    header("Location: logout.php"); exit;
}

// Handle AJAX to mark notification as read
if (isset($_POST['ajax_mark_read'])) {
    header('Content-Type: application/json');
    try {
        $notification_id = $_POST['notification_id'];
        $user_id = $_SESSION['user_id'];

        // Security check: make sure user owns the notification
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ? AND is_read = 0");
        $stmt->execute([$notification_id, $user_id]);

        // Recalculate unread count
        $unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
        $unread_count_stmt->execute([$user_id]);
        $new_count = $unread_count_stmt->fetchColumn();
        echo json_encode(['status' => 'success', 'new_count' => $new_count]);

    } catch (PDOException $e) {
        // Log error and respond
        error_log("Mark as read error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
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
        error_log("Mark all as read error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
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

// Handle Grade File Import (store Excel/CSV file for external editing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_grade_file') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } elseif (!isset($_FILES['grade_file']) || ($_FILES['grade_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $_SESSION['message'] = "Please choose an Excel/CSV file to import.";
        $_SESSION['message_type'] = "warning";
    } else {
        $file = $_FILES['grade_file'];
        $origName = (string)$file['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $size = (int)$file['size'];
        $allowedExt = ['xlsx','xls','xlsm','xltx','xltm','csv','tsv','txt'];
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $_SESSION['message'] = "File upload failed.";
            $_SESSION['message_type'] = "danger";
        } elseif (!in_array($ext, $allowedExt, true)) {
            $_SESSION['message'] = "Unsupported file type. Use .xlsx, .xls, .csv, .tsv, or .txt.";
            $_SESSION['message_type'] = "danger";
        } elseif ($size <= 0 || $size > 20 * 1024 * 1024) {
            $_SESSION['message'] = "File is empty or too large (max 20MB).";
            $_SESSION['message_type'] = "danger";
        } else {
            $dir = __DIR__ . '/uploads/grade_imports';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME));
            if ($safeBase === '' || $safeBase === '_') { $safeBase = 'grade'; }

            // Resolve/validate the subject first so a re-import can match the existing entry.
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            $subjectName = '';
            $importNote = null;
            if ($subjectId > 0) {
                try {
                    $q = $pdo->prepare("SELECT s.name FROM subjects s WHERE s.id = ? AND (s.teacher_id = ? OR s.teacher_id IS NULL OR EXISTS (SELECT 1 FROM grades g WHERE g.subject_id = s.id AND g.teacher_id = ?))");
                    $q->execute([$subjectId, $teacher_id, $teacher_id]);
                    $subjectName = (string)$q->fetchColumn();
                } catch (Throwable $e) {}
                if ($subjectName === '') {
                    $subjectId = 0;
                    $importNote = ["File imported, but the selected subject is not available to you. Re-import with one of your subjects to compute grades.", "warning"];
                }
            }

            $manifestFile = $dir . '/manifest.json';
            $manifest = is_file($manifestFile) ? (json_decode(@file_get_contents($manifestFile), true) ?: []) : [];
            if (!is_array($manifest)) { $manifest = []; }

            // Use the clean original filename (no random date/hash prefix) so the file
            // opens/saves in Excel under its real name. A numeric suffix is added only
            // when another uploader already uses the same filename.
            $stored = $safeBase . '.' . $ext;
            $existingIndex = -1;
            foreach ($manifest as $i => $m) {
                if (($m['original_name'] ?? '') === $origName
                    && (int)($m['subject_id'] ?? 0) === $subjectId
                    && (int)($m['uploader_id'] ?? 0) === (int)$_SESSION['user_id']) {
                    $existingIndex = $i;
                    $oldStored = (string)($m['stored'] ?? '');
                    if ($oldStored !== '' && strtolower(pathinfo($oldStored, PATHINFO_EXTENSION)) === $ext) {
                        $stored = $oldStored; // same format: overwrite the existing file
                    } elseif ($oldStored !== '' && is_file($dir . '/' . $oldStored)) {
                        @unlink($dir . '/' . $oldStored); // format changed: remove the stale file
                    }
                    break;
                }
            }

            // Avoid clobbering a different uploader's file that happens to share the name.
            $suffix = 1;
            while (true) {
                $clash = false;
                foreach ($manifest as $m) {
                    if (($m['stored'] ?? '') === $stored && (int)($m['uploader_id'] ?? 0) !== (int)$_SESSION['user_id']) { $clash = true; break; }
                }
                if (!$clash) break;
                $stored = $safeBase . '_' . $suffix . '.' . $ext;
                $suffix++;
            }

            $dest = $dir . '/' . $stored;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $entry = [
                    'stored'        => $stored,
                    'original_name' => $origName,
                    'subject_id'    => $subjectId,
                    'subject_name'  => $subjectName,
                    'uploader_id'   => (int)$_SESSION['user_id'],
                    'uploader_name' => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                    'uploaded_at'   => date('Y-m-d H:i:s'),
                    'size'          => $size,
                ];
                if ($existingIndex >= 0) { $manifest[$existingIndex] = $entry; }
                else { $manifest[] = $entry; }
                @file_put_contents($manifestFile, json_encode(array_values($manifest), JSON_PRETTY_PRINT), LOCK_EX);

                if ($importNote === null) {
                    if ($subjectId <= 0) {
                        $importNote = ["File imported. Select one of your subjects on the next import to compute grades.", "warning"];
                    } else {
                        $res = importComputeGrades($pdo, $dest, $origName, $subjectId, $teacher_id);
                        if (!empty($res['error'])) {
                            $importNote = ["File imported, but grades were not computed: " . $res['error'], "warning"];
                        } else {
                            $msg = "File imported. Computed grades for " . (int)$res['updated'] . " student(s)";
                            if (!empty($res['skipped'])) { $msg .= "; " . (int)$res['skipped'] . " row(s) skipped"; }
                            $msg .= ". Download it to edit in Excel, then import the saved file to refresh.";
                            if (!empty($res['unmatched'])) {
                                $sample = array_slice($res['unmatched'], 0, 3);
                                $msg .= " Unmatched: " . implode(', ', $sample) . (count($res['unmatched']) > 3 ? ', ...' : '') . '.';
                            }
                            $importNote = [$msg, !empty($res['updated']) ? "success" : "warning"];
                        }
                    }
                }

                $_SESSION['message'] = $importNote[0];
                $_SESSION['message_type'] = $importNote[1];
            } else {
                $_SESSION['message'] = "Failed to save the uploaded file.";
                $_SESSION['message_type'] = "danger";
            }
        }
    }
    header("Location: teacher.php?tab=manage-grades");
    exit;
}

// Handle Grade File Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_grade_file') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $stored = basename((string)($_POST['stored'] ?? ''));
        $dir = __DIR__ . '/uploads/grade_imports';
        $manifestFile = $dir . '/manifest.json';
        $manifest = is_file($manifestFile) ? (json_decode(@file_get_contents($manifestFile), true) ?: []) : [];
        if (!is_array($manifest)) { $manifest = []; }
        $found = false;
        foreach ($manifest as $k => $m) {
            if (($m['stored'] ?? '') === $stored && $stored !== '') {
                if ((int)($m['uploader_id'] ?? 0) !== $teacher_id) { break; }
                unset($manifest[$k]); $found = true; break;
            }
        }
        if ($found) {
            @file_put_contents($manifestFile, json_encode(array_values($manifest), JSON_PRETTY_PRINT), LOCK_EX);
            $path = $dir . '/' . $stored;
            if (is_file($path)) { @unlink($path); }
            $_SESSION['message'] = "File removed.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "File not found.";
            $_SESSION['message_type'] = "warning";
        }
    }
    header("Location: teacher.php?tab=manage-grades");
    exit;
}

// Handle Grade File Recompute (re-read the stored file after editing it in Excel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recompute_grade_file') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $stored = basename((string)($_POST['stored'] ?? ''));
        $dir = __DIR__ . '/uploads/grade_imports';
        $manifestFile = $dir . '/manifest.json';
        $manifest = is_file($manifestFile) ? (json_decode(@file_get_contents($manifestFile), true) ?: []) : [];
        if (!is_array($manifest)) { $manifest = []; }

        $entry = null;
        foreach ($manifest as $m) {
            if (($m['stored'] ?? '') === $stored && $stored !== '') {
                if ((int)($m['uploader_id'] ?? 0) === $teacher_id) { $entry = $m; }
                break;
            }
        }

        if (!$entry) {
            $_SESSION['message'] = "File not found.";
            $_SESSION['message_type'] = "warning";
        } else {
            $subjectId = (int)($entry['subject_id'] ?? 0);
            $subjectName = '';
            if ($subjectId > 0) {
                try {
                    $q = $pdo->prepare("SELECT s.name FROM subjects s WHERE s.id = ? AND (s.teacher_id = ? OR s.teacher_id IS NULL OR EXISTS (SELECT 1 FROM grades g WHERE g.subject_id = s.id AND g.teacher_id = ?))");
                    $q->execute([$subjectId, $teacher_id, $teacher_id]);
                    $subjectName = (string)$q->fetchColumn();
                } catch (Throwable $e) {}
            }

            if ($subjectId <= 0 || $subjectName === '') {
                $_SESSION['message'] = "This file has no subject assigned to you. Re-import it with one of your subjects to compute grades.";
                $_SESSION['message_type'] = "warning";
            } else {
                $path = $dir . '/' . $stored;
                $res = importComputeGrades($pdo, $path, (string)($entry['original_name'] ?? $stored), $subjectId, $teacher_id);
                if (!empty($res['error'])) {
                    $_SESSION['message'] = "Could not compute grades: " . $res['error'];
                    $_SESSION['message_type'] = "danger";
                } else {
                    $msg = "Recomputed grades for " . (int)$res['updated'] . " student(s)";
                    if (!empty($res['skipped'])) { $msg .= "; " . (int)$res['skipped'] . " row(s) skipped"; }
                    if (!empty($res['unmatched'])) {
                        $sample = array_slice($res['unmatched'], 0, 3);
                        $msg .= ". Unmatched: " . implode(', ', $sample) . (count($res['unmatched']) > 3 ? ', ...' : '');
                    }
                    $_SESSION['message'] = $msg . '.';
                    $_SESSION['message_type'] = !empty($res['updated']) ? "success" : "warning";
                }
            }
        }
    }
    header("Location: teacher.php?tab=manage-grades");
    exit;
}

// Handle in-browser editor Save (overwrite the stored workbook, then recompute)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grade_file_editor') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=manage-grades");
        exit;
    }
    $stored = basename((string)($_POST['stored'] ?? ''));
    $dir = __DIR__ . '/uploads/grade_imports';
    $manifestFile = $dir . '/manifest.json';
    $manifest = is_file($manifestFile) ? (json_decode(@file_get_contents($manifestFile), true) ?: []) : [];
    if (!is_array($manifest)) { $manifest = []; }

    $entry = null;
    foreach ($manifest as $m) {
        if (($m['stored'] ?? '') === $stored && $stored !== '') {
            if ((int)($m['uploader_id'] ?? 0) === $teacher_id) { $entry = $m; }
            break;
        }
    }

    $uploaded = $_FILES['grade_file'] ?? null;
    if (!$entry) {
        $_SESSION['message'] = "File not found.";
        $_SESSION['message_type'] = "warning";
    } elseif (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $_SESSION['message'] = "No edited file was received.";
        $_SESSION['message_type'] = "warning";
    } elseif (($uploaded['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = "Upload failed while saving.";
        $_SESSION['message_type'] = "danger";
    } else {
        $path = $dir . '/' . $stored;
        if (move_uploaded_file($uploaded['tmp_name'], $path)) {
            $subjectId = (int)($entry['subject_id'] ?? 0);
            $subjectName = '';
            if ($subjectId > 0) {
                try {
                    $q = $pdo->prepare("SELECT s.name FROM subjects s WHERE s.id = ? AND (s.teacher_id = ? OR s.teacher_id IS NULL OR EXISTS (SELECT 1 FROM grades g WHERE g.subject_id = s.id AND g.teacher_id = ?))");
                    $q->execute([$subjectId, $teacher_id, $teacher_id]);
                    $subjectName = (string)$q->fetchColumn();
                } catch (Throwable $e) {}
            }
            if ($subjectId <= 0 || $subjectName === '') {
                $_SESSION['message'] = "Changes saved. This file has no subject assigned, so grades were not recomputed.";
                $_SESSION['message_type'] = "warning";
            } else {
                $res = importComputeGrades($pdo, $path, (string)($entry['original_name'] ?? $stored), $subjectId, $teacher_id);
                if (!empty($res['error'])) {
                    $_SESSION['message'] = "Changes saved, but grades were not recomputed: " . $res['error'];
                    $_SESSION['message_type'] = "warning";
                } else {
                    $_SESSION['message'] = "Changes saved. Recomputed grades for " . (int)$res['updated'] . " student(s).";
                    $_SESSION['message_type'] = "success";
                }
            }
        } else {
            $_SESSION['message'] = "Could not save the edited file.";
            $_SESSION['message_type'] = "danger";
        }
    }
    header("Location: teacher.php?tab=manage-grades");
    exit;
}

// Handle Send Notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $recipient_id = $_POST['recipient_id'];
        $message = trim($_POST['message']);
        $sender_id = $_SESSION['user_id'];

        if (empty($message)) {
            $_SESSION['message'] = "Notification message cannot be empty.";
            $_SESSION['message_type'] = "warning";
        } else {
            try {
                // Add a check to ensure the recipient exists before sending.
                $check_student = $pdo->prepare("SELECT id, email, first_name FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
                $check_student->execute([$recipient_id]);
                $student = $check_student->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    // This is the most likely cause of the foreign key error.
                    $_SESSION['message'] = "Error: The target student does not exist or is inactive. Notification not sent.";
                    $_SESSION['message_type'] = "danger";
                } else {
                    $sql = "INSERT INTO notifications (sender_id, recipient_id, message) VALUES (?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$sender_id, $recipient_id, $message]);

                    $emailNote = '';
                    if (!empty($_POST['send_email'])) {
                        if (isEmailConfigured($pdo) && filter_var($student['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                            $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937">'
                                . '<p>Hello ' . htmlspecialchars($student['first_name'] ?: 'Student') . ',</p>'
                                . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
                                . '<p style="color:#6b7280;font-size:12px;margin-top:16px">Sent by your teacher via GradeView.</p>'
                                . '</div>';
                            $emailNote = sendMail($student['email'], 'GradeView Notification', $body) ? ' Email copy sent.' : ' (Email could not be sent.)';
                        } else {
                            $emailNote = ' (Email not configured or recipient has no valid email.)';
                        }
                    }
                    $_SESSION['message'] = "Notification sent successfully." . $emailNote;
                    $_SESSION['message_type'] = "success";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == '42S02') { // Base table or view not found
                    $_SESSION['message'] = "Error: The 'notifications' database table is missing. Please ask the administrator to set it up.";
                } else {
                    $_SESSION['message'] = "Database Error: Could not send notification.";
                }
                $_SESSION['message_type'] = "danger";
                error_log("Notification Error: " . $e->getMessage());
            }
        }
    }
    $active_tab_context = $_POST['active_tab_context'] ?? 'dashboard';
    header("Location: teacher.php?tab=" . urlencode($active_tab_context));
    exit;
}

// Handle Notify Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_class'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $subject_id = $_POST['subject_id'];
        $message = trim($_POST['message']);
        $sender_id = $_SESSION['user_id'];

        if (empty($message) || empty($subject_id)) {
            $_SESSION['message'] = "Message and subject are required.";
            $_SESSION['message_type'] = "warning";
        } else {
            $students_stmt = $pdo->prepare("SELECT student_id, email, first_name FROM grades g JOIN users u ON g.student_id = u.id WHERE g.subject_id = ? AND g.teacher_id = ?");
            $students_stmt->execute([$subject_id, $sender_id]);
            $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($students)) {
                $sendEmail = !empty($_POST['send_email']);
                $emailReady = $sendEmail && isEmailConfigured($pdo);
                $emailed = 0;
                $sent = 0;
                $ins = $pdo->prepare("INSERT INTO notifications (sender_id, recipient_id, message) VALUES (?, ?, ?)");
                foreach ($students as $s) {
                    try {
                        $ins->execute([$sender_id, $s['student_id'], $message]);
                        $sent++;
                    } catch (PDOException $e) {
                        error_log("Notify Class insert failed for student {$s['student_id']}: " . $e->getMessage());
                    }
                }

                if ($sent === 0) {
                    $_SESSION['message'] = "Database Error: Could not send the class broadcast.";
                    $_SESSION['message_type'] = "danger";
                    header("Location: teacher.php?tab=dashboard");
                    exit;
                }

                if ($emailReady) {
                    foreach ($students as $s) {
                        if (empty($s['email']) || !filter_var($s['email'], FILTER_VALIDATE_EMAIL)) continue;
                        try {
                            $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937">'
                                . '<p>Hello ' . htmlspecialchars($s['first_name'] ?: 'Student') . ',</p>'
                                . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
                                . '<p style="color:#6b7280;font-size:12px;margin-top:16px">Sent by your teacher via GradeView.</p>'
                                . '</div>';
                            if (sendMail($s['email'], 'GradeView Class Broadcast', $body)) $emailed++;
                        } catch (Throwable $te) {
                            error_log("Class broadcast email failed for {$s['email']}: " . $te->getMessage());
                        }
                    }
                }

                $_SESSION['message'] = "Notification sent to " . $sent . " students in the class."
                    . ($sendEmail ? ($emailReady ? " Email copies sent: {$emailed}." : " (Email not configured, so no email copies were sent.)") : "");
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "No students are enrolled in this subject yet, so the broadcast was not sent.";
                $_SESSION['message_type'] = "warning";
            }
        }
    }
    header("Location: teacher.php?tab=dashboard");
    exit;
}

// CSRF Protection: Generate a token if one doesn't exist for the forms on this page.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle AJAX to get students for a subject (scoped to teacher)
if (isset($_GET['ajax_get_students_by_subject'])) {
    header('Content-Type: application/json');
    try {
        $subject_id = $_GET['ajax_get_students_by_subject'];
        
        // Security: A teacher can view a subject's student list if they are the owner,
        // or if they have at least one student enrolled in it (for global subjects).
        $auth_stmt = $pdo->prepare("
            SELECT 1 FROM subjects s
            WHERE s.id = ? AND (
                s.teacher_id = ? 
                OR EXISTS (SELECT 1 FROM grades g WHERE g.subject_id = s.id AND g.teacher_id = ?)
            ) LIMIT 1
        ");
        $auth_stmt->execute([$subject_id, $teacher_id, $teacher_id]);
        if ($auth_stmt->fetchColumn() === false) {
            http_response_code(403);
            echo json_encode(['error' => 'Authorization denied. You do not have permission to view this subject.']);
            exit;
        }

        // Fetch subject details
        $subject_stmt = $pdo->prepare("SELECT name, target_course, target_year_level FROM subjects WHERE id = ?");
        $subject_stmt->execute([$subject_id]);
        $subject_details = $subject_stmt->fetch(PDO::FETCH_ASSOC);

        // Query to get students enrolled in the specific subject for this teacher
        $students_stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.course, u.year_level, u.profile_picture
            FROM users u JOIN grades g ON u.id = g.student_id
            WHERE g.subject_id = ? AND g.teacher_id = ? AND u.role = 'student' AND u.status = 'active'
            ORDER BY u.last_name, u.first_name
        ");
        $students_stmt->execute([$subject_id, $teacher_id]);
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['details' => $subject_details, 'students' => $students]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Teacher AJAX get_students_by_subject failed: " . $e->getMessage());
        echo json_encode(['error' => 'A database error occurred. This might be due to missing `target_course` or `target_year_level` columns in the `subjects` table.']);
    }
    exit;
}

// Handle AJAX Student Search Suggestions
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $query = $_GET['ajax_search'];
    $stmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE role = 'student' AND status = 'active' AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?) LIMIT 5");
    $stmt->execute(["%$query%", "%$query%", "%$query%", "%$query%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Set default timezone if not already set globally
date_default_timezone_set('Asia/Hong_Kong');

// Define available periods for the ledger view
$ledger_periods = [
    'prelim'    => 'PRELIM PERIOD',
    'midterm'   => 'MIDTERM PERIOD',
    'prefinal'  => 'PREFINAL PERIOD',
    'final'     => 'FINAL PERIOD'
];

// Handle AJAX Performance Monitoring
if (isset($_GET['ajax_performance'])) {
    header('Content-Type: application/json');
    $student_id = $_GET['ajax_performance'];

    // Security: Authorization check - ensure teacher has at least one grade record for this student.
    $auth_stmt = $pdo->prepare("SELECT 1 FROM grades WHERE student_id = ? AND teacher_id = ? LIMIT 1");
    $auth_stmt->execute([$student_id, $teacher_id]);
    if ($auth_stmt->fetchColumn() === false) {
        echo json_encode(['status' => 'error', 'message' => 'Authorization denied.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT g.*, s.name as subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ?");
    $stmt->execute([$student_id]);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $period_sums = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
    $period_counts = ['prelim' => 0, 'midterm' => 0, 'prefinal' => 0, 'final' => 0];
    $datasets = [];
    $avg_sum = 0;

    // New: Per-period distribution
    $periods = ['prelim', 'midterm', 'prefinal', 'final'];
    $period_distributions = [];
    $grade_point_labels = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'IP'];
    foreach ($periods as $p) {
        $period_distributions[$p] = array_fill_keys($grade_point_labels, 0);
    }

    foreach ($grades as $g) {
        $avg_sum += $g['grade'];
        foreach(['prelim', 'midterm', 'prefinal', 'final'] as $p) {
            if (isset($g[$p]) && $g[$p] > 0) {
                $period_sums[$p] += $g[$p];
                $period_counts[$p]++;
                $point = calculateGradePoint($g[$p]);
                if (isset($period_distributions[$p][$point])) {
                    $period_distributions[$p][$point]++;
                }
            }
        }
        $hue = abs(crc32($g['subject_name'])) % 360;
        $datasets[] = [
            'label' => htmlspecialchars($g['subject_name']),
            'data' => [(float)($g['prelim']??0), (float)($g['midterm']??0), (float)($g['prefinal']??0), (float)($g['final']??0)],
            'borderColor' => "hsl($hue, 70%, 45%)",
            'tension' => 0.4
        ];
    }
    $overall_avg = count($grades) > 0 ? $avg_sum / count($grades) : 0;

    echo json_encode([
        'average' => number_format($overall_avg, 1),
        'letter' => calculateGradePoint($overall_avg),
        'total' => count($grades),
        'period_distributions' => $period_distributions,
        'datasets' => $datasets
    ]);
    exit;
}

// Handle Subject CRUD from new 'manage-subjects' tab
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['source_page']) && $_POST['source_page'] === 'manage_subjects') {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=manage-subjects");
        exit;
    }

    if (isset($_POST['add_subject'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];

        // Check if subject name already exists for this specific teacher
        $check_name = $pdo->prepare("SELECT id FROM subjects WHERE name = ? AND (teacher_id = ? OR teacher_id IS NULL)");
        $check_name->execute([$name, $teacher_id]);

        if ($check_name->rowCount() > 0) {
            $_SESSION['message'] = "Error: A subject with the name '{$name}' already exists.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Auto-generate Subject Code: UGRD- followed by 7 random digits
            do {
                $code = 'UGRD-' . str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);
                $check = $pdo->prepare("SELECT id FROM subjects WHERE code = ?");
                $check->execute([$code]);
            } while ($check->rowCount() > 0);

            $stmt = $pdo->prepare("INSERT INTO subjects (name, code, description, teacher_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $code, $description, $teacher_id]);
            $_SESSION['message'] = "Subject Added: '{$name}' has been created with code {$code}.";
            $_SESSION['message_type'] = "success";
        }
    }

    if (isset($_POST['update_subject'])) {
        $id = $_POST['subject_id'];
        $name = $_POST['name'];
        $code = $_POST['code'];
        $description = $_POST['description'];

        // Security: Authorization check - ensure teacher owns the subject they are trying to update.
        $auth_stmt = $pdo->prepare("SELECT 1 FROM subjects WHERE id = ? AND teacher_id = ?");
        $auth_stmt->execute([$id, $teacher_id]);
        if ($auth_stmt->fetchColumn() === false) {
            $_SESSION['message'] = "Error: You are not authorized to edit this subject.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Check for duplicate name/code, excluding the current subject
            $check = $pdo->prepare("SELECT id FROM subjects WHERE (code = ? OR name = ?) AND id != ?");
            $check->execute([$code, $name, $id]);
        if ($check->rowCount() > 0) {
            $_SESSION['message'] = "Error: Subject name or code already exists!";
            $_SESSION['message_type'] = "danger";
            } else {
            $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $code, $description, $id]);
            $_SESSION['message'] = "Success: Subject '{$name}' has been updated.";
            $_SESSION['message_type'] = "success";
        }
    }
    }
    header("Location: teacher.php?tab=manage-subjects");
    exit();
}

// Handle Teacher Weight Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher_weights'])) {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=settings");
        exit;
    }

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

// Handle Student Enrollment from Teacher Ledger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll_student') {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=students");
        exit;
    }

    $student_id = $_POST['student_id'];
    $subject_id = $_POST['subject_id'];

    // NEW: Check enrollment restrictions
    $subject_stmt = $pdo->prepare("SELECT target_course, target_year_level FROM subjects WHERE id = ?");
    $subject_stmt->execute([$subject_id]);
    $subject = $subject_stmt->fetch();

    $student_stmt = $pdo->prepare("SELECT course, year_level FROM users WHERE id = ?");
    $student_stmt->execute([$student_id]);
    $student = $student_stmt->fetch();

    $can_enroll = true;
    $error_msg = "";

    if ($subject && ($subject['target_course'] || $subject['target_year_level'])) {
        // Check course
        if ($subject['target_course']) {
            $allowed_courses = array_map('trim', explode(',', strtolower($subject['target_course'])));
            if (!in_array(strtolower($student['course']), $allowed_courses)) {
                $can_enroll = false;
                $error_msg = "This subject is restricted to course(s): " . $subject['target_course'];
            }
        }
        // Check year level
        if ($can_enroll && $subject['target_year_level']) {
            $allowed_years = array_map('trim', explode(',', strtolower($subject['target_year_level'])));
            if (!in_array(strtolower($student['year_level']), $allowed_years)) {
                $can_enroll = false;
                $error_msg = "This subject is restricted to year level(s): " . $subject['target_year_level'];
            }
        }
    }

    if (!$can_enroll) {
        $_SESSION['message'] = "Enrollment Failed: " . $error_msg;
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=students");
        exit();
    }

    // Use INSERT IGNORE to prevent errors on duplicate enrollment and check affected rows.
    $stmt = $pdo->prepare("INSERT IGNORE INTO grades (student_id, subject_id, teacher_id) VALUES (?, ?, ?)");
    $stmt->execute([$student_id, $subject_id, $teacher_id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['message'] = "Enrollment successful! The student is now in your ledger.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Notice: This student is already enrolled in this subject.";
        $_SESSION['message_type'] = "warning";
    }

    header("Location: teacher.php?tab=manage-subjects");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_subject') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
    } else {
        $subject_id_to_delete = $_POST['subject_id'];
        // Security: Authorization check
        $auth_stmt = $pdo->prepare("SELECT 1 FROM subjects WHERE id = ? AND teacher_id = ?");
        $auth_stmt->execute([$subject_id_to_delete, $teacher_id]);
        if ($auth_stmt->fetchColumn()) {
            $result = deleteSubject($pdo, $subject_id_to_delete);
            $_SESSION['message'] = ($result === true) ? "Subject permanently deleted." : $result;
            $_SESSION['message_type'] = ($result === true) ? "success" : "danger";
        } else {
            $_SESSION['message'] = "Error: You are not authorized to delete this subject.";
            $_SESSION['message_type'] = "danger";
        }
    }
    header("Location: teacher.php?tab=manage-subjects");
    exit();
}

// Handle Teacher Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher_profile'])) {
    // CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid form submission. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: teacher.php?tab=profile");
        exit;
    }

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $suffix = trim($_POST['suffix'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $age = (int)($_POST['age'] ?? 0);
    $home_address = trim($_POST['home_address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $update_fields = [];
    $params = [];
    $errors = []; // Use a local error array

    // Handle file upload using the new reusable function
    $profile_picture_path = handleProfilePictureUpload($pdo, $_FILES['profile_picture'] ?? null, $teacher_id, $errors);
    if ($profile_picture_path) {
        $update_fields[] = "profile_picture = ?";
        $params[] = $profile_picture_path;
    }

    // Check for duplicate email (excluding self)
    $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check_email->execute([$email, $teacher_id]);
    if ($check_email->rowCount() > 0) {
        $errors[] = "This email address is already in use by another account.";
    }

    // Validation for required fields
    if (empty($first_name)) $errors[] = "First name is required.";
    if (empty($last_name)) $errors[] = "Last name is required.";
    if (empty($sex)) $errors[] = "Sex is required.";
    if (empty($birth_date)) $errors[] = "Birth date is required.";
    if ($age <= 0) $errors[] = "Age is required and must be a positive number.";
    if (empty($home_address)) $errors[] = "Home address is required.";
    if (empty($city)) $errors[] = "City is required.";
    if (empty($province)) $errors[] = "Province is required.";

    // Validate and add fields
    $update_fields[] = "first_name = ?"; $params[] = $first_name;
    $update_fields[] = "last_name = ?";  $params[] = $last_name;
    $update_fields[] = "suffix = ?";     $params[] = $suffix ?: null;
    $update_fields[] = "sex = ?";        $params[] = $sex;
    $update_fields[] = "birth_date = ?"; $params[] = $birth_date;
    $update_fields[] = "age = ?";        $params[] = $age;
    $update_fields[] = "home_address = ?"; $params[] = $home_address;
    $update_fields[] = "city = ?";       $params[] = $city;
    $update_fields[] = "province = ?";   $params[] = $province;
    $update_fields[] = "email = ?";      $params[] = $email;

    if (!empty($new_password)) {
        if ($new_password !== $confirm_password) { $errors[] = "Password update failed: Passwords do not match."; } 
        elseif (strlen($new_password) < 8) { $errors[] = "Password update failed: Password must be at least 8 characters long."; } 
        else { $update_fields[] = "password = ?"; $params[] = password_hash($new_password, PASSWORD_DEFAULT); }
    }

    if (empty($errors)) {
        if (!empty($update_fields)) {
            $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?"; $params[] = $teacher_id;
            $pdo->prepare($sql)->execute($params);
            $_SESSION['first_name'] = $first_name; $_SESSION['last_name'] = $last_name;
            $_SESSION['message'] = "Profile updated successfully."; $_SESSION['message_type'] = "success";
        }
    } else {
        // Store errors in session to display them on the form
        $_SESSION['form_errors'] = $errors;
        $_SESSION['message_type'] = "danger";
    }
    header("Location: teacher.php?tab=profile");
    exit;
}

// Retrieve form data and errors from session for repopulation on validation failure
$form_errors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

// Determine if the edit form should be shown on page load (i.e., if there were validation errors)
$show_edit_form_on_load = !empty($form_errors);

// Fetch broadcast templates for the teacher modal
$teacher_broadcast_templates = [];
try {
    $broadcast_templates_stmt = $pdo->prepare("SELECT * FROM broadcast_templates WHERE role_access = 'teacher' OR role_access = 'all' ORDER BY title");
    $broadcast_templates_stmt->execute();
    $teacher_broadcast_templates = $broadcast_templates_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail if table doesn't exist.
    error_log("Teacher broadcast templates table may not exist: " . $e->getMessage());
}

// Fetch notifications for the teacher
$notifications = [];
$unread_count = 0;
try {
    $notif_stmt = $pdo->prepare("
        SELECT n.*, u.first_name as sender_first, u.last_name as sender_last
        FROM notifications n
        JOIN users u ON n.sender_id = u.id
        WHERE n.recipient_id = ? 
        ORDER BY n.created_at DESC 
        LIMIT 10
    ");
    $notif_stmt->execute([$teacher_id]);
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND is_read = 0");
    $unread_count_stmt->execute([$teacher_id]);
    $unread_count = $unread_count_stmt->fetchColumn();
} catch (PDOException $e) {
    // Gracefully handle if notifications table doesn't exist yet
    if ($e->getCode() !== '42S02') {
        error_log("Teacher Notification fetch error: " . $e->getMessage());
    }
}


$active_tab = $_GET['tab'] ?? 'dashboard';

// --- Data for Notification History Tab ---
$sent_notifications = [];
$total_sent_notifications = 0;
$sent_page = 1;
$sent_per_page = 15;
$total_sent_pages = 0;

if ($active_tab === 'history') {
    $sent_page = isset($_GET['sent_page']) ? (int)$_GET['sent_page'] : 1;
    if ($sent_page < 1) $sent_page = 1;
    $sent_offset = ($sent_page - 1) * $sent_per_page;

    // Count total sent notifications for pagination
    $total_sent_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE sender_id = ?");
    $total_sent_stmt->execute([$teacher_id]);
    $total_sent_notifications = $total_sent_stmt->fetchColumn();
    $total_sent_pages = ceil($total_sent_notifications / $sent_per_page);

    // Fetch paginated sent notifications
    $sent_stmt = $pdo->prepare("
        SELECT n.*, r.first_name, r.last_name, r.profile_picture 
        FROM notifications n
        JOIN users r ON n.recipient_id = r.id
        WHERE n.sender_id = ?
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $sent_stmt->bindValue(1, $teacher_id, PDO::PARAM_INT);
    $sent_stmt->bindValue(2, $sent_per_page, PDO::PARAM_INT);
    $sent_stmt->bindValue(3, $sent_offset, PDO::PARAM_INT);
    $sent_stmt->execute();
    $sent_notifications = $sent_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// NEW: Data for Dashboard Subject/Student Accordion
$subjects_with_students = [];
if ($active_tab === 'dashboard') {
    $enrollments_stmt = $pdo->prepare("
        SELECT 
            s.id as subject_id, 
            s.name as subject_name,
            s.code as subject_code,
            u.id as student_id,
            u.profile_picture,
            u.first_name,
            u.last_name,
            u.username,
            u.course,
            u.year_level,
            g.grade
        FROM grades g
        JOIN users u ON g.student_id = u.id
        JOIN subjects s ON g.subject_id = s.id
        WHERE g.teacher_id = ? AND u.role = 'student' AND u.status = 'active'
        ORDER BY s.name, u.last_name, u.first_name
    ");
    $enrollments_stmt->execute([$teacher_id]);
    $all_enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_enrollments as $enrollment) {
        $subject_id = $enrollment['subject_id'];
        if (!isset($subjects_with_students[$subject_id])) {
            $subjects_with_students[$subject_id] = [ 'name' => $enrollment['subject_name'], 'code' => $enrollment['subject_code'], 'students' => [] ];
        }
        $subjects_with_students[$subject_id]['students'][] = $enrollment;
    }
}
// 2. Live Count: Total Registered Students
// We count all users with the role 'student' so the dashboard reflects the total population
$total_students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();

// 3. Count: Grades Assigned
$grades_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE teacher_id = ?");
$grades_count_stmt->execute([$teacher_id]);
$total_grades_assigned = $grades_count_stmt->fetchColumn();

// NEW: Count students with failing grades for this teacher
$failing_students_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT g.student_id) 
    FROM grades g
    WHERE g.teacher_id = ? AND (
        (g.grade < 75 AND g.grade > 0)
        OR (g.prelim > 0 AND g.prelim < 75)
        OR (g.midterm > 0 AND g.midterm < 75)
        OR (g.prefinal > 0 AND g.prefinal < 75)
        OR (g.final > 0 AND g.final < 75)
    )
");
$failing_students_stmt->execute([$teacher_id]);
$total_failing_students = $failing_students_stmt->fetchColumn();

// Master list of subjects for enrollment dropdown - Show teacher's private subjects and global (Registrar-created) subjects
$all_subjects_stmt = $pdo->prepare("SELECT * FROM subjects WHERE teacher_id = ? OR teacher_id IS NULL ORDER BY name ASC");
$all_subjects_stmt->execute([$teacher_id]);
$all_subjects = $all_subjects_stmt->fetchAll();

// Fetch Dynamic Grade Weights
$weights = getGradeWeights($pdo, $teacher_id);

$filter_subject = $_GET['filter_subject'] ?? '';
$search = $_GET['search'] ?? '';
$filter_failing = isset($_GET['filter_failing']);

// Filtered list: Only subjects this teacher is currently handling
$my_subjects_stmt = $pdo->prepare("SELECT DISTINCT s.* FROM subjects s JOIN grades g ON s.id = g.subject_id WHERE g.teacher_id = ? ORDER BY s.name");
$my_subjects_stmt->execute([$teacher_id]);
$my_subjects = $my_subjects_stmt->fetchAll();

// Subjects list for grade sheet management (fallback to all accessible subjects)
$subjects = !empty($my_subjects) ? $my_subjects : $all_subjects;
$subjects_by_id = [];
foreach ($subjects as $_s) { $subjects_by_id[(int)$_s['id']] = $_s; }
foreach ($all_subjects as $_s) { if (!isset($subjects_by_id[(int)$_s['id']])) $subjects_by_id[(int)$_s['id']] = $_s; }

// 5. Fetch Student List for the table view
$filter_subject = $_GET['filter_subject'] ?? '';
$search = $_GET['search'] ?? '';
$filter_failing = isset($_GET['filter_failing']);

// Build dynamic query to handle search context and subject filtering correctly
$select_fields = "s.*, sub_list.all_subs as enrolled_subjects";
$join_clause = "";
$params = [];

if ($filter_subject) {
    // Subject specific view: get detailed period grades for this teacher's entries
    $select_fields .= ", g.grade as average, g.id as grade_id,
           g.prelim, g.prelim_quiz, g.prelim_cs, g.prelim_attendance, g.prelim_laboratory, g.prelim_online, g.prelim_exam,
           g.midterm, g.midterm_quiz, g.midterm_cs, g.midterm_attendance, g.midterm_laboratory, g.midterm_online, g.midterm_exam,
           g.prefinal, g.prefinal_quiz, g.prefinal_cs, g.prefinal_attendance, g.prefinal_laboratory, g.prefinal_online, g.prefinal_exam,
           g.final, g.final_quiz, g.final_cs, g.final_attendance, g.final_laboratory, g.final_online, g.final_exam";
    
    $join_clause = "LEFT JOIN grades g ON s.id = g.student_id AND g.subject_id = ? AND g.teacher_id = ?
                    LEFT JOIN (SELECT student_id, GROUP_CONCAT(subjects.name SEPARATOR ', ') as all_subs FROM grades JOIN subjects ON grades.subject_id = subjects.id WHERE grades.teacher_id = ? GROUP BY student_id) sub_list ON s.id = sub_list.student_id";
    $params[] = $filter_subject;
    $params[] = $teacher_id;
    $params[] = $teacher_id;
} else {
    // Overall view: get cumulative average of students managed by this teacher
    $select_fields = "s.*, MAX(sub_list.all_subs) as enrolled_subjects";
    
    $select_fields .= ", AVG(g.grade) as average,
                       MAX(fail_counts.prelim_fails) as prelim_fails, MAX(fail_counts.midterm_fails) as midterm_fails, 
                       MAX(fail_counts.prefinal_fails) as prefinal_fails, MAX(fail_counts.final_fails) as final_fails";
    $join_clause = "LEFT JOIN grades g ON s.id = g.student_id AND g.teacher_id = ?
                    LEFT JOIN (
                        SELECT 
                            student_id,
                            SUM(CASE WHEN prelim > 0 AND prelim < 75 THEN 1 ELSE 0 END) as prelim_fails,
                            SUM(CASE WHEN midterm > 0 AND midterm < 75 THEN 1 ELSE 0 END) as midterm_fails,
                            SUM(CASE WHEN prefinal > 0 AND prefinal < 75 THEN 1 ELSE 0 END) as prefinal_fails,
                            SUM(CASE WHEN final > 0 AND final < 75 THEN 1 ELSE 0 END) as final_fails
                        FROM grades 
                        WHERE teacher_id = ? 
                        GROUP BY student_id
                    ) fail_counts ON s.id = fail_counts.student_id
                    LEFT JOIN (SELECT student_id, GROUP_CONCAT(subjects.name SEPARATOR ', ') as all_subs FROM grades JOIN subjects ON grades.subject_id = subjects.id WHERE grades.teacher_id = ? GROUP BY student_id) sub_list ON s.id = sub_list.student_id";
    $params[] = $teacher_id;
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
    } else if ($active_tab === 'manage-grades') {
        // Scoped view for Ledger: Show only students this teacher has records for
        $student_sql .= " AND EXISTS (SELECT 1 FROM grades WHERE student_id = s.id AND teacher_id = ?) ";
        $params[] = $teacher_id;
    }
    // If $active_tab is 'students', all registered students are visible by default to allow the teacher to find them.
}

if (!$filter_subject) $student_sql .= " GROUP BY s.id ";

if ($filter_failing) {
    if ($filter_subject) {
        // Subject view: failing if the final grade or any period grade is below 75.
        $student_sql .= " HAVING (g.grade < 75 AND g.grade > 0)
            OR (g.prelim > 0 AND g.prelim < 75)
            OR (g.midterm > 0 AND g.midterm < 75)
            OR (g.prefinal > 0 AND g.prefinal < 75)
            OR (g.final > 0 AND g.final < 75) ";
    } else {
        // Overall view: failing if the average is below 75 or any period failed.
        $student_sql .= " HAVING (average < 75 AND average > 0)
            OR COALESCE(prelim_fails, 0) > 0
            OR COALESCE(midterm_fails, 0) > 0
            OR COALESCE(prefinal_fails, 0) > 0
            OR COALESCE(final_fails, 0) > 0 ";
    }
}

$student_sql .= " ORDER BY s.year_level DESC, s.course ASC, s.last_name ASC";

$students_stmt = $pdo->prepare($student_sql);
$students_stmt->execute($params);
$students = $students_stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="image/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Student Grade Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> 
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="css/portal.css" rel="stylesheet">
    <?php outputThemeColors($pdo); ?>
    <style>
        /* My Students tab - modernized, compact layout */
        .students-header .title-wrap h4 {
            letter-spacing: -0.01em;
        }
        .students-filter-card .form-control,
        .students-filter-card .form-select,
        .students-filter-card .btn {
            border-radius: 10px;
        }
        .students-filter-card .form-control:focus,
        .students-filter-card .form-select:focus {
            box-shadow: 0 0 0 .25rem rgba(79, 70, 229, .08);
            border-color: rgba(79, 70, 229, .45);
        }
        .students-table {
            --bs-table-bg: transparent;
            font-size: 0.85rem;
            width: 100%;
        }
        .students-table thead th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .03em;
            font-size: .72rem;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
            padding: .5rem .6rem;
            vertical-align: middle;
        }
        .students-table tbody td {
            padding: .45rem .6rem;
            vertical-align: middle;
            border-color: #f1f5f9;
        }
        .students-table tbody tr:hover {
            background: #f8fafc;
        }
        .students-table .student-name {
            display: flex;
            align-items: center;
            gap: .55rem;
            min-width: 180px;
        }
        .students-table .student-name .avatar-sm {
            width: 30px;
            height: 30px;
            object-fit: cover;
            flex: 0 0 auto;
        }
        .students-table .student-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            min-width: 0;
        }
        .students-table .student-meta .name {
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
        }
        .students-table .student-meta .subtle {
            color: #64748b;
            font-size: .75rem;
        }
        .students-table .enrolled-subjects {
            max-width: 220px;
            color: #475569;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: .8rem;
        }
        .students-table .course-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .15rem .5rem;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
            font-weight: 600;
            font-size: .75rem;
            white-space: nowrap;
        }
        .period-status-list {
            display: flex;
            flex-wrap: nowrap;
            gap: .3rem;
        }
        .period-status-list .period-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .1rem .4rem;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: .72rem;
            white-space: nowrap;
        }
        .period-status-list .period-pill .label {
            font-weight: 600;
            color: #64748b;
        }
        .period-status-list .period-pill .value {
            font-weight: 700;
        }
        .period-status-list .period-pill.is-ok .value { color: #15803d; }
        .period-status-list .period-pill.is-bad .value { color: #dc2626; }
        .period-status-list .period-pill.is-pending .value { color: #94a3b8; }
        .students-action-group {
            display: inline-flex;
            flex-wrap: nowrap;
            gap: .25rem;
            justify-content: flex-end;
        }
        .students-action-group .btn {
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            padding: 0;
            font-size: .8rem;
        }
        .students-action-group .btn .label {
            display: none !important;
        }
        @media (max-width: 991px) {
            .students-table .enrolled-subjects { max-width: 120px; }
            .students-table .student-name { min-width: 0; }
            .students-table .student-meta .name { white-space: normal; word-break: break-word; }
            .students-table .student-meta { min-width: 0; }
        }
        /* Phones: keep wide tables readable via horizontal scroll instead of
           compressing/overlapping columns (dashboard list, history, ledgers). */
        @media (max-width: 767.98px) {
            .table-responsive > .table { min-width: 640px; }
            .table-responsive > .table .text-nowrap { white-space: normal !important; }
            .table-responsive > .history-table { min-width: 0; }
            .history-table thead { display: none; }
            .history-table, .history-table tbody, .history-table tr, .history-table td { display: block; width: 100%; }
            .history-table tr { margin-bottom: .75rem; border: 1px solid #e5e7eb; border-radius: .5rem; padding: .5rem .75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
            .history-table td { border: 0; padding: .25rem 0; max-width: 100% !important; min-width: 0 !important; white-space: normal !important; word-break: break-word; text-align: left !important; }
            .history-table td[data-label]::before { content: attr(data-label); display: block; font-weight: 600; color: #64748b; font-size: .72rem; text-transform: uppercase; letter-spacing: .02em; margin-bottom: .1rem; }
        }
        .students-filter-card .search-input-wrap .form-control {
            padding-right: 2.1rem;
        }
        .students-filter-card .search-clear-btn {
            position: absolute;
            right: .45rem;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #94a3b8;
            padding: .15rem .35rem;
            line-height: 1;
            border-radius: 999px;
            display: none;
            cursor: pointer;
        }
        .students-filter-card .search-clear-btn:hover {
            color: #475569;
            background: #e2e8f0;
        }
        .students-filter-card .search-clear-btn.is-visible {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .students-results-animate {
            animation: studentsFadeUp .22s ease;
        }
        @keyframes studentsFadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .students-results.is-filtering {
            opacity: .45;
            transform: translateY(2px);
            transition: opacity .15s ease, transform .15s ease;
            pointer-events: none;
        }
        @media (max-width: 767px) {
            .students-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: .8rem;
            }
            .students-filter-card .form-control,
            .students-filter-card .form-select {
                width: 100%;
            }
            .period-status-list { flex-wrap: wrap; }
        }
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
            <div class="d-flex flex-column p-3">
                <div class="text-center mb-4">
                    <img src="<?php echo getSystemLogoUrl($pdo); ?>" alt="School Logo" style="width: 80px; height: 80px; margin-bottom: 10px; border-radius: 50%; object-fit: cover;">
                    <h5 class="mb-0 text-white"><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                    <small class="text-white-50">Teacher Portal</small>
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
                        <a class="nav-link <?php echo $active_tab === 'manage-grades' ? 'active' : ''; ?>" href="teacher.php?tab=manage-grades">
                            <i class="fas fa-edit me-2"></i>Manage Grade
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'manage-subjects' ? 'active' : ''; ?>" href="teacher.php?tab=manage-subjects">
                            <i class="fas fa-book me-2"></i>Manage Subjects
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'history' ? 'active' : ''; ?>" href="teacher.php?tab=history"><i class="fas fa-history me-2"></i>Notification History</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" href="teacher.php?tab=settings"><i class="fas fa-cogs me-2"></i>Grading Policy</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" href="teacher.php?tab=profile"><i class="fas fa-user-edit me-2"></i>My Profile</a>
                    </li>
                    <li class="nav-item mt-auto">
                        <a id="logoutBtn" class="nav-link text-warning" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="main-content" id="main-content">
            <nav class="navbar navbar-light bg-light px-4 border-bottom">
                <i class="fas fa-bars toggle-btn me-3" id="sidebarToggle"></i>
                <div class="d-flex align-items-center ms-auto">
                    <span class="navbar-brand mb-0 h1 me-4">GradeView Dashboard</span>
                    <div class="dropdown">
                        <a href="#" class="text-secondary position-relative" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell fa-lg"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo $unread_count > 0 ? '' : 'd-none'; ?>" id="notif-count-badge">
                                <?php echo $unread_count; ?>
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
                                    <li class="px-3 py-4 text-muted text-center small">You have no new notifications.</li> 
                                <?php else: foreach ($notifications as $notif): ?> 
                                    <li><a class="dropdown-item text-wrap py-2 notification-item <?php echo $notif['is_read'] ? '' : 'bg-light'; ?>" href="#" data-id="<?php echo $notif['id']; ?>"><p class="mb-1 small <?php echo $notif['is_read'] ? 'text-muted' : 'fw-bold'; ?>"><?php echo highlightNotificationKeywords($notif['message']); ?></p><small class="text-xs text-muted">From: <?php echo htmlspecialchars($notif['sender_first'] . ' ' . $notif['sender_last']); ?> &bull; <?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></small></a></li> 
                                <?php endforeach; endif; ?> 
                            </div>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li><a class="dropdown-item text-center small text-primary py-2 view-all-notifications" href="#">View All</a></li>
                        </ul>
                    </div>
                </div>
            </nav>

            <div class="px-4 py-4">
                <div class="tab-content">
                    <div class="tab-pane fade <?php echo $active_tab === 'dashboard' ? 'show active' : ''; ?>" id="dashboard">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0 fw-bold">Dashboard Overview</h4>
                            <span>Welcome, <?php echo $_SESSION['first_name']; ?>!</span>
                        </div>

                        <div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-box grad-blue">
            <div>
                <h4><?php echo count($my_subjects); ?></h4>
                <p>My Subjects</p>
            </div>
            <i class="fas fa-book"></i>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-box grad-cyan">
            <div>
                <h4><?php echo $total_grades_assigned; ?></h4>
                <p>Total Enrolled</p>
            </div>
            <i class="fas fa-user-check"></i>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-box grad-red">
            <div>
                <h4><?php echo $total_failing_students; ?></h4>
                <p>Failing Students</p>
            </div>
            <i class="fas fa-exclamation-triangle"></i>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-box grad-purple">
            <div>
                <h4><?php echo $total_students; ?></h4>
                <p>Total Students</p>
            </div>
            <i class="fas fa-user-graduate"></i>
        </div>
    </div>
</div>

                        <!-- Student Enrollment List by Subject -->
                        <h5 class="mb-3 fw-bold text-secondary"><i class="fas fa-list-ul me-2"></i>My Class Lists</h5>
                        <div class="accordion" id="subjectsAccordion">
                            <?php if (empty($subjects_with_students)): ?>
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body text-center text-muted p-5">
                                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                                        <p class="mb-0">No students are currently enrolled in any of your subjects.</p>
                                        <small>You can enroll students from the "My Students" tab.</small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php $idx = 0; foreach ($subjects_with_students as $subject_id => $subject_data): $idx++; ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading-<?php echo $subject_id; ?>">
                                            <button class="accordion-button <?php echo $idx > 1 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $subject_id; ?>" aria-expanded="<?php echo $idx === 1 ? 'true' : 'false'; ?>" aria-controls="collapse-<?php echo $subject_id; ?>">
                                                <span class="fw-bold"><?php echo htmlspecialchars($subject_data['name']); ?></span>
                                                <span class="badge bg-primary rounded-pill ms-auto me-3"><?php echo count($subject_data['students']); ?> Students</span>
                                            </button>
                                        </h2>
                                        <div id="collapse-<?php echo $subject_id; ?>" class="accordion-collapse collapse <?php echo $idx === 1 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $subject_id; ?>" data-bs-parent="#subjectsAccordion">
                                            <div class="accordion-body p-0">
                                                <div class="table-responsive">
                                                    <table class="table table-hover table-sm mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th class="ps-3">#</th>
                                                                <th>Student Name</th>
                                                                <th>Username</th>
                                                                <th>Course & Year</th>
                                                                <th class="text-center">Current Grade</th>
                                                                <th class="pe-3">Actions</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php $i = 1; foreach ($subject_data['students'] as $student): ?>
                                                                <tr>
                                                                    <td class="ps-3 align-middle"><?php echo $i++; ?></td>
                                                                    <td class="fw-semibold align-middle">
                                                                    <img src="<?php echo htmlspecialchars($student['profile_picture'] ?: 'image/default.png'); ?>" class="rounded-circle me-2 avatar-sm" onerror="this.onerror=null;this.src='image/default.png';">
                                                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                                    </td>
                                                                    <td><span class="badge bg-light text-dark border">@<?php echo htmlspecialchars($student['username']); ?></span></td>
                                                                    <td><small><?php echo htmlspecialchars($student['course'] . ' - ' . $student['year_level']); ?></small></td>
                                                                    <td class="text-center">
                                                                        <?php $avg = (float)($student['grade'] ?? 0); ?>
                                                                        <?php if ($avg <= 0): ?>
                                                                            <span class="badge bg-secondary">IP (In Progress)</span>
                                                                        <?php else: ?>
                                                                            <span class="badge bg-<?php echo getGradeColor($avg); ?>"><?php echo number_format($avg, 2); ?></span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="pe-3">
                                                                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#notifyClassModal" data-subject-id="<?php echo $subject_id; ?>" data-subject-name="<?php echo htmlspecialchars($subject_data['name']); ?>" title="Notify Entire Class">
                                                                            <i class="fas fa-bullhorn"></i>
                                                                        </button>
                                                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#sendNotificationModal" data-id="<?php echo $student['student_id']; ?>" data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>" title="Send Notification">
                                                                            <i class="fas fa-paper-plane"></i>
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
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'students' ? 'show active' : ''; ?>" id="students">
                        <div class="students-header d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div class="title-wrap d-flex flex-column gap-1">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h4 class="mb-0 fw-bold">My Students</h4>
                                    <span class="badge rounded-pill bg-light text-secondary border"><?php echo count($students); ?> student<?php echo count($students) === 1 ? '' : 's'; ?></span>
                                    <?php if ($filter_failing): ?>
                                        <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">Failing only</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small">View, search, and manage students enrolled in your classes.</div>
                                <?php if($filter_subject): ?>
                                    <div class="text-primary small fw-semibold">
                                        <i class="fas fa-book me-1"></i>Viewing: <?php 
                                            foreach($all_subjects as $s) { 
                                                if($s['id'] == $filter_subject) { echo htmlspecialchars($s['name']); break; }
                                            } 
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <a href="print_grade.php?all_students=true" target="_blank" class="btn btn-primary rounded-pill px-3 shadow-sm"><i class="fas fa-print me-2"></i>Print All Students</a>
                        </div>

                        <div class="card border-0 shadow-sm mb-3 students-filter-card">
                            <div class="card-body py-3">
                                <form class="row g-2 align-items-center" action="teacher.php" method="GET" id="studentFilterForm">
                                    <input type="hidden" name="tab" value="students">
                                    <div class="col-12 col-md">
                                        <div class="position-relative search-input-wrap">
                                            <span class="position-absolute top-50 start-0 translate-middle-y ps-3 text-muted"><i class="fas fa-search"></i></span>
                                            <input type="text" name="search" id="liveSearchInput" class="form-control form-control-sm ps-5" placeholder="Search by student name..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                                            <button type="button" id="searchClearBtn" class="search-clear-btn<?php echo $search !== '' ? ' is-visible' : ''; ?>" aria-label="Clear search" title="Clear search"><i class="fas fa-times"></i></button>
                                            <div id="searchResultsDropdown" class="list-group position-absolute w-100 shadow d-none" style="z-index: 1050;"></div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-sm-6 col-md-auto">
                                        <select name="filter_subject" id="subjectFilterSelect" class="form-select form-select-sm teacher-filter-select" onchange="this.form.submit()">
                                            <option value="">All Subjects</option>
                                            <?php foreach($all_subjects as $s): ?>
                                            <option value="<?php echo htmlspecialchars($s['id']); ?>" <?php echo $filter_subject == $s['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-6 col-md-auto">
                                        <div class="form-check form-switch m-0 py-1 pe-3 rounded-pill border bg-light d-inline-flex align-items-center" style="padding-left: 2.75rem; min-height: auto;">
                                            <input class="form-check-input my-0" type="checkbox" name="filter_failing" value="1" id="failingFilterSwitch" onchange="this.form.submit()" <?php echo isset($_GET['filter_failing']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small fw-semibold text-danger ms-1 text-nowrap" for="failingFilterSwitch">Show Failing Only</label>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-auto d-flex gap-2">
                                        <button type="submit" id="teacherSearchClearBtn" class="btn btn-sm btn-primary px-3" title="Search"><i class="fas fa-search me-1"></i>Search</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm students-results students-results-animate" id="studentsResults">
                            <div class="card-body p-0">
                                <?php if (empty($students)): ?>
                                    <div class="text-center text-muted py-5 px-3">
                                        <div class="mb-3"><i class="fas fa-user-graduate fa-2x text-secondary opacity-50"></i></div>
                                        <h6 class="fw-semibold mb-1">No students found</h6>
                                        <p class="small mb-0">Try adjusting your search or filters, or enroll students into your subjects.</p>
                                    </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 students-table">
                                        <thead>
                                            <tr>
                                                <th class="ps-3" style="width: 40px;">#</th>
                                                <th>Student</th>
                                                <th class="d-none d-xl-table-cell">Username</th>
                                                <th>Subjects</th>
                                                <th>Course</th>
                                                <th>Status</th>
                                                <th class="text-end pe-3" style="width: 180px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $i = 1; foreach($students as $student): ?>
                                            <tr>
                                                <td class="ps-3 text-muted"><?php echo htmlspecialchars($i++); ?></td>
                                                <td>
                                                    <div class="student-name">
                                                        <img src="<?php echo htmlspecialchars($student['profile_picture'] ?: 'image/default.png'); ?>" class="rounded-circle avatar-sm border" onerror="this.onerror=null;this.src='image/default.png';" alt="">
                                                        <div class="student-meta">
                                                            <span class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span>
                                                            <span class="subtle"><?php echo htmlspecialchars($student['username']); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-muted small d-none d-xl-table-cell"><?php echo htmlspecialchars($student['username']); ?></td>
                                                <td>
                                                    <div class="enrolled-subjects" title="<?php echo htmlspecialchars($student['enrolled_subjects'] ?: 'None'); ?>">
                                                        <?php echo htmlspecialchars($student['enrolled_subjects'] ?: 'None'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="course-badge" title="<?php echo htmlspecialchars(trim(($student['course'] ?? '') . ' - ' . ($student['year_level'] ?? ''), ' -')); ?>"><?php echo htmlspecialchars(trim(($student['course'] ?? '') . ' ' . ($student['year_level'] ?? ''), ' ')); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($filter_subject): ?>
                                                        <div class="period-status-list">
                                                        <?php
                                                        $periods = ['prelim', 'midterm', 'prefinal', 'final'];
                                                        foreach ($periods as $p) {
                                                            $grade = (float)($student[$p] ?? 0);
                                                            $short = substr(ucfirst($p), 0, 3);
                                                            if ($grade > 0) {
                                                                $state = $grade < 75 ? 'is-bad' : 'is-ok';
                                                                $val = number_format($grade, 1);
                                                            } else {
                                                                $state = 'is-pending';
                                                                $val = 'IP';
                                                            }
                                                            echo '<span class="period-pill ' . $state . '" title="' . ucfirst($p) . '"><span class="label">' . $short . '</span><span class="value">' . $val . '</span></span>';
                                                        }
                                                        ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="period-status-list">
                                                        <?php
                                                        $periods = ['prelim', 'midterm', 'prefinal', 'final'];
                                                        $has_fails = false;
                                                        foreach ($periods as $p) {
                                                            $fail_count = (int)($student[$p.'_fails'] ?? 0);
                                                            $short = substr(ucfirst($p), 0, 3);
                                                            if ($fail_count > 0) {
                                                                $has_fails = true;
                                                                echo '<span class="period-pill is-bad" title="' . ucfirst($p) . '"><span class="label">' . $short . '</span><span class="value">' . $fail_count . 'F</span></span>';
                                                            }
                                                        }
                                                        if (!$has_fails) { echo '<span class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle px-2 py-1" style="font-size:.72rem;">OK</span>'; }
                                                        ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="pe-3">
                                                    <?php $all_data = htmlspecialchars(json_encode($student), ENT_QUOTES, "UTF-8"); ?>
                                                    <div class="students-action-group">
                                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewUserProfileModal" data-all='<?php echo $all_data; ?>' title="View"><i class="fas fa-eye"></i></button>
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#monitorPerformanceModal" data-id="<?php echo htmlspecialchars($student['id']); ?>" data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>" title="Performance"><i class="fas fa-chart-line"></i></button>
                                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#sendNotificationModal" data-id="<?php echo htmlspecialchars($student['id']); ?>" data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>" title="Notify"><i class="fas fa-paper-plane"></i></button>
                                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#enrollStudentModal" data-id="<?php echo htmlspecialchars($student['id']); ?>" data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>" title="Enroll"><i class="fas fa-user-plus"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Grade Weights/Settings Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                        <h4 class="fw-bold mb-4">Grading Policy Configuration</h4>
                        <form method="POST" id="teacherWeightsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="update_teacher_weights" value="1">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm h-100">
                                        <div class="card-header bg-primary text-white fw-bold">Lecture Components (<?php echo $weights['lec']*100; ?>%)</div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Lecture Weight (Overall %)</label>
                                                <input type="number" name="weight_lec" class="form-control" value="<?php echo $weights['lec']*100; ?>">
                                            </div>
                                            <hr>
                                            <div class="mb-3">
                                                <label class="form-label small">Quiz Weight (%)</label>
                                                <input type="number" name="weight_lec_quiz" class="form-control" value="<?php echo $weights['lec_quiz']*100; ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small">Online Activities (%)</label>
                                                <input type="number" name="weight_lec_online" class="form-control" value="<?php echo $weights['lec_online']*100; ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small">Major Exam (%)</label>
                                                <input type="number" name="weight_lec_exam" class="form-control" value="<?php echo $weights['lec_exam']*100; ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-0 shadow-sm h-100">
                                        <div class="card-header bg-success text-white fw-bold">Laboratory Components (<?php echo $weights['lab']*100; ?>%)</div>
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold">Laboratory Weight (Overall %)</label>
                                                <input type="number" name="weight_lab" class="form-control" value="<?php echo $weights['lab']*100; ?>">
                                            </div>
                                            <hr>
                                            <div class="mb-3">
                                                <label class="form-label small">Attendance Weight (%)</label>
                                                <input type="number" name="weight_lab_att" class="form-control" value="<?php echo $weights['lab_att']*100; ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small">Laboratory Performance (%)</label>
                                                <input type="number" name="weight_lab_cs" class="form-control" value="<?php echo $weights['lab_cs']*100; ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label small">Actual Exam (%)</label>
                                                <input type="number" name="weight_lab_exam" class="form-control" value="<?php echo $weights['lab_exam']*100; ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>Save Personalized Policy</button>
                                </div>
                            </div>
                        </form>
                        
                        <div class="alert alert-info mt-4 border-0 shadow-sm">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Tip:</strong> These weights configure your personalized grading policy. Ensure the sub-components for each category (Lecture/Lab) sum up to 100% as needed for your grading setup.
                        </div>
                    </div>

                    <!-- My Profile Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'profile' ? 'show active' : ''; ?>" id="profile">
                        <h4 class="fw-bold mb-4">My Profile</h4>
                        <?php if (!empty($form_errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong><i class="fas fa-exclamation-triangle me-2"></i>Please fix the following:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($form_errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-4">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="update_teacher_profile" value="1">
                                    
                                    <div class="row">
                                        <div class="col-lg-8">
                                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-user-circle me-2"></i>Personal Information</h5>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small">First Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($teacher_info['first_name']); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Last Name <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($teacher_info['last_name']); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Suffix (Optional)</label>
                                                    <input type="text" class="form-control" name="suffix" value="<?php echo htmlspecialchars($teacher_info['suffix'] ?? ''); ?>" placeholder="Jr., Sr., III, etc.">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Sex <span class="text-danger">*</span></label>
                                                    <select class="form-select" name="sex" required>
                                                        <option value="">Select...</option>
                                                        <option value="Male" <?php echo ($teacher_info['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                                        <option value="Female" <?php echo ($teacher_info['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Birth Date <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="birth_date" id="teacher_birth_date" value="<?php echo htmlspecialchars($teacher_info['birth_date'] ?? ''); ?>" required max="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Age <span class="text-danger">*</span></label>
                                                    <input type="number" class="form-control" name="age" id="teacher_age" value="<?php echo htmlspecialchars($teacher_info['age'] ?? ''); ?>" required min="18" max="100" readonly>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small">Email Address <span class="text-danger">*</span></label>
                                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($teacher_info['email']); ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small">Home Address <span class="text-danger">*</span></label>
                                                    <textarea class="form-control" name="home_address" rows="2" required><?php echo htmlspecialchars($teacher_info['home_address'] ?? ''); ?></textarea>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small">City <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($teacher_info['city'] ?? ''); ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small">Province <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" name="province" value="<?php echo htmlspecialchars($teacher_info['province'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-4 text-center">
                                            <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-image me-2"></i>Profile Picture</h5>
                                            <img src="<?php echo htmlspecialchars($teacher_info['profile_picture'] ?: 'image/default.png'); ?>" id="profilePicturePreview" class="rounded-circle mb-2 cropper-preview avatar-xl">
                                            <input type="file" class="form-control form-control-sm cropper-file-input" name="profile_picture" id="profilePictureInput" accept="image/*">
                                            <input type="hidden" name="cropped_image_data" class="cropper-data">
                                            <div class="form-text">Max 2MB. JPG, PNG, GIF.</div>
                                        </div>
                                    </div>

                                    <hr class="my-4">

                                    <h5 class="text-primary fw-bold border-bottom pb-2 mb-3"><i class="fas fa-key me-2"></i>Change Password</h5>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label small">New Password</label>
                                            <input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep current password">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small">Confirm New Password</label>
                                            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password">
                                        </div>
                                    </div>

                                    <div class="text-end mt-4">
                                        <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Manage Grade Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'manage-grades' ? 'show active' : ''; ?>" id="manage-grades">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h4 class="fw-bold mb-1"><i class="fas fa-edit me-2 text-primary"></i>Manage Grade</h4>
                                <p class="text-muted small mb-0">Import an Excel grade file, then use <strong>Open</strong> to edit it in Excel with the desktop sync helper (auto-uploads on Ctrl+S), <strong>View</strong> for a read-only preview, <strong>Download</strong> for a copy, and <strong>Compute</strong> to calculate grades from the latest server copy.</p>
                                <p class="text-muted small mb-0 mt-1"><i class="fas fa-circle-info me-1"></i>Desktop sync needs the helper running on your PC. <a href="sync_api.php?action=token" target="_blank" class="text-decoration-none fw-semibold">Connect desktop helper</a> (generate a device token, then paste it into <code>gradeview_helper.json</code>).</p>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" class="row g-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="import_grade_file">
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold">Subject <span class="text-muted fw-normal">(optional)</span></label>
                                        <select name="subject_id" class="form-select">
                                            <option value="">-- Select subject --</option>
                                            <?php foreach ($subjects as $sub): ?>
                                                <option value="<?php echo (int)$sub['id']; ?>"><?php echo htmlspecialchars($sub['name'] . ($sub['code'] ? " ({$sub['code']})" : '')); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label fw-semibold">Excel / CSV File</label>
                                        <input type="file" name="grade_file" class="form-control" accept=".xlsx,.xls,.xlsm,.xltx,.xltm,.csv,.tsv,.txt" required>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-success"><i class="fas fa-file-import me-1"></i>Import Excel File</button>
                                        <span class="form-text ms-2">Files are saved so you can download and edit them in Excel. Pick a subject to compute grades automatically on import.</span>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-file-excel me-1"></i>Imported Grade Files</span>
                                <?php
                                    $gradeDir = __DIR__ . '/uploads/grade_imports';
                                    $gradeManifestFile = $gradeDir . '/manifest.json';
                                    $gradeFiles = is_file($gradeManifestFile) ? (json_decode(@file_get_contents($gradeManifestFile), true) ?: []) : [];
                                    if (!is_array($gradeFiles)) { $gradeFiles = []; }
                                    // Isolate imports: only show files uploaded by this teacher.
                                    $currentTeacherId = (int)($_SESSION['user_id'] ?? 0);
                                    $gradeFiles = array_values(array_filter($gradeFiles, function($gf) use ($currentTeacherId) {
                                        return (int)($gf['uploader_id'] ?? 0) === $currentTeacherId;
                                    }));
                                    usort($gradeFiles, function($a, $b) { return strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? '')); });
                                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                    $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
                                    $baseSegments = array_values(array_filter(explode('/', trim($basePath, '/')), function($s) { return $s !== ''; }));
                                    $encodedBase = $baseSegments ? '/' . implode('/', array_map('rawurlencode', $baseSegments)) : '';
                                ?>
                                <span class="badge bg-secondary"><?php echo count($gradeFiles); ?></span>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($gradeFiles)): ?>
                                    <div class="text-center text-muted py-5">
                                        <i class="fas fa-file-excel fa-2x mb-3 opacity-50"></i>
                                        <h6 class="fw-semibold mb-1">No imported files yet</h6>
                                        <p class="small mb-0">Import an Excel grade file above to get started.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="ps-4">File Name</th>
                                                    <th>Subject</th>
                                                    <th>Uploaded By</th>
                                                    <th>Date</th>
                                                    <th class="text-end pe-4">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($gradeFiles as $gf): ?>
                                                    <tr>
                                                        <td class="ps-4 fw-semibold"><?php echo htmlspecialchars($gf['original_name'] ?? $gf['stored'] ?? 'file'); ?></td>
                                                        <td><?php echo htmlspecialchars((($gf['subject_name'] ?? '') !== '') ? $gf['subject_name'] : '—'); ?></td>
                                                        <td><small class="text-muted"><?php echo htmlspecialchars($gf['uploader_name'] ?? ''); ?></small></td>
                                                        <td><small class="text-muted"><?php echo htmlspecialchars($gf['uploaded_at'] ?? ''); ?></small></td>
                                                        <td class="text-end pe-4">
                                                            <button type="button" class="btn btn-sm btn-outline-success js-view-grade" data-stored="<?php echo htmlspecialchars($gf['stored'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-name="<?php echo htmlspecialchars($gf['original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" title="View / edit grade sheet and save"><i class="fas fa-eye me-1"></i>View</button>
                                                            <?php
                                                            $fileUrl = $scheme . '://' . $host . $encodedBase . '/uploads/grade_imports/' . rawurlencode($gf['stored'] ?? '');
                                                            // Open launches the desktop sync helper (local agent), which downloads
                                                            // a writable copy, opens it in Excel, and auto-uploads on save. The helper
                                                            // reads its own device token from gradeview_helper.json.
                                                            $helperPort = 8765;
                                                            $helperUrl = 'http://127.0.0.1:' . $helperPort . '/open?stored=' . rawurlencode($gf['stored'] ?? '') . '&name=' . rawurlencode($gf['original_name'] ?? ($gf['stored'] ?? 'grade.xlsx'));
                                                            ?>
                                                            <a href="<?php echo htmlspecialchars($helperUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-info" title="Open in Excel via the desktop sync helper (auto-syncs on save)"><i class="fas fa-external-link-alt me-1"></i>Open</a>
                                                            <a href="<?php echo htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8'); ?>" download="<?php echo htmlspecialchars($gf['original_name'] ?? 'grades.xlsx', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary" title="Download a writable copy, edit it in Excel, save, then import the updated file"><i class="fas fa-download me-1"></i>Download</a>
                                                            <?php if ((int)($gf['subject_id'] ?? 0) > 0): ?>
                                                            <form method="POST" class="d-inline m-0">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                <input type="hidden" name="action" value="recompute_grade_file">
                                                                <input type="hidden" name="stored" value="<?php echo htmlspecialchars($gf['stored'] ?? ''); ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Recompute grades from the stored file (refresh after editing or changing weights)"><i class="fas fa-calculator me-1"></i>Compute</button>
                                                            </form>
                                                            <?php endif; ?>
                                                            <form method="POST" class="d-inline m-0" onsubmit="return confirm('Remove this imported file?');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                                <input type="hidden" name="action" value="delete_grade_file">
                                                                <input type="hidden" name="stored" value="<?php echo htmlspecialchars($gf['stored'] ?? ''); ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Manage Subjects Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'manage-subjects' ? 'show active' : ''; ?>" id="manage-subjects">
                        <div class="row">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                                        <h4 class="fw-bold mb-0"><i class="fas fa-book me-2"></i>Manage Subjects</h4>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                                            <i class="fas fa-plus me-2"></i> Add New Subject
                                        </button>
                                    </div>                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0" style="font-size: 0.95rem;">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="ps-4">Subject Name</th>
                                                        <th>Code</th>
                                                        <th class="text-center">Enrolled Students</th>
                                                        <th>Description</th>
                                                        <th class="text-end pe-4">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    // Fetch subjects created by this teacher, along with the count of students enrolled in each.
                                                    $my_created_subjects_stmt = $pdo->prepare("
                                                        SELECT s.*, COUNT(g.student_id) as student_count 
                                                        FROM subjects s 
                                                        LEFT JOIN grades g ON s.id = g.subject_id 
                                                        WHERE s.teacher_id = ? 
                                                        GROUP BY s.id 
                                                        ORDER BY s.name
                                                    ");
                                                    $my_created_subjects_stmt->execute([$teacher_id]);
                                                    $my_created_subjects = $my_created_subjects_stmt->fetchAll();

                                                    // Fetch enrolled students grouped by subject for inline lists
                                                    $enrolled_by_subject = [];
                                                    if (!empty($my_created_subjects)) {
                                                        $subjectIds = array_map(function($row){ return (int)$row['id']; }, $my_created_subjects);
                                                        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
                                                        $enrStmt = $pdo->prepare("
                                                            SELECT g.subject_id, u.first_name, u.last_name, u.course, u.year_level
                                                            FROM grades g
                                                            JOIN users u ON u.id = g.student_id
                                                            WHERE g.subject_id IN ($placeholders) AND u.role = 'student'
                                                            ORDER BY u.last_name ASC, u.first_name ASC
                                                        ");
                                                        $enrStmt->execute($subjectIds);
                                                        foreach ($enrStmt->fetchAll(PDO::FETCH_ASSOC) as $enr) {
                                                            $enrolled_by_subject[(int)$enr['subject_id']][] = $enr;
                                                        }
                                                    }

                                                    foreach ($my_created_subjects as $s): ?>
                                                        <?php $enrolledList = $enrolled_by_subject[(int)$s['id']] ?? []; $detailId = 'enrolled-' . (int)$s['id']; ?>
                                                        <tr id="subject-row-<?php echo $s['id']; ?>" class="align-middle">
                                                            <td class="ps-4 fw-bold"><?php echo htmlspecialchars($s['name']); ?></td>
                                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($s['code']); ?></span></td>
                                                            <td class="text-center">
                                                                <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $detailId; ?>" aria-expanded="false" aria-controls="<?php echo $detailId; ?>" title="Show enrolled students">
                                                                    <i class="fas fa-users me-1"></i><?php echo (int)$s['student_count']; ?>
                                                                </button>
                                                            </td>
                                                            <td><small class="text-muted"><?php echo htmlspecialchars($s['description']); ?></small></td>
                                                            <td class="text-end pe-4">
                                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSubjectModal" data-id="<?php echo htmlspecialchars($s['id']); ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>" data-code="<?php echo htmlspecialchars($s['code']); ?>" data-desc="<?php echo htmlspecialchars($s['description']); ?>" title="Edit Subject"><i class="fas fa-edit"></i></button>
                                                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSubjectModal" data-id="<?php echo htmlspecialchars($s['id']); ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>" title="Delete Subject"><i class="fas fa-trash"></i></button>
                                                            </td>
                                                        </tr>
                                                        <tr class="collapse" id="<?php echo $detailId; ?>">
                                                            <td colspan="5" class="bg-light p-3">
                                                                <div class="fw-semibold mb-2"><i class="fas fa-user-graduate me-1"></i>Enrolled Students in <?php echo htmlspecialchars($s['name']); ?> (<?php echo count($enrolledList); ?>)</div>
                                                                <?php if (empty($enrolledList)): ?>
                                                                    <div class="text-muted small">No students enrolled in this subject yet.</div>
                                                                <?php else: ?>
                                                                    <div class="table-responsive">
                                                                        <table class="table table-sm table-bordered mb-0 bg-white">
                                                                            <thead class="table-light"><tr><th>#</th><th>Student Name</th><th>Course</th><th>Year Level</th></tr></thead>
                                                                            <tbody>
                                                                                <?php foreach ($enrolledList as $i => $enr): ?>
                                                                                    <tr>
                                                                                        <td><?php echo $i + 1; ?></td>
                                                                                        <td><?php echo htmlspecialchars($enr['last_name'] . ', ' . $enr['first_name']); ?></td>
                                                                                        <td><?php echo htmlspecialchars($enr['course'] ?? ''); ?></td>
                                                                                        <td><?php echo htmlspecialchars($enr['year_level'] ?? ''); ?></td>
                                                                                    </tr>
                                                                                <?php endforeach; ?>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if(empty($my_created_subjects)): ?>
                                                        <tr><td colspan="5" class="text-center text-muted p-5">
                                                            <i class="fas fa-box-open fa-3x mb-3"></i><br><h5>No Subjects Found</h5><p>Click the "Add New Subject" button to create your first subject.</p>
                                                        </td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notification History Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'history' ? 'show active' : ''; ?>" id="history">
                        <h4 class="fw-bold mb-4">Sent Notification History</h4>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-paper-plane me-2"></i>My Sent Messages (<?php echo $total_sent_notifications; ?> total)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0 history-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="ps-4">Recipient</th>
                                                <th style="width: 50%;">Message</th>
                                                <th>Date Sent</th>
                                                <th class="text-center">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($sent_notifications)): ?>
                                                <tr><td colspan="4" class="text-center text-muted p-5">You have not sent any notifications.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($sent_notifications as $notif): ?>
                                                    <tr class="align-middle">
                                                        <td class="ps-4" data-label="Recipient" style="max-width:220px;">
                                                            <div class="d-flex align-items-center text-nowrap">
                                                                <img src="<?php echo htmlspecialchars($notif['profile_picture'] ?: 'image/default.png'); ?>" class="rounded-circle me-2 avatar-sm flex-shrink-0" onerror="this.onerror=null;this.src='image/default.png';">
                                                                <div class="fw-bold text-truncate"><?php echo htmlspecialchars($notif['first_name'] . ' ' . $notif['last_name']); ?></div>
                                                            </div>
                                                        </td>
                                                        <td data-label="Message" style="min-width:200px; word-break:break-word;"><small><?php echo highlightNotificationKeywords($notif['message']); ?></small></td>
                                                        <td data-label="Date Sent"><small><?php echo date('M j, Y, g:i a', strtotime($notif['created_at'])); ?></small></td>
                                                        <td class="text-center" data-label="Status">
                                                            <?php if ($notif['is_read']): ?>
                                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Read</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Unread</span>
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

                        <!-- Pagination for Sent Notifications -->
                        <?php if ($total_sent_pages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo ($sent_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?tab=history&sent_page=<?php echo $sent_page - 1; ?>">Previous</a></li>
                                <?php for ($i = 1; $i <= $total_sent_pages; $i++): ?><?php if ($i == $sent_page || $i <= 2 || $i >= $total_sent_pages - 1 || abs($i - $sent_page) <= 1): ?><li class="page-item <?php echo ($sent_page == $i) ? 'active' : ''; ?>"><a class="page-link" href="?tab=history&sent_page=<?php echo $i; ?>"><?php echo $i; ?></a></li><?php elseif ($i == $sent_page - 2 || $i == $sent_page + 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?><?php endfor; ?>
                                <li class="page-item <?php echo ($sent_page >= $total_sent_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?tab=history&sent_page=<?php echo $sent_page + 1; ?>">Next</a></li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notify Class Modal -->
    <div class="modal fade" id="notifyClassModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Notify Entire Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="teacher.php" method="POST" id="notifyClassForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="notify_class" value="1">
                    <input type="hidden" name="subject_id" id="notify_class_subject_id">
                    <div class="modal-body">
                        <p>You are sending a broadcast to all students in <strong id="notify_class_subject_name"></strong>.</p>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="send_email" value="1" checked>
                            <label class="form-check-label small">Also send a copy via email to each student</label>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Quick Messages</label>
                            <div>
                                <?php foreach ($teacher_broadcast_templates as $template): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-1 quick-message-btn" data-message="<?php echo htmlspecialchars($template['message'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($template['title']); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small">Set Date</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm date-picker-input" placeholder="Select a date..." readonly>
                                    <button class="btn btn-outline-primary insert-date-btn" type="button" title="Insert Date"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Set Time</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm time-picker-input" placeholder="Select a time..." readonly>
                                    <button class="btn btn-outline-info insert-time-btn" type="button" title="Insert Time"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3"><label for="class_notification_message" class="form-label">Message</label><textarea class="form-control" id="class_notification_message" name="message" rows="4" required placeholder="Enter your message here..."></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Send to Class</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Notification Modal -->
    <div class="modal fade" id="sendNotificationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send Notification</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="teacher.php" method="POST" id="sendNotificationForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="send_notification" value="1">
                    <input type="hidden" name="recipient_id" id="notification_recipient_id">
                    <input type="hidden" name="active_tab_context" value="<?php echo htmlspecialchars($active_tab); ?>">
                    <div class="modal-body">
                        <p>You are sending a notification to <strong id="notification_recipient_name"></strong>.</p>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="send_email" value="1" checked>
                            <label class="form-check-label small">Also send a copy via email to this student</label>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Quick Messages</label>
                            <div>
                                <?php foreach ($teacher_broadcast_templates as $template): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-1 quick-message-btn" data-message="<?php echo htmlspecialchars($template['message'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($template['title']); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small">Set Date</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm date-picker-input" placeholder="Select a date..." readonly>
                                    <button class="btn btn-outline-primary insert-date-btn" type="button" title="Insert Date"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                            <div class="col-5">
                                <label class="form-label small">Set Time</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm time-picker-input" placeholder="Select a time..." readonly>
                                    <button class="btn btn-outline-info insert-time-btn" type="button" title="Insert Time"><i class="fas fa-arrow-down"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3"><label for="notification_message" class="form-label">Message</label><textarea class="form-control" id="notification_message" name="message" rows="4" required placeholder="Enter your message here..."></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-info text-white">Send Notification</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>

    <!-- Enroll Student Modal -->
    <div class="modal fade" id="enrollStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Enroll Student in Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="teacher.php" method="POST" id="confirmEnrollForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="student_id" id="enroll_student_id">
                    <div class="modal-body">
                        <p>Select which subject to enroll <strong id="enroll_student_name"></strong> in. This will add them to your grade ledger.</p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Subject</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">-- Choose Subject --</option>
                                <?php foreach($all_subjects as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo htmlspecialchars($s['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-user-plus me-2"></i>Confirm Enrollment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New Subject</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="source_page" value="manage_subjects">
                    <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Subject Name</label><input type="text" class="form-control" name="name" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" name="add_subject" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Subject Confirmation Modal -->
    <div class="modal fade" id="deleteSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="teacher.php?tab=manage-subjects" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="delete_subject">
                        <input type="hidden" name="subject_id" id="delete_subject_id">
                        <p>This action is irreversible. You will permanently delete the subject <strong id="delete_subject_name_text"></strong> and all associated grade records.</p>
                        <p>To confirm, please type the subject's name below:</p>
                        <div class="mb-3">
                            <input type="text" class="form-control" id="delete_confirm_input" autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" id="confirmDeleteBtn" class="btn btn-danger" disabled>
                            <i class="fas fa-trash me-2"></i>I understand, delete this subject
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Subject</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="source_page" value="manage_subjects">
                    <div class="modal-body">
                        <input type="hidden" name="subject_id" id="edit_sub_id">
                        <div class="mb-3"><label class="form-label">Subject Name</label><input type="text" class="form-control" name="name" id="edit_sub_name" required></div>
                        <div class="mb-3"><label class="form-label">Subject Code</label><input type="text" class="form-control" name="code" id="edit_sub_code" required></div>
                        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" id="edit_sub_desc" rows="2"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-arrow-left me-2"></i>Cancel</button>
                        <button type="submit" name="update_subject" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Enrolled Students Modal -->
    <div class="modal fade" id="viewEnrolledStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Enrolled Students: <span id="enrolledSubjectName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small" id="enrollmentRestrictionsAlert" style="display: none;">
                        <strong>Enrollment Restrictions:</strong>
                        <span class="d-block">Courses: <strong id="restrictedCourses"></strong></span>
                        <span class="d-block">Year Levels: <strong id="restrictedYears"></strong></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>#</th><th>Student Name</th><th>Course</th><th>Year Level</th></tr></thead>
                            <tbody id="enrolledStudentsTbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View User Full Profile Modal (Copied from admin.php) -->
    <div class="modal fade" id="viewUserProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-gradient text-white">
                    <h5 class="modal-title"><i class="fas fa-id-card me-2"></i>Student Profile Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-lg-4 text-center border-end pe-lg-4 mb-4 mb-lg-0 d-flex flex-column align-items-center">
                            <img src="image/default.png" id="view_profile_picture" class="rounded-circle mb-3 avatar-lg" onerror="this.onerror=null;this.src='image/default.png';">
                            <h4 class="mb-0 fw-bold" id="view_fullname_header"></h4>
                            <p class="text-muted" id="view_username_header"></p>
                            <div id="view_student_subheader">
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
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
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
                        <div class="col-md-6">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Overall Average</h6>
                                <h2 class="display-5 fw-bold text-primary mb-0" id="perf_avg">0.0</h2>
                                <span class="badge bg-primary mt-2 mx-auto w-25" id="perf_letter">N/A</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card text-center p-3 shadow-sm border-0">
                                <h6 class="text-muted small text-uppercase fw-bold">Total Subjects</h6>
                                <h2 class="display-5 fw-bold text-success mb-0" id="perf_total">0</h2>
                                <small class="text-muted mt-2">Enrolled & Graded</small>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white fw-bold">Grade Trend (Periodic)</div>
                                <div class="card-body">
                                    <div style="height: 350px;"><canvas id="teacherGradeChart"></canvas></div>
                                    <div id="teacherGradeChartLegend" class="d-flex flex-wrap gap-3 justify-content-center mt-3"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <?php 
                        $period_titles = ['prelim' => 'Prelim', 'midterm' => 'Midterm', 'prefinal' => 'Prefinal', 'final' => 'Final'];
                        foreach ($ledger_periods as $p_key => $p_label): ?>
                        <div class="col-xl-3 col-md-6">
                            <div class="card h-100">
                                <div class="card-header"><h5 class="mb-0"><?php echo $period_titles[$p_key]; ?> Summary</h5></div>
                                <div class="card-body d-flex flex-column">
                                    <div class="text-center mb-3">
                                        <h2 class="display-5 fw-bold text-primary mb-0" id="perf_<?php echo $p_key; ?>_avg">0.0</h2>
                                        <p class="lead mb-0" id="perf_<?php echo $p_key; ?>_letter">N/A</p>
                                        <small class="fw-bold" id="perf_<?php echo $p_key; ?>_status"></small>
                                    </div>
                                    <div class="mt-auto" style="position: relative; height: 200px;">
                                        <canvas id="perf_<?php echo $p_key; ?>_dist_chart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        (function() { "use strict";

            // --- MODULE: Utilities ---
            const Utils = {
                async fetchApi(url, options = {}) {
                    try {
                        const response = await fetch(url, options);
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        return await response.json();
                    } catch (error) {
                        console.error('API Fetch Error:', error);
                        this.showToast('A network error occurred. Please try again.', 'danger');
                        return { status: 'error', message: error.message };
                    }
                },
                showToast(message, type = 'info') {
                    const toastEl = document.getElementById('msgToast');
                    if (toastEl) {
                        const toastBody = document.getElementById('toastMsg');
                        toastBody.innerText = message;
                        toastEl.className = 'toast fade align-items-center text-white border-0';
                        toastEl.classList.add(`bg-${type}`);
                        bootstrap.Toast.getOrCreateInstance(toastEl).show();
                    }
                },
                showAlertModal(message, title = 'Alert', type = 'warning') {
                    const modalEl = document.getElementById('alertModal');
                    if (!modalEl) { alert(message); return; }
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    const modalTitle = document.getElementById('alertModalLabel');
                    const modalBody = document.getElementById('alertModalBody');
                    const modalHeader = document.getElementById('alertModalHeader');

                    const icon = type === 'danger' ? 'fa-times-circle' : (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle');
                    modalTitle.innerHTML = `<i class="fas ${icon} me-2"></i> ${title}`;
                    modalBody.innerHTML = message;
                    modalHeader.className = `modal-header bg-${type} text-white`;
                    
                    modal.show();
                },
                showConfirmModal(message, onConfirm, title = 'Please Confirm') {
                    const modalEl = document.getElementById('confirmModal');
                    if (!modalEl) { if (confirm(message)) onConfirm(); return; }
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    const modalTitle = document.getElementById('confirmModalLabel');
                    const modalBody = document.getElementById('confirmModalBody');
                    const confirmBtn = document.getElementById('confirmModalConfirmBtn');

                    modalTitle.innerHTML = `<i class="fas fa-question-circle me-2"></i> ${title}`;
                    modalBody.innerHTML = message;

                    const newConfirmBtn = confirmBtn.cloneNode(true);
                    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

                    newConfirmBtn.addEventListener('click', () => {
                        onConfirm();
                        modal.hide();
                    }, { once: true });

                    modal.show();
                },
                calculateGradePoint(grade) {
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
                },
                getGpaBadgeColor(grade) {
                    grade = parseFloat(grade);
                    if (grade >= 90) return 'bg-success';
                    if (grade >= 80) return 'bg-primary';
                    if (grade >= 75) return 'bg-info text-dark';
                    if (grade >= 70) return 'bg-warning text-dark';
                    if (grade > 0 && grade < 70) return 'bg-danger';
                    return 'bg-secondary';
                }
            };

            // --- MODULE: UI & Modals ---
            const UI = {
                init() {
                    this.initSidebar();
                    this.initToasts();
                    this.initModals();
                    this.initNotifications();
                },
                initSidebar() {
                    const sidebar = document.getElementById('sidebar');
                    const mainContent = document.getElementById('main-content');
                    const toggle = document.getElementById('sidebarToggle');
                    const overlay = document.getElementById('sidebarOverlay');

                    if (!sidebar || !mainContent || !toggle || !overlay) return;

                    let sidebarPinned = false;
                    let sidebarCloseTimer = null;

                    const applySidebar = (expanded) => {
                        sidebar.classList.toggle('collapsed', !expanded);
                        sidebar.classList.toggle('active', expanded);
                        overlay.classList.toggle('active', expanded);
                        mainContent.classList.toggle('expanded', !expanded);
                        window.dispatchEvent(new Event('resize'));
                    };

                    const toggleSidebar = () => {
                        applySidebar(sidebar.classList.contains('collapsed'));
                    };

                    const cancelSidebarClose = () => {
                        if (sidebarCloseTimer) { clearTimeout(sidebarCloseTimer); sidebarCloseTimer = null; }
                    };

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

                    overlay.addEventListener('click', toggleSidebar);

                    // Auto-close on mobile when a link is clicked
                    sidebar.querySelectorAll('.nav-link:not(.dropdown-toggle)').forEach(link => {
                        link.addEventListener('click', (e) => {
                            if (link.id === 'logoutBtn') {
                                if (!confirm('Are you sure you want to logout?')) {
                                    e.preventDefault();
                                    return;
                                }
                            }
                            if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                                toggleSidebar();
                            }
                        });
                    });

                    // Auto-minimize the sidebar on desktop page load for more viewing area.
                    if (window.innerWidth > 768 && !sidebar.classList.contains('collapsed')) {
                        toggleSidebar();
                    }
                },
                initToasts() {
                    <?php if (isset($_SESSION['message'])): ?>
                    Utils.showToast("<?php echo addslashes($_SESSION['message']); ?>", "<?php echo $_SESSION['message_type']; ?>");
                    <?php unset($_SESSION['message'], $_SESSION['message_type']); endif; ?>
                },
                initModals() {
                    document.querySelectorAll('.quick-message-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const messageTemplate = this.dataset.message;
                            const form = this.closest('form');
                            if (form) form.querySelector('textarea[name="message"]').value = messageTemplate;
                        });
                    });

                    document.querySelectorAll('.modal').forEach(modal => {
                        const textarea = modal.querySelector('textarea[name="message"]');
                        const datePickerInput = modal.querySelector('.date-picker-input');
                        const timePickerInput = modal.querySelector('.time-picker-input');
                        const insertDateBtn = modal.querySelector('.insert-date-btn');
                        const insertTimeBtn = modal.querySelector('.insert-time-btn');

                        if (textarea && datePickerInput && timePickerInput && insertDateBtn && insertTimeBtn) {
                            flatpickr(datePickerInput, {
                                dateFormat: "F j, Y",
                                defaultDate: new Date(),
                                static: true, // Prevents modal from scrolling up on open
                                onOpen: function(selectedDates, dateStr, instance) {
                                    instance.calendarContainer.addEventListener('click', e => e.stopPropagation());
                                }
                            });
                            flatpickr(timePickerInput, {
                                enableTime: true, noCalendar: true, dateFormat: "h:i K", time_24hr: false,
                                static: true, // Prevents modal from scrolling up on open
                                onOpen: function(selectedDates, dateStr, instance) {
                                    instance.calendarContainer.addEventListener('click', e => e.stopPropagation());
                                }
                            });

                            insertDateBtn.addEventListener('click', () => {
                                if (datePickerInput.value) textarea.value = textarea.value.replace(/\[Date\]/g, datePickerInput.value);
                            });

                            insertTimeBtn.addEventListener('click', () => {
                                if (timePickerInput.value) textarea.value = textarea.value.replace(/\[Time\]/g, timePickerInput.value);
                            });
                        }
                    });

                    document.getElementById('notifyClassModal')?.addEventListener('show.bs.modal', function(e) {
                        this.querySelector('#notify_class_subject_id').value = e.relatedTarget.dataset.subjectId;
                        this.querySelector('#notify_class_subject_name').innerText = e.relatedTarget.dataset.subjectName;
                    });

                    document.getElementById('sendNotificationModal')?.addEventListener('show.bs.modal', function(e) {
                        this.querySelector('#notification_recipient_id').value = e.relatedTarget.dataset.id;
                        this.querySelector('#notification_recipient_name').innerText = e.relatedTarget.dataset.name;
                    });

                    document.getElementById('enrollStudentModal')?.addEventListener('show.bs.modal', function(e) {
                        this.querySelector('#enroll_student_id').value = e.relatedTarget.dataset.id;
                        this.querySelector('#enroll_student_name').innerText = e.relatedTarget.dataset.name;
                    });

                    document.getElementById('deleteSubjectModal')?.addEventListener('show.bs.modal', function(e) {
                        const name = e.relatedTarget.dataset.name;
                        const confirmInput = this.querySelector('#delete_confirm_input');
                        const confirmBtn = this.querySelector('#confirmDeleteBtn');
                        this.querySelector('#delete_subject_id').value = e.relatedTarget.dataset.id;
                        this.querySelector('#delete_subject_name_text').innerText = name;
                        confirmInput.value = '';
                        confirmBtn.disabled = true;
                        confirmInput.oninput = () => { confirmBtn.disabled = (confirmInput.value !== name); };
                    });

                    document.getElementById('editSubjectModal')?.addEventListener('show.bs.modal', function(e) {
                        this.querySelector('#edit_sub_id').value = e.relatedTarget.dataset.id;
                        this.querySelector('#edit_sub_name').value = e.relatedTarget.dataset.name;
                        this.querySelector('#edit_sub_code').value = e.relatedTarget.dataset.code;
                        this.querySelector('#edit_sub_desc').value = e.relatedTarget.dataset.desc;
                    });

                    const notifyClassForm = document.getElementById('notifyClassForm');
                    if (notifyClassForm) {
                        notifyClassForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            if (document.getElementById('notify_class_subject_id').value === '') {
                                Utils.showAlertModal('Could not determine the target class. Please close and try again.', 'Error', 'danger');
                                return;
                            }
                            Utils.showConfirmModal(
                                'Are you sure you want to send this notification to the entire class?',
                                () => notifyClassForm.submit(),
                                'Confirm Class Notification'
                            );
                        });
                    }

                    const sendNotificationForm = document.getElementById('sendNotificationForm');
                    if (sendNotificationForm) {
                        sendNotificationForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            if (document.getElementById('notification_recipient_id').value === '') {
                                Utils.showAlertModal('Could not determine the target student. Please close and try again.', 'Error', 'danger');
                                return;
                            }
                            Utils.showConfirmModal('Are you sure you want to send this notification to the student?', () => sendNotificationForm.submit(), 'Confirm Student Notification');
                        });
                    }

                    // Listener for the new View User Profile Modal in teacher.php
                    const viewUserProfileModal = document.getElementById('viewUserProfileModal');
                    if (viewUserProfileModal) {
                        viewUserProfileModal.addEventListener('show.bs.modal', function(event) {
                            const modal = this;
                            const button = event.relatedTarget;
                            const data = JSON.parse(button.getAttribute('data-all'));

                            const calculateAge = (birthDateString) => {
                                if (!birthDateString) return 'N/A';
                                try {
                                    const birthDate = new Date(birthDateString);
                                    if (isNaN(birthDate.getTime())) return 'N/A';
                                    const today = new Date();
                                    let age = today.getFullYear() - birthDate.getFullYear();
                                    const m = today.getMonth() - birthDate.getMonth();
                                    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
                                    return age > 0 ? age : 'N/A';
                                } catch (e) { return 'N/A'; }
                            };

                            const profilePic = modal.querySelector('#view_profile_picture');
                            profilePic.src = (data.profile_picture && data.profile_picture.trim() !== '') ? data.profile_picture : 'image/default.png';
                            profilePic.onerror = () => { profilePic.src = 'image/default.png'; };

                            modal.querySelector('#view_fullname_header').innerText = `${data.first_name} ${data.last_name}`;
                            modal.querySelector('#view_username_header').innerText = `@${data.username}`;
                            modal.querySelector('#view_email').innerText = data.email || 'N/A';
                            modal.querySelector('#view_role').innerText = data.role.toUpperCase();
                            modal.querySelector('#view_course').innerText = data.course || 'N/A';
                            modal.querySelector('#view_year').innerText = data.year_level || 'N/A';
                            const birthDate = data.birth_date;
                            modal.querySelector('#view_suffix').innerText = data.suffix || 'N/A';
                            modal.querySelector('#view_bdate').innerText = birthDate ? new Date(birthDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                            modal.querySelector('#view_age').innerText = calculateAge(birthDate);
                            modal.querySelector('#view_sex').innerText = data.sex ? data.sex.charAt(0).toUpperCase() + data.sex.slice(1) : 'N/A';
                            modal.querySelector('#view_phone').innerText = data.phone_number || 'N/A';
                            modal.querySelector('#view_address').innerText = [data.home_address, data.city, data.province].filter(Boolean).join(', ') || 'N/A';
                            modal.querySelector('#view_gname').innerText = data.guardian_name || 'N/A';
                            modal.querySelector('#view_gphone').innerText = data.guardian_phone || 'N/A';
                            modal.querySelector('#view_gocc').innerText = data.guardian_occupation || 'N/A';
                            modal.querySelector('#view_gstatus').innerText = data.guardian_status || 'N/A';
                            modal.querySelector('#view_gaddress').innerText = data.guardian_address || 'N/A';
                        });
                    }
                },
                initNotifications() {
                    // Handler for "Mark all as read"
                    const markAllReadBtn = document.getElementById('markAllAsReadBtn');
                    if (markAllReadBtn) {
                        markAllReadBtn.addEventListener('click', async (e) => {
                            e.preventDefault();
                            const data = await Utils.fetchApi('teacher.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'ajax_mark_all_read=1'
                            });

                            if (data.status === 'success') {
                                document.querySelectorAll('.notification-item.bg-light').forEach(item => {
                                    item.classList.remove('bg-light');
                                    const p = item.querySelector('p.fw-bold');
                                    if (p) p.classList.replace('fw-bold', 'text-muted');
                                });
                                const badge = document.getElementById('notif-count-badge');
                                if (badge) badge.classList.add('d-none');
                                markAllReadBtn.remove();
                            }
                        });
                    }

                    // Handler for marking a single notification as read
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.addEventListener('click', async (e) => {
                            e.preventDefault();
                            const notifId = item.dataset.id;

                            if (item.classList.contains('bg-light')) {
                                const data = await Utils.fetchApi('teacher.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `ajax_mark_read=1&notification_id=${notifId}`
                                });

                                if (data.status === 'success') {
                                    item.classList.remove('bg-light');
                                    const p = item.querySelector('p.fw-bold');
                                    if (p) p.classList.replace('fw-bold', 'text-muted');
                                    
                                    const badge = document.getElementById('notif-count-badge');
                                    if (badge) {
                                        badge.textContent = data.new_count;
                                        if (data.new_count <= 0) {
                                            badge.classList.add('d-none');
                                            document.getElementById('markAllAsReadBtn')?.remove();
                                        }
                                    }
                                }
                            }
                        });
                    });
                }
            };

            // --- MODULE: Main Application ---
            const App = {
                init() {
                    UI.init();
                    Profile.init();
                    Search.init();
                    Validation.init();
                    Subjects.init(); // Keep for subject management modals
                    PerformanceMonitor.init(); // Keep for performance modal
                }
            };

            // --- MODULE: Profile Page ---
            const Profile = {
                init() {
                    const profilePicInput = document.getElementById('profilePictureInput');
                    const profilePicPreview = document.getElementById('profilePicturePreview');
                    if (profilePicInput && profilePicPreview) {
                        profilePicInput.addEventListener('change', (event) => {
                            const file = event.target.files[0];
                            if (file) {
                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    profilePicPreview.src = e.target.result; // This is handled by the global cropper script
                                };
                                reader.readAsDataURL(file);
                            }
                        });
                    }
                }
            };

            // --- MODULE: Search & Filters ---
            const Search = {
                init() {
                    this.initLiveSearch('liveSearchInput', 'searchResultsDropdown');
                    if (document.getElementById('students').classList.contains('active')) {
                        this.initStudentsFilters('studentFilterForm', 'liveSearchInput', 'subjectFilterSelect', 'teacherSearchClearBtn', 'teacher.php?tab=students', 'failingFilterSwitch', 'searchClearBtn');
                    }
                },
                initLiveSearch(inputId, dropdownId) {
                    const input = document.getElementById(inputId);
                    const dropdown = document.getElementById(dropdownId);
                    if (!input || !dropdown) return;
                    input.addEventListener('input', async (e) => {
                        const query = e.target.value.trim();
                        if (query.length < 2) return dropdown.classList.add('d-none');
                        const data = await Utils.fetchApi(`teacher.php?ajax_search=${encodeURIComponent(query)}`);
                        this.renderLiveSearchResults(data, input, dropdown);
                    });
                    document.addEventListener('click', (e) => {
                        if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.add('d-none');
                    });
                },
                renderLiveSearchResults(data, input, dropdown) {
                    dropdown.innerHTML = '';
                    if (data && data.length > 0) {
                        data.forEach(s => {
                            const a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item list-group-item-action py-2';
                            a.innerHTML = `<div class="small fw-bold">${s.first_name} ${s.last_name}</div><small class="text-muted">@${s.username}</small>`;
                            a.onclick = (e) => {
                                e.preventDefault();
                                input.value = `${s.first_name} ${s.last_name}`;
                                dropdown.classList.add('d-none');
                                input.form.submit();
                            };
                            dropdown.appendChild(a);
                        });
                        dropdown.classList.remove('d-none');
                    } else {
                        dropdown.classList.add('d-none');
                    }
                },
                animateAndSubmit(form) {
                    const results = document.getElementById('studentsResults');
                    if (results) results.classList.add('is-filtering');
                    setTimeout(() => form.submit(), 150);
                },
                initStudentsFilters(formId, inputId, selectId, buttonId, clearUrl, failingSwitchId, searchClearBtnId) {
                    const form = document.getElementById(formId);
                    if (!form) return;
                    const input = document.getElementById(inputId);
                    const select = document.getElementById(selectId);
                    const button = document.getElementById(buttonId);
                    const failingSwitch = document.getElementById(failingSwitchId);
                    const searchClearBtn = document.getElementById(searchClearBtnId);
                    if (!input || !button) return;

                    const updateButton = () => {
                        const hasSearch = input.value.trim() !== '';
                        const hasFilter = select ? select.value !== '' : false;
                        const hasFailingFilter = failingSwitch ? failingSwitch.checked : false;

                        if (searchClearBtn) {
                            searchClearBtn.classList.toggle('is-visible', hasSearch);
                        }

                        if (hasSearch || hasFilter || hasFailingFilter) {
                            button.type = 'button';
                            button.className = 'btn btn-sm btn-light border px-3';
                            button.title = 'Clear Filters';
                            button.innerHTML = '<i class="fas fa-times me-1"></i>Clear';
                            button.onclick = (e) => { e.preventDefault(); window.location.href = clearUrl; };
                        } else {
                            button.type = 'submit';
                            button.className = 'btn btn-sm btn-primary px-3';
                            button.title = 'Search';
                            button.innerHTML = '<i class="fas fa-search me-1"></i>Search';
                            button.onclick = null;
                        }
                    };

                    input.addEventListener('input', updateButton);
                    if (select) {
                        select.addEventListener('change', () => {
                            updateButton();
                            this.animateAndSubmit(form);
                        });
                    }
                    if (failingSwitch) {
                        failingSwitch.addEventListener('change', () => {
                            updateButton();
                            this.animateAndSubmit(form);
                        });
                    }
                    if (searchClearBtn) {
                        searchClearBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            input.value = '';
                            updateButton();
                            this.animateAndSubmit(form);
                        });
                    }
                    form.addEventListener('submit', (e) => {
                        if (form.dataset.submitting === '1') return;
                        e.preventDefault();
                        form.dataset.submitting = '1';
                        this.animateAndSubmit(form);
                    });
                    updateButton();
                }
            };

            // --- MODULE: Subject Management ---
            const Subjects = {
                modal: null,
                init() {
                    this.modal = document.getElementById('viewEnrolledStudentsModal');
                    if (this.modal) this.modal.addEventListener('show.bs.modal', (event) => this.loadData(event));
                },
                async loadData(event) {
                    const subjectId = event.relatedTarget.getAttribute('data-id');
                    const modalTitle = this.modal.querySelector('#enrolledSubjectName');
                    const modalTbody = this.modal.querySelector('#enrolledStudentsTbody');
                    modalTitle.textContent = 'Loading...';
                    modalTbody.innerHTML = '<tr><td colspan="4" class="text-center"><i class="fas fa-spinner fa-spin"></i></td></tr>';
                    this.modal.querySelector('#enrollmentRestrictionsAlert').style.display = 'none';
                    const data = await Utils.fetchApi(`teacher.php?ajax_get_students_by_subject=${subjectId}`);
                    if (data?.error) {
                        modalTbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger">Failed to load: ${data.error}</td></tr>`;
                    } else {
                        this.render(data);
                    }
                },
                render(data) {
                    const modalTitle = this.modal.querySelector('#enrolledSubjectName');
                    const modalTbody = this.modal.querySelector('#enrolledStudentsTbody');
                    const alertBox = this.modal.querySelector('#enrollmentRestrictionsAlert');
                    modalTitle.textContent = data.details.name;
                    if (data.details.target_course || data.details.target_year_level) {
                        this.modal.querySelector('#restrictedCourses').textContent = data.details.target_course || 'Any';
                        this.modal.querySelector('#restrictedYears').textContent = data.details.target_year_level || 'Any';
                        alertBox.style.display = 'block';
                    }
                    if (data.students?.length > 0) {
                        modalTbody.innerHTML = data.students.map((s, i) =>
                            `<tr><td>${i + 1}</td><td><img src="${s.profile_picture || 'image/default.png'}" class="rounded-circle me-2 avatar-sm" onerror="this.onerror=null;this.src='image/default.png';"> ${s.first_name} ${s.last_name}</td><td>${s.course}</td><td>${s.year_level}</td></tr>`
                        ).join('');
                    } else {
                        modalTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No students enrolled.</td></tr>';
                    }
                }
            };

            // --- MODULE: Performance Monitor ---
            const PerformanceMonitor = {
                lineChart: null,
                periodCharts: {},
                elements: {},
                init() {
                    const modal = document.getElementById('monitorPerformanceModal');
                    if (!modal) return;
                    this.elements = {
                        modal,
                        studentName: document.getElementById('perf_student_name'),
                        avg: document.getElementById('perf_avg'),
                        letter: document.getElementById('perf_letter'),
                        total: document.getElementById('perf_total'),
                        lineChartCanvas: document.getElementById('teacherGradeChart'),
                        legendContainer: document.getElementById('teacherGradeChartLegend')
                    };
                    modal.addEventListener('show.bs.modal', (event) => this.loadData(event));
                },
                async loadData(event) {
                    const studentId = event.relatedTarget.getAttribute('data-id');
                    this.elements.studentName.innerText = event.relatedTarget.getAttribute('data-name');
                    const data = await Utils.fetchApi(`teacher.php?ajax_performance=${studentId}`);
                    if (data.error) console.error("Performance data fetch failed:", data.error);
                    else this.render(data);
                },
                render(data) {
                    if (this.lineChart) this.lineChart.destroy();
                    Object.values(this.periodCharts).forEach(chart => chart?.destroy());
                    this.elements.total.innerText = data.total;
                    this.elements.avg.innerText = data.average;
                    this.elements.letter.innerText = data.letter;
                    this._renderLineChart(data);
                    this._renderLegend();
                    this.updateStats();
                    const gradePointLabels = Object.keys(data.period_distributions.prelim);
                    ['prelim', 'midterm', 'prefinal', 'final'].forEach(p => {
                        this.periodCharts[p] = this._createPeriodPieChart(`perf_${p}_dist_chart`, Object.values(data.period_distributions[p]), gradePointLabels);
                    });
                },
                _renderLineChart(data) {                    
                    this.lineChart = new Chart(this.elements.lineChartCanvas.getContext('2d'), {
                        type: 'line',
                        data: { labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'], datasets: data.datasets },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
                    });
                },
                _createPeriodPieChart(canvasId, data, labels) {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas) return null;
                    const canvasParent = canvas.parentElement;
                    canvasParent.querySelector('.no-data-msg')?.remove();
                    canvas.style.display = 'block';
                    const filteredData = [], filteredLabels = [], backgroundColors = [];
                    const colorMap = { '1.00': '#10b981', '1.25': '#10b981', '1.50': '#2563eb', '1.75': '#2563eb', '2.00': '#3b82f6', '2.25': '#3b82f6', '2.50': '#f59e0b', '2.75': '#f59e0b', '3.00': '#ef4444', '5.00': '#1e293b', 'IP': '#64748b' };
                    labels.forEach((label, index) => {
                        if (data[index] > 0) {
                            filteredLabels.push(label);
                            filteredData.push(data[index]);
                            backgroundColors.push(colorMap[label] || '#cccccc');
                        }
                    });
                    if (filteredData.length === 0) {
                        canvas.style.display = 'none';
                        const p = document.createElement('p');
                        p.className = 'text-center text-muted py-5 small no-data-msg';
                        p.innerText = 'No grades for this period.';
                        canvasParent.appendChild(p);
                        return null;
                    }
                    return new Chart(canvas.getContext('2d'), {
                        type: 'doughnut',
                        data: { labels: filteredLabels, datasets: [{ data: filteredData, backgroundColor: backgroundColors, borderColor: '#ffffff', borderWidth: 2 }] },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                    });
                },
                _renderLegend() {
                    this.elements.legendContainer.innerHTML = '';
                    this.lineChart.data.datasets.forEach((dataset, index) => {
                        const div = document.createElement('div');
                        div.className = 'form-check form-check-inline';
                        div.innerHTML = `<input class="form-check-input" type="checkbox" id="t_ds_check_${index}" checked style="cursor: pointer;">
                                         <label class="form-check-label small fw-bold d-flex align-items-center" for="t_ds_check_${index}" style="cursor: pointer;">
                                             <span class="me-2" style="width: 12px; height: 12px; background-color: ${dataset.borderColor}; display: inline-block; border-radius: 2px;"></span>
                                             ${dataset.label}
                                         </label>`;
                        div.querySelector('input').addEventListener('change', (e) => {
                            div.querySelector('label').style.opacity = e.target.checked ? '1' : '0.4';
                            this.lineChart.setDatasetVisibility(index, e.target.checked);
                            this.lineChart.update();
                            this.updateStats();
                        });
                        this.elements.legendContainer.appendChild(div);
                    });
                },
                updateStats() {
                    if (!this.lineChart) return;
                    const visibleDatasets = this.lineChart.data.datasets.filter((_, i) => this.lineChart.isDatasetVisible(i));
                    const periodData = { prelim: [], midterm: [], prefinal: [], final: [] };
                    let overallAverageSum = 0;
                    visibleDatasets.forEach(ds => {
                        const subjectGrades = ds.data.filter(grade => grade > 0);
                        if (subjectGrades.length > 0) {
                            overallAverageSum += subjectGrades.reduce((a, b) => a + b, 0) / subjectGrades.length;
                            if (ds.data[0] > 0) periodData.prelim.push(ds.data[0]);
                            if (ds.data[1] > 0) periodData.midterm.push(ds.data[1]);
                            if (ds.data[2] > 0) periodData.prefinal.push(ds.data[2]);
                            if (ds.data[3] > 0) periodData.final.push(ds.data[3]);
                        }
                    });
                    const overallAvg = visibleDatasets.length > 0 ? overallAverageSum / visibleDatasets.length : 0;
                    this.elements.avg.innerText = overallAvg.toFixed(1);
                    this.elements.letter.innerText = Utils.calculateGradePoint(overallAvg);
                    Object.entries(periodData).forEach(([period, grades]) => {
                        const avg = grades.length > 0 ? grades.reduce((a, b) => a + b, 0) / grades.length : 0;
                        const avgEl = document.getElementById(`perf_${period}_avg`);
                        const letterEl = document.getElementById(`perf_${period}_letter`);
                        const statusEl = document.getElementById(`perf_${period}_status`);
                        if (avgEl) avgEl.innerText = avg.toFixed(1);
                        if (letterEl) letterEl.innerText = Utils.calculateGradePoint(avg);
                        if (statusEl) {
                            const isPassing = avg >= 75;
                            statusEl.innerText = avg > 0 ? (isPassing ? 'Passing' : 'Failing') : 'No Grade';
                            statusEl.className = 'fw-bold ' + (avg > 0 ? (isPassing ? 'text-success' : 'text-danger') : 'text-muted');
                        }
                    });
                }
            };

            // --- MODULE: Form Validation ---
            const Validation = {
                init() {
                    const weightsForm = document.getElementById('teacherWeightsForm');
                    if (weightsForm) weightsForm.addEventListener('submit', (e) => this.validateWeights(e));
                },
                validateWeights(e) {
                    const form = e.target;
                    const getVal = (name) => parseInt(form.querySelector(`[name="${name}"]`).value || 0, 10);
                    const weights = {
                        lec: getVal('weight_lec'), lab: getVal('weight_lab'),
                        lec_quiz: getVal('weight_lec_quiz'), lec_online: getVal('weight_lec_online'), lec_exam: getVal('weight_lec_exam'),
                        lab_att: getVal('weight_lab_att'), lab_cs: getVal('weight_lab_cs'), lab_exam: getVal('weight_lab_exam')
                    };
                    if (weights.lec + weights.lab !== 100) {
                        alert(`Overall weights (Lecture + Lab) must sum to 100%. Currently: ${weights.lec + weights.lab}%`);
                        e.preventDefault();
                    }
                    if (weights.lec_quiz + weights.lec_online + weights.lec_exam !== 100) {
                        alert(`Lecture sub-components must sum to 100%. Currently: ${weights.lec_quiz + weights.lec_online + weights.lec_exam}%`);
                        e.preventDefault();
                    }
                    if (weights.lab_att + weights.lab_cs + weights.lab_exam !== 100) {
                        alert(`Laboratory sub-components must sum to 100%. Currently: ${weights.lab_att + weights.lab_cs + weights.lab_exam}%`);
                        e.preventDefault();
                    }
                }
            };

            // Auto-calculate age from birth date in profile form
            document.addEventListener('DOMContentLoaded', function() {
                const bdateInput = document.getElementById('teacher_birth_date');
                const ageInput = document.getElementById('teacher_age');
                function calcAge() {
                    if (!bdateInput || !ageInput || !bdateInput.value) return;
                    const birthDate = new Date(bdateInput.value);
                    if (isNaN(birthDate.getTime())) return;
                    const today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const m = today.getMonth() - birthDate.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
                    ageInput.value = age > 0 ? age : '';
                }
                if (bdateInput) {
                    bdateInput.addEventListener('change', calcAge);
                    calcAge();
                }
            });

            document.addEventListener('DOMContentLoaded', () => App.init());

        })();
    </script>

    <!-- Grade File Viewer Modal -->
    <div class="modal fade" id="gradeFileViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="gx-resize-handle gx-resize-n" data-dir="n"></div>
            <div class="gx-resize-handle gx-resize-e" data-dir="e"></div>
            <div class="gx-resize-handle gx-resize-s" data-dir="s"></div>
            <div class="gx-resize-handle gx-resize-w" data-dir="w"></div>
            <div class="gx-resize-handle gx-resize-ne" data-dir="ne"></div>
            <div class="gx-resize-handle gx-resize-nw" data-dir="nw"></div>
            <div class="gx-resize-handle gx-resize-se" data-dir="se"></div>
            <div class="gx-resize-handle gx-resize-sw" data-dir="sw"></div>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i><span id="gradeFileViewerTitle">Grade Sheet</span></h5>
                    <div class="d-flex align-items-center gap-2">
                        <select id="gradeFileViewerSheet" class="form-select form-select-sm" style="max-width:280px;"></select>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body" id="gradeFileViewerBody">
                    <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>
                </div>
                <div class="modal-footer">
                    <span id="gradeFileViewerHint" class="me-auto small text-muted">Click any cell to edit, then Save (auto-computes). Use Compute on the list to recompute manually.</span>
                    <button type="button" class="btn btn-success" id="gradeFileViewerSave" data-csrf="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-save me-1"></i>Save</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .gx-scroll-top { position: sticky; top: 0; z-index: 20; background: #fff; overflow-x: auto; overflow-y: hidden; }
        .gx-scroll-top::-webkit-scrollbar { height: 14px; }
        .gx-scroll-top::-webkit-scrollbar-track { background: #f1f3f5; }
        .gx-scroll-top::-webkit-scrollbar-thumb { background: #adb5bd; border-radius: 7px; }
        .gx-scroll-top::-webkit-scrollbar-thumb:hover { background: #868e96; }
        .gx-scroll-top-inner { height: 1px; }
        #gradeFileViewerModal .modal-header { cursor: move; user-select: none; }
        #gradeFileViewerModal td.gx-cell { cursor: text; }
        #gradeFileViewerModal td.gx-cell:focus { outline: 2px solid #0d6efd; outline-offset: -2px; background: #e7f1ff; }
        #gradeFileViewerModal .modal-content { height: 100%; }
        .gx-resize-handle {
            position: absolute;
            z-index: 35;
            pointer-events: auto;
            touch-action: none;
        }
        .gx-resize-n { top: -4px; left: 10px; right: 10px; height: 8px; cursor: n-resize; }
        .gx-resize-s { bottom: -4px; left: 10px; right: 10px; height: 8px; cursor: s-resize; }
        .gx-resize-e { top: 10px; right: -4px; bottom: 10px; width: 8px; cursor: e-resize; }
        .gx-resize-w { top: 10px; left: -4px; bottom: 10px; width: 8px; cursor: w-resize; }
        .gx-resize-ne { top: -5px; right: -5px; width: 16px; height: 16px; cursor: ne-resize; }
        .gx-resize-nw { top: -5px; left: -5px; width: 16px; height: 16px; cursor: nw-resize; }
        .gx-resize-se { bottom: -5px; right: -5px; width: 16px; height: 16px; cursor: se-resize; }
        .gx-resize-sw { bottom: -5px; left: -5px; width: 16px; height: 16px; cursor: sw-resize; }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        (function () {
            var MAX_ROWS = 500;
            var state = { workbook: null, top: null, topInner: null, bottom: null };
            var modalEl = document.getElementById('gradeFileViewerModal');
            var sheetSel = document.getElementById('gradeFileViewerSheet');
            var titleEl = document.getElementById('gradeFileViewerTitle');
            var bodyEl = document.getElementById('gradeFileViewerBody');
            if (!modalEl || !bodyEl) return;

            function escapeHtml(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function syncTopWidth() {
                if (state.bottom && state.topInner) {
                    state.topInner.style.width = state.bottom.scrollWidth + 'px';
                }
            }

            function formatViewerCell(v) {
                if (v === null || v === undefined) return '';
                if (typeof v === 'number') {
                    return String(Math.round(v * 100) / 100);
                }
                var s = String(v);
                // Round long floating-point artifacts (e.g. 89.449999) to 2 decimals.
                if (/^-?\d+\.\d{3,}$/.test(s)) {
                    return String(Math.round(parseFloat(s) * 100) / 100);
                }
                return s;
            }

            function renderSheet(name) {
                if (!state.workbook || !name) { bodyEl.innerHTML = ''; return; }
                var ws = state.workbook.Sheets[name];
                if (!ws || !ws['!ref']) { bodyEl.innerHTML = '<div class="text-muted">Sheet not found or empty.</div>'; return; }
                var range = XLSX.utils.decode_range(ws['!ref']);
                var totalRows = range.e.r - range.s.r + 1;
                var truncated = totalRows > MAX_ROWS;
                var lastRow = truncated ? (range.s.r + MAX_ROWS - 1) : range.e.r;
                var tableHtml = '<table class="table table-bordered table-sm mb-0 align-middle gx-edit">';
                for (var R = range.s.r; R <= lastRow; R++) {
                    var isHeader = (R === range.s.r);
                    tableHtml += '<tr>';
                    for (var C = range.s.c; C <= range.e.c; C++) {
                        var addr = XLSX.utils.encode_cell({ r: R, c: C });
                        var cell = ws[addr];
                        var v = cell ? formatViewerCell(cell.v) : '';
                        if (isHeader) {
                            tableHtml += '<th class="table-light" style="white-space:nowrap;">' + escapeHtml(v) + '</th>';
                        } else if (state.editable) {
                            tableHtml += '<td class="gx-cell" contenteditable="true" data-addr="' + addr + '" style="white-space:nowrap;min-width:80px;">' + escapeHtml(v) + '</td>';
                        } else {
                            tableHtml += '<td style="white-space:nowrap;">' + escapeHtml(v) + '</td>';
                        }
                    }
                    tableHtml += '</tr>';
                }
                tableHtml += '</table>';

                var html = '<div class="gx-scroll-top"><div class="gx-scroll-top-inner"></div></div>';
                html += '<div class="table-responsive gx-scroll-bottom">' + tableHtml + '</div>';
                if (truncated) { html += '<div class="text-muted small mt-2">Showing first ' + MAX_ROWS + ' rows for editing. Larger files should be edited in Excel.</div>'; }
                bodyEl.innerHTML = html;

                state.top = bodyEl.querySelector('.gx-scroll-top');
                state.topInner = bodyEl.querySelector('.gx-scroll-top-inner');
                state.bottom = bodyEl.querySelector('.gx-scroll-bottom');

                syncTopWidth();

                state.top.addEventListener('scroll', function () { state.bottom.scrollLeft = state.top.scrollLeft; });
                state.bottom.addEventListener('scroll', function () { state.top.scrollLeft = state.bottom.scrollLeft; });

                // Persist edits back into the in-memory workbook so Save writes them out.
                bodyEl.querySelectorAll('td.gx-cell').forEach(function (td) {
                    td.addEventListener('input', function () {
                        var addr = td.getAttribute('data-addr');
                        var raw = td.textContent;
                        var num = Number(raw);
                        var isNum = raw.trim() !== '' && !isNaN(num) && isFinite(num);
                        ws[addr] = isNum ? { t: 'n', v: num } : { t: 's', v: raw };
                    });
                });
            }

            function normalizeKey(s) {
                return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
            }

            function isGradeLikeImportKey(nk) {
                if (/(image|picture|date|time|remarks|status|letter_grade|grade_point|gp)/.test(nk)) return false;
                return /(grade|score|rating|average|avg|percent|pct|mark|point|term|sem|semester|period|prelim|midterm|prefinal|final|quiz|exam|attendance|laboratory|lab|online|assignment|activity|project|seatwork|recitation)/.test(nk);
            }

            // Pick the sheet that most likely holds the real grade table (student rows plus
            // grade columns), mirroring the server-side importer, so the viewer opens on the
            // transcript instead of an instructions/weights sheet (e.g. "Grade Weights").
            function pickBestSheet(wb) {
                var best = null, bestScore = -1;
                (wb.SheetNames || []).forEach(function (name) {
                    var ws = wb.Sheets[name];
                    if (!ws) return;
                    var rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', blankrows: false, raw: false });
                    if (!rows || !rows.length) return;
                    var keys = (rows[0] || []).map(function (h) { return normalizeKey(h); }).filter(function (k) { return k; });
                    var hasStudent = keys.some(function (k) {
                        if (/(subject|course|teacher|weight|component|description)/.test(k)) return false;
                        return /(student|learner|lrn)/.test(k) || /(^|_)name(_|$)/.test(k);
                    });
                    var gradeHits = 0;
                    keys.forEach(function (k) { if (isGradeLikeImportKey(k)) gradeHits++; });
                    var score = (hasStudent ? 40 : 0) + Math.min(gradeHits * 15, 90);
                    if (rows.length > 1) score += Math.min(rows.length, 5);
                    if (score > bestScore) { bestScore = score; best = name; }
                });
                return bestScore > 0 ? best : null;
            }

            async function openViewer(stored, name, editable) {
                state.workbook = null;
                state.stored = stored;
                state.editable = !!editable;
                titleEl.textContent = (editable ? 'Edit: ' : 'View: ') + (name || 'Grade Sheet');
                var saveBtnEl = document.getElementById('gradeFileViewerSave');
                var hintEl = document.getElementById('gradeFileViewerHint');
                if (saveBtnEl) saveBtnEl.style.display = editable ? '' : 'none';
                if (hintEl) hintEl.textContent = (editable ? 'Click any cell to edit, then Save (auto-computes).' : 'Read-only preview. Use Open to edit in Excel, then Compute.') + ' [editor build 2026-11-27d]';
                sheetSel.innerHTML = '';
                bodyEl.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>';
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                resetGeometry();
                try {
                    // Cache-bust so edits saved in Excel (same filename) are always reflected.
                    var resp = await fetch('uploads/grade_imports/' + encodeURIComponent(stored) + '?t=' + Date.now(), { cache: 'no-store' });
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    var buf = await resp.arrayBuffer();
                    if (typeof XLSX === 'undefined') throw new Error('Spreadsheet reader not available.');
                    var wb = XLSX.read(buf, { type: 'array' });
                    state.workbook = wb;
                    sheetSel.innerHTML = wb.SheetNames.map(function (n) { return '<option value="' + escapeHtml(n) + '">' + escapeHtml(n) + '</option>'; }).join('');
                    if (wb.SheetNames.length) { renderSheet(pickBestSheet(wb) || wb.SheetNames[0]); }
                    else { bodyEl.innerHTML = '<div class="text-center text-muted py-4">No sheets found.</div>'; }
                } catch (e) {
                    bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Failed to load file: ' + escapeHtml(e.message) + '</div>';
                }
            }

            var dialog = modalEl.querySelector('.modal-dialog');
            var headerEl = modalEl.querySelector('.modal-header');
            var drag = { active: false, sx: 0, sy: 0, left: 0, top: 0 };
            var resize = { active: false, dir: '', sx: 0, sy: 0, left: 0, top: 0, w: 0, h: 0 };

            function resetGeometry() {
                if (!dialog) return;
                var vw = window.innerWidth, vh = window.innerHeight;
                var w = Math.min(1140, vw - 32);
                var h = Math.min(760, vh - 32);
                dialog.style.position = 'fixed';
                dialog.style.margin = '0';
                dialog.style.maxWidth = 'none';
                dialog.style.maxHeight = 'none';
                dialog.style.left = Math.max(0, Math.round((vw - w) / 2)) + 'px';
                dialog.style.top = Math.max(0, Math.round((vh - h) / 2)) + 'px';
                dialog.style.width = w + 'px';
                dialog.style.height = h + 'px';
                drag.active = false;
                resize.active = false;
            }

            function clamp(v, min, max) { return Math.min(Math.max(v, min), max); }

            function resizeCursor(dir) {
                return { n: 'n-resize', s: 's-resize', e: 'e-resize', w: 'w-resize', ne: 'ne-resize', nw: 'nw-resize', se: 'se-resize', sw: 'sw-resize' }[dir] || 'default';
            }

            if (headerEl && dialog) {
                headerEl.addEventListener('mousedown', function (e) {
                    if (e.target.closest('button, select, .btn-close')) return;
                    drag.active = true;
                    drag.sx = e.clientX;
                    drag.sy = e.clientY;
                    drag.left = parseFloat(dialog.style.left) || 0;
                    drag.top = parseFloat(dialog.style.top) || 0;
                    e.preventDefault();
                });
            }

            document.querySelectorAll('.gx-resize-handle').forEach(function (h) {
                h.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    resize.active = true;
                    resize.dir = h.getAttribute('data-dir') || '';
                    resize.sx = e.clientX;
                    resize.sy = e.clientY;
                    resize.left = parseFloat(dialog.style.left) || 0;
                    resize.top = parseFloat(dialog.style.top) || 0;
                    resize.w = parseFloat(dialog.style.width) || 0;
                    resize.h = parseFloat(dialog.style.height) || 0;
                    document.body.style.cursor = resizeCursor(resize.dir);
                });
            });

            document.addEventListener('mousemove', function (e) {
                if (resize.active) {
                    var dx = e.clientX - resize.sx;
                    var dy = e.clientY - resize.sy;
                    var dir = resize.dir;
                    var left = resize.left, top = resize.top, w = resize.w, h = resize.h;

                    if (dir.indexOf('e') !== -1) w = resize.w + dx;
                    if (dir.indexOf('s') !== -1) h = resize.h + dy;
                    if (dir.indexOf('w') !== -1) { w = resize.w - dx; left = resize.left + dx; }
                    if (dir.indexOf('n') !== -1) { h = resize.h - dy; top = resize.top + dy; }

                    var MIN_W = 360, MIN_H = 200;
                    if (w < MIN_W) { if (dir.indexOf('w') !== -1) left = resize.left + (resize.w - MIN_W); w = MIN_W; }
                    if (h < MIN_H) { if (dir.indexOf('n') !== -1) top = resize.top + (resize.h - MIN_H); h = MIN_H; }

                    // Keep the window within the viewport.
                    w = Math.min(w, window.innerWidth);
                    h = Math.min(h, window.innerHeight);
                    left = clamp(left, 0, Math.max(0, window.innerWidth - w));
                    top = clamp(top, 0, Math.max(0, window.innerHeight - h));

                    dialog.style.left = left + 'px';
                    dialog.style.top = top + 'px';
                    dialog.style.width = w + 'px';
                    dialog.style.height = h + 'px';
                    return;
                }
                if (!drag.active) return;
                var nw = parseFloat(dialog.style.width) || 0;
                var nh = parseFloat(dialog.style.height) || 0;
                var nl = clamp(drag.left + (e.clientX - drag.sx), 0, Math.max(0, window.innerWidth - nw));
                var nt = clamp(drag.top + (e.clientY - drag.sy), 0, Math.max(0, window.innerHeight - nh));
                dialog.style.left = nl + 'px';
                dialog.style.top = nt + 'px';
            });

            document.addEventListener('mouseup', function () {
                drag.active = false;
                resize.active = false;
                document.body.style.cursor = '';
                syncTopWidth();
            });

            modalEl.addEventListener('hidden.bs.modal', function () {
                drag.active = false;
                resize.active = false;
                document.body.style.cursor = '';
            });

            sheetSel.addEventListener('change', function () { renderSheet(this.value); });
            window.addEventListener('resize', syncTopWidth);

            var saveBtn = document.getElementById('gradeFileViewerSave');
            if (saveBtn) {
                saveBtn.addEventListener('click', async function () {
                    if (!state.workbook || !state.stored) return;
                    var csrf = saveBtn.getAttribute('data-csrf') || '';
                    saveBtn.disabled = true;
                    var prev = saveBtn.innerHTML;
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
                    try {
                        var out = XLSX.write(state.workbook, { bookType: 'xlsx', type: 'array' });
                        var fd = new FormData();
                        fd.append('action', 'save_grade_file_editor');
                        fd.append('csrf_token', csrf);
                        fd.append('stored', state.stored);
                        fd.append('grade_file', new Blob([out], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), 'edited.xlsx');
                        var resp = await fetch('teacher.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (resp.redirected) { window.location.href = resp.url; return; }
                        window.location.href = 'teacher.php?tab=manage-grades';
                    } catch (err) {
                        alert('Save failed: ' + (err && err.message ? err.message : err));
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = prev;
                    }
                });
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-view-grade');
                if (!btn) return;
                e.preventDefault();
                openViewer(btn.getAttribute('data-stored'), btn.getAttribute('data-name'), false);
            });
        })();
    </script>
    <script src="includes/notifications.js"></script>
</body>
</html>