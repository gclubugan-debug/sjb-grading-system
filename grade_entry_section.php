<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin','teacher']);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_section_grades'])) {
    $subject_id = intval($_POST['subject_id']);
    $academic_term = $_POST['academic_term'];
    $grading_period = $_POST['grading_period'];
    $component = $_POST['component'];
    $item_name = trim($_POST['item_name']);
    $total_score = floatval($_POST['total_score']);
    $scores = $_POST['scores'] ?? [];
    $encoded_by = $_SESSION['user_id'] ?? null;

    foreach ($scores as $student_id => $score_value) {
        if ($score_value === "") {
            continue;
        }

        $student_id = intval($student_id);
        $score = floatval($score_value);

        if ($score > $total_score) {
            continue;
        }

        $check = $conn->prepare("SELECT id FROM grade_items WHERE student_id=? AND subject_id=? AND academic_term=? AND grading_period=? AND component=? AND item_name=? LIMIT 1");
        $check->bind_param("iissss", $student_id, $subject_id, $academic_term, $grading_period, $component, $item_name);
        $check->execute();
        $existing = $check->get_result();

        if ($existing && $existing->num_rows > 0) {
            $row = $existing->fetch_assoc();
            $update = $conn->prepare("UPDATE grade_items SET score=?, total_score=?, encoded_by=? WHERE id=?");
            $update->bind_param("ddii", $score, $total_score, $encoded_by, $row['id']);
            $update->execute();
        } else {
            $insert = $conn->prepare("INSERT INTO grade_items (student_id, subject_id, academic_term, grading_period, component, item_name, score, total_score, encoded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->bind_param("iissssddi", $student_id, $subject_id, $academic_term, $grading_period, $component, $item_name, $score, $total_score, $encoded_by);
            $insert->execute();
        }
    }

    $message = "Section grades saved successfully.";
}

$course = $_GET['course'] ?? '';
$section = $_GET['section'] ?? '';
$subject_id = $_GET['subject_id'] ?? '';
$academic_term = $_GET['academic_term'] ?? '1st Term';
$grading_period = $_GET['grading_period'] ?? 'Prelim';
$component = $_GET['component'] ?? 'Quiz';
$item_name = $_GET['item_name'] ?? '';
$total_score = $_GET['total_score'] ?? '100';

$courses = $conn->query("SELECT DISTINCT course FROM students ORDER BY course");
$sections = $conn->query("SELECT DISTINCT section FROM students ORDER BY section");

if ($_SESSION['role'] === 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    $subject_stmt = $conn->prepare("SELECT * FROM subjects WHERE teacher_id=? ORDER BY subject_code");
    $subject_stmt->bind_param("i", $teacher_id);
    $subject_stmt->execute();
    $subjects = $subject_stmt->get_result();
} else {
    $subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
}

$students = null;

if ($course !== '' && $section !== '') {
    $student_stmt = $conn->prepare("SELECT * FROM students WHERE course=? AND section=? AND (status='active' OR status IS NULL) ORDER BY name");
    $student_stmt->bind_param("ss", $course, $section);
    $student_stmt->execute();
    $students = $student_stmt->get_result();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Grade Entry by Section</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>
<div class="container">
    <h1>Grade Entry by Section</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <form method="GET" class="card grid grid-4 no-print">
        <select name="course" required>
            <option value="">Select Course</option>
            <?php while($c = $courses->fetch_assoc()): ?>
                <option value="<?= safe($c['course']) ?>" <?= $course === $c['course'] ? 'selected' : '' ?>><?= safe($c['course']) ?></option>
            <?php endwhile; ?>
        </select>

        <select name="section" required>
            <option value="">Select Section</option>
            <?php while($s = $sections->fetch_assoc()): ?>
                <option value="<?= safe($s['section']) ?>" <?= $section === $s['section'] ? 'selected' : '' ?>><?= safe($s['section']) ?></option>
            <?php endwhile; ?>
        </select>

        <select name="subject_id" required>
            <option value="">Select Subject</option>
            <?php while($sub = $subjects->fetch_assoc()): ?>
                <option value="<?= $sub['id'] ?>" <?= $subject_id == $sub['id'] ? 'selected' : '' ?>><?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?></option>
            <?php endwhile; ?>
        </select>

        <select name="academic_term">
            <option value="1st Term" <?= $academic_term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
            <option value="2nd Term" <?= $academic_term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
            <option value="3rd Term" <?= $academic_term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
        </select>

        <select name="grading_period">
            <option value="Prelim" <?= $grading_period === 'Prelim' ? 'selected' : '' ?>>Prelim</option>
            <option value="Midterm" <?= $grading_period === 'Midterm' ? 'selected' : '' ?>>Midterm</option>
            <option value="Finals" <?= $grading_period === 'Finals' ? 'selected' : '' ?>>Finals</option>
        </select>

        <select name="component">
            <option value="Quiz" <?= $component === 'Quiz' ? 'selected' : '' ?>>Quiz</option>
            <option value="Exam" <?= $component === 'Exam' ? 'selected' : '' ?>>Exam</option>
            <option value="Project" <?= $component === 'Project' ? 'selected' : '' ?>>Project</option>
            <option value="Attendance" <?= $component === 'Attendance' ? 'selected' : '' ?>>Attendance</option>
        </select>

        <input name="item_name" value="<?= safe($item_name) ?>" placeholder="Item Name e.g. Quiz 1" required>
        <input type="number" step="0.01" name="total_score" value="<?= safe($total_score) ?>" placeholder="Total Score" required>

        <button class="btn btn-yellow">Load Section</button>
    </form>

    <br>

    <?php if ($students && $subject_id !== '' && $item_name !== ''): ?>
        <form method="POST" class="card">
            <input type="hidden" name="save_section_grades" value="1">
            <input type="hidden" name="subject_id" value="<?= safe($subject_id) ?>">
            <input type="hidden" name="academic_term" value="<?= safe($academic_term) ?>">
            <input type="hidden" name="grading_period" value="<?= safe($grading_period) ?>">
            <input type="hidden" name="component" value="<?= safe($component) ?>">
            <input type="hidden" name="item_name" value="<?= safe($item_name) ?>">
            <input type="hidden" name="total_score" value="<?= safe($total_score) ?>">

            <h2><?= safe($course) ?> <?= safe($section) ?> - <?= safe($component) ?>: <?= safe($item_name) ?></h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student No.</th>
                            <th>Student Name</th>
                            <th>Score / <?= safe($total_score) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($students->num_rows > 0): ?>
                            <?php while($st = $students->fetch_assoc()): ?>
                                <tr>
                                    <td><?= safe($st['student_no']) ?></td>
                                    <td><?= safe($st['name']) ?></td>
                                    <td><input type="number" step="0.01" name="scores[<?= $st['id'] ?>]" placeholder="Enter score"></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No students found in this section.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <br>
            <button class="btn btn-yellow">Save Section Grades</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
