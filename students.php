<?php
session_start();
require_once 'audit_helper.php';
require_once 'db.php';
require_once 'helpers.php';
require_login(['admin','teacher']);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_SESSION['role'] === 'admin') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $status = $_POST['status'] ?? 'active';

        if (in_array($status, ['active', 'suspended'])) {
            $stmt = $conn->prepare("UPDATE students SET status=? WHERE id=?");
            $stmt->bind_param("si", $status, $student_id);

            if ($stmt->execute()) {
                $message = "Student account status updated.";
                log_audit($conn, "Update Student Status", "Updated student ID $student_id status to $status.");
            } else {
                $message = "Error: " . $stmt->error;
            }
        }
    }

    if ($action === 'delete_student') {
        $stmt = $conn->prepare("DELETE FROM students WHERE id=?");
        $stmt->bind_param("i", $student_id);

        if ($stmt->execute()) {
            $message = "Student account deleted.";
            log_audit($conn, "Delete Student", "Deleted student account ID $student_id.");
        } else {
            $message = "Error: " . $stmt->error;
        }
    }
}

$course = $_GET['course'] ?? '';
$year_level = $_GET['year_level'] ?? '';
$section = $_GET['section'] ?? '';
$search = $_GET['search'] ?? '';

$query = "SELECT * FROM students WHERE 1";
$params = [];
$types = "";

if ($course !== '') {
    $query .= " AND course=?";
    $params[] = $course;
    $types .= "s";
}

if ($year_level !== '') {
    $query .= " AND year_level=?";
    $params[] = $year_level;
    $types .= "s";
}

if ($section !== '') {
    $query .= " AND section=?";
    $params[] = $section;
    $types .= "s";
}

if ($search !== '') {
    $query .= " AND (name LIKE ? OR student_no LIKE ? OR email LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$query .= " ORDER BY course, year_level, section, name";

$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$students = $stmt->get_result();

$courses = $conn->query("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");
$year_levels = $conn->query("SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL AND year_level != '' ORDER BY year_level");
$sections = $conn->query("SELECT section_name FROM sections ORDER BY section_name");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Students</title>
    <link rel="stylesheet" href="style.css">

    <style>
        @media print {
            .no-print,
            .nav,
            form,
            button,
            select,
            input,
            .btn {
                display: none !important;
            }

            body {
                background: white !important;
                color: black !important;
                font-family: Arial, sans-serif;
            }

            .container {
                padding: 0 !important;
            }

            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                color: black !important;
            }

            th,
            td {
                border: 1px solid black;
                padding: 8px;
                font-size: 12px;
                color: black !important;
            }

            th {
                background: #ddd !important;
            }

            .table-wrap {
                overflow: visible !important;
            }

            h1 {
                text-align: center;
                color: black !important;
            }
        }
    </style>
</head>
<body>
       
<?php nav_bar(); ?>

<div class="container">
    <h1>Students by Course, Year, and Section</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= safe($message) ?></div>
    <?php endif; ?>

    <form method="GET" class="card grid grid-4 no-print">
        <input name="search" value="<?= safe($search) ?>" placeholder="Search name, student no., email">

        <select name="course">
            <option value="">All Courses</option>
            <?php while($c = $courses->fetch_assoc()): ?>
                <option value="<?= safe($c['course']) ?>" <?= $course === $c['course'] ? 'selected' : '' ?>>
                    <?= safe($c['course']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <select name="year_level">
            <option value="">All Year Levels</option>
            <?php while($y = $year_levels->fetch_assoc()): ?>
                <option value="<?= safe($y['year_level']) ?>" <?= $year_level === $y['year_level'] ? 'selected' : '' ?>>
                    <?= safe($y['year_level']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <select name="section">
            <option value="">All Sections</option>
            <?php while($s = $sections->fetch_assoc()): ?>
                <option value="<?= safe($s['section_name']) ?>" <?= $section === $s['section_name'] ? 'selected' : '' ?>>
                    <?= safe($s['section_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <button class="btn btn-yellow">Filter</button>
    </form>

    <br>

    <div class="no-print">
        <button type="button" onclick="window.print()" class="btn btn-yellow">
            Print Student List
        </button>
    </div>

    <div class="print-header" style="display:none;">
        <h2>Student List Report</h2>

        <p><strong>Course:</strong> <?= $course !== '' ? safe($course) : 'All Courses' ?></p>
        <p><strong>Year Level:</strong> <?= $year_level !== '' ? safe($year_level) : 'All Year Levels' ?></p>
        <p><strong>Section:</strong> <?= $section !== '' ? safe($section) : 'All Sections' ?></p>
        <p><strong>Date Printed:</strong> <?= date('F d, Y') ?></p>
    </div>

    <br>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Course</th>
                    <th>Year Level</th>
                    <th>Section</th>
                    <th>Mobile</th>
                    <th>Status</th>
                    <th class="no-print">Profile</th>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <th class="no-print">Admin Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php if ($students && $students->num_rows > 0): ?>
                    <?php while($st = $students->fetch_assoc()): ?>
                        <tr>
                            <td><?= safe($st['student_no']) ?></td>
                            <td><?= safe($st['name']) ?></td>
                            <td><?= safe($st['course']) ?></td>
                            <td><?= safe($st['year_level']) ?></td>
                            <td><?= safe($st['section']) ?></td>
                            <td><?= safe($st['mobile_number']) ?></td>
                            <td><?= safe($st['status'] ?? 'active') ?></td>

                            <td class="no-print">
                                <a href="student_profile.php?id=<?= safe($st['id']) ?>" class="btn btn-yellow">
                                    View Profile
                                </a>
                            </td>

                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <td class="no-print">
                                    <form method="POST" style="display:inline-block; margin-bottom:6px;">
                                        <input type="hidden" name="student_id" value="<?= safe($st['id']) ?>">
                                        <input type="hidden" name="action" value="update_status">

                                        <select name="status" onchange="this.form.submit()">
                                            <option value="active" <?= (($st['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>
                                                Active
                                            </option>
                                            <option value="suspended" <?= (($st['status'] ?? 'active') === 'suspended') ? 'selected' : '' ?>>
                                                Suspended
                                            </option>
                                        </select>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this student account?');">
                                        <input type="hidden" name="student_id" value="<?= safe($st['id']) ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <button class="btn btn-red">Delete</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= $_SESSION['role'] === 'admin' ? '9' : '8' ?>" style="text-align:center;">
                            No students found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>

