<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';
require_once 'grade_functions.php';
require_login(['admin','teacher']);

$message = "";
$edit_item = null;
$tab = $_GET['tab'] ?? 'individual';

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM grade_items WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        log_audit($conn, "Delete Grade", "Deleted grade item ID $id.");
    }
    header("Location: grade_entry.php?tab=individual");
    exit();
}

if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM grade_items WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_item = $stmt->get_result()->fetch_assoc();
    $tab = "individual";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === "save_individual") {
        $item_id = intval($_POST['item_id'] ?? 0);
        $student_id = intval($_POST['student_id']);
        $subject_id = intval($_POST['subject_id']);
        $term = $_POST['academic_term'];
        $period = $_POST['grading_period'];
        $component = $_POST['component'];
        $item = trim($_POST['item_name']);
        $score = floatval($_POST['score']);
        $total = floatval($_POST['total_score']);

        if ($score > $total) {
            $message = "Score cannot be higher than total score.";
        } else {
            if ($item_id > 0) {
                $stmt = $conn->prepare("UPDATE grade_items SET student_id=?,subject_id=?,academic_term=?,grading_period=?,component=?,item_name=?,score=?,total_score=? WHERE id=?");
                $stmt->bind_param("iissssddi", $student_id, $subject_id, $term, $period, $component, $item, $score, $total, $item_id);
                if ($stmt->execute()) {
                    $message = "Grade item updated.";
                    log_audit($conn, "Edit Grade", "Updated grade item ID $item_id for student ID $student_id, subject ID $subject_id, $term - $period - $component.");
                } else {
                    $message = "Error: " . $stmt->error;
                }
            } else {
                $encoded_by = $_SESSION['user_id'] ?? null;
                $stmt = $conn->prepare("INSERT INTO grade_items (student_id,subject_id,academic_term,grading_period,component,item_name,score,total_score,encoded_by) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iissssddi", $student_id, $subject_id, $term, $period, $component, $item, $score, $total, $encoded_by);
                if ($stmt->execute()) {
                    $message = "Grade item added.";
                    log_audit($conn, "Add Grade", "Added grade for student ID $student_id, subject ID $subject_id, $term - $period - $component, item $item.");
                } else {
                    $message = "Error: " . $stmt->error;
                }
            }
        }

        $tab = "individual";
    }

    if ($action === "save_section") {
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
        log_audit($conn, "Grade Entry by Section", "Encoded section grades for subject ID $subject_id, $academic_term - $grading_period - $component, item $item_name.");
        $tab = "section";
    }
}

