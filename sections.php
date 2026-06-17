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

    if ($action === "add_section") {
        $section_name = strtoupper(trim($_POST['section_name'] ?? ''));

        if ($section_name === '') {
            $error = "Section name is required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO sections (section_name) VALUES (?)");
            $stmt->bind_param("s", $section_name);

            if ($stmt->execute()) {
                $message = "Section added successfully.";

                if (function_exists('log_audit')) {
                    log_audit($conn, "Add Section", "Added section $section_name.");
                }
            } else {
                $error = "Section already exists or could not be added.";
            }
        }
    }

    if ($action === "delete_section") {
        $section_id = intval($_POST['section_id'] ?? 0);

        $section_stmt = $conn->prepare("SELECT section_name FROM sections WHERE id=?");
        $section_stmt->bind_param("i", $section_id);
        $section_stmt->execute();
        $section_data = $section_stmt->get_result()->fetch_assoc();

        if ($section_data) {
            $section_name = $section_data['section_name'];

            $check_students = $conn->prepare("SELECT COUNT(*) AS total FROM students WHERE section=?");
            $check_students->bind_param("s", $section_name);
            $check_students->execute();
            $student_count = $check_students->get_result()->fetch_assoc()['total'] ?? 0;

            $check_pending = $conn->prepare("SELECT COUNT(*) AS total FROM pending_requests WHERE section=? AND status='pending'");
            $check_pending->bind_param("s", $section_name);
            $check_pending->execute();
            $pending_count = $check_pending->get_result()->fetch_assoc()['total'] ?? 0;

            if ($student_count > 0 || $pending_count > 0) {
                $error = "Cannot delete this section because it is already used by students or pending requests.";
            } else {
                $stmt = $conn->prepare("DELETE FROM sections WHERE id=?");
                $stmt->bind_param("i", $section_id);

                if ($stmt->execute()) {
                    $message = "Section deleted successfully.";

                    if (function_exists('log_audit')) {
                        log_audit($conn, "Delete Section", "Deleted section $section_name.");
                    }
                } else {
                    $error = "Error: " . $stmt->error;
                }
            }
        } else {
            $error = "Invalid section selected.";
        }
    }
}

$sections = $conn->query("SELECT * FROM sections ORDER BY section_name");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sections</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php nav_bar(); ?>

<div class="container">
    <h1>Manage Sections</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= safe($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="card grid grid-3">
        <input type="hidden" name="action" value="add_section">
        <input name="section_name" placeholder="Section Name e.g. BSIT-3A" required>
        <button class="btn btn-yellow">Add Section</button>
    </form>

    <br>

    <div class="card">
        <h2 style="color:#facc15;">Available Sections</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sections && $sections->num_rows > 0): ?>
                        <?php while($sec = $sections->fetch_assoc()): ?>
                            <tr>
                                <td data-label="Section"><?= safe($sec['section_name']) ?></td>
                                <td data-label="Created At"><?= safe($sec['created_at']) ?></td>
                                <td data-label="Action">
                                    <form method="POST" onsubmit="return confirm('Delete this section?');">
                                        <input type="hidden" name="action" value="delete_section">
                                        <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                        <button class="btn btn-red">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align:center;">No sections added yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
