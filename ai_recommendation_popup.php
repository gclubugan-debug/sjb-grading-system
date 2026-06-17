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

header("Content-Type: application/json");

if (!$student || !$subject) {
    echo json_encode([
        "success" => false,
        "html" => "<p>Invalid student or subject.</p>"
    ]);
    exit();
}

$tr = calculate_term_grade($conn, $student_id, $subject_id, $term);
$target_to_pass = calculate_target_to_pass($tr['periods']);

$grade_data = [
    "prelim" => $tr['periods']['Prelim']['percentage'] ?? 0,
    "midterm" => $tr['periods']['Midterm']['percentage'] ?? 0,
    "finals" => $tr['periods']['Finals']['percentage'] ?? 0,
    "term_grade" => $tr['percentage'] ?? 0,
    "equivalent" => $tr['equivalent'] ?? "No Grade",
    "remarks" => $tr['remarks'] ?? "No Data",
    "target_to_pass" => $target_to_pass
];

$ai = generate_ai_feedback_api($student['name'], $subject['subject_name'], $term, $grade_data, "long");
$risk = in_array($ai['status'], ['At Risk', 'Failed', 'High Risk']);
$status = status_badge($ai['status'], $risk);

$html = '
<div>
    <h2 style="margin-top:0;">Detailed AI Recommendation</h2>

    <div class="card" style="margin-bottom:12px;">
        <p><b>Student:</b> ' . safe($student['student_no']) . ' - ' . safe($student['name']) . '</p>
        <p><b>Subject:</b> ' . safe($subject['subject_code']) . ' - ' . safe($subject['subject_name']) . '</p>
        <p><b>Term:</b> ' . safe($term) . '</p>
        <p><b>Term Grade:</b> ' . ($tr['has_data'] ? safe($tr['percentage']) . '% / ' . safe($tr['equivalent']) : 'No Data') . '</p>
        <p><b>AI Status:</b> ' . $status . '</p>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <h3 style="color:#facc15;">Target Grade to Pass</h3>
        <p>' . safe($target_to_pass['message']) . '</p>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <h3 style="color:#facc15;">AI Feedback</h3>
        <p>' . nl2br(safe($ai['feedback'])) . '</p>
    </div>

    <div class="card">
        <h3 style="color:#facc15;">AI Suggestion / Recommendation</h3>
        <p>' . nl2br(safe($ai['suggestion'])) . '</p>
    </div>
</div>';

echo json_encode([
    "success" => true,
    "html" => $html
]);
?>