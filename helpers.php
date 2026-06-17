<?php
function require_login($roles = []) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['role'])) {
        header("Location: index.php");
        exit();
    }
    if (!empty($roles) && !in_array($_SESSION['role'], $roles)) {
        header("Location: index.php");
        exit();
    }
}

function safe($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function nav_bar() {
    $role = $_SESSION['role'] ?? '';
    echo '<div class="nav no-print">';
    echo '<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;flex:1;">';

    if ($role === 'admin') {
        echo '<a href="admin.php">Dashboard</a>';
        echo '<a href="students.php">Students</a>';
        echo '<a href="teachers.php">Users</a>';
        echo '<a href="subjects.php">Subjects</a>';
        echo '<a href="enrollment.php">Student Subjects</a>';
        echo '<a href="reports.php">AI Reports</a>';
    } elseif ($role === 'teacher') {
        echo '<a href="teacher.php">Teacher Dashboard</a>';
        echo '<a href="students.php">Students</a>';
        echo '<a href="grade_entry.php">Grade Entry</a>';
        echo '<a href="reports.php">AI Reports</a>';
    } elseif ($role === 'student') {
        echo '<a href="user.php">Dashboard</a>';
        echo '<a href="student_grade_report.php">Grade Report</a>';
    }

    echo '<a href="account.php">Account</a>';
    echo '<a href="logout.php">Logout</a>';
    echo '</div>';

    echo '<img src="sjb_logo.jpg" alt="SJB Logo" style="width:46px;height:46px;border-radius:50%;object-fit:cover;border:3px solid #facc15;background:#000;flex-shrink:0;">';
    echo '</div>';
}

function status_badge($status, $risk = false) {
    if ($risk || in_array($status, ['At Risk','Failed'])) return '<span class="badge badge-red">'.safe($status).'</span>';
    if (in_array($status, ['Needs Monitoring','Declining'])) return '<span class="badge badge-yellow">'.safe($status).'</span>';
    if (in_array($status, ['Passed','Improving','Consistent','Good Standing'])) return '<span class="badge badge-green">'.safe($status).'</span>';
    return '<span class="badge badge-gray">'.safe($status).'</span>';
}
?>