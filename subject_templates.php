<?php
session_start();
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin']);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === "create_template") {
        $template_name = trim($_POST['template_name']);
        $academic_term = $_POST['academic_term'];
        $school_year = trim($_POST['school_year']);
        $stmt = $conn->prepare("INSERT INTO subject_templates (template_name, academic_term, school_year) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $template_name, $academic_term, $school_year);
        $message = $stmt->execute() ? "Template created successfully." : "Error: " . $stmt->error;
    }

    if ($action === "add_subject_to_template") {
        $template_id = intval($_POST['template_id']);
        $subject_id = intval($_POST['subject_id']);
        $stmt = $conn->prepare("INSERT IGNORE INTO subject_template_items (template_id, subject_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $template_id, $subject_id);
        $message = $stmt->execute() ? "Subject added to template." : "Error: " . $stmt->error;
    }

    if ($action === "assign_template_to_student") {
        $template_id = intval($_POST['template_id']);
        $student_id = intval($_POST['student_id']);
        $template = $conn->prepare("SELECT academic_term, school_year FROM subject_templates WHERE id=?");
        $template->bind_param("i", $template_id);
        $template->execute();
        $template_data = $template->get_result()->fetch_assoc();

        if ($template_data) {
            $items = $conn->prepare("SELECT subject_id FROM subject_template_items WHERE template_id=?");
            $items->bind_param("i", $template_id);
            $items->execute();
            $result = $items->get_result();

            while ($row = $result->fetch_assoc()) {
                $insert = $conn->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id, academic_term, school_year) VALUES (?, ?, ?, ?)");
                $insert->bind_param("iiss", $student_id, $row['subject_id'], $template_data['academic_term'], $template_data['school_year']);
                $insert->execute();
            }

            $message = "Template assigned to student successfully.";
        }
    }

    if ($action === "assign_template_to_section") {
        $template_id = intval($_POST['template_id']);
        $course = trim($_POST['course']);
        $section = trim($_POST['section']);
        $template = $conn->prepare("SELECT academic_term, school_year FROM subject_templates WHERE id=?");
        $template->bind_param("i", $template_id);
        $template->execute();
        $template_data = $template->get_result()->fetch_assoc();

        if ($template_data) {
            $students = $conn->prepare("SELECT id FROM students WHERE course=? AND section=? AND (status='active' OR status IS NULL)");
            $students->bind_param("ss", $course, $section);
            $students->execute();
            $student_result = $students->get_result();

            while ($student = $student_result->fetch_assoc()) {
                $items = $conn->prepare("SELECT subject_id FROM subject_template_items WHERE template_id=?");
                $items->bind_param("i", $template_id);
                $items->execute();
                $item_result = $items->get_result();

                while ($row = $item_result->fetch_assoc()) {
                    $insert = $conn->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id, academic_term, school_year) VALUES (?, ?, ?, ?)");
                    $insert->bind_param("iiss", $student['id'], $row['subject_id'], $template_data['academic_term'], $template_data['school_year']);
                    $insert->execute();
                }
            }

            $message = "Template assigned to section successfully.";
        }
    }
}

