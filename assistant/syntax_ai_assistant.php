<?php
include '../includes/conn.php';
include '../includes/functions.php';

// Completely disable the minifyHTML output buffer started in functions.php
while (ob_get_level()) {
    ob_end_clean();
}

if (!isLoggedIn() || !hasRole('student')) {
    echo json_encode(['recommendation' => 'Greetings. Please log in to access the Syntax AI assistant.']);
    exit;
}

// Fetch student metadata for personalized context
$student_id = $_SESSION['user_id'];
$profile_stmt = $pdo->prepare("SELECT course, year_level FROM users WHERE id = ?");
$profile_stmt->execute([$student_id]);
$profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

$query = strtolower($_POST['query'] ?? '');

// --- Context Awareness Logic ---
$last_intent = $_SESSION['last_ai_intent'] ?? null;
$is_affirmative = preg_match('/\b(yes|yep|sure|ok|okay|please|proceed|do it|tell me|y)\b/i', $query);
$is_negative = preg_match('/\b(no|nope|not now|stop|nothing|n)\b/i', $query);

$acknowledgment = "";
if ($is_affirmative && $last_intent) {
    // Prepend a polite acknowledgment to the next response
    $acknowledgment = "Certainly! I shall proceed with that for you. ";
    $query = $last_intent;
    unset($_SESSION['last_ai_intent']);
} elseif ($is_negative) {
    echo json_encode(['recommendation' => "Understood. I shall remain on standby. Is there anything else you would like me to analyze?"]);
    exit;
}

// Fetch student grades for analysis
$stmt = $pdo->prepare("SELECT g.*, s.name as subject_name FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ?");
$stmt->execute([$student_id]);
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lowest_grade = 101; // Initialize higher than max possible grade (100)
$lowest_subject = "N/A";
$improving_subjects = [];
$struggling_subjects = [];
$passing_subjects = [];
$at_risk_subjects = [];
$subject_mentioned = null;

foreach ($grades as $g) {
    $avg = $g['grade'];
    
    if ($avg < $lowest_grade) {
        $lowest_grade = $avg;
        $lowest_subject = $g['subject_name'];
    }

    if ($avg >= 75) {
        $passing_subjects[] = $g['subject_name'];
    } else {
        $struggling_subjects[] = $g['subject_name']; 
    }

    // Borderline Risk Detection (75-78 range)
    if ($avg >= 75 && $avg <= 78) {
        $at_risk_subjects[] = $g['subject_name'];
    }

    // Analysis Logic: Detect if a specific subject from the student's records is mentioned in the query
    if (strpos($query, strtolower($g['subject_name'])) !== false) {
        $subject_mentioned = $g;
    }
    
    // Analyze trends across periods for each subject
    $period_grades_for_subject = [];
    if (isset($g['prelim']) && $g['prelim'] > 0) $period_grades_for_subject['prelim'] = (float)$g['prelim'];
    if (isset($g['midterm']) && $g['midterm'] > 0) $period_grades_for_subject['midterm'] = (float)$g['midterm'];
    if (isset($g['prefinal']) && $g['prefinal'] > 0) $period_grades_for_subject['prefinal'] = (float)$g['prefinal'];
    if (isset($g['final']) && $g['final'] > 0) $period_grades_for_subject['final'] = (float)$g['final'];

    $num_periods = count($period_grades_for_subject);
    if ($num_periods >= 2) {
        $grades_values = array_values($period_grades_for_subject);
        $latest_grade = end($grades_values);
        $previous_grade = $grades_values[$num_periods - 2]; // Second to last element

        if ($latest_grade < $previous_grade) {
            if (!in_array($g['subject_name'], $struggling_subjects)) $struggling_subjects[] = $g['subject_name'];
        } elseif ($latest_grade > $previous_grade) {
            if (!in_array($g['subject_name'], $improving_subjects)) $improving_subjects[] = $g['subject_name'];
        }
        
        // Detect sharp decline (>10 points) even if still passing
        if (($previous_grade - $latest_grade) > 10) {
            if (!in_array($g['subject_name'], $at_risk_subjects)) $at_risk_subjects[] = $g['subject_name'] . " (Significant Drop)";
        }
    }
}

