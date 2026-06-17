<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_once 'ai_api.php';
require_login(['admin','teacher']);

$student_id = intval($_GET['student_id'] ?? 0);
$subject_id = intval($_GET['subject_id'] ?? 0);
$term = $_GET['academic_term'] ?? '1st Term';

$student_stmt = $conn->prepare("SELECT * FROM students WHERE id=?");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();

$subject_stmt = $conn->prepare("SELECT * FROM subjects WHERE id=?");
$subject_stmt->bind_param("i", $subject_id);
$subject_stmt->execute();
$subject = $subject_stmt->get_result()->fetch_assoc();

if (!$student || !$subject) {
    die("Invalid student or subject.");
}

$tr = calculate_term_grade($conn, $student_id, $subject_id, $term);

$grade_data = [
    "prelim" => $tr['periods']['Prelim']['percentage'] ?? 0,
    "midterm" => $tr['periods']['Midterm']['percentage'] ?? 0,
    "finals" => $tr['periods']['Finals']['percentage'] ?? 0,
    "term_grade" => $tr['percentage'] ?? 0,
    "equivalent" => $tr['equivalent'] ?? "No Grade",
    "remarks" => $tr['remarks'] ?? "No Data"
];

$ai = generate_ai_feedback_api($student['name'], $subject['subject_name'], $term, $grade_data, "long");
$risk = in_array($ai['status'], ['At Risk', 'Failed', 'High Risk']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Detailed AI Recommendation</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>
<div class="container">
    <h1>Detailed AI Recommendation</h1>

    <div class="card">
        <h2><?= safe($student['student_no']) ?> - <?= safe($student['name']) ?></h2>
        <p><b>Subject:</b> <?= safe($subject['subject_code']) ?> - <?= safe($subject['subject_name']) ?></p>
        <p><b>Term:</b> <?= safe($term) ?></p>
        <p><b>Term Grade:</b> <?= $tr['has_data'] ? safe($tr['percentage']).'% / '.safe($tr['equivalent']) : 'No Data' ?></p>
        <p><b>AI Status:</b> <?= status_badge($ai['status'], $risk) ?></p>
    </div>

    <br>

    <div class="card">
        <h2 style="color:#facc15;">AI Feedback</h2>
        <p><?= nl2br(safe($ai['feedback'])) ?></p>
    </div>

    <br>

    <div class="card">
        <h2 style="color:#facc15;">AI Suggestion / Recommendation</h2>
        <p><?= nl2br(safe($ai['suggestion'])) ?></p>
    </div>

    <br>
    <a class="btn btn-gray" href="reports.php?academic_term=<?= urlencode($term) ?>&subject_id=<?= $subject_id ?>">Back to AI Reports</a>
</div>
</body>
</html>