$templates = $conn->query("SELECT * FROM subject_templates ORDER BY template_name");
$templates2 = $conn->query("SELECT * FROM subject_templates ORDER BY template_name");
$templates3 = $conn->query("SELECT * FROM subject_templates ORDER BY template_name");
$subjects = $conn->query("SELECT * FROM subjects ORDER BY subject_code");
$students = $conn->query("SELECT * FROM students ORDER BY course, section, name");
$courses = $conn->query("SELECT DISTINCT course FROM students ORDER BY course");
$sections = $conn->query("SELECT DISTINCT section FROM students ORDER BY section");
$template_list = $conn->query("SELECT st.template_name, st.academic_term, st.school_year, sub.subject_code, sub.subject_name FROM subject_template_items sti JOIN subject_templates st ON sti.template_id = st.id JOIN subjects sub ON sti.subject_id = sub.id ORDER BY st.template_name, sub.subject_code");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Subject Templates</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>
<div class="container">
    <h1>Subject Templates</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <div class="grid grid-2">
        <form method="POST" class="card grid">
            <h2>Create Template</h2>
            <input type="hidden" name="action" value="create_template">
            <input name="template_name" placeholder="Template Name e.g. BSIT 3A First Term" required>
            <select name="academic_term" required>
                <option value="1st Term">1st Term</option>
                <option value="2nd Term">2nd Term</option>
                <option value="3rd Term">3rd Term</option>
            </select>
            <input name="school_year" value="2025-2026" required>
            <button class="btn btn-yellow">Create Template</button>
        </form>

        <form method="POST" class="card grid">
            <h2>Add Subject to Template</h2>
            <input type="hidden" name="action" value="add_subject_to_template">
            <select name="template_id" required>
                <option value="">Select Template</option>
                <?php while($t = $templates->fetch_assoc()): ?>
                    <option value="<?= $t['id'] ?>"><?= safe($t['template_name']) ?> - <?= safe($t['academic_term']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="subject_id" required>
                <option value="">Select Subject</option>
                <?php while($s = $subjects->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>"><?= safe($s['subject_code']) ?> - <?= safe($s['subject_name']) ?></option>
                <?php endwhile; ?>
            </select>
            <button class="btn btn-yellow">Add Subject</button>
        </form>
    </div>

    <br>

    <div class="grid grid-2">
        <form method="POST" class="card grid">
            <h2>Assign Template to Student</h2>
            <input type="hidden" name="action" value="assign_template_to_student">
            <select name="template_id" required>
                <option value="">Select Template</option>
                <?php while($t = $templates2->fetch_assoc()): ?>
                    <option value="<?= $t['id'] ?>"><?= safe($t['template_name']) ?> - <?= safe($t['academic_term']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="student_id" required>
                <option value="">Select Student</option>
                <?php while($st = $students->fetch_assoc()): ?>
                    <option value="<?= $st['id'] ?>"><?= safe($st['student_no']) ?> - <?= safe($st['name']) ?> (<?= safe($st['course']) ?> <?= safe($st['section']) ?>)</option>
                <?php endwhile; ?>
            </select>
            <button class="btn btn-yellow">Assign to Student</button>
        </form>

        <form method="POST" class="card grid">
            <h2>Assign Template to Section</h2>
            <input type="hidden" name="action" value="assign_template_to_section">
            <select name="template_id" required>
                <option value="">Select Template</option>
                <?php while($t = $templates3->fetch_assoc()): ?>
                    <option value="<?= $t['id'] ?>"><?= safe($t['template_name']) ?> - <?= safe($t['academic_term']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="course" required>
                <option value="">Select Course</option>
                <?php while($c = $courses->fetch_assoc()): ?>
                    <option value="<?= safe($c['course']) ?>"><?= safe($c['course']) ?></option>
                <?php endwhile; ?>
            </select>
            <select name="section" required>
                <option value="">Select Section</option>
                <?php while($sec = $sections->fetch_assoc()): ?>
                    <option value="<?= safe($sec['section']) ?>"><?= safe($sec['section']) ?></option>
                <?php endwhile; ?>
            </select>
            <button class="btn btn-yellow">Assign to Section</button>
        </form>
    </div>

    <br>

    <h2>Template Contents</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Template</th>
                    <th>Term</th>
                    <th>School Year</th>
                    <th>Subject</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($template_list && $template_list->num_rows > 0): ?>
                    <?php while($row = $template_list->fetch_assoc()): ?>
                        <tr>
                            <td><?= safe($row['template_name']) ?></td>
                            <td><?= safe($row['academic_term']) ?></td>
                            <td><?= safe($row['school_year']) ?></td>
                            <td><?= safe($row['subject_code']) ?> - <?= safe($row['subject_name']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center;">No template subjects yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