// Intelligent Recommendation Logic
$response = "";

if ($subject_mentioned && preg_match('/(need|target|pass|get|score|reach|calculate|chance|possible)/i', $query)) {
    // Target Score / Passing Requirement Logic
    $s_name = $subject_mentioned['subject_name'];
    $current_avg = $subject_mentioned['grade'];
    
    if ($current_avg >= 75) {
        $response = "You are currently passing <strong>$s_name</strong> with a grade of <strong>" . number_format($current_avg, 1) . "</strong>. To achieve a 1.0 (90%+), you would need to maintain an average of at least 92 in your remaining assessments.";
    } else {
        $needed_total = (75 * 4) - ($subject_mentioned['prelim'] + $subject_mentioned['midterm'] + $subject_mentioned['prefinal']);
        $response = "To reach the passing mark of 75 in <strong>$s_name</strong>, you need to target approximately <strong>" . number_format($needed_total, 1) . "</strong> in your remaining period(s). Since the Final Exam is weighted at 40%, you still have a strong chance to pass.";
    }
    unset($_SESSION['last_ai_intent']);
} elseif ($subject_mentioned && preg_match('/(why|reason|diagnostic|detail|breakdown|low|bad)/i', $query)) {
    // Diagnostic logic: Identify weak components
    $s_name = $subject_mentioned['subject_name'];
    $weak_areas = [];
    $comp_map = ['quiz' => 'Quizzes', 'recit' => 'Recitation', 'report' => 'Reports', 'project' => 'Projects', 'exam' => 'Exams'];
    
    foreach ($comp_map as $key => $label) {
        $sum = 0; $count = 0;
        foreach (['prelim', 'midterm', 'prefinal', 'final'] as $p) {
            if (isset($subject_mentioned[$p.'_'.$key]) && $subject_mentioned[$p.'_'.$key] > 0) {
                $sum += $subject_mentioned[$p.'_'.$key];
                $count++;
            }
        }
        if ($count > 0 && ($sum / $count) < 75) {
            $weak_areas[] = "<strong>$label</strong> (Avg: " . number_format($sum/$count, 1) . ")";
        }
    }
    
    if (!empty($weak_areas)) {
        $response = "Analyzing the breakdown for <strong>$s_name</strong>, your performance is currently hampered by " . implode(', ', $weak_areas) . ". Focusing on these specific components will yield the fastest improvement.";
    } else {
        $response = "Your component scores for <strong>$s_name</strong> are consistent. Your current average of " . number_format($subject_mentioned['grade'], 1) . " reflects steady work across the board.";
    }
    unset($_SESSION['last_ai_intent']);
} elseif ($subject_mentioned && preg_match('/(recommend|improve|help|advice|tips|suggest|analyze|trend|track|progress)/i', $query)) {
    // Directly provide subject-specific analysis if an action is requested alongside the subject name
    $s_name = $subject_mentioned['subject_name'];
    $s_avg = number_format($subject_mentioned['grade'], 1);
    $s_point = calculateGradePoint($subject_mentioned['grade']);
    
    $response = "I am specifically analyzing your performance for <strong>$s_name</strong>. Your current average is <strong>$s_avg</strong> ($s_point). ";
    
    if ($subject_mentioned['grade'] < 75) {
        $response .= "Since this is currently below the passing threshold, I recommend focusing on your upcoming assessments. Reviewing your previous period mistakes and reaching out to your instructor for a consultation would be highly beneficial.";
    } else {
        $response .= "You are performing well! To further improve or maintain this standing, I suggest focusing on consistency in your recitation and project components.";
    }
    unset($_SESSION['last_ai_intent']);
} elseif ($subject_mentioned) {
    // General status for a specific subject
    $s_name = $subject_mentioned['subject_name'];
    $s_avg = number_format($subject_mentioned['grade'], 2);
    $s_point = calculateGradePoint($subject_mentioned['grade']);
    
    $response = "I have retrieved your records for <strong>$s_name</strong>. Your current average is <strong>$s_avg</strong>, which equates to a grade point of <strong>$s_point</strong>. ";
    
    $period_marks = [];
    if ($subject_mentioned['prelim'] > 0) $period_marks[] = "Prelim: " . number_format($subject_mentioned['prelim'], 1);
    if ($subject_mentioned['midterm'] > 0) $period_marks[] = "Midterm: " . number_format($subject_mentioned['midterm'], 1);
    if ($subject_mentioned['prefinal'] > 0) $period_marks[] = "Prefinal: " . number_format($subject_mentioned['prefinal'], 1);
    if ($subject_mentioned['final'] > 0) $period_marks[] = "Final: " . number_format($subject_mentioned['final'], 1);

    if (!empty($period_marks)) {
        $response .= "The breakdown of your periodic marks is as follows: " . implode(', ', $period_marks) . ". ";
    }

    if ($subject_mentioned['grade'] < 75) {
        $response .= "<br>Since this is currently below the target threshold, would you like some specific <strong>recommendations</strong> for this subject?";
        $_SESSION['last_ai_intent'] = 'recommendation';
    }
} elseif (preg_match('/(trend|analyze|track|progress|trajectory|movement)/i', $query)) {
    if (empty($grades)) {
        $response = "I cannot perform a trend analysis without grade data. Once your instructors provide your marks, I will be able to map your progress.";
    } else {
        $trend_report = [];
        if (!empty($improving_subjects)) {
            $trend_report[] = "You are demonstrating an <strong>upward trajectory</strong> in " . implode(', ', array_unique($improving_subjects)) . ".";
        }
        if (!empty($struggling_subjects)) {
            $trend_report[] = "I have noted a <strong>recent dip</strong> in " . implode(', ', array_unique($struggling_subjects)) . ".";
        }
        
        if (empty($trend_report)) {
            $response = "Your current academic performance is exceptionally stable across all subjects.";
        } else {
            $response = "Here is my analysis of your current trends:<br><ul><li>" . implode("</li><li>", $trend_report) . "</li></ul>";
        }
        $response .= "<br><strong>Would you like some specific tips on how to improve these areas?</strong>";
        $_SESSION['last_ai_intent'] = 'recommendation';
    }
} 
elseif (preg_match('/(recommend|help|advice|suggest|improve|tips|what should i do|guide|plan|strategy)/i', $query)) {
    if (empty($grades)) {
        $response = "It appears I do not have access to your grade data yet, " . $_SESSION['first_name'] . ". Once your instructors upload your marks, I shall be ready to provide a thorough analysis of your performance.";
        unset($_SESSION['last_ai_intent']);
    } else {
        $recommendations_list = [];
        
        if ($lowest_grade < 75 && $lowest_subject !== "N/A") {
            $recommendations_list[] = "Focus heavily on <strong>$lowest_subject</strong>. It is currently your most critical area for improvement.";
        }

        if (!empty($at_risk_subjects)) {
            $recommendations_list[] = "I've flagged <strong>" . implode(', ', array_unique($at_risk_subjects)) . "</strong> as 'At-Risk'. These subjects are either borderline passing or showing a sudden drop in performance.";
        }
        
        if (!empty($struggling_subjects)) {
            $recommendations_list[] = "I've observed a slight downward trend in subjects like <strong>" . implode(', ', array_unique($struggling_subjects)) . "</strong> based on your latest period grades. Perhaps reviewing your notes from previous periods or exploring additional resources could be beneficial.";
        }

        if (!empty($improving_subjects)) {
            $recommendations_list[] = "Excellent work! You've shown commendable improvement in subjects such as <strong>" . implode(', ', array_unique($improving_subjects)) . "</strong> based on your latest period grades. Keep applying those effective study strategies!";
        }

        if (empty($recommendations_list)) {
            $response = "As a " . ($profile['year_level'] ?? 'student') . ", maintaining consistency is key. Your grades are looking solid, " . $_SESSION['first_name'] . "! Keep up the great work.";
        } else {
            $response = "Here are some personalized insights based on your academic performance:<br><ul><li>" . implode("</li><li>", $recommendations_list) . "</li></ul>";
        }
        unset($_SESSION['last_ai_intent']);
    }
} elseif (preg_match('/(status|how am i|overall|average|standing|grades|marks|list|record|performance|result|ranking|honor)/i', $query)) {
    if (empty($grades)) {
        $response = "I cannot provide an overall status without your grade data. Please wait for your teachers to submit your grades.";
        unset($_SESSION['last_ai_intent']);
    } else {
        $total_grades_count = count($grades);
        $overall_avg = $total_grades_count > 0 ? array_sum(array_column($grades, 'grade')) / $total_grades_count : 0;
        $point = calculateGradePoint($overall_avg);

        // If the user is specifically asking for a list of grades rather than just a status summary
        if (preg_match('/(list|all|grades|marks|record)/i', $query) && !preg_match('/(overall|average|status)/i', $query)) {
            $grade_summary = [];
            foreach ($grades as $g) {
                $grade_summary[] = "<strong>{$g['subject_name']}</strong>: " . number_format($g['grade'], 1) . " (" . calculateGradePoint($g['grade']) . ")";
            }
            $response = "Here is a detailed summary of your current academic records:<br><ul><li>" . implode("</li><li>", $grade_summary) . "</li></ul>";
            $response .= "<br>Your overall average is <strong>" . number_format($overall_avg, 1) . "</strong> ($point).";
        } else {
            // General status response formatted to 1 decimal place to match the Grade Summary dashboard
        $response = "Your current overall academic average across <strong>all your recorded subjects</strong> is <strong>" . number_format($overall_avg, 1) . "</strong>, which translates to a point of <strong>$point</strong>. ";
        
        if ($overall_avg >= 90) {
            $response .= "This is truly outstanding performance! Based on this average, you may be eligible for the <strong>Dean's List</strong> or Latin Honors. Keep pushing, " . $_SESSION['first_name'] . "!";
        } elseif ($overall_avg >= 85) {
            $response .= "You're maintaining a strong academic standing. Consistent effort will help you excel further and reach even higher achievements.";
        } elseif ($overall_avg >= 70) {
            $response .= "Your current standing is stable, but there's always an opportunity for growth. Let's explore areas where you can boost your grades and strengthen your understanding.";
        } else {
            $response .= "It seems there are some areas where we can focus on improvement. Don't worry, with targeted effort, we can work towards better results. <strong>Would you like some recommendations?</strong>";
            $_SESSION['last_ai_intent'] = 'recommendation'; // Set context for follow-up
        }
        }

        // If the user didn't have a specific intent set yet, suggest trends
        if (!isset($_SESSION['last_ai_intent']) && count($grades) > 1) {
            $response .= "<br><strong>Would you like to analyze your grade trends?</strong>";
            $_SESSION['last_ai_intent'] = 'trend';
        }
    }
} elseif (preg_match('/(passing|failing|failed|pass|fail|standing|okay|ok)/i', $query)) {
    $total = count($grades);
    $pass_count = count($passing_subjects);
    $fail_count = $total - $pass_count;
    
    if ($total === 0) {
        $response = "I cannot determine your passing status yet as no grades have been recorded.";
    } elseif ($fail_count === 0) {
        $response = "You are currently passing <strong>all $total</strong> of your recorded subjects! You are in excellent academic standing.";
        if (!empty($at_risk_subjects)) {
            $response .= " However, I noticed " . count($at_risk_subjects) . " subjects are in the borderline range. Would you like to see which ones?";
            $_SESSION['last_ai_intent'] = 'recommendation';
        }
    } else {
        $response = "You are passing <strong>$pass_count</strong> out of $total subjects. I've noted that you are currently below the 75% threshold in " . ($fail_count == 1 ? "one subject" : "$fail_count subjects") . ". Would you like recommendations on how to improve?";
        $_SESSION['last_ai_intent'] = 'recommendation';
    }
} elseif (preg_match('/(latest|recent|update|new|last)/i', $query)) {
    // Get the most recently graded subject
    if (empty($grades)) {
        $response = "There are no grade updates recorded in the system yet.";
    } else {
        usort($grades, function($a, $b) { return strtotime($b['graded_at']) - strtotime($a['graded_at']); });
        $latest = $grades[0];
        $response = "The most recent update to your records was for <strong>" . $latest['subject_name'] . "</strong>, updated on " . date('M j, Y', strtotime($latest['graded_at'])) . ". Your current grade in that subject is " . number_format($latest['grade'], 1) . ".";
    }
    unset($_SESSION['last_ai_intent']);
} elseif (preg_match('/(best|highest|top)/i', $query)) {
    $best = array_reduce($grades, function($a, $b) { return ($a['grade'] > $b['grade']) ? $a : $b; }, ['grade' => 0, 'subject_name' => 'N/A']);
    $response = "Your highest performing subject is <strong>" . $best['subject_name'] . "</strong> with a grade of " . number_format($best['grade'], 1) . ". Excellent work!";
    unset($_SESSION['last_ai_intent']);
} elseif (preg_match('/(who are you|what can you do|capabilities|syntax|help|bot|ai)/i', $query)) {
    $response = "I am <strong>Syntax AI</strong>, your personal academic advisor. I can help you by:<br>
    <ul>
        <li>Providing a <strong>diagnostic breakdown</strong> of any subject.</li>
        <li>Calculating the scores you <strong>need to pass</strong> or reach a target.</li>
        <li>Identifying <strong>upward or downward trends</strong> in your performance.</li>
        <li>Giving <strong>personalized study tips</strong> based on your weakest areas.</li>
    </ul>
    Ask me something like <em>'How am I doing in Math?'</em> or <em>'What do I need to pass?'</em> to get started.";
    unset($_SESSION['last_ai_intent']);
} elseif (preg_match('/(consult|talk|approach|teacher|instructor|message|consultation)/i', $query)) {
    $response = "When approaching your instructors, I recommend being professional and specific. You might say: <br><br><em>'Greetings [Teacher Name], I am reviewing my performance in your subject and would appreciate any guidance on topics I struggled with during the [Specific Period].'</em>";
    if ($lowest_subject !== "N/A") {
        $response .= "<br><br>Since <strong>$lowest_subject</strong> is your current focus area, I suggest starting there.";
    }
    unset($_SESSION['last_ai_intent']);
 } elseif (preg_match('/(hi|hello|hey|greetings|syntax)/i', $query)) {
    $response = "Greetings, " . $_SESSION['first_name'] . "! I am Syntax, your dedicated academic intelligence assistant. I am ready to analyze your performance. How may I assist you today?";
    unset($_SESSION['last_ai_intent']);
} elseif (preg_match('/(thank|thanks|great|good|awesome)/i', $query)) {
    $response = "You are most welcome. Academic excellence is a journey, and I am here to help you navigate it. <strong>Is there any other data you would like me to examine?</strong>";
    $_SESSION['last_ai_intent'] = 'status'; // Suggest checking status if they are done
} else {
    $response = "I apologize, " . $_SESSION['first_name'] . ", but I didn't quite catch that. I am specifically programmed to analyze your academic data. Try asking about your <strong>status</strong>, <strong>recommendations</strong>, or <strong>grade trends</strong>.";
    unset($_SESSION['last_ai_intent']);
}

header('Content-Type: application/json');
echo json_encode([
    'recommendation' => $acknowledgment . $response,
    'context' => [
        'lowest_subject' => $lowest_subject,
        'trend_status' => count($struggling_subjects) > 0 ? 'warning' : (count($improving_subjects) > 0 ? 'improving' : 'stable')
    ]
]);
exit;