$filter_student = $_GET['filter_student'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_period = $_GET['filter_period'] ?? '';

$students = $conn->query("SELECT * FROM students ORDER BY course,section,name");

if ($_SESSION['role'] === 'teacher') {
    $tid = $_SESSION['user_id'];
    $stmt_subjects = $conn->prepare("SELECT * FROM subjects WHERE teacher_id=? ORDER BY subject_code");
    $stmt_subjects->bind_param("i", $tid);
    $stmt_subjects->execute();
    $subjects = $stmt_subjects->get_result();
} else {
    $subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
}

$filter_students = $conn->query("SELECT * FROM students ORDER BY course,section,name");
$filter_subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");

$query = "SELECT gi.*, st.name AS student_name, st.student_no, st.course, st.section, sub.subject_code
          FROM grade_items gi
          JOIN students st ON gi.student_id=st.id
          JOIN subjects sub ON gi.subject_id=sub.id
          WHERE 1";

$params = [];
$types = "";

if ($_SESSION['role'] === 'teacher') {
    $query .= " AND sub.teacher_id=?";
    $params[] = $_SESSION['user_id'];
    $types .= "i";
}

if ($filter_student !== '') {
    $query .= " AND gi.student_id=?";
    $params[] = intval($filter_student);
    $types .= "i";
}

if ($filter_subject !== '') {
    $query .= " AND gi.subject_id=?";
    $params[] = intval($filter_subject);
    $types .= "i";
}

if ($filter_term !== '') {
    $query .= " AND gi.academic_term=?";
    $params[] = $filter_term;
    $types .= "s";
}

if ($filter_period !== '') {
    $query .= " AND gi.grading_period=?";
    $params[] = $filter_period;
    $types .= "s";
}

$query .= " ORDER BY gi.created_at DESC";

$stmt_records = $conn->prepare($query);
if (!empty($params)) {
    $stmt_records->bind_param($types, ...$params);
}
$stmt_records->execute();
$records = $stmt_records->get_result();

$quick_component = $_GET['component'] ?? ($edit_item['component'] ?? 'Quiz');

$course = $_GET['course'] ?? '';
$section = $_GET['section'] ?? '';
$section_subject_id = $_GET['section_subject_id'] ?? '';
$section_term = $_GET['section_term'] ?? '1st Term';
$section_period = $_GET['section_period'] ?? 'Prelim';
$section_component = $_GET['section_component'] ?? 'Quiz';
$section_item_name = $_GET['section_item_name'] ?? '';
$section_total_score = $_GET['section_total_score'] ?? '100';

$courses = $conn->query("SELECT DISTINCT course FROM students ORDER BY course");
$sections = $conn->query("SELECT DISTINCT section FROM students ORDER BY section");

if ($_SESSION['role'] === 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    $section_subject_stmt = $conn->prepare("SELECT * FROM subjects WHERE teacher_id=? ORDER BY subject_code");
    $section_subject_stmt->bind_param("i", $teacher_id);
    $section_subject_stmt->execute();
    $section_subjects = $section_subject_stmt->get_result();
} else {
    $section_subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
}

$section_students = null;

if ($course !== '' && $section !== '') {
    $student_stmt = $conn->prepare("SELECT * FROM students WHERE course=? AND section=? AND (status='active' OR status IS NULL) ORDER BY name");
    $student_stmt->bind_param("ss", $course, $section);
    $student_stmt->execute();
    $section_students = $student_stmt->get_result();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Grade Entry</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
 
<?php nav_bar(); ?>

<div class="container">
    <h1>Grade Entry</h1>

    <?php if($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <div class="quick-buttons no-print">
        <a class="btn <?= $tab === 'individual' ? 'btn-yellow' : 'btn-gray' ?>" href="grade_entry.php?tab=individual">
            Individual Grade Entry
        </a>
        <a class="btn <?= $tab === 'section' ? 'btn-yellow' : 'btn-gray' ?>" href="grade_entry.php?tab=section">
            Grade Entry by Section
        </a>
    </div>

    <br>

    <?php if ($tab === 'individual'): ?>

        <h2><?= $edit_item ? 'Edit Grade Item' : 'Individual Grade Entry' ?></h2>

        <div class="quick-buttons no-print">
            <a class="btn btn-yellow" href="grade_entry.php?tab=individual&component=Quiz">Add Quiz</a>
            <a class="btn btn-yellow" href="grade_entry.php?tab=individual&component=Exam">Add Exam</a>
            <a class="btn btn-yellow" href="grade_entry.php?tab=individual&component=Project">Add Project</a>
            <a class="btn btn-yellow" href="grade_entry.php?tab=individual&component=Attendance">Add Attendance</a>
        </div>

        <br>

        <form method="POST" class="card grid grid-3">
            <input type="hidden" name="action" value="save_individual">
            <input type="hidden" name="item_id" value="<?= safe($edit_item['id'] ?? 0) ?>">

            <select name="student_id" required>
                <option value="">Select Student</option>
                <?php while($s = $students->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>" <?= ($edit_item && $edit_item['student_id'] == $s['id']) ? 'selected' : '' ?>>
                        <?= safe($s['student_no']) ?> - <?= safe($s['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="subject_id" required>
                <option value="">Select Subject</option>
                <?php while($sub = $subjects->fetch_assoc()): ?>
                    <option value="<?= $sub['id'] ?>" <?= ($edit_item && $edit_item['subject_id'] == $sub['id']) ? 'selected' : '' ?>>
                        <?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="academic_term">
                <option <?= ($edit_item && $edit_item['academic_term'] === '1st Term') ? 'selected' : '' ?>>1st Term</option>
                <option <?= ($edit_item && $edit_item['academic_term'] === '2nd Term') ? 'selected' : '' ?>>2nd Term</option>
                <option <?= ($edit_item && $edit_item['academic_term'] === '3rd Term') ? 'selected' : '' ?>>3rd Term</option>
            </select>

            <select name="grading_period">
                <?php foreach(['Prelim','Midterm','Finals'] as $p): ?>
                    <option <?= ($edit_item && $edit_item['grading_period'] === $p) ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>

            <select name="component">
                <?php foreach(['Exam','Project','Quiz','Attendance'] as $c): ?>
                    <option <?= (($edit_item && $edit_item['component'] === $c) || (!$edit_item && $quick_component === $c)) ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>

            <input name="item_name" value="<?= safe($edit_item['item_name'] ?? '') ?>" placeholder="Item Name e.g. Quiz 1" required>
            <input type="number" step="0.01" name="score" value="<?= safe($edit_item['score'] ?? '') ?>" placeholder="Score" required>
            <input type="number" step="0.01" name="total_score" value="<?= safe($edit_item['total_score'] ?? '100') ?>" placeholder="Total Score" required>

            <button class="btn btn-yellow"><?= $edit_item ? 'Update Grade Item' : 'Save Grade Item' ?></button>
        </form>

        <br>

        <h2>Filter Grade Records</h2>

        <form method="GET" class="card grid grid-4 no-print">
            <input type="hidden" name="tab" value="individual">

            <select name="filter_student">
                <option value="">All Students</option>
                <?php while($fs = $filter_students->fetch_assoc()): ?>
                    <option value="<?= $fs['id'] ?>" <?= $filter_student == $fs['id'] ? 'selected' : '' ?>>
                        <?= safe($fs['student_no']) ?> - <?= safe($fs['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="filter_subject">
                <option value="">All Subjects</option>
                <?php while($fsub = $filter_subjects->fetch_assoc()): ?>
                    <option value="<?= $fsub['id'] ?>" <?= $filter_subject == $fsub['id'] ? 'selected' : '' ?>>
                        <?= safe($fsub['subject_code']) ?> - <?= safe($fsub['subject_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="filter_term">
                <option value="">All Terms</option>
                <option value="1st Term" <?= $filter_term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                <option value="2nd Term" <?= $filter_term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                <option value="3rd Term" <?= $filter_term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
            </select>

            <select name="filter_period">
                <option value="">All Periods</option>
                <option value="Prelim" <?= $filter_period === 'Prelim' ? 'selected' : '' ?>>Prelim</option>
                <option value="Midterm" <?= $filter_period === 'Midterm' ? 'selected' : '' ?>>Midterm</option>
                <option value="Finals" <?= $filter_period === 'Finals' ? 'selected' : '' ?>>Finals</option>
            </select>

            <button class="btn btn-yellow">Apply Filter</button>
            <a href="grade_entry.php?tab=individual" class="btn btn-gray">Clear Filter</a>
        </form>

        <br>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Subject</th>
                        <th>Term</th>
                        <th>Period</th>
                        <th>Component</th>
                        <th>Item</th>
                        <th>Score</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records && $records->num_rows > 0): ?>
                        <?php while($r = $records->fetch_assoc()): ?>
                            <tr>
                                <td><?= safe($r['student_no']) ?> - <?= safe($r['student_name']) ?></td>
                                <td><?= safe($r['subject_code']) ?></td>
                                <td><?= safe($r['academic_term']) ?></td>
                                <td><?= safe($r['grading_period']) ?></td>
                                <td><?= safe($r['component']) ?></td>
                                <td><?= safe($r['item_name']) ?></td>
                                <td><?= safe($r['score']) ?>/<?= safe($r['total_score']) ?></td>
                                <td>
                                    <a class="btn btn-blue" href="grade_entry.php?tab=individual&edit=<?= $r['id'] ?>">Edit</a>
                                    <a class="btn btn-red" onclick="return confirm('Delete this grade item?')" href="grade_entry.php?tab=individual&delete=<?= $r['id'] ?>">Delete</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center;">No grade records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <?php if ($tab === 'section'): ?>

        <h2>Grade Entry by Section</h2>

        <form method="GET" class="card grid grid-4 no-print">
            <input type="hidden" name="tab" value="section">

            <select name="course" required>
                <option value="">Select Course</option>
                <?php while($c = $courses->fetch_assoc()): ?>
                    <option value="<?= safe($c['course']) ?>" <?= $course === $c['course'] ? 'selected' : '' ?>>
                        <?= safe($c['course']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="section" required>
                <option value="">Select Section</option>
                <?php while($s = $sections->fetch_assoc()): ?>
                    <option value="<?= safe($s['section']) ?>" <?= $section === $s['section'] ? 'selected' : '' ?>>
                        <?= safe($s['section']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="section_subject_id" required>
                <option value="">Select Subject</option>
                <?php while($sub = $section_subjects->fetch_assoc()): ?>
                    <option value="<?= $sub['id'] ?>" <?= $section_subject_id == $sub['id'] ? 'selected' : '' ?>>
                        <?= safe($sub['subject_code']) ?> - <?= safe($sub['subject_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <select name="section_term">
                <option value="1st Term" <?= $section_term === '1st Term' ? 'selected' : '' ?>>1st Term</option>
                <option value="2nd Term" <?= $section_term === '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                <option value="3rd Term" <?= $section_term === '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
            </select>

            <select name="section_period">
                <option value="Prelim" <?= $section_period === 'Prelim' ? 'selected' : '' ?>>Prelim</option>
                <option value="Midterm" <?= $section_period === 'Midterm' ? 'selected' : '' ?>>Midterm</option>
                <option value="Finals" <?= $section_period === 'Finals' ? 'selected' : '' ?>>Finals</option>
            </select>

            <select name="section_component">
                <option value="Quiz" <?= $section_component === 'Quiz' ? 'selected' : '' ?>>Quiz</option>
                <option value="Exam" <?= $section_component === 'Exam' ? 'selected' : '' ?>>Exam</option>
                <option value="Project" <?= $section_component === 'Project' ? 'selected' : '' ?>>Project</option>
                <option value="Attendance" <?= $section_component === 'Attendance' ? 'selected' : '' ?>>Attendance</option>
            </select>

            <input name="section_item_name" value="<?= safe($section_item_name) ?>" placeholder="Item Name e.g. Quiz 1" required>
            <input type="number" step="0.01" name="section_total_score" value="<?= safe($section_total_score) ?>" placeholder="Total Score" required>

            <button class="btn btn-yellow">Load Section</button>
        </form>

        <br>

        <?php if ($section_students && $section_subject_id !== '' && $section_item_name !== ''): ?>
            <form method="POST" class="card">
                <input type="hidden" name="action" value="save_section">
                <input type="hidden" name="subject_id" value="<?= safe($section_subject_id) ?>">
                <input type="hidden" name="academic_term" value="<?= safe($section_term) ?>">
                <input type="hidden" name="grading_period" value="<?= safe($section_period) ?>">
                <input type="hidden" name="component" value="<?= safe($section_component) ?>">
                <input type="hidden" name="item_name" value="<?= safe($section_item_name) ?>">
                <input type="hidden" name="total_score" value="<?= safe($section_total_score) ?>">

                <h2>
                    <?= safe($course) ?> <?= safe($section) ?>
                    -
                    <?= safe($section_component) ?>:
                    <?= safe($section_item_name) ?>
                </h2>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student No.</th>
                                <th>Student Name</th>
                                <th>Score / <?= safe($section_total_score) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($section_students->num_rows > 0): ?>
                                <?php while($st = $section_students->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= safe($st['student_no']) ?></td>
                                        <td><?= safe($st['name']) ?></td>
                                        <td>
                                            <input type="number" step="0.01" name="scores[<?= $st['id'] ?>]" placeholder="Enter score">
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align:center;">No students found in this section.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <br>
                <button class="btn btn-yellow">Save Section Grades</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>

</div>
</body>
</html>
