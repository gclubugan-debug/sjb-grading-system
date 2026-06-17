<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin']);

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_subject') {
        $code = strtoupper(trim($_POST['subject_code']));
        $name = trim($_POST['subject_name']);
        $units = floatval($_POST['units']);
        $teacher_id = intval($_POST['teacher_id']);

        $check = $conn->prepare("SELECT id FROM subjects WHERE LOWER(subject_code) = LOWER(?) LIMIT 1");
        $check->bind_param("s", $code);
        $check->execute();
        $existing = $check->get_result();

        if ($existing && $existing->num_rows > 0) {
            $error = "Subject already exists. Duplicate subject code is not allowed.";
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, units, teacher_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssdi", $code, $name, $units, $teacher_id);
            if ($stmt->execute()) {
                $message = "Subject added successfully.";
                log_audit($conn, "Add Subject", "Added subject $code - $name with $units units and assigned teacher ID $teacher_id.");
            } else {
                $message = "Error: " . $stmt->error;
            }
        }
    }

    if ($action === 'update_teacher') {
        $subject_id = intval($_POST['subject_id']);
        $teacher_id = intval($_POST['teacher_id']);

        $stmt = $conn->prepare("UPDATE subjects SET teacher_id=? WHERE id=?");
        $stmt->bind_param("ii", $teacher_id, $subject_id);
        if ($stmt->execute()) {
            $message = "Subject teacher updated successfully.";
            log_audit($conn, "Update Subject Teacher", "Updated subject ID $subject_id assigned teacher to teacher ID $teacher_id.");
        } else {
            $message = "Error: " . $stmt->error;
        }
    }

    if ($action === 'delete_subject') {
        $subject_id = intval($_POST['subject_id']);

        $stmt = $conn->prepare("DELETE FROM subjects WHERE id=?");
        $stmt->bind_param("i", $subject_id);
        if ($stmt->execute()) {
            $message = "Subject deleted successfully.";
            log_audit($conn, "Delete Subject", "Deleted subject ID $subject_id.");
        } else {
            $message = "Error: " . $stmt->error;
        }
    }
}

$subjects = $conn->query("SELECT subjects.*, users.name AS teacher_name FROM subjects LEFT JOIN users ON subjects.teacher_id=users.id ORDER BY subject_code");
$teachers_for_add = $conn->query("SELECT * FROM users WHERE role='teacher' ORDER BY name");
$teachers_for_table = $conn->query("SELECT * FROM users WHERE role='teacher' ORDER BY name");

$teacher_options = [];
while ($teacher = $teachers_for_table->fetch_assoc()) {
    $teacher_options[] = $teacher;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Subjects</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
        
<?php nav_bar(); ?>

<div class="container">
    <h1>Subjects</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="card grid grid-4">
        <input type="hidden" name="action" value="add_subject">

        <input name="subject_code" placeholder="Subject Code" required>
        <input name="subject_name" placeholder="Subject Name" required>
        <input name="units" type="number" step="0.5" value="3" required>

        <select name="teacher_id" required>
            <option value="">Assign Teacher</option>
            <?php while($t = $teachers_for_add->fetch_assoc()): ?>
                <option value="<?= $t['id'] ?>"><?= safe($t['name']) ?></option>
            <?php endwhile; ?>
        </select>

        <button class="btn btn-yellow">Add Subject</button>
    </form>

    <br>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Subject</th>
                    <th>Units</th>
                    <th>Assigned Teacher</th>
                    <th>Change Teacher</th>
                    <th>Admin Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($sub = $subjects->fetch_assoc()): ?>
                    <tr>
                        <td><?= safe($sub['subject_code']) ?></td>
                        <td><?= safe($sub['subject_name']) ?></td>
                        <td><?= safe($sub['units']) ?></td>
                        <td><?= safe($sub['teacher_name'] ?? 'Not assigned') ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_teacher">
                                <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">

                                <select name="teacher_id" onchange="this.form.submit()">
                                    <option value="">Select Teacher</option>
                                    <?php foreach($teacher_options as $teacher): ?>
                                        <option value="<?= $teacher['id'] ?>" <?= intval($sub['teacher_id']) === intval($teacher['id']) ? 'selected' : '' ?>>
                                            <?= safe($teacher['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this subject?');">
                                <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                                <input type="hidden" name="action" value="delete_subject">
                                <button class="btn btn-red">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
