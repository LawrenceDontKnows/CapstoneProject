<?php
include 'includes/conn.php';
include 'includes/functions.php';
requireRole('teacher');

// This file is now primarily for backend processing of grade/subject actions initiated from teacher.php.
// All UI has been consolidated into teacher.php.
// If a user lands here directly, redirect them to the appropriate teacher page.

// All query parameters are preserved in the redirect.
// This file is now primarily for backend processing of grade/subject actions initiated from teacher.php.
// All UI has been consolidated into teacher.php.
// If a user lands here directly, redirect them to the appropriate teacher page.

// All query parameters are preserved in the redirect.
$query_string = $_SERVER['QUERY_STRING'] ?? '';
$redirect_url = 'teacher.php' . ($query_string ? '?' . $query_string : '');

header('Location: ' . $redirect_url);
exit